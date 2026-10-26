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
        return 'Post types on the site: `available` (every public type) and `defaults` (post type -> the template its records wear automatically, with its title '
            . 'and whether it is ready). Any public type can be given one with set-default-template; a record '
            . 'of any type can also pick a template for itself, which outranks the default.';
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
                    'description' => 'Post type -> { templateId, title }: the template records of that type wear unless they picked one themselves. Empty when no type has a default.',
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

        // Which types take a template BY DEFAULT is simply which types HAVE one — the map is the
        // list. There used to be a second list beside it ("covered types"), and the two could
        // disagree: a default stored for a type missing from that list was invisible here, could
        // not be removed, and still blocked its template's deletion.
        $defaults = [];
        foreach ( SiteTemplatePostType::type_defaults() as $type => $template_id ) {
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
