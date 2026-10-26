<?php

namespace Fabrement\Modules\Lifecycle;

/** Loaded explicitly before either edition's shared autoloader and constants. */
final class LiteEdition {
    public static function other( string $plugin, string $self ): bool {
        // Both editions sit in the same plugins folder: the one this file is in.
        $file = dirname( plugin_dir_path( $self ) ) . '/' . $plugin;
        if ( validate_file( $plugin ) !== 0 || ! is_file( $file ) || realpath( $file ) === realpath( $self ) ) {
            return false;
        }
        $header = get_file_data( $file, [ 'name' => 'Plugin Name', 'domain' => 'Text Domain' ] );
        return $header['domain'] === 'fabrement' && str_starts_with( $header['name'], 'Fabrement' );
    }

    public static function active_other( string $self ): bool {
        $active = array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        foreach ( $active as $plugin ) {
            if ( self::other( $plugin, $self ) ) { return true; }
        }
        return false;
    }

    public static function boot( string $self ): bool {
        register_activation_hook( $self, static function () use ( $self ): void {
            if ( self::active_other( $self ) ) {
                wp_die( esc_html__( 'Another Fabrement edition is active. Deactivate it before activating Fabrement Lite. Your content and settings are preserved.', 'fabrement-lite' ) );
            }
        } );

        if ( self::active_other( $self ) || defined( 'FABREMENT_BLOCKS_FILE' ) ) {
            // Covers an imported active_plugins list, regardless of the plugin load order.
            // Do not load Lite's shared classes/functions into the other edition's process.
            add_action( 'admin_notices', static function (): void {
                if ( ! current_user_can( 'activate_plugins' ) || ! NoticeScreen::here() ) {
                    return;
                }
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'Fabrement Lite is paused because another Fabrement edition is active. Deactivate Lite on the Plugins screen.', 'fabrement-lite' ) . '</p></div>';
            } );
            return false;
        }

        // Core validates requirements BEFORE sandbox-including a newly activated plugin.
        // activate_plugin/activated_plugin would be too late to prevent duplicate PHP symbols.
        add_filter( 'validate_plugin_requirements', static function ( $result, string $plugin ) use ( $self ) {
            if ( self::other( $plugin, $self ) ) {
                return new \WP_Error( 'fabrement_edition_conflict', __( 'Deactivate Fabrement Lite first, then activate the other Fabrement edition. Content and settings are preserved.', 'fabrement-lite' ) );
            }
            return $result;
        }, 10, 2 );
        return true;
    }
}
