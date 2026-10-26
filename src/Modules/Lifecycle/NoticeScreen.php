<?php

namespace Fabrement\Modules\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Where the plugin's admin notices show: the Plugins screens and Fabrement's own, not every
 * admin page (WordPress.org guideline 11). Loaded before the autoloader, for the paused-edition
 * notice too.
 */
final class NoticeScreen {
    public static function here(): bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) {
            return false;
        }
        $id = (string) $screen->id;
        return in_array( $id, [ 'plugins', 'plugins-network' ], true ) || str_contains( $id, 'fabrement' );
    }
}
