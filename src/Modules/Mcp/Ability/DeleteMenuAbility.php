<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Menu\MenuWriter;

/**
 * Delete a WordPress navigation menu (its items go with it). Thin adapter over
 * {@see MenuWriter::delete()}.
 */
final class DeleteMenuAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/delete-menu';
    }

    public function label(): string {
        return 'Delete menu';
    }

    public function description(): string {
        return 'Delete a navigation menu and its items (irreversible). Confirm with the user first.';
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
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'deleted', 'id' ],
            'properties' => [
                'deleted' => [ 'type' => 'boolean' ],
                'id'      => [ 'type' => 'integer' ],
            ],
        ];
    }

    public function execute( array $input ) {
        return MenuWriter::delete( (int) ( $input['menuId'] ?? 0 ) );
    }
}
