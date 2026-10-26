<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\PostTypeArchive;
use Fabrement\Modules\Mcp\Support\ContentGuard;
use Fabrement\Modules\Mcp\Support\LanguageVersion;
use Fabrement\Modules\Mcp\Support\NotKept;
use Fabrement\Modules\Mcp\Support\StrictBooleans;
use Fabrement\Modules\Seo\Contract\SeoBridge;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Per-record SEO through the SEO plugin ({@see Seo::bridge()}): the fields of its metabox, written
 * by an agent — for a record, a term archive, or a post-type archive.
 *
 * Refused without an SEO plugin: the values would sit in post meta with nothing reading them, and
 * the agent would report "SEO done" on a page whose <title> never changed.
 */
final class SetSeoAbility extends AbstractAbility {

    private const MAX_TITLE = 200;
    private const MAX_DESC  = 320;

    /** What a post-type archive takes: the plugin keeps one site-wide value each. */
    private const ARCHIVE_DEFAULT_FIELDS = [ 'title', 'metaDesc', 'noindex' ];

    public function name(): string {
        return 'fabrement/set-seo';
    }

    public function label(): string {
        return 'Set SEO for a record';
    }

    public function description(): string {
        return 'Set the Yoast fields of ONE record (contentId), ONE term archive (termId — a category, tag or '
            . 'term of a custom taxonomy) or ONE post-type archive (archive: the post type, e.g. "book" for '
            . '/books/; it takes title, metaDesc and noindex, one value for the whole site): title, metaDesc, focusKeyword, canonical, '
            . 'noindex/nofollow, robotsAdvanced, schemaPageType/ArticleType, og*/twitter*. A term takes title, metaDesc, '
            . 'focusKeyword, canonical, noindex, breadcrumbTitle, cornerstone, og*/twitter* only. Send only what '
            . 'changes; "" or null clears a field. Refused when Yoast is not active. Custom schema nodes go '
            . 'through set-schema. Verify with get-seo {contentId} / {termId} / {archive}.';
    }

    public function contract(): ?string {
        return 'seo-contract';
    }

    public function input_schema(): array {
        $seo = Seo::bridge();
        return [
            'type'                 => 'object',
            'properties'           => [
                'contentId'          => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'A page, post or custom record. Give this, termId OR archive.' ],
                'termId'             => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'A term (list-terms): the SEO of its archive page.' ],
                'archive'            => [ 'type' => 'string', 'description' => 'A post type (list-archives): the SEO of its archive.' ],
            ] + self::multilingual( [
                'language'           => [ 'type' => 'string', 'description' => 'With the contentId or termId of an ORIGINAL: its version in this language (the answer gives that id, and the original in from). Codes: list-languages.' ],
            ] ) + [
                'title'              => [ 'type' => [ 'string', 'null' ], 'maxLength' => self::MAX_TITLE ],
                'metaDesc'           => [ 'type' => [ 'string', 'null' ], 'maxLength' => self::MAX_DESC ],
                'focusKeyword'       => [ 'type' => [ 'string', 'null' ], 'maxLength' => 120 ],
                'canonical'          => [ 'type' => [ 'string', 'null' ], 'maxLength' => 2000 ],
                'noindex'            => [ 'type' => [ 'boolean', 'null' ], 'description' => 'true hides it from search, false shows it even when its type is hidden, null follows the setting of its type; an archive takes only true or false.' ],
                'nofollow'           => [ 'type' => 'boolean' ],
                'robotsAdvanced'     => [ 'type' => [ 'array', 'null' ], 'items' => self::one_of( $seo->robots_advanced() ) ],
                'breadcrumbTitle'    => [ 'type' => [ 'string', 'null' ], 'maxLength' => 200 ],
                'cornerstone'        => [ 'type' => 'boolean' ],
                'schemaPageType'     => self::one_of( $seo->page_types(), true ),
                'schemaArticleType'  => self::one_of( $seo->article_types(), true ),
                'ogTitle'            => [ 'type' => [ 'string', 'null' ], 'maxLength' => self::MAX_TITLE ],
                'ogDescription'      => [ 'type' => [ 'string', 'null' ], 'maxLength' => self::MAX_DESC ],
                'ogImageId'          => [ 'type' => [ 'integer', 'null' ], 'minimum' => 0 ],
                'twitterTitle'       => [ 'type' => [ 'string', 'null' ], 'maxLength' => self::MAX_TITLE ],
                'twitterDescription' => [ 'type' => [ 'string', 'null' ], 'maxLength' => self::MAX_DESC ],
                'twitterImageId'     => [ 'type' => [ 'integer', 'null' ], 'minimum' => 0 ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * A string from the SEO plugin's list — any string when there is no plugin to list them (the
     * call is refused then anyway, and an empty enum is not a valid schema).
     *
     * @param list<string> $values
     * @return array<string,mixed>
     */
    public static function one_of( array $values, bool $nullable = false ): array {
        $schema = [ 'type' => $nullable ? [ 'string', 'null' ] : 'string' ];
        if ( $values !== [] ) {
            $schema['enum'] = $nullable ? array_merge( $values, [ null ] ) : $values;
        }
        return $schema;
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'written' ],
            'properties' => [
                'contentId' => [ 'type' => 'integer' ],
                'termId'    => [ 'type' => 'integer' ],
                'archive'   => [ 'type' => 'string' ],
                'from'      => [ 'type' => 'integer', 'description' => 'The original whose `language` version this is.' ],
                'written'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'stored'    => [ 'type' => 'object' ],
                'effective' => [ 'type' => 'object' ],
                'warnings'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'note'      => [ 'type' => 'string' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        $invalid = StrictBooleans::check( $this->input_schema(), $input );
        if ( $invalid !== null ) {
            return $invalid;
        }
        $seo = Seo::bridge();
        if ( ! $seo->is_active() ) {
            return new WP_Error( 'yoast_inactive', 'Yoast SEO is not active, so these fields would never reach the page. Ask the site admin to install and activate it (get-seo shows the state).' );
        }

        $targets = count( array_filter( [ ! empty( $input['contentId'] ), ! empty( $input['termId'] ), ! empty( $input['archive'] ) ] ) );
        if ( $targets !== 1 ) {
            return new WP_Error( 'one_target', 'Give exactly one of contentId (a record), termId (a term archive) or archive (a post-type archive).' );
        }
        $input = LanguageVersion::target( $input );
        if ( is_wp_error( $input ) ) {
            return $input;
        }
        $from = (int) ( $input['from'] ?? 0 );
        unset( $input['from'] );
        if ( ! empty( $input['archive'] ) ) {
            return self::archive( $seo, $input );
        }
        if ( ! empty( $input['termId'] ) ) {
            $result = self::term( $seo, $input );
        } else {
            $fields = $input;
            unset( $fields['contentId'] );
            $result = self::record( $seo, (int) $input['contentId'], $fields );
        }
        return $from > 0 && is_array( $result ) ? $result + [ 'from' => $from ] : $result;
    }

    /**
     * A record's fields — a page, a post or a custom record.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>|WP_Error
     */
    private static function record( SeoBridge $seo, int $id, array $fields ) {
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post || in_array( $post->post_type, [ 'revision', 'attachment', 'nav_menu_item' ], true ) ) {
            return ContentGuard::not_found( $id );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You may not edit content %d.', $id ) );
        }

        $warnings = [];
        $fields   = self::media_and_canonical( $fields );
        if ( is_wp_error( $fields ) ) {
            return $fields;
        }

        if ( isset( $fields['title'] ) && is_string( $fields['title'] ) && mb_strlen( $fields['title'] ) > 70 && ! $seo->has_variables( $fields['title'] ) ) {
            $warnings[] = sprintf( 'title is %d characters; search results truncate around 60.', mb_strlen( $fields['title'] ) );
        }
        if ( isset( $fields['metaDesc'] ) && is_string( $fields['metaDesc'] ) && mb_strlen( $fields['metaDesc'] ) > 160 ) {
            $warnings[] = sprintf( 'metaDesc is %d characters; search results truncate around 155.', mb_strlen( $fields['metaDesc'] ) );
        }
        if ( ! empty( $fields['noindex'] ) && $post->post_status === 'publish' ) {
            $warnings[] = 'noindex=true on a published record — it will drop out of search results.';
        }
        if ( ! empty( $fields['schemaArticleType'] ) && $fields['schemaArticleType'] !== 'None' && ! $seo->article_supported( (string) $post->post_type ) ) {
            return new WP_Error(
                'article_needs_author',
                sprintf( 'Yoast outputs an Article node only for post types that support "author" — "%s" does not, so schemaArticleType would do nothing. Describe the record with set-schema instead.', $post->post_type )
            );
        }

        $refused = NotKept::error( $seo->not_kept_post( (string) $post->post_type, $fields ) );
        if ( $refused !== null ) {
            return $refused;
        }
        $written = $seo->write_post( $id, $fields );
        // The plugin rebuilds its index on shutdown; read back AFTER a direct rebuild so
        // `effective` reflects this call, not the previous state.
        $seo->reindex_post( $id );

        return [
            'contentId' => $id,
            'written'   => $written,
            'stored'    => (object) $seo->read_post( $id ),
            'effective' => (object) self::without( $seo->effective_post( $id, false ), [ 'schema' ] ),
            'warnings'  => $warnings,
        ];
    }

    /**
     * A post-type archive: the SEO plugin keeps one title, description and noindex for it across the
     * whole site, and that is what is written.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    private static function archive( SeoBridge $seo, array $input ) {
        $type    = (string) $input['archive'];
        $archive = PostTypeArchive::check( $type );
        if ( $archive instanceof WP_Error ) {
            return $archive;
        }
        $lang = (string) ( $input['language'] ?? '' );
        if ( $lang !== '' && Translation::is_multilingual() && $lang !== Translation::bridge()->default_language() ) {
            return new WP_Error( 'archive_one_value', sprintf( 'The "%s" archive has ONE SEO value for every language; send it without language.', $type ) );
        }
        $fields = $input;
        unset( $fields['archive'], $fields['language'] );

        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'The SEO of an archive is a site setting — that needs the manage_options capability.' );
        }
        $other = array_diff( array_keys( $fields ), self::ARCHIVE_DEFAULT_FIELDS );
        if ( $other !== [] ) {
            return new WP_Error( 'not_for_archive', sprintf( '%s cannot be set on an archive. It takes: %s.', implode( ', ', $other ), implode( ', ', self::ARCHIVE_DEFAULT_FIELDS ) ) );
        }
        if ( array_key_exists( 'noindex', $fields ) && $fields['noindex'] === null ) {
            return new WP_Error(
                'archive_noindex_null',
                sprintf( 'The "%s" archive noindex is true (hidden) or false (shown) — there is no type setting for it to follow. Send true or false.', $type )
            );
        }
        $refused = NotKept::error( $seo->not_kept_defaults( $fields ) );
        if ( $refused !== null ) {
            return $refused;
        }
        $written  = $seo->write_archive_defaults( $type, $fields );
        $warnings = [ sprintf( 'The "%s" archive: these are site-wide values for it%s.', $type, Translation::is_multilingual() ? ', the same in every language' : '' ) ];
        if ( ! empty( $fields['noindex'] ) ) {
            $warnings[] = 'noindex=true on this archive — it will drop out of search results.';
        }

        return [
            'archive'  => $type,
            'written'  => $written,
            'stored'   => (object) $seo->read_archive_defaults( $type ),
            'warnings' => $warnings,
        ];
    }

    /**
     * The term branch. Its fields are a subset: a term archive has no nofollow, advanced robots or
     * schema types in Yoast — refused by name rather than dropped, so "set" means set.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    private static function term( SeoBridge $seo, array $input ) {
        if ( ! $seo->terms_supported() ) {
            return new WP_Error( 'yoast_unsupported', sprintf( 'This Yoast version (%s) stores term SEO differently; term SEO cannot be written from here. Set it on the term screen in wp-admin.', $seo->version() ) );
        }
        // The term asked for, not get_term()'s version of it: a translation plugin adjusts the id
        // to the request's language, and the SEO of another language's term would be read or written.
        $term = \WP_Term::get_instance( (int) $input['termId'] );
        if ( ! $term instanceof \WP_Term ) {
            return new WP_Error( 'not_found', sprintf( 'No term %d — see list-terms.', (int) $input['termId'] ) );
        }
        $taxonomy = get_taxonomy( $term->taxonomy );
        if ( ! $taxonomy || ! $taxonomy->public ) {
            return new WP_Error( 'not_public', sprintf( 'Taxonomy "%s" has no public archive, so its terms have no page to describe.', $term->taxonomy ) );
        }
        if ( ! current_user_can( 'edit_term', (int) $term->term_id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You may not edit term %d.', (int) $term->term_id ) );
        }
        $fields = $input;
        unset( $fields['termId'] );
        $other = array_diff( array_keys( $fields ), $seo->term_fields() );
        if ( $other !== [] ) {
            return new WP_Error( 'not_for_terms', sprintf( '%s cannot be set on a term. A term takes: %s.', implode( ', ', $other ), implode( ', ', $seo->term_fields() ) ) );
        }
        $fields = self::media_and_canonical( $fields );
        if ( is_wp_error( $fields ) ) {
            return $fields;
        }

        $refused = NotKept::error( $seo->not_kept_term( $term, $fields ) );
        if ( $refused !== null ) {
            return $refused;
        }
        $written = $seo->write_term( $term, $fields );
        if ( $written === [] && $fields !== [] ) {
            return new WP_Error( 'yoast_unsupported', 'Yoast refused the term values; nothing was written. Set them on the term screen in wp-admin.' );
        }

        // No `effective` here: on a WPML site Yoast SEO Multilingual answers term SEO from a copy it
        // cached earlier in this very request, so Yoast would print the state BEFORE this write.
        // get-seo {termId} runs in a request of its own and reads what is stored.
        return [
            'termId'   => (int) $term->term_id,
            'written'  => $written,
            'stored'   => (object) $seo->read_term( $term ),
            'warnings' => [],
            'note'     => 'Check what Yoast prints with get-seo {termId}.',
        ];
    }

    /**
     * The input rules a record and a term share: canonical is an absolute http(s) URL; an image id
     * is an image attachment.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>|WP_Error
     */
    private static function media_and_canonical( array $fields ) {
        if ( isset( $fields['canonical'] ) && is_string( $fields['canonical'] ) && $fields['canonical'] !== '' ) {
            $url = esc_url_raw( $fields['canonical'] );
            if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
                return new WP_Error( 'bad_canonical', 'canonical must be an absolute http(s) URL.' );
            }
            $fields['canonical'] = $url;
        }
        foreach ( [ 'ogImageId', 'twitterImageId' ] as $img ) {
            if ( ! empty( $fields[ $img ] ) && ! wp_attachment_is_image( (int) $fields[ $img ] ) ) {
                return new WP_Error( 'not_image', sprintf( '%s %d is not an image attachment — upload-media first and pass the id it returns.', $img, (int) $fields[ $img ] ) );
            }
        }
        return $fields;
    }

    private static function without( array $arr, array $keys ): array {
        foreach ( $keys as $k ) {
            unset( $arr[ $k ] );
        }
        return $arr;
    }
}
