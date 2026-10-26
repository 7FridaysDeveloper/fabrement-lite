<?php

namespace Fabrement\Modules\Mcp\Ability;

/**
 * Every public taxonomy the site has: what terms can be added to and assigned with.
 */
final class ListTaxonomiesAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/list-taxonomies';
    }

    public function label(): string {
        return 'List taxonomies';
    }

    public function description(): string {
        return 'The taxonomies on this site: slug, label, hierarchical, public, postTypes, terms count. Call before '
            . 'create-term.';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'taxonomies' ],
            'properties' => [
                'taxonomies' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'slug'         => [ 'type' => 'string' ],
                            'label'        => [ 'type' => 'string' ],
                            'hierarchical' => [ 'type' => 'boolean' ],
                            'public'       => [ 'type' => 'boolean' ],
                            'postTypes'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'terms'        => [ 'type' => 'integer' ],
                        ] + self::multilingual( [
                            'translationMode' => [ 'type' => [ 'string', 'null' ], 'description' => '"translate" | "fallback" | "none" — the translation plugin answer; null when it cannot answer.' ],
                        ] ),
                    ],
                ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        global $wpdb;

        $out = [];

        // Other plugins' private taxonomies stay out: WPML's translation_priority is bookkeeping,
        // not a classification.
        foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
            // No admin screen = plumbing (nav_menu, post_format…), which the term tools refuse.
            if ( ! $tax->show_ui ) {
                continue;
            }
            // COUNTED IN THE DATABASE, for the reason list-terms counts there too: wp_count_terms()
            // is filtered by language, so this listing said 4 for a taxonomy whose list-terms
            // answered with 8 rows — the same taxonomy, read by the same agent, in two numbers.
            // This listing takes no language, so every row is the honest total.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counted across languages: wp_count_terms() is filtered by language (see above).
            $count = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $tax->name )
            );

            $out[] = [
                'slug'         => $tax->name,
                'label'        => (string) ( $tax->labels->name ?? $tax->label ?: $tax->name ),
                'hierarchical' => (bool) $tax->hierarchical,
                'public'       => (bool) $tax->public,
                'postTypes'    => array_values( (array) $tax->object_type ),
                'terms'        => is_numeric( $count ) ? (int) $count : 0,
            ] + self::multilingual( [
                // Multilingual sites only. On a site with one language this came back null for
                // every row - a field answering "unknown" to a question nobody can ask here.
                'translationMode' => \Fabrement\Modules\Translation\TranslationMode::of( 'tax', $tax->name ),
            ] );
        }

        return [ 'taxonomies' => $out ];
    }
}
