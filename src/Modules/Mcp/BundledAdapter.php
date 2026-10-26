<?php

namespace Fabrement\Modules\Mcp;

/**
 * Lite's own copy of the MCP adapter (vendor/), loaded only when no other copy is there: the MCP
 * Adapter plugin's, or one another plugin bundles. PHP keeps one copy of a class, so the first one
 * loaded is the one every plugin uses; Lite takes that one, as WooCommerce and Elementor do.
 */
final class BundledAdapter {

    private const PACKAGES = [
        'WP\\MCP\\'       => 'vendor/wordpress/mcp-adapter/includes/',
        'WP\\McpSchema\\' => 'vendor/wordpress/php-mcp-schema/src/',
    ];

    private static bool $registered = false;

    public static function ensure(): void {
        if ( self::$registered || class_exists( \WP\MCP\Core\McpAdapter::class ) ) {
            return;
        }
        self::$registered = true;
        spl_autoload_register( [ self::class, 'load' ] );
        // Lite's copy serves only Lite's server: the adapter's default one (its own abilities and
        // route) would be built on every MCP request for nothing.
        add_filter( 'mcp_adapter_create_default_server', '__return_false' );
    }

    public static function load( string $class ): void {
        foreach ( self::PACKAGES as $prefix => $dir ) {
            if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
                continue;
            }
            $file = FABREMENT_BLOCKS_PATH . $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
            if ( is_readable( $file ) ) {
                require $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- a class file under the plugin's own vendor/ folder.
            }
            return;
        }
    }
}
