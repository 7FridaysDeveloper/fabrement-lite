<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;

/**
 * Read-only: every public post type, and the template each one wears BY DEFAULT.
 *
 * There is no separate pool a type must join first: having a default IS being covered. A record of
 * any type can also pick a template for itself, and that pick outranks the default.
 */
final class ListPostTypesAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/list-post-types';
    }

    public function label(): string {
        return 'List post types';
    }

    public function description(): string {
        return 'Post types on the site: `available` (every public type) and `defaults` (post type -> the template its records '
            . 'wear automatically; read-only — defaults are set in wp-admin). A record can pick its own template '
            . '(assign-template-pages), which outranks the default.';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'available', 'defaults' ],
            'properties' => [
                'available' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'slug'      => [ 'type' => 'string' ],
                            'label'     => [ 'type' => 'string' ],
                            'hierarchical' => [ 'type' => 'boolean', 'description' => 'true = records nest under a parent (save-content `parent`), like pages.' ],
                        ],
                    ],
                ],
                'defaults'  => [
                    'type'        => 'object',
                    'description' => 'Post type -> { templateId, title }: the template records of that type wear unless a record has its own. Empty when no type has a default, or this user may not open templates.',
                ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $available = [];
        foreach ( SiteTemplatePostType::available_post_types() as $slug => $label ) {
            $slug        = (string) $slug;
            $available[] = [
                'slug'      => $slug,
                'label'     => (string) $label,
                'hierarchical' => is_post_type_hierarchical( $slug ),
            ];
        }

        // Which types take a template BY DEFAULT is simply which types HAVE one — the map is the list.
        $defaults = [];
        foreach ( SiteTemplatePostType::type_defaults() as $type => $template_id ) {
            // Only templates this user may read (Capabilities::TEMPLATES).
            if ( ! current_user_can( 'read_post', (int) $template_id ) ) {
                continue;
            }
            $record = SiteTemplateRegistry::find( (int) $template_id );
            $defaults[ (string) $type ] = [
                'templateId' => (int) $template_id,
                'title'      => $record !== null ? $record->title : '',
            ];
        }

        return [
            'available' => $available,
            'defaults'  => (object) $defaults,
        ];
    }
}
