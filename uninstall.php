<?php
/**
 * Uninstall cleanup — runs ONLY when the plugin is deleted from wp-admin
 * (WordPress defines WP_UNINSTALL_PLUGIN before including this file; it does NOT
 * run on deactivate).
 *
 * Removes Lite's connection state (OAuth storage, schema version) and its own transients.
 * What the site built stays — templates and SEO schema — so installing the plugin again brings
 * everything back as it was.
 *
 * @package Fabrement
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Both editions currently share OAuth/schema options and tables. Never remove those
// while another installed edition may need them, even when it is temporarily inactive.
if ( ! class_exists( \Fabrement\Modules\Lifecycle\LiteEdition::class, false ) ) {
    require_once __DIR__ . '/src/Modules/Lifecycle/LiteEdition.php';
}
if ( ! function_exists( 'get_plugins' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
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

    // Another edition's data (credentials, generated files, its caches and journal) is not Lite's
    // to remove. Lite's own short-lived transients are named at run time, so they are swept by
    // prefix (value + timeout rows): shared content previews and the once-a-day adapter notice.
    global $wpdb;
    foreach ( array( $keys::TRANSIENT_CONTENT_PREVIEW_PREFIX, $keys::TRANSIENT_MCP_ADAPTER_NOTICE_PREFIX ) as $prefix ) {
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
