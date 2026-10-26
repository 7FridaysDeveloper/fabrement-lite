<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\TermLanguages;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Seo\SeoTranslations;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * The site's search appearance as a list: records and terms with where their title and
 * description come from, whether they are kept out of search — and, on a multilingual site, whether
 * each language version's texts are translated. Filters answer the audit questions directly ("which
 * pages have no description", "which French versions still read English"); get-seo then opens one,
 * set-seo writes it.
 *
 * Read in batches, not per row: the records' columns in one query, their SEO through the SEO bridge
 * (SeoBridge::post_listing / termListing — one read each), their languages through the translation
 * bridge (one per post type). Addresses — which run WordPress's and the translation plugin's link
 * filters — only for the rows that go out, and for the few a canonical has to be compared with. The
 * status of a version's texts is {@see SeoTranslations}, the same rule get-seo reports.
 */
final class ListSeoAbility extends AbstractAbility {

    private const FILTERS = [ 'noMetaDesc', 'noCustomTitle', 'noindex', 'canonicalElsewhere' ];

    /** Rows read per kind before filtering: a listing, not an export. */
    private const SCAN = 5000;

    public function name(): string {
        return 'fabrement/list-seo';
    }

    public function label(): string {
        return 'List SEO';
    }

    public function description(): string {
        return 'List what search engines see across the site — records (pages, posts, any type) or '
            . 'terms — each with seo {title, metaDesc: "own"|"template"|"none", noindex, canonical}'
            . self::multilingual_text( ', its language and originalId; a version also carries translation {title, metaDesc: '
            . '"translated"|"sameAsOriginal"|"missing"|"template"}, an original its missingLanguages' )
            . '. `filter` narrows to what needs work: noMetaDesc (no description at all), noCustomTitle (the title is the '
            . 'type\'s template), noindex, canonicalElsewhere'
            . self::multilingual_text( ', untranslated (a version whose title or description is missing or still the '
            . 'original\'s); `language` lists one language\'s rows; `missingIn` lists originals with no version in that '
            . 'language (translate-content / translate-term makes it)' )
            . '. A row kept out of search (noindex) is left out of every filter but noindex. Then get-seo opens one and '
            . 'set-seo writes it.';
    }

    public function contract(): ?string {
        return 'seo-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'kind'     => [ 'type' => 'string', 'enum' => [ 'content', 'term' ], 'description' => 'content (default): records; term: term archives.' ],
                'postType' => [ 'type' => 'string', 'description' => 'kind content: one post type. Default: every public type.' ],
                'taxonomy' => [ 'type' => 'string', 'description' => 'kind term: one taxonomy. Default: every public taxonomy.' ],
                'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'any' ], 'description' => 'kind content: default publish — what search engines can see.' ],
                'filter'   => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'string', 'enum' => array_merge( self::FILTERS, self::multilingual( [ 'untranslated' ] ) ) ],
                    'description' => 'All must hold.',
                ],
                'limit'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Default 50.' ],
                'offset'   => [ 'type' => 'integer', 'minimum' => 0 ],
            ] + self::multilingual( [
                'language' => [ 'type' => 'string', 'description' => 'Only rows in this language (list-languages).' ],
                'missingIn' => [ 'type' => 'string', 'description' => 'Only originals with no version in this language.' ],
            ] ),
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'items', 'total' ],
            'properties' => [
                'items'  => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'total'  => [ 'type' => 'integer', 'description' => 'Rows matching, before limit/offset.' ],
                'offset' => [ 'type' => 'integer' ],
                'note'   => [ 'type' => 'string' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        if ( ! Seo::bridge()->is_active() ) {
            return new WP_Error( 'yoast_inactive', 'Yoast SEO is not active: nothing decides what search engines see. Ask the site admin to install and activate it (get-seo shows the state).' );
        }
        $multi = Translation::is_multilingual();
        $codes = $multi ? array_column( Translation::bridge()->languages(), 'code' ) : [];
        foreach ( [ 'language', 'missingIn' ] as $key ) {
            $code = $multi ? trim( (string) ( $input[ $key ] ?? '' ) ) : '';
            if ( $code !== '' && ! in_array( $code, $codes, true ) ) {
                return new WP_Error( 'unknown_language', sprintf( 'The site has no "%s" language. Active: %s (list-languages).', $code, implode( ', ', $codes ) ) );
            }
        }

        $kind = (string) ( $input['kind'] ?? 'content' );
        if ( $kind === 'term' ) {
            $read = $this->term_rows( (string) ( $input['taxonomy'] ?? '' ) );
        } else {
            $read = $this->content_rows( (string) ( $input['postType'] ?? '' ), (string) ( $input['status'] ?? 'publish' ) );
        }
        if ( $read instanceof WP_Error ) {
            return $read;
        }

        $rows   = array_values( array_filter( $read['rows'], $this->matcher( $input, $multi ) ) );
        $offset = (int) ( $input['offset'] ?? 0 );
        $out    = [
            'items'  => self::with_urls( array_slice( $rows, $offset, (int) ( $input['limit'] ?? 50 ) ) ),
            'total'  => count( $rows ),
            'offset' => $offset,
        ];
        if ( $read['truncated'] ) {
            $out['note'] = sprintf( 'Only the first %d rows of this kind were read — narrow with postType / taxonomy.', self::SCAN );
        }
        return $out;
    }

    /** @return callable(array):bool */
    private function matcher( array $input, bool $multi ): callable {
        $filters = array_map( 'strval', (array) ( $input['filter'] ?? [] ) );
        $lang    = $multi ? trim( (string) ( $input['language'] ?? '' ) ) : '';
        $missing = $multi ? trim( (string) ( $input['missingIn'] ?? '' ) ) : '';

        return static function ( array $row ) use ( $filters, $lang, $missing ): bool {
            $seo = $row['seo'];
            // A row kept out of search shows nothing to fix: every filter but noindex passes it by.
            if ( $seo['noindex'] && ( $missing !== '' || array_diff( $filters, [ 'noindex' ] ) !== [] ) ) {
                return false;
            }
            foreach ( $filters as $filter ) {
                $ok = true;
                switch ( $filter ) {
                    case 'noMetaDesc':
                        $ok = $seo['metaDesc'] === 'none';
                        break;
                    case 'noCustomTitle':
                        $ok = $seo['title'] !== 'own';
                        break;
                    case 'noindex':
                        $ok = $seo['noindex'];
                        break;
                    case 'canonicalElsewhere':
                        $ok = $seo['canonical'] !== '' && untrailingslashit( $seo['canonical'] ) !== untrailingslashit( self::url_of( $row ) );
                        break;
                    case 'untranslated':
                        $ok = isset( $row['translation'] ) && SeoTranslations::untranslated( $row['translation'] );
                        break;
                }
                if ( ! $ok ) {
                    return false;
                }
            }
            if ( $lang !== '' && ( $row['language'] ?? '' ) !== $lang ) {
                return false;
            }
            return $missing === '' || in_array( $missing, $row['missingLanguages'] ?? [], true );
        };
    }

    /** A title as a reader sees it — WordPress hands it back with its typographic entities. */
    private static function text( $value ): string {
        return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
    }

    // ── addresses, for the rows that need one ────────────────────────────────────────────────

    /** @param array<string,mixed> $row */
    private static function url_of( array $row ): string {
        $link = $row['_link'] ?? null;
        return is_callable( $link ) ? (string) $link() : '';
    }

    /**
     * The rows that go out, with their addresses — records in one pass per language.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function with_urls( array $rows ): array {
        $posts = [];
        foreach ( $rows as $row ) {
            if ( isset( $row['_post'] ) ) {
                $posts[ (int) $row['id'] ] = (string) $row['_post'];
            }
        }
        $links = $posts !== [] && Translation::is_multilingual() ? Translation::bridge()->permalinks_of_many( $posts ) : [];
        foreach ( $rows as $i => $row ) {
            $url = $links[ (int) $row['id'] ] ?? self::url_of( $row );
            unset( $row['_link'], $row['_post'] );
            // Right after the title, where a reader of the row looks for it.
            $out = [];
            foreach ( $row as $key => $value ) {
                $out[ $key ] = $value;
                if ( $key === 'title' ) {
                    $out['url'] = (string) $url;
                }
            }
            $rows[ $i ] = $out;
        }
        return $rows;
    }

    // ── records ──────────────────────────────────────────────────────────────────────────────

    /** @return array{rows: list<array<string,mixed>>, truncated: bool}|WP_Error */
    private function content_rows( string $post_type, string $status ) {
        if ( $post_type !== '' ) {
            if ( ! post_type_exists( $post_type ) ) {
                return new WP_Error( 'unknown_post_type', sprintf( 'Post type "%s" is not registered — see list-post-types.', $post_type ) );
            }
            $types = [ $post_type ];
        } else {
            $types = array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
        }
        $statuses = $status === 'any' ? [ 'publish', 'draft', 'pending', 'future', 'private' ] : [ $status ];

        // The columns a row shows, every language at once: the table knows no language, and the
        // translation plugin's filters would narrow get_posts() to one.
        global $wpdb;
        $in_types    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $in_statuses = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one read for a listing; the IN lists are placeholders built per type and status.
        $posts     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_title, post_status FROM {$wpdb->posts} WHERE post_type IN ($in_types) AND post_status IN ($in_statuses) ORDER BY post_title ASC, ID ASC LIMIT %d", ...array_merge( $types, $statuses, [ self::SCAN ] ) ) );
        $truncated = count( $posts ) >= self::SCAN;

        $by_type = [];
        foreach ( $posts as $post ) {
            $by_type[ (string) $post->post_type ][ (int) $post->ID ] = $post;
        }

        $multi = Translation::is_multilingual();
        $rows  = [];
        foreach ( $by_type as $type => $group ) {
            $facts     = $multi ? Translation::bridge()->version_facts( array_keys( $group ), $type ) : [];
            $originals = $multi ? self::originals( $facts, $type ) : [];
            $outside   = array_diff( array_values( $originals ), array_keys( $group ) );
            $listing   = Seo::bridge()->post_listing( array_fill_keys( array_keys( $group ), $type ) + array_fill_keys( $outside, $type ) );
            foreach ( $group as $id => $post ) {
                $row = [
                    'kind'     => 'content',
                    'id'       => $id,
                    'postType' => $type,
                    'title'    => self::text( $post->post_title ),
                    'status'   => (string) $post->post_status,
                    'seo'      => $listing[ $id ]['seo'] ?? [],
                    '_post'    => (string) ( $facts[ $id ]['language'] ?? '' ),
                    '_link'    => static fn(): string => (string) get_permalink( $id ),
                ];
                if ( isset( $facts[ $id ] ) ) {
                    $original          = $originals[ $id ] ?? $id;
                    $row['language']   = (string) $facts[ $id ]['language'];
                    $row['originalId'] = $original;
                    if ( $original === $id ) {
                        $row['missingLanguages'] = Translation::missing_languages( $facts[ $id ]['translations'] );
                    } else {
                        $row['translation'] = SeoTranslations::status( $listing[ $original ]['texts'] ?? [], $listing[ $id ]['texts'] ?? [] );
                    }
                }
                $rows[] = $row;
            }
        }
        // One order across the types, as the query had it.
        usort( $rows, static fn( array $a, array $b ): int => [ $a['title'], $a['id'] ] <=> [ $b['title'], $b['id'] ] );
        return [ 'rows' => $rows, 'truncated' => $truncated ];
    }

    /**
     * Each version's original, from the language facts already read: the member of its group the
     * translation plugin calls the original. A group whose original is not in this read (a draft,
     * one in the trash) asks the plugin, as get-seo does.
     *
     * @param array<int, array{language:string,translations:array<string,int>,isOriginal:bool}> $facts
     * @return array<int,int> version id → original id (originals are absent)
     */
    private static function originals( array $facts, string $type ): array {
        $out = [];
        foreach ( $facts as $id => $fact ) {
            if ( $fact['isOriginal'] ) {
                continue;
            }
            $original = 0;
            foreach ( $fact['translations'] as $member ) {
                if ( ! empty( $facts[ (int) $member ]['isOriginal'] ) ) {
                    $original = (int) $member;
                    break;
                }
            }
            $out[ (int) $id ] = $original > 0 ? $original : Translation::original_of( (int) $id, $type );
        }
        return $out;
    }

    // ── terms ────────────────────────────────────────────────────────────────────────────────

    /** @return array{rows: list<array<string,mixed>>, truncated: bool}|WP_Error */
    private function term_rows( string $taxonomy ) {
        if ( ! Seo::bridge()->terms_supported() ) {
            return new WP_Error( 'yoast_unsupported', sprintf( 'This Yoast version (%s) stores term SEO differently; it cannot be listed from here.', Seo::bridge()->version() ) );
        }
        if ( $taxonomy !== '' ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                return new WP_Error( 'unknown_taxonomy', sprintf( 'Taxonomy "%s" is not registered — see list-taxonomies.', $taxonomy ) );
            }
            $taxonomies = [ $taxonomy ];
        } else {
            $taxonomies = array_values( array_diff( get_taxonomies( [ 'public' => true ] ), [ 'post_format' ] ) );
        }
        $args   = [ 'taxonomy' => $taxonomies, 'hide_empty' => false, 'number' => self::SCAN, 'orderby' => 'name' ];
        $bridge = Translation::bridge();
        // Once per language: the translation plugin filters get_terms() by the request's language
        // (see list-terms for why lifting the filter does not work).
        $terms     = [];
        $truncated = false;
        foreach ( Translation::is_multilingual() ? array_column( $bridge->languages(), 'code' ) : [ '' ] as $code ) {
            $found = $code === '' ? get_terms( $args ) : $bridge->in_language( (string) $code, static fn() => get_terms( $args ) );
            if ( is_wp_error( $found ) ) {
                return $found;
            }
            $truncated = $truncated || count( (array) $found ) >= self::SCAN;
            foreach ( (array) $found as $term ) {
                $terms[ (int) $term->term_id ] = $term;
            }
        }

        // The originals of the versions listed, for their texts: those not listed themselves too.
        $languages = [];
        $outside   = [];
        foreach ( $terms as $id => $term ) {
            $languages[ $id ] = TermLanguages::of( $term );
            $original         = (int) ( $languages[ $id ]['originalId'] ?? $id );
            if ( $original !== $id && ! isset( $terms[ $original ] ) ) {
                $found = \WP_Term::get_instance( $original );
                if ( $found instanceof \WP_Term ) {
                    $outside[ $original ] = $found;
                }
            }
        }
        $listing = Seo::bridge()->term_listing( array_values( $terms + $outside ) );

        $rows = [];
        foreach ( $terms as $id => $term ) {
            $facts = $languages[ $id ];
            $row   = [
                'kind'     => 'term',
                'id'       => $id,
                'taxonomy' => (string) $term->taxonomy,
                'title'    => self::text( $term->name ),
                'seo'      => $listing[ $id ]['seo'] ?? [],
                '_link'    => static fn(): string => Translation::term_link( $term, (string) ( $facts['language'] ?? '' ) ),
            ];
            if ( $facts !== null ) {
                $row['language']   = $facts['language'];
                $row['originalId'] = $facts['originalId'];
                if ( $facts['originalId'] === $id ) {
                    $row['missingLanguages'] = Translation::missing_languages( $facts['translations'] );
                } else {
                    $row['translation'] = SeoTranslations::status( $listing[ $facts['originalId'] ]['texts'] ?? [], $listing[ $id ]['texts'] ?? [] );
                }
            }
            $rows[] = $row;
        }
        return [ 'rows' => $rows, 'truncated' => $truncated ];
    }
}
