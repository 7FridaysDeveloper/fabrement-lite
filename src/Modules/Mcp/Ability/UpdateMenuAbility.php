<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Menu\MenuWriter;

/**
 * Rename a WordPress navigation menu and/or REPLACE its items. Thin adapter over
 * {@see MenuWriter::update()}.
 */
final class UpdateMenuAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/update-menu';
    }

    public function label(): string {
        return 'Update menu';
    }

    public function description(): string {
        return 'Rename a menu and/or REPLACE its items with the FULL new tree (omit items to keep them). Read '
            . 'the current tree with list-menus, edit, send the whole tree.';
    }

    public function contract(): ?string {
        return 'site-assets-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'menuId' ],
            'properties'           => [
                'menuId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Menu id from list-menus.' ],
                'name'   => [ 'type' => 'string', 'description' => 'New name (omit to keep the current one).' ],
                'items'  => CreateMenuAbility::items_schema() + [ 'description' => 'Full replacement item tree (omit to leave items untouched).' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'menu' ],
            'properties' => [ 'menu' => [ 'type' => 'object' ] ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => true ];
    }

    public function execute( array $input ) {
        // null = the key was absent = leave the items alone. A wrong shape is refused rather
        // than folded into that same null; see CreateMenuAbility::items_or_error().
        $items = CreateMenuAbility::items_or_error( $input );
        if ( $items instanceof \WP_Error ) {
            return $items;
        }

        return CreateMenuAbility::wrap(
            MenuWriter::update(
                (int) ( $input['menuId'] ?? 0 ),
                array_key_exists( 'name', $input ) ? (string) $input['name'] : null,
                $items
            )
        );
    }
}
