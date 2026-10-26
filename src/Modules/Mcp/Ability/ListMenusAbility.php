<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Menu\MenuWriter;

/** Read-only: the site's WordPress nav menus with their (nested) items. */
final class ListMenusAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/list-menus';
    }

    public function label(): string {
        return 'List menus';
    }

    public function description(): string {
        return 'The site\'s navigation menus: id, name, items (nested). The theme and its navigation '
            . 'blocks show them.';
    }

    public function contract(): ?string {
        return 'site-assets-contract';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'menus' ],
            'properties' => [
                'menus' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => [ 'id', 'name', 'items' ],
                        'properties' => [
                            'id'    => [ 'type' => 'integer', 'description' => 'What update-menu / delete-menu take.' ],
                            'name'  => [ 'type' => 'string' ],
                            // The SAME definition create-menu and update-menu accept, so a caller
                            // can read the tree, edit it, and send it straight back.
                            'items' => array_merge(
                                CreateMenuAbility::items_schema(),
                                [ 'description' => 'Nested items. url is every item\'s address; postId / termId / archive say what it is attached to (absent on a custom link). `newTab` and `children` are OMITTED when they do not apply — test for the key, not for false.' ]
                            ),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $menus = [];
        foreach ( wp_get_nav_menus() as $menu ) {
            $menus[] = MenuWriter::summary( (int) $menu->term_id );
        }
        return [ 'menus' => $menus ];
    }
}
