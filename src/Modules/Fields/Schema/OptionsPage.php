<?php

namespace Fabrement\Modules\Fields\Schema;

/**
 * How an options page shows in wp-admin: its title, its menu entry and where that entry sits.
 * Kept in the page's field file (`options.<slug>.json`, key `page`) next to its fields and tabs.
 */
final class OptionsPage {

    private const PROPERTIES = [ 'title', 'menuTitle', 'description', 'position', 'autoload' ];

    /**
     * The `page` a save sends, over the stored one: a property left out keeps its stored value.
     *
     * @param mixed      $input
     * @param array|null $current The stored page, or null for a new one.
     * @return array{title: string, menuTitle: string, description: string, position: int, autoload: bool}
     * @throws SchemaError
     */
    public static function normalize( $input, ?array $current ): array {
        if ( ! is_array( $input ) || ( $input && array_values( $input ) === $input ) ) {
            throw new SchemaError( 'page', 'A page is {title, menuTitle?, description?, position?, autoload?}.' );
        }
        foreach ( array_keys( $input ) as $property ) {
            if ( ! in_array( $property, self::PROPERTIES, true ) ) {
                throw new SchemaError( esc_html( 'page.' . $property ), 'Unknown property "' . esc_html( $property ) . '". A page is {title, menuTitle?, description?, position?, autoload?}.' );
            }
        }
        $page = array_merge( $current ?? [], $input );
        $title = $page['title'] ?? null;
        if ( ! is_string( $title ) || trim( $title ) === '' || strlen( $title ) > 100 ) {
            throw new SchemaError( 'page.title', 'Expected the page title, at most 100 characters.' );
        }
        $menu_title = $page['menuTitle'] ?? '';
        if ( ! is_string( $menu_title ) || strlen( $menu_title ) > 60 ) {
            throw new SchemaError( 'page.menuTitle', 'Expected the menu label, at most 60 characters ("" = the title).' );
        }
        $description = $page['description'] ?? '';
        if ( ! is_string( $description ) || strlen( $description ) > 1000 ) {
            throw new SchemaError( 'page.description', 'Expected text shown above the form, at most 1000 characters.' );
        }
        $position = $page['position'] ?? 100;
        if ( ! is_int( $position ) || $position < 0 || $position > 1000 ) {
            throw new SchemaError( 'page.position', 'Expected an order in the menu, 0–1000 (lower first).' );
        }
        $autoload = $page['autoload'] ?? true;
        if ( ! is_bool( $autoload ) ) {
            throw new SchemaError( 'page.autoload', 'Expected true (read on every request: header, footer) or false (a big page read on a few screens).' );
        }
        return [ 'title' => $title, 'menuTitle' => $menu_title, 'description' => $description, 'position' => $position, 'autoload' => $autoload ];
    }

    /**
     * The stored page, read from a file that may have been edited by hand: whatever is wrong falls
     * back, so the page still shows with its fields.
     *
     * @param mixed $raw
     * @return array{title: string, menuTitle: string, description: string, position: int, autoload: bool}
     */
    public static function read( $raw, string $slug ): array {
        try {
            return self::normalize( is_array( $raw ) ? array_intersect_key( $raw, array_flip( self::PROPERTIES ) ) : [], null );
        } catch ( SchemaError $e ) {
            $raw = is_array( $raw ) ? $raw : [];
            return [
                'title'       => is_string( $raw['title'] ?? null ) && trim( $raw['title'] ) !== '' ? substr( $raw['title'], 0, 100 ) : ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) ),
                'menuTitle'   => '',
                'description' => '',
                'position'    => 100,
                'autoload'    => ! is_array( $raw ) || ( $raw['autoload'] ?? true ) !== false,
            ];
        }
    }
}
