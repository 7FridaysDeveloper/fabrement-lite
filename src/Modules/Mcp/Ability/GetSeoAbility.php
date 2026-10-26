<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\PostTypeArchive;
use Fabrement\Modules\Mcp\Support\ContentGuard;
use Fabrement\Modules\Mcp\Support\LanguageVersion;
use Fabrement\Modules\Mcp\Support\TermLanguages;
use Fabrement\Modules\Seo\Schema\SchemaOutput;
use Fabrement\Modules\Seo\Schema\SchemaStore;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Seo\SeoTranslations;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * The SEO state of the site, a post type, or one record — read before any set-seo / set-schema.
 *
 * One read tool for the whole area, because the questions come together: "is Yoast even here",
 * "what does this page currently emit", "what template does this type use", "what schema is on
 * it". Three answers at once cost less than three round trips.
 */
final class GetSeoAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/get-seo';
    }

    public function label(): string {
        return 'Get SEO state';
    }

    public function description(): string {
        return 'Read the SEO state: always yoast {active, version}; with contentId — the record\'s stored Yoast '
            . 'fields, `effective` output (title, description, canonical, robots) and the FULL schema graph it '
            . 'emits (and primaryTerms: the main term per taxonomy, for breadcrumbs); with termId — a term '
            . 'archive\'s stored and effective SEO; with archive (a post type) — its archive\'s stored SEO'
            . '; with postType — its defaults and schema template; with neither — the site settings and '
            . 'knowledge graph. Call it FIRST (is Yoast active?) and AFTER a write to verify.'
            . self::multilingual_text( ' Every level says what other languages hold and which still lack a version: '
            . 'translations / missingLanguages; a record and a term also translationsSeo {code: {id, noindex, title, metaDesc: '
            . '"translated"|"sameAsOriginal"|"missing"|"template"}} — sameAsOriginal and missing are still to translate '
            . '(set-seo on that version). list-seo finds them across the site.' );
    }

    public function contract(): ?string {
        return 'seo-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'contentId' => [ 'type' => 'integer', 'minimum' => 1 ],
                'termId'    => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'A term: the stored and effective SEO of its archive page.' ],
                'archive'   => [ 'type' => 'string', 'description' => 'A post type: the stored SEO of its archive.' ],
                'postType'  => [ 'type' => 'string' ],
            ] + self::multilingual( [
                'language'  => [ 'type' => 'string', 'description' => 'With the contentId or termId of an ORIGINAL: its version in this language (the answer gives that id, and the original in from). Codes: list-languages.' ],
            ] ),
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'yoast' ],
            'properties' => [
                'yoast'    => [
                    'type'       => 'object',
                    'properties' => [
                        'active'    => [ 'type' => 'boolean' ],
                        'installed' => [ 'type' => 'boolean', 'description' => 'Present on disk (maybe inactive).' ],
                        'version'   => [ 'type' => 'string' ],
                    ],
                ],
                'content'  => [ 'type' => 'object' ],
                'term'     => [ 'type' => 'object' ],
                'archive'  => [ 'type' => 'object' ],
                'postType' => [ 'type' => 'object' ],
                'site'     => [ 'type' => 'object' ],
                'typesWithSchema' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'note'     => [ 'type' => 'string' ],
                'from'     => [ 'type' => 'integer', 'description' => 'The original whose `language` version was read.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $seo = Seo::bridge();
        $out = [
            'yoast' => [
                'active'    => $seo->is_active(),
                'installed' => Seo::installed(),
                'version'   => $seo->version(),
            ],
        ];

        $input = LanguageVersion::target( $input );
        if ( is_wp_error( $input ) ) {
            return $input;
        }
        if ( ! empty( $input['from'] ) ) {
            $out['from'] = (int) $input['from'];
        }

        if ( ! empty( $input['contentId'] ) ) {
            $id   = (int) $input['contentId'];
            $post = get_post( $id );
            if ( ! $post instanceof \WP_Post || $post->post_type === 'revision' ) {
                return ContentGuard::not_found( $id );
            }
            if ( ! current_user_can( 'edit_post', $id ) ) {
                return new WP_Error( 'forbidden', sprintf( 'You may not read content %d.', $id ) );
            }
            $language  = Translation::live_language_of( $id, (string) $post->post_type );
            $effective = $seo->effective_post( $id );
            $out['content'] = [
                'id'        => $id,
                'title'     => (string) $post->post_title,
                'url'       => Translation::bridge()->permalink( $id, $language ),
                'status'    => (string) $post->post_status,
                'postType'  => (string) $post->post_type,
                'stored'    => (object) $seo->read_post( $id ),
                'effective' => (object) self::without( $effective, [ 'schema' ] ),
                'schema'    => SchemaOutput::preview( $id, $effective ),
            ] + self::content_languages( $id, (string) $post->post_type );
            if ( $seo->is_active() ) {
                // Without Yoast a main term drives nothing, so none is reported.
                $out['content']['primaryTerms'] = (object) self::primary_terms( $id, (string) $post->post_type );
            }
            if ( $post->post_status !== 'publish' ) {
                $out['note'] = 'This record is not published: crawlers cannot see any of this until it is.';
            }
        }

        if ( ! empty( $input['termId'] ) ) {
            // The term asked for, not get_term()'s version of it: a translation plugin adjusts the id
            // to the request's language, and the SEO of another language's term would be read or written.
            $term = \WP_Term::get_instance( (int) $input['termId'] );
            if ( ! $term instanceof \WP_Term ) {
                return new WP_Error( 'not_found', sprintf( 'No term %d — see list-terms.', (int) $input['termId'] ) );
            }
            $language = Translation::bridge()->term_language( (int) $term->term_id, (string) $term->taxonomy );
            $out['term'] = [
                'id'        => (int) $term->term_id,
                'name'      => (string) $term->name,
                'taxonomy'  => (string) $term->taxonomy,
                'url'       => Translation::term_link( $term, $language ),
                'stored'    => (object) $seo->read_term( $term ),
                'effective' => (object) $seo->effective_term( (int) $term->term_id ),
            ] + self::term_languages( $term );
        }

        if ( ! empty( $input['archive'] ) ) {
            $archive = self::archive( (string) $input['archive'] );
            if ( $archive instanceof WP_Error ) {
                return $archive;
            }
            $out['archive'] = $archive;
        }

        if ( ! empty( $input['postType'] ) ) {
            $slug   = (string) $input['postType'];
            $object = get_post_type_object( $slug );
            if ( ! $object ) {
                return new WP_Error( 'unknown_post_type', sprintf( 'Post type "%s" is not registered — see list-post-types.', $slug ) );
            }
            $counts = (array) wp_count_posts( $slug );
            $out['postType'] = [
                'slug'           => $slug,
                'label'          => (string) $object->label,
                'public'         => (bool) $object->public,
                'hasArchive'     => (bool) $object->has_archive,
                'defaults'       => (object) $seo->read_defaults( 'postType', $slug ),
                'schemaTemplate' => SchemaStore::read_type( $slug ),
                'records'        => (int) ( $counts['publish'] ?? 0 ),
            ] + self::defaults_languages( 'postType', $slug );
        }

        if ( empty( $input['contentId'] ) && empty( $input['termId'] ) && empty( $input['archive'] ) && empty( $input['postType'] ) ) {
            $out['site']            = (object) ( $seo->read_defaults( 'site' ) + self::defaults_languages( 'site', '' ) + self::front_page_languages() );
            $out['typesWithSchema'] = SchemaStore::types_with_schema();
            if ( ! $seo->is_active() ) {
                $out['note'] = $out['yoast']['installed']
                    ? 'Yoast SEO is installed but NOT active — ask the site admin to activate it.'
                    : 'Yoast SEO is not installed — ask the site admin to install and activate it. Until then no SEO tool writes (set-schema included).';
            }
        }

        return $out;
    }

    /**
     * A post-type archive's SEO: the site-wide values the SEO plugin keeps for it.
     *
     * @return array<string,mixed>|WP_Error
     */
    private static function archive( string $type ) {
        $has = PostTypeArchive::check( $type );
        if ( $has instanceof WP_Error ) {
            return $has;
        }
        return [
            'postType' => $type,
            'url'      => (string) ( get_post_type_archive_link( $type ) ?: '' ),
            'stored'   => (object) Seo::bridge()->read_archive_defaults( $type ),
            'note'     => 'An archive\'s SEO is one site-wide value' . ( Translation::is_multilingual() ? ', the same in every language.' : '.' ),
        ];
    }

    /**
     * The record's language group, as list-content reports it, plus whether each version's title
     * and description are translated ({@see SeoTranslations}) — so "the English title is set" does
     * not pass for "done" while the Polish one is empty or still English. Nothing on a
     * single-language site.
     *
     * @return array<string,mixed>
     */
    private static function content_languages( int $id, string $post_type ): array {
        if ( ! Translation::is_multilingual() ) {
            return [];
        }
        $facts = Translation::bridge()->version_facts( [ $id ], $post_type )[ $id ] ?? null;
        if ( $facts === null ) {
            return [];
        }
        // The group's original even when it is in the trash — as list-seo reports it.
        $original = $facts['isOriginal'] ? $id : Translation::original_of( $id, $post_type );
        $seo    = [];
        $source = Seo::bridge()->read_post( $original );
        foreach ( $facts['translations'] as $code => $other ) {
            if ( (int) $other === $id ) {
                continue;
            }
            $stored       = Seo::bridge()->read_post( (int) $other );
            $seo[ $code ] = [ 'id' => (int) $other ]
                + ( (int) $other === $original ? [ 'original' => true ] : SeoTranslations::status( $source, $stored ) )
                + [ 'noindex' => ! empty( $stored['noindex'] ) ];
        }
        return [
            'language'         => (string) $facts['language'],
            'originalId'       => $original,
            'translations'     => (object) $facts['translations'],
            'missingLanguages' => Translation::missing_languages( $facts['translations'] ),
            'translationsSeo'  => (object) $seo,
        ];
    }

    /**
     * A term's language group and the state of each version's texts, as for a record.
     *
     * @return array<string,mixed>
     */
    private static function term_languages( \WP_Term $term ): array {
        $facts = TermLanguages::of( $term );
        if ( $facts === null ) {
            return [];
        }
        $original = \WP_Term::get_instance( $facts['originalId'] );
        $source   = $original instanceof \WP_Term ? Seo::bridge()->read_term( $original ) : [];
        $seo      = [];
        foreach ( $facts['translations'] as $code => $other ) {
            $version = \WP_Term::get_instance( (int) $other );
            if ( (int) $other === (int) $term->term_id || ! $version instanceof \WP_Term ) {
                continue;
            }
            $stored       = Seo::bridge()->read_term( $version );
            $seo[ $code ] = [ 'id' => (int) $other ]
                + ( (int) $other === $facts['originalId'] ? [ 'original' => true ] : SeoTranslations::status( $source, $stored ) )
                + [ 'noindex' => ! empty( $stored['noindex'] ) ];
        }
        return [
            'language'         => $facts['language'],
            'originalId'       => $facts['originalId'],
            'translations'     => (object) $facts['translations'],
            'missingLanguages' => Translation::missing_languages( $facts['translations'] ),
            'translationsSeo'  => (object) $seo,
        ];
    }

    /**
     * Which languages hold their own version of the text defaults, and which read the original.
     *
     * @return array<string,mixed>
     */
    private static function defaults_languages( string $scope, string $slug ): array {
        if ( ! Translation::is_multilingual() ) {
            return [];
        }
        $translations = Seo::bridge()->read_defaults_translations( $scope, $slug );
        $default      = Translation::bridge()->default_language();
        $missing      = [];
        foreach ( Translation::bridge()->languages() as $language ) {
            $code = (string) ( $language['code'] ?? '' );
            if ( $code !== '' && $code !== $default && ! isset( $translations[ $code ] ) ) {
                $missing[] = $code;
            }
        }
        return [
            'language'         => $default,
            'translations'     => (object) $translations,
            'missingLanguages' => $missing,
        ];
    }

    /**
     * A language home page exists only when the front page has a version in that language —
     * otherwise /<lang>/ redirects to the original, and "check the Polish home" checks the English one.
     *
     * @return array<string,mixed>
     */
    private static function front_page_languages(): array {
        if ( ! Translation::is_multilingual() || get_option( 'show_on_front' ) !== 'page' ) {
            return [];
        }
        $front = (int) get_option( 'page_on_front' );
        if ( $front <= 0 ) {
            return [];
        }
        $translations = Translation::bridge()->visible_versions( $front, 'page' );
        return [ 'frontPageMissingLanguages' => Translation::missing_languages( $translations ) ];
    }

    /**
     * The main term per hierarchical taxonomy, as Yoast uses it for breadcrumbs — 0 where none is
     * chosen (Yoast then takes the deepest one). set-post-terms {primary} chooses it.
     *
     * @return array<string,int>
     */
    private static function primary_terms( int $id, string $post_type ): array {
        $out = [];
        foreach ( \Fabrement\Modules\Seo\PrimaryTerm::taxonomies( $post_type ) as $taxonomy ) {
            $out[ $taxonomy ] = \Fabrement\Modules\Seo\PrimaryTerm::get( $id, $taxonomy );
        }
        return $out;
    }

    private static function without( array $arr, array $keys ): array {
        foreach ( $keys as $k ) {
            unset( $arr[ $k ] );
        }
        return $arr;
    }
}
