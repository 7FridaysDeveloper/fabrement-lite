<?php

namespace Fabrement\Modules\Mcp\Ability;

defined( 'ABSPATH' ) || exit;

use Fabrement\Modules\Mcp\Support\ContentGuard;
use Fabrement\Modules\Mcp\Support\LanguageVersion;
use Fabrement\Modules\Mcp\Support\TermLanguages;
use Fabrement\Modules\Seo\Schema\SchemaOutput;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Seo\SeoTranslations;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * The SEO state of one record or term — read before any set-seo / set-schema. Site-wide Yoast
 * settings are not read: they belong to Yoast's own settings screens.
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
            . 'archive\'s stored and effective SEO. Call it FIRST (is Yoast active?) and AFTER a write to verify.'
            . self::multilingual_text( ' A record and a term say what other languages hold and which still lack a version: '
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
            if ( ! current_user_can( 'edit_term', (int) $term->term_id ) ) {
                return new WP_Error( 'forbidden', sprintf( 'You may not edit term %d (edit_term).', (int) $term->term_id ) );
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

        if ( empty( $input['contentId'] ) && empty( $input['termId'] ) && ! $seo->is_active() ) {
            $out['note'] = $out['yoast']['installed']
                ? 'Yoast SEO is installed but NOT active — ask the site admin to activate it.'
                : 'Yoast SEO is not installed — ask the site admin to install and activate it. Until then no SEO tool writes (set-schema included).';
        }

        return $out;
    }

    /**
     * The record's language group, as list-content reports it, plus whether each version's title
     * and description are translated ({@see SeoTranslations}) — so "the English title is set" does
     * not pass for "done" while the Polish one is empty or still English. Nothing on a
     * single-language site. Only versions the user may edit are listed, as wp-admin shows them.
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
        $seo      = [];
        $visible  = [];
        $source   = Seo::bridge()->read_post( $original );
        foreach ( $facts['translations'] as $code => $other ) {
            if ( (int) $other !== $id && ! current_user_can( 'edit_post', (int) $other ) ) {
                continue;
            }
            $visible[ $code ] = $other;
            if ( (int) $other === $id ) {
                continue;
            }
            $stored       = Seo::bridge()->read_post( (int) $other );
            $seo[ $code ] = [ 'id' => (int) $other ]
                + ( (int) $other === $original ? [ 'original' => true ] : SeoTranslations::status( $source, $stored ) )
                + [ 'noindex' => ! empty( $stored['noindex'] ) ];
        }
        return [ 'language' => (string) $facts['language'] ]
            + ( $original === $id || current_user_can( 'edit_post', $original ) ? [ 'originalId' => $original ] : [] )
            + [
                'translations'     => (object) $visible,
                // From the whole group: a version the user cannot open still exists.
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
