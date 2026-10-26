<?php

namespace Fabrement\Modules\Seo\Schema;

use Fabrement\Framework\Keys;
use WP_Error;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Translation\Translation;

/**
 * Custom structured data (schema.org JSON-LD) an agent attaches to ONE record or to EVERY record
 * of a post type.
 *
 * Yoast builds a good generic graph (Organization, WebSite, WebPage, Breadcrumb, Article) but it
 * cannot know that a `book` is a Book, a `course` is a Course, or that this one landing page is a
 * FAQPage with these questions. That is what this store holds: extra graph PIECES, written once,
 * output on every matching page.
 *
 * TWO SCOPES, one shape:
 *   - post   → post meta `_fabrement_schema` — pieces for this record only (a FAQPage, an Event).
 *   - type   → option `fabrement_schema_types[<post_type>]` — a TEMPLATE of pieces applied to every
 *              record of the type, with `{{token}}` placeholders resolved per post at render time
 *              ({{title}}, {{url}}, {{field:price}}, {{option:site.phone}}, {{term:genre}} …;
 *              the field tokens are {@see SchemaFields}). This is how a CPT gets a
 *              Product / Book / Event node without touching each record.
 *
 * Pieces are stored as they were given (tokens intact) and VALIDATED on write: a JSON-LD block
 * that does not parse is worse than none — Google silently drops the whole graph, Yoast's included.
 *
 * Output is the SEO plugin's: the nodes are a piece of its graph ({@see SchemaOutput}, one
 * <script>, one @graph, cross-references to its nodes work). Without an SEO plugin nothing is
 * printed — structured data is that plugin's job; the store keeps the templates until one runs.
 *
 * The page tokens ({{url}}, {{site_url}}, {{title}}, {{excerpt}}, {{featured_image}}, the dates) are
 * the plugin's own values ({@see \Fabrement\Modules\Seo\Contract\SeoBridge::schema_values()}), so
 * a node points at exactly the nodes the plugin prints, in the record's language. Fabrement's own
 * data — {{field:…}}, {{option:…}}, {{term:…}}, {{meta:…}} — is read here.
 */
final class SchemaStore {

    /** Hard cap per scope. JSON-LD is inlined into every page it applies to. */
    public const MAX_BYTES  = 32 * 1024;
    public const MAX_PIECES = 20;

    /** A token as it is checked on write and resolved on render: `{{name}}` or `{{name:key}}`. */
    private const TOKEN = '/\{\{\s*([a-z_]+)(?::([^}]+))?\s*\}\}/';

    /** Tokens a template may use. Kept as a list so the docs and the validator agree. */
    public const TOKENS = [
        'title', 'url', 'excerpt', 'featured_image', 'date_published', 'date_modified', 'site_url',
    ];

    // ───────────────────────────── read ─────────────────────────────

    /** @return list<array<string, mixed>> */
    public static function read_post( int $post_id ): array {
        $raw = get_post_meta( $post_id, Keys::META_SCHEMA, true );
        return self::decode( is_string( $raw ) ? $raw : '' );
    }

    /** @return list<array<string, mixed>> */
    public static function read_type( string $post_type ): array {
        $all = get_option( Keys::OPTION_SCHEMA_TYPES, [] );
        if ( ! is_array( $all ) || ! isset( $all[ $post_type ] ) ) {
            return [];
        }
        return self::decode( is_string( $all[ $post_type ] ) ? $all[ $post_type ] : '' );
    }

    /** @return list<string> post types that carry a type-level graph */
    public static function types_with_schema(): array {
        $all = get_option( Keys::OPTION_SCHEMA_TYPES, [] );
        return is_array( $all ) ? array_values( array_map( 'strval', array_keys( $all ) ) ) : [];
    }

    // ───────────────────────────── write ─────────────────────────────

    /**
     * @param list<array<string, mixed>> $pieces  [] clears.
     * @return true|WP_Error
     */
    public static function write_post( int $post_id, array $pieces ) {
        $json = self::validate( $pieces, (string) get_post_type( $post_id ), (string) get_permalink( $post_id ) );
        if ( $json instanceof WP_Error ) {
            return $json;
        }
        if ( $json === '' ) {
            delete_post_meta( $post_id, Keys::META_SCHEMA );
            return true;
        }
        update_post_meta( $post_id, Keys::META_SCHEMA, wp_slash( $json ) );
        return true;
    }

    /**
     * @param list<array<string, mixed>> $pieces  [] clears.
     * @return true|WP_Error
     */
    public static function write_type( string $post_type, array $pieces ) {
        $json = self::validate( $pieces, $post_type );
        if ( $json instanceof WP_Error ) {
            return $json;
        }
        $all = get_option( Keys::OPTION_SCHEMA_TYPES, [] );
        if ( ! is_array( $all ) ) {
            $all = [];
        }
        if ( $json === '' ) {
            unset( $all[ $post_type ] );
        } else {
            $all[ $post_type ] = $json;
        }
        if ( $all === [] ) {
            delete_option( Keys::OPTION_SCHEMA_TYPES );
        } else {
            update_option( Keys::OPTION_SCHEMA_TYPES, $all, false );
        }
        return true;
    }

    /**
     * Shape check + size cap. Returns the canonical JSON ('' for an empty list) or a WP_Error
     * naming the piece and the reason, so the agent can fix exactly that.
     *
     * @param list<array<string, mixed>> $pieces
     * @param string                     $post_type The records the graph is for: `{{field:…}}` paths
     *                                              are checked against that type's fields.
     * @param string                     $page_url  The record's address, for a record's own graph: an
     *                                              @id written as that literal URL is Yoast's too.
     * @return string|WP_Error
     */
    public static function validate( array $pieces, string $post_type = '', string $page_url = '' ) {
        if ( $pieces === [] ) {
            return '';
        }
        if ( count( $pieces ) > self::MAX_PIECES ) {
            return new WP_Error( 'too_many_pieces', sprintf( 'At most %d graph pieces per scope — got %d. One node per entity; lists go inside a node (e.g. FAQPage.mainEntity[]), not as separate pieces.', self::MAX_PIECES, count( $pieces ) ) );
        }
        foreach ( array_values( $pieces ) as $i => $piece ) {
            if ( ! is_array( $piece ) || array_keys( $piece ) === range( 0, count( $piece ) - 1 ) ) {
                return new WP_Error( 'piece_not_object', sprintf( 'graph[%d] must be a JSON object (a schema.org node), not a list or scalar.', $i ) );
            }
            $type = $piece['@type'] ?? null;
            if ( is_array( $type ) ) {
                $types = $type;
            } else {
                $types = [ $type ];
            }
            foreach ( $types as $t ) {
                if ( ! is_string( $t ) || ! preg_match( '/^[A-Z][A-Za-z0-9]*$/', $t ) ) {
                    return new WP_Error( 'bad_type', sprintf( 'graph[%d] needs a schema.org "@type" in UpperCamelCase (e.g. "FAQPage", "Product", "Event") — got %s.', $i, wp_json_encode( $type ) ) );
                }
            }
            if ( isset( $piece['@context'] ) ) {
                return new WP_Error( 'context_on_piece', sprintf( 'graph[%d] carries "@context" — drop it. Pieces are nodes of ONE @graph; the context is added once at output.', $i ) );
            }
            if ( self::takes_a_yoast_id( $piece['@id'] ?? null, $page_url ) ) {
                return new WP_Error(
                    'yoast_node_id',
                    sprintf(
                        'graph[%d] "@id" %s is the id of a node Yoast SEO already prints (the page URL is its WebPage; #breadcrumb, #primaryimage, #article, #website, #organization, #/schema/… are its too). Nodes are added to Yoast\'s graph, never swapped for its own: give yours an id of its own ("{{url}}#book") and point at Yoast\'s ("mainEntityOfPage": {"@id": "{{url}}"}). The page type is set-seo schemaPageType.',
                        $i,
                        wp_json_encode( $piece['@id'] )
                    )
                );
            }
            $nested = self::yoast_node_extended( $piece, 'graph[' . $i . ']', $page_url );
            if ( $nested !== '' ) {
                return new WP_Error(
                    'yoast_node_extended',
                    sprintf(
                        '%s names a node Yoast SEO prints (%s) and gives it properties of its own. Objects with one @id are ONE node in JSON-LD, so this would add to or overwrite the node Yoast prints. Point at it with the id alone — {"@id": "%s"}; what that node says (the name, logo, profiles of the organisation) is set-seo-defaults {site}.',
                        $nested,
                        wp_json_encode( self::id_at( $piece, $nested ) ),
                        (string) self::id_at( $piece, $nested )
                    )
                );
            }
            $bad = self::scan_strings( $piece, 'graph[' . $i . ']', $post_type );
            if ( $bad instanceof WP_Error ) {
                return $bad;
            }
        }
        $json = wp_json_encode( array_values( $pieces ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) {
            return new WP_Error( 'not_json', 'The graph could not be encoded as JSON: ' . json_last_error_msg() );
        }
        if ( strlen( $json ) > self::MAX_BYTES ) {
            return new WP_Error( 'too_large', sprintf( 'The graph is %d bytes; the cap is %d. It is inlined into every page it applies to — trim descriptions, drop decorative properties.', strlen( $json ), self::MAX_BYTES ) );
        }
        return $json;
    }

    /**
     * What Google needs of the node types agents write most, as [@type => [property, …]], each
     * entry either one property or alternatives ("offers|review|aggregateRating"). Without them
     * the node is valid JSON-LD that Search ignores — worth a warning, not a refusal.
     */
    private const REQUIRED = [
        'Product'       => [ 'name', 'offers|review|aggregateRating' ],
        'Event'         => [ 'name', 'startDate', 'location' ],
        'Person'        => [ 'name' ],
        'FAQPage'       => [ 'mainEntity' ],
        'LocalBusiness' => [ 'name', 'address' ],
        'Course'        => [ 'name', 'description' ],
        'Recipe'        => [ 'name', 'image' ],
        'JobPosting'    => [ 'title', 'description', 'datePosted', 'hiringOrganization' ],
    ];

    /**
     * Advice on a graph that was saved: what will not do what the agent likely meant. Never a
     * refusal — the graph is valid; these say where it will be thinner than intended.
     *
     * @param list<array<string, mixed>> $pieces
     * @return list<string>
     */
    public static function warnings( array $pieces ): array {
        $out = [];
        foreach ( array_values( $pieces ) as $i => $piece ) {
            if ( ! is_array( $piece ) ) {
                continue;
            }
            foreach ( (array) ( $piece['@type'] ?? [] ) as $type ) {
                foreach ( self::REQUIRED[ (string) $type ] ?? [] as $need ) {
                    $any = explode( '|', $need );
                    if ( array_intersect( $any, array_keys( $piece ) ) === [] ) {
                        $out[] = sprintf( 'graph[%d] %s has no %s — Google does not show a %s without it.', $i, $type, implode( ' / ', $any ), $type );
                    }
                }
            }
        }
        return $out;
    }

    /**
     * No markup in values (a "</script>" inside a JSON-LD string is the classic breakout; json
     * encoding escapes the slash, but the intent is never legitimate) and only KNOWN tokens.
     *
     * @return true|WP_Error
     */
    private static function scan_strings( $value, string $path, string $post_type = '' ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $k => $v ) {
                $r = self::scan_strings( $v, $path . '.' . $k, $post_type );
                if ( $r instanceof WP_Error ) {
                    return $r;
                }
            }
            return true;
        }
        if ( ! is_string( $value ) ) {
            return true;
        }
        if ( stripos( $value, '<script' ) !== false || stripos( $value, '</script' ) !== false ) {
            return new WP_Error( 'script_in_value', sprintf( '%s contains a <script> tag — structured data holds plain text and URLs only.', $path ) );
        }
        if ( preg_match_all( self::TOKEN, $value, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $tok ) {
                $name = $tok[1];
                $arg  = isset( $tok[2] ) ? trim( $tok[2] ) : '';
                if ( in_array( $name, [ 'meta', 'term' ], true ) ) {
                    if ( $arg === '' ) {
                        return new WP_Error( 'token_needs_arg', sprintf( '%s: {{%s:…}} needs a key — e.g. {{meta:price}} or {{term:genre}}.', $path, $name ) );
                    }
                    if ( $name === 'meta' && strpos( $arg, Keys::FIELD_META_PREFIX ) === 0 ) {
                        return new WP_Error( 'meta_is_a_field', sprintf( '%s: {{meta:%s}} is a Fabrement field — {{field:%s}} reads it as the page shows it (the default when unset, an image as its URL, a date-time with the time zone).', $path, $arg, substr( $arg, strlen( Keys::FIELD_META_PREFIX ) ) ) );
                    }
                    if ( $name === 'term' && ! self::public_taxonomy( $arg ) ) {
                        return new WP_Error( 'taxonomy_not_public', sprintf( '%s: {{term:%s}} — "%s" is not a public taxonomy (list-taxonomies); the graph is public.', $path, $arg, $arg ) );
                    }
                    if ( $name === 'meta' && self::is_private_meta( $arg ) ) {
                        return new WP_Error(
                            'protected_meta',
                            sprintf(
                                '%s: {{meta:%s}} reads a protected key — WordPress and plugins keep their internal state there (edit locks, template, other plugins\' settings), and the graph is public. A Fabrement field is {{field:<name>}}; list-post-type-fields names the keys a record has.',
                                $path,
                                $arg
                            )
                        );
                    }
                    continue;
                }
                if ( $name === 'field' || $name === 'option' ) {
                    if ( $arg === '' ) {
                        return new WP_Error( 'token_needs_arg', sprintf( '%s: {{%s:…}} needs a path — e.g. {{field:price}} or {{option:site.phone}}.', $path, $name ) );
                    }
                    $where = $path . ' {{' . $name . ':' . $arg . '}}';
                    $bad   = $name === 'option'
                        ? SchemaFields::check_option( $arg, $where )
                        : ( $post_type !== '' ? SchemaFields::check_field( $arg, $post_type, $where ) : null );
                    if ( $bad instanceof WP_Error ) {
                        return $bad;
                    }
                    continue;
                }
                if ( ! in_array( $name, self::TOKENS, true ) ) {
                    return new WP_Error( 'unknown_token', sprintf( '%s: unknown token {{%s}}. Known: {{%s}}, {{field:<name>}}, {{option:<page>.<field>}}, {{meta:<key>}}, {{term:<taxonomy>}}.', $path, $name, implode( '}}, {{', self::TOKENS ) ) );
                }
            }
        }
        // Braces left once the tokens are out are a token mistyped ({{Title}}, {{field :price}},
        // a lost brace): refused, or the page would print them as text.
        $rest = (string) preg_replace( self::TOKEN, '', $value );
        if ( str_contains( $rest, '{{' ) || str_contains( $rest, '}}' ) ) {
            preg_match( '/\{\{[^{}]*\}*|\}\}/', $rest, $fragment );
            return new WP_Error( 'malformed_token', sprintf( '%s: "%s" is not a token — a token is lowercase, {{name}} or {{name:key}}, with nothing before the colon. Known: {{%s}}, {{field:<name>}}, {{option:<page>.<field>}}, {{meta:<key>}}, {{term:<taxonomy>}}.', $path, $fragment[0] ?? '{{', implode( '}}, {{', self::TOKENS ) ) );
        }
        return true;
    }

    /**
     * The path of an object INSIDE a node that carries the @id of a Yoast node and more than that
     * id, or "". A reference is `{"@id": …}` and nothing else; anything beside it is a claim about
     * Yoast's node. The node's own @id is checked by the caller.
     *
     * @param array<mixed> $node
     */
    private static function yoast_node_extended( array $node, string $path, string $page_url, bool $top = true ): string {
        if ( ! $top && isset( $node['@id'] ) && count( $node ) > 1 && self::takes_a_yoast_id( $node['@id'], $page_url ) ) {
            return $path;
        }
        foreach ( $node as $key => $value ) {
            if ( is_array( $value ) ) {
                $found = self::yoast_node_extended( $value, $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key ), $page_url, false );
                if ( $found !== '' ) {
                    return $found;
                }
            }
        }
        return '';
    }

    /** The @id at a path yoast_node_extended() returned. @param array<mixed> $node @return mixed */
    private static function id_at( array $node, string $path ) {
        preg_match_all( '/\.([^.\[]+)|\[(\d+)\]/', (string) preg_replace( '/^graph\[\d+\]/', '', $path ), $m, PREG_SET_ORDER );
        foreach ( $m as $step ) {
            $node = $node[ $step[1] !== '' ? $step[1] : (int) $step[2] ] ?? [];
        }
        return is_array( $node ) ? ( $node['@id'] ?? '' ) : '';
    }

    /**
     * Whether a piece's own `@id` is one of Yoast's, in any spelling: the tokens, the literal site
     * URL, and — for one record, whose address is known — its literal URL or tokens that resolve to
     * it (on a static front page `{{url}}` IS the site URL, so `{{url}}#organization` is Yoast's).
     *
     * @param mixed $raw
     */
    private static function takes_a_yoast_id( $raw, string $page_url ): bool {
        if ( ! is_string( $raw ) || $raw === '' ) {
            return false;
        }
        $seo  = Seo::bridge();
        $id   = (string) preg_replace( '/\{\{\s*([a-z_]+)\s*\}\}/', '{{$1}}', $raw );
        $home = home_url( '/' );
        if ( $seo->is_own_schema_id( $id, '{{url}}', '{{site_url}}' ) || $seo->is_own_schema_id( $id, '', $home ) ) {
            return true;
        }
        if ( $page_url === '' ) {
            return false;
        }
        $resolved = strtr( $id, [ '{{url}}' => $page_url, '{{site_url}}' => $home ] );
        return $seo->is_own_schema_id( $resolved, $page_url, $home );
    }

    /**
     * A key WordPress treats as internal (a leading "_", or one a plugin marks through the
     * `is_protected_meta` filter). Checked on write AND at render: a graph stored before the write
     * check existed must not print one either.
     */
    private static function is_private_meta( string $key ): bool {
        return is_protected_meta( $key, 'post' );
    }

    /** A taxonomy whose terms are public anyway — the graph is. */
    private static function public_taxonomy( string $taxonomy ): bool {
        $object = get_taxonomy( $taxonomy );
        return $object instanceof \WP_Taxonomy && $object->public;
    }

    /** @return list<array<string, mixed>> */
    private static function decode( string $json ): array {
        if ( $json === '' ) {
            return [];
        }
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return [];
        }
        return array_values( array_filter( $data, 'is_array' ) );
    }

    // ───────────────────────────── resolve ─────────────────────────────

    /**
     * The pieces that apply to a post — type template first, then the post's own — with every
     * `{{token}}` replaced by the post's value. A piece whose `@id` is missing gets a stable one
     * derived from the URL, so every render names the same node.
     *
     * @param bool                       $with_type Include the template stored for this record's
     *                                              OWN post type. The posts page is a `page`, so
     *                                              what this suppresses is the site's page-level
     *                                              template — which describes a document, and the
     *                                              blog index is a listing.
     * @param array<string,string>|null $values    The page tokens as the SEO plugin gives them
     *                                              (SeoBridge::schema_values()); null asks it. Without
     *                                              an SEO plugin there are none, and nothing resolves.
     */
    public static function resolve_for_post( int $post_id, bool $with_type = true, ?array $values = null ): array {
        // Supplied values belong to the caller's existing page context. Without them the ENTIRE
        // resolution must follow the record, including field/option tokens after schema_values().
        return $values !== null
            ? self::resolve( $post_id, $with_type, $values )
            : Translation::in_context( Translation::live_language_of( $post_id ), static fn(): array => self::resolve( $post_id, $with_type, null ) );
    }

    /** @param array<string,string>|null $values */
    private static function resolve( int $post_id, bool $with_type, ?array $values ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return [];
        }
        $pieces = $with_type
            ? array_merge( self::read_type( (string) $post->post_type ), self::read_post( $post_id ) )
            : self::read_post( $post_id );
        if ( $pieces === [] ) {
            return [];
        }
        // A password-protected record keeps its words behind the password; the JSON-LD in <head>
        // is served before anyone types it. So its summary, field values and terms resolve
        // to "" (and those properties drop out) — for every visitor, the one who knows the
        // password included, since the same page is cached for both.
        $locked = (string) $post->post_password !== '';
        $ctx    = $values ?? Seo::bridge()->schema_values( $post_id );
        if ( $ctx === [] ) {
            return [];
        }
        if ( $locked ) {
            $ctx['excerpt'] = '';
        }
        $out    = [];
        foreach ( $pieces as $i => $piece ) {
            $resolved = self::replace( $piece, $ctx, $locked ? 0 : $post_id );
            if ( ! is_array( $resolved ) ) {
                continue;
            }
            if ( empty( $resolved['@id'] ) ) {
                $type             = is_array( $resolved['@type'] ?? null ) ? implode( '-', $resolved['@type'] ) : (string) ( $resolved['@type'] ?? 'node' );
                $resolved['@id']  = $ctx['url'] . '#fabrement-' . strtolower( $type ) . '-' . $i;
            }
            $out[] = self::prune_empty( $resolved );
        }
        return $out;
    }

    /** @param int $post_id 0 = a locked record: every field, meta and term token resolves to "". */
    private static function replace( $value, array $ctx, int $post_id ) {
        if ( is_array( $value ) ) {
            $out = [];
            foreach ( $value as $k => $v ) {
                $out[ $k ] = self::replace( $v, $ctx, $post_id );
            }
            return $out;
        }
        if ( ! is_string( $value ) || strpos( $value, '{{' ) === false ) {
            return $value;
        }
        return preg_replace_callback(
            self::TOKEN,
            static function ( array $m ) use ( $ctx, $post_id ): string {
                $name = $m[1];
                $arg  = isset( $m[2] ) ? trim( $m[2] ) : '';
                if ( $name === 'field' ) {
                    return $post_id > 0 ? SchemaFields::field( $arg, $post_id ) : '';
                }
                if ( $name === 'option' ) {
                    // Site-wide facts (a phone, an address), not the record's words: a locked
                    // record does not hide them.
                    return SchemaFields::option( $arg );
                }
                if ( $name === 'meta' ) {
                    if ( $post_id <= 0 || self::is_private_meta( $arg ) ) {
                        return '';
                    }
                    $v = get_post_meta( $post_id, $arg, true );
                    return is_scalar( $v ) ? (string) $v : '';
                }
                if ( $name === 'term' ) {
                    if ( $post_id <= 0 || ! self::public_taxonomy( $arg ) ) {
                        return '';
                    }
                    $terms = get_the_terms( $post_id, $arg );
                    if ( ! is_array( $terms ) ) {
                        return '';
                    }
                    return implode( ', ', array_map( static fn( $t ) => (string) $t->name, $terms ) );
                }
                return $ctx[ $name ] ?? '';
            },
            $value
        );
    }

    /** Drop properties whose resolved value is "" — a Product with "price": "" fails validation; without the key it merely lacks a price. */
    private static function prune_empty( array $node ): array {
        $out = [];
        foreach ( $node as $k => $v ) {
            if ( is_array( $v ) ) {
                $v = self::prune_empty( $v );
                if ( $v === [] ) {
                    continue;
                }
            } elseif ( $v === '' || $v === null ) {
                continue;
            }
            $out[ $k ] = $v;
        }
        return $out;
    }
}
