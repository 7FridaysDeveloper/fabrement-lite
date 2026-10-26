<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Taxonomy\TermWriter;

/** Read the terms in a taxonomy — what a classification actually holds. */
final class ListTermsAbility extends AbstractAbility {

    /** Enough for a picker; a taxonomy with more than this is browsed with `search`. */
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT     = 500;

    public function name(): string {
        return 'fabrement/list-terms';
    }

    public function label(): string {
        return 'List terms';
    }

    public function description(): string {
        return 'The terms of a taxonomy: termId, name, slug, parent, count, url. Call before create-term (extend'
            . ' the vocabulary, don\'t add "Sci-Fi" next to "Science Fiction").';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'taxonomy' ],
            'properties'           => [
                'taxonomy' => [ 'type' => 'string', 'description' => 'Taxonomy slug (see list-taxonomies).' ],
                'search'   => [ 'type' => 'string', 'description' => 'Filter by a substring of the term name.' ],
                'limit'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT ],
            ] + self::multilingual( [
                'language' => [
                    'type'        => 'string',
                    'description' => 'Language code (list-languages) to list the terms of ONE language. Omit for every'
                        . ' once — which is the default, because a term and its versions are different terms with'
                        . ' different ids, and listing only one language silently hides the rest.',
                ],
            ] ),
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'terms', 'total' ],
            'properties' => [
                'terms' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'termId' => [ 'type' => 'integer' ],
                            'name'   => [ 'type' => 'string' ],
                            'slug'   => [ 'type' => 'string' ],
                            'parent' => [ 'type' => 'integer' ],
                            'count'  => [ 'type' => 'integer' ],
                            'url'    => [ 'type' => 'string' ],
                        ] + self::multilingual( [
                            'language'     => [ 'type' => 'string' ],
                            'originalId'   => [ 'type' => 'integer', 'description' => 'The ORIGINAL of its language group (equals termId for the original): the id translate-term and set-seo {termId, language} take.' ],
                            'translations' => [ 'type' => 'object', 'description' => 'Language code → term id of every version.' ],
                        ] ),
                    ],
                ],
                'total' => [ 'type' => 'integer', 'description' => 'Terms in the taxonomy, ignoring search/limit.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
        $refused = TermWriter::refuse_term_taxonomy( $taxonomy );
        if ( $refused !== null ) {
            return $refused;
        }

        $limit = isset( $input['limit'] ) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
        $limit = max( 1, min( self::MAX_LIMIT, $limit ) );

        $args = [
            'taxonomy'   => $taxonomy,
            // Empty terms are exactly what an agent needs to see: a vocabulary it just
            // created has no posts yet, and hiding it would read as "nothing is there".
            'hide_empty' => false,
            'search'     => (string) ( $input['search'] ?? '' ),
            'number'     => $limit,
            'orderby'    => 'name',
        ];

        // WHICH LANGUAGE'S TERMS. The translation plugin filters get_terms() by the language the
        // request is running as, and a REST request runs as the site's default — so this listed one
        // language and reported its count as the total, with the other languages' terms simply
        // absent and nothing saying so. Measured on a taxonomy holding eleven terms: six came back.
        //
        // ONCE PER LANGUAGE, not once with the filter lifted. WPML lifts it for the pseudo-language
        // 'all', but refuses to switch to it — measured: switch_lang('all') leaves the current
        // language exactly where it was, and the filter with it. Asking each real language in turn
        // is the supported route, and it is what gives every row its own language below.
        $language = isset( $input['language'] ) ? trim( (string) $input['language'] ) : '';
        $bridge   = \Fabrement\Modules\Translation\Translation::bridge();
        $multi    = \Fabrement\Modules\Translation\Translation::is_multilingual();
        $codes    = $multi ? array_column( $bridge->languages(), 'code' ) : [];

        if ( $multi && $language !== '' && ! in_array( $language, $codes, true ) ) {
            return new \WP_Error(
                'unknown_language',
                sprintf( 'The site has no "%s" language. Active: %s (list-languages).', $language, implode( ', ', $codes ) )
            );
        }

        $wanted = ! $multi ? [ '' ] : ( $language !== '' ? [ $language ] : $codes );

        $terms = [];
        $total = 0;
        foreach ( $wanted as $code ) {
            $found = $multi
                ? $bridge->in_language( (string) $code, static fn() => get_terms( $args ) )
                : get_terms( $args );

            if ( is_wp_error( $found ) ) {
                return $found;
            }

            foreach ( (array) $found as $term ) {
                $terms[ (int) $term->term_id ] = $term;
            }

        }

        // Counted ONCE, in the database. Summing wp_count_terms() per language double-counts every
        // term that has no version in one of them: display-as-translated shows the original there
        // too, so it is counted in both. Measured: ten terms reported as twelve.
        if ( $multi && $language === '' ) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counted once across languages: wp_count_terms() is filtered by language (see above).
            $total = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy )
            );
        } else {
            $count = $multi
                ? $bridge->in_language( (string) $wanted[0], static fn() => wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] ) )
                : wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
            $total = is_numeric( $count ) ? (int) $count : 0;
        }

        // The limit is per REQUEST, not per language — asking each language in turn would otherwise
        // return as many times the limit as the site has languages.
        usort( $terms, static fn( $a, $b ) => strcmp( (string) $a->name, (string) $b->name ) );
        $terms = array_slice( $terms, 0, $limit );

        return [
            'terms' => array_map(
                static function ( $term ) use ( $taxonomy, $multi, $bridge ) {
                    // ONCE per term. term_language() runs a SELECT of its own (termTaxonomyId), and
                    // it used to be asked twice for every row — once for the link, once for the
                    // field — on a listing that already returns up to 100 of them.
                    $language = $multi ? $bridge->term_language( (int) $term->term_id, $taxonomy ) : '';

                    $row = [
                        'termId' => (int) $term->term_id,
                        'name'   => (string) $term->name,
                        'slug'   => (string) $term->slug,
                        'parent' => (int) $term->parent,
                        'count'  => (int) $term->count,
                        // In the term's OWN language: get_term_link() is language-adjusted, and from
                        // the request's language it hands back another version's address.
                        'url'    => \Fabrement\Modules\Translation\Translation::term_link( $term, $multi ? $language : '' ),
                    ];

                    if ( $multi ) {
                        $row['language']     = $language;
                        $row['originalId']   = $bridge->original_term_id( (int) $term->term_id, $taxonomy );
                        $row['translations'] = \Fabrement\Modules\Translation\Translation::term_translations( (int) $term->term_id, $taxonomy );
                    }

                    return $row;
                },
                is_array( $terms ) ? $terms : []
            ),
            'total' => is_numeric( $total ) ? (int) $total : 0,
        ];
    }
}
