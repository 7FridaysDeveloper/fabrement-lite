<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding;

/**
 * Read-only: every ARCHIVE view a Fabrement template can dress — the post-type
 * archives (has_archive) and the blog home — with their current binding.
 */
final class ListArchivesAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/list-archives';
    }

    public function label(): string {
        return 'List archives';
    }

    public function description(): string {
        return 'The archive views a template can be bound to: context ("archive:<postType>" | "blog-home" | '
            . '"tax" | "tax:<taxonomy>" | "tax:<taxonomy>:<termId>"), label, url, templateId bound at that level '
            . '(term views resolve most-specific-first). Pick a context here, compose installed blocks with save-template, '
            . 'then assign-template-archive. Optional PHP sources must already exist in list-theme-templates.';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'archives' ],
            'properties' => [
                'archives' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'context'    => [ 'type' => 'string' ],
                            'label'      => [ 'type' => 'string' ],
                            'url'        => [ 'type' => 'string' ],
                            'templateId' => [ 'type' => 'integer' ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function contract(): ?string {
        return 'template-contract';
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $bindings = ArchiveTemplateBinding::all();
        $out      = [];

        // The posts index first — it exists on every site.
        $out[] = [
            'context'    => 'blog-home',
            'label'      => __( 'Blog home (posts index)', 'fabrement-lite' ),
            'url'        => (string) ( get_post_type_archive_link( 'post' ) ?: home_url( '/' ) ),
            'templateId' => $bindings['blog-home'] ?? 0,
        ];

        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
            if ( empty( $type->has_archive ) ) {
                continue;
            }
            $context = 'archive:' . $type->name;
            $out[]   = [
                'context'    => $context,
                'label'      => sprintf( '%s archive', $type->labels->singular_name ?? $type->name ),
                'url'        => (string) ( get_post_type_archive_link( $type->name ) ?: '' ),
                'templateId' => $bindings[ $context ] ?? 0,
            ];
        }

        // Term archives: the catch-all level, one row per PUBLIC taxonomy, and the
        // terms that carry their OWN binding. Unbound terms are not enumerated — a
        // taxonomy can hold hundreds and list-terms already names them; a term only
        // becomes a row here by being made special.
        $out[] = [
            'context'    => 'tax',
            'label'      => __( 'Any term archive (all public taxonomies)', 'fabrement-lite' ),
            'url'        => '',
            'templateId' => $bindings['tax'] ?? 0,
        ];
        foreach ( get_taxonomies( [], 'objects' ) as $tax ) {
            if ( $tax->name === 'post_format' || ! is_taxonomy_viewable( $tax ) ) {
                continue; // post_format is a theme feature; non-viewable taxonomies have no term URLs.
            }
            $context = 'tax:' . $tax->name;
            $sample  = get_terms( [ 'taxonomy' => $tax->name, 'number' => 1, 'hide_empty' => false ] );
            $link    = is_array( $sample ) && isset( $sample[0] ) && $sample[0] instanceof \WP_Term ? get_term_link( $sample[0] ) : '';
            $out[]   = [
                'context'    => $context,
                'label'      => sprintf( /* translators: %s: taxonomy name, singular. */ __( '%s term archives', 'fabrement-lite' ), $tax->labels->singular_name ?? $tax->name ),
                'url'        => is_wp_error( $link ) ? '' : (string) $link,
                'templateId' => $bindings[ $context ] ?? 0,
            ];
        }
        foreach ( $bindings as $context => $template_id ) {
            if ( preg_match( '/^tax:([a-z0-9_-]+):([a-z0-9%_-]+)$/D', (string) $context, $m ) !== 1 ) {
                continue;
            }
            // Keyed on the term's id. Anything else is listed as it is stored — that string is what
            // unbinds it.
            $term = ctype_digit( $m[2] ) ? \WP_Term::get_instance( (int) $m[2], $m[1] ) : null;
            $link = $term instanceof \WP_Term ? get_term_link( $term ) : '';
            $out[] = [
                'context'    => (string) $context,
                'label'      => $term instanceof \WP_Term
                    ? sprintf( '%s (%s)', $term->name, $m[1] )
                    : (string) $context,
                'url'        => is_wp_error( $link ) ? '' : (string) $link,
                'templateId' => $template_id,
            ];
        }

        return [ 'archives' => $out ];
    }
}
