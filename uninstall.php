<?php
/**
 * Uninstall cleanup — runs ONLY when the plugin is deleted from wp-admin
 * (WordPress defines WP_UNINSTALL_PLUGIN before including this file; it does NOT
 * run on deactivate).
 *
 * Removes Lite's connection state (OAuth storage, the application passwords it issued, schema
 * version) and its own transients.
 * What the site built stays — templates, field definitions and values,
 * SEO schema — so installing the plugin again brings everything back as it was.
 *
 * @package Fabrement
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Both editions currently share OAuth/schema options and tables. Never remove those
// while another installed edition may need them, even when it is temporarily inactive.
if ( ! class_exists( \Fabrement\Modules\Lifecycle\LiteEdition::class, false ) ) {
    require_once __DIR__ . '/src/Modules/Lifecycle/LiteEdition.php';
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
foreach ( array_keys( get_plugins() ) as $fabrement_candidate ) {
    if ( \Fabrement\Modules\Lifecycle\LiteEdition::other( $fabrement_candidate, __DIR__ . '/fabrement-lite.php' ) ) {
        return;
    }
}

// Bring in the plugin's classes so we reuse the shared cleanup (and its key
// constants) rather than duplicating option names. A PSR-4 autoloader has no
// side effects on require, so this is safe in the uninstall context.
$fabrement_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $fabrement_autoload ) ) {
    require_once $fabrement_autoload;
}

/** Remove the connection state and transients; the site's own data stays. */
$fabrement_cleanup = static function (): void {
    if ( ! class_exists( \Fabrement\Framework\Keys::class ) ) {
        return; // No autoloader: the plugin's files are incomplete, and so are its names.
    }
    $keys = \Fabrement\Framework\Keys::class;

    \Fabrement\Modules\Updates\DatabaseUpdater::uninstall();
    ( new \Fabrement\Modules\Mcp\OAuth\Store() )->uninstall();

    // The application passwords the MCP Connection screen issued: left behind, a connected client
    // would keep its access to the site's REST API after the plugin is gone.
    if ( class_exists( \WP_Application_Passwords::class ) ) {
        $holders = get_users( array( 'meta_key' => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- once, on uninstall: the users who hold any application password.
        foreach ( $holders as $holder ) {
            foreach ( \WP_Application_Passwords::get_user_application_passwords( (int) $holder ) as $password ) {
                if ( \Fabrement\Modules\Mcp\McpPasswordScope::is_mcp_password( $password ) ) {
                    \WP_Application_Passwords::delete_application_password( (int) $holder, (string) $password['uuid'] );
                }
            }
        }
    }

    // Another edition's data (credentials, generated files, its caches and journal) is not Lite's
    // to remove. Lite's own short-lived transients are named at run time, so they are swept by
    // prefix (value + timeout rows): shared content previews, the once-a-day adapter notice and
    // the connection command shown once to the user who made it.
    global $wpdb;
    foreach ( array( $keys::TRANSIENT_CONTENT_PREVIEW_PREFIX, $keys::TRANSIENT_MCP_ADAPTER_NOTICE_PREFIX, $keys::TRANSIENT_MCP_COMMAND . '_' ) as $prefix ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of transients that cannot be listed by name.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $prefix ) . '%',
                $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
            )
        );
    }
};

$fabrement_cleanup();
