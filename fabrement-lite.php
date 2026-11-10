<?php
/**
 * Plugin Name:       Fabrement Lite
 * Plugin URI:        https://fabrement.com/
 * Description:       Turn WordPress into an MCP server. Manage content, installed Gutenberg and ACF blocks, templates and SEO with an external AI client.
 * Version:           1.5.0
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            7Fridays INC.
 * Author URI:        https://www.7fridays.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fabrement-lite
 *
 * @package Fabrement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( \Fabrement\Modules\Lifecycle\LiteEdition::class, false ) ) {
    require_once __DIR__ . '/src/Modules/Lifecycle/LiteEdition.php';
}
if ( ! class_exists( \Fabrement\Modules\Lifecycle\NoticeScreen::class, false ) ) {
    require_once __DIR__ . '/src/Modules/Lifecycle/NoticeScreen.php';
}
if ( ! \Fabrement\Modules\Lifecycle\LiteEdition::boot( __FILE__ ) ) {
    return;
}

define( 'FABREMENT_BLOCKS_VERSION', '1.5.0' );
define( 'FABREMENT_BLOCKS_FILE', __FILE__ );
define( 'FABREMENT_BLOCKS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FABREMENT_BLOCKS_URL', plugin_dir_url( __FILE__ ) );

// Composer's autoloader. The bundled MCP adapter is not in it: it loads only when no other copy
// is there ({@see \Fabrement\Modules\Mcp\BundledAdapter}).
$fabrement_autoload = FABREMENT_BLOCKS_PATH . 'vendor/autoload.php';
if ( is_readable( $fabrement_autoload ) ) {
    require_once $fabrement_autoload;
}
unset( $fabrement_autoload );

if ( ! class_exists( \Fabrement\Framework\App::class ) ) {
    return;
}

// Multisite is not supported. The plugin activates there without errors, but nothing boots — no
// post types, no MCP routes, no activation hook and no table. Only a notice explains why.
if ( ! \Fabrement\Modules\Lifecycle\SingleSiteOnly::supported() ) {
    add_action( 'admin_notices', [ \Fabrement\Modules\Lifecycle\SingleSiteOnly::class, 'notice' ] );
    add_action( 'network_admin_notices', [ \Fabrement\Modules\Lifecycle\SingleSiteOnly::class, 'notice' ] );
    return;
}

require_once __DIR__ . '/src/Api/helpers.php';

\Fabrement\Framework\App::boot( __FILE__ );

// Plugins-screen action link — an obvious way to open the connection screen right after
// install (the admin menu entry is easy to miss).
add_filter(
    'plugin_action_links_' . plugin_basename( __FILE__ ),
    static function ( array $links ): array {
        $connection_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=fabrement-credentials' ) ),
            esc_html__( 'MCP Connection', 'fabrement-lite' )
        );

        // Prepend ours so it appears first (before Deactivate).
        return array_merge( [ 'connection' => $connection_link ], $links );
    }
);

// Schema checks also cover updates, which do not run the activation hook.
add_action( 'plugins_loaded', [ \Fabrement\Modules\Updates\DatabaseUpdater::class, 'maybe_upgrade' ], 2, 0 );

add_action( 'admin_notices', [ \Fabrement\Modules\Updates\DatabaseUpdater::class, 'notice' ] );

// Activation: set up the database.
register_activation_hook(
    __FILE__,
    static function (): void {
        // Registered after the multisite guard, so it only runs on a single site.
        \Fabrement\Modules\Updates\DatabaseUpdater::activate();
    }
);

// Deactivation: stop the OAuth cleanup event (activation schedules it again) and end every OAuth
// connection, so a client approved here needs a new consent after any later activation.
register_deactivation_hook(
    __FILE__,
    static function (): void {
        wp_clear_scheduled_hook( 'fabrement_mcp_oauth_cleanup' );
        if ( class_exists( \Fabrement\Modules\Mcp\OAuth\Bootstrap::class ) ) {
            \Fabrement\Modules\Mcp\OAuth\Bootstrap::end_all_connections();
        }
        // The OAuth well-known rewrite rules are flushed again on the next activation.
        delete_option( \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_REWRITE );
        // This request already added them: WordPress rebuilds the rules on the next one, without them.
        delete_option( 'rewrite_rules' );
    }
);

/**
 * All plugin initialisation happens inside plugins_loaded so that every
 * other plugin is already loaded before we run.
 *
 * Translations need no call here: WordPress loads the "fabrement-lite" text domain (Text Domain header)
 * just in time, from the language packs translate.wordpress.org publishes.
 */
add_action(
    'plugins_loaded',
    static function (): void {

        // ── Bootstrap ─────────────────────────────────────────────────────────

        if ( class_exists( \Fabrement\Container\PluginContainer::class ) ) {
            \Fabrement\Framework\App::set_container( \Fabrement\Container\PluginContainer::create() );
        }

        $plugin_admin_pages = FABREMENT_BLOCKS_PATH . 'src/Page/index.php';
        if ( is_readable( $plugin_admin_pages ) ) {
            require_once $plugin_admin_pages;
        }

        $plugin_rest = FABREMENT_BLOCKS_PATH . 'src/Route/index.php';
        if ( is_readable( $plugin_rest ) ) {
            require_once $plugin_rest;
        }

        // The page chosen as "not found": served for every missing address with a 404 status.
        \Fabrement\Modules\NotFound\NotFoundPage::init();

        // A draft on its real address, for a request carrying a preview token (the preview tool).
        \Fabrement\Modules\ContentPreview\ContentPreview::init();

        // The SEO plugin's own output (Yoast today) told about what Fabrement builds: images in
        // blocks.
        \Fabrement\Modules\Seo\SeoIntegrations::init();

        // MCP server for external agents. Inert without the Abilities API or a compatible adapter.
        \Fabrement\Modules\Mcp\McpBootstrap::register();
        \Fabrement\Modules\SiteTemplate\TemplateBootstrap::init();

        \Fabrement\Modules\Translation\Version::init();
    },
    1
);
