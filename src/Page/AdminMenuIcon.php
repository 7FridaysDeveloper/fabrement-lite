<?php

namespace Fabrement\Page;

use Fabrement\Framework\App;

/**
 * Custom SVG icon for the top-level wp-admin menu.
 *
 * WordPress draws a `data:image/svg+xml` 6th {@see add_menu_page()} argument as the
 * background of `.wp-menu-image.svg` and sizes it like a dashicon, so it needs no CSS.
 */
final class AdminMenuIcon {

    private const SVG_REL = 'assets/images/menu-icon.svg';

    /**
     * SVG as `data:image/svg+xml;base64,…` for {@see add_menu_page()}.
     * Falls back to a dashicon if the asset file is missing.
     */
    public static function data_uri(): string {
        static $cached = null;

        if ( is_string( $cached ) ) {
            return $cached;
        }

        $path = App::path( self::SVG_REL );
        if ( ! is_readable( $path ) ) {
            $cached = 'dashicons-admin-generic';

            return $cached;
        }

        $svg = file_get_contents( $path );
        if ( ! is_string( $svg ) || $svg === '' ) {
            $cached = 'dashicons-admin-generic';

            return $cached;
        }

        $cached = 'data:image/svg+xml;base64,' . base64_encode( $svg );

        return $cached;
    }
}
