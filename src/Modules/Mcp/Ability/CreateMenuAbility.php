<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Menu\MenuWriter;

/**
 * Create a WordPress navigation menu with nested items. Thin adapter over
 * {@see MenuWriter::create()}.
 */
final class CreateMenuAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/create-menu';
    }

    public function label(): string {
        return 'Create menu';
    }

    public function description(): string {
        return 'Create a navigation menu (unique name; items nested at any depth) for the theme and its blocks '
            . 'to show. Each item is a custom link (url) or attached to a page or record (postId), a term '
            . '(termId) or a post type archive (archive) — then its address follows it. list-menus first to reuse one.';
    }

    public function contract(): ?string {
        return 'site-assets-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'name' ],
            'properties'           => [
                'name'  => [ 'type' => 'string', 'description' => 'Menu name (unique on the site).' ],
                'items' => self::items_schema(),
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
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        $items = self::items_or_error( $input );
        if ( $items instanceof \WP_Error ) {
            return $items;
        }

        return self::wrap( MenuWriter::create( (string) ( $input['name'] ?? '' ), $items ?? [] ) );
    }

    /**
     * A LIST of items, or nothing — never a quiet reinterpretation.
     *
     * `is_array( $items ) ? $items : []` read every wrong SHAPE as "no items": create-menu
     * answered success with an empty menu, and update-menu (whose fallback is null, "leave
     * them") answered success with the menu's OLD items laid out in full — a fresh-looking
     * tree that is simply not the one the caller sent. Nobody diffs an answer that shape.
     * `items: "Work, Contact"` is the slip a caller makes when a field looks like it takes
     * one value; it is refused by name instead.
     *
     * @param array<string,mixed> $input
     * @return array<int,mixed>|null|\WP_Error  The items, null when the key is absent.
     */
    public static function items_or_error( array $input ) {
        if ( ! array_key_exists( 'items', $input ) ) {
            return null;
        }
        if ( ! is_array( $input['items'] ) ) {
            return new \WP_Error(
                'fabrement_not_a_list',
                'items must be a list of menu items — [{"label":"Work","url":"/work/"}], not "Work, Contact". Send [] to empty the menu, or leave the field out to change nothing.',
                [ 'status' => 400 ]
            );
        }
        return $input['items'];
    }

    /** The nested menu-items schema shared by create/update. */
    public static function items_schema(): array {
        return [
            'type'        => 'array',
            'description' => 'Nested menu items: each { label, url | postId | termId | archive, newTab?, children?:[items] }. '
                . 'url = a custom link (external, anchor, tel:; none = no address — a dropdown parent). postId / termId / archive '
                . '= attached to that page or record, term or post type archive: its address follows it, and with no '
                . 'label it shows the object\'s title. One of the four per item; a url beside a link must be its address.',
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    'label'    => [ 'type' => 'string' ],
                    'url'      => [ 'type' => 'string' ],
                    'postId'   => [ 'type' => 'integer', 'description' => 'A page or record (list-content).' ],
                    'termId'   => [ 'type' => 'integer', 'description' => 'A term (list-terms).' ],
                    'archive'  => [ 'type' => 'string', 'description' => 'A post type slug: its archive (list-post-types).' ],
                    'newTab'   => [ 'type' => 'boolean' ],
                    'children' => [ 'type' => 'array', 'description' => 'Child items, same shape.' ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed>|\WP_Error $result */
    public static function wrap( $result ) {
        return is_wp_error( $result ) ? $result : [ 'menu' => $result ];
    }
}
