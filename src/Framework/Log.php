<?php

namespace Fabrement\Framework;

/**
 * The plugin's one way to write to the PHP error log.
 *
 * Only while WP_DEBUG is on: a production site keeps its log to itself, and a developer who turns
 * debugging on sees why a block, a listing or an MCP tool failed. Callers pass the finished line,
 * their own "[fabrement][area]" prefix included.
 */
final class Log {

    public static function debug( string $message ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the plugin's single log call, gated by WP_DEBUG above.
        error_log( $message );
    }
}
