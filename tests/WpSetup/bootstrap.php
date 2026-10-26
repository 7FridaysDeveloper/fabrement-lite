<?php
/**
 * Bootstrap of the WordPress integration tests (tests/Wp): real WordPress core, a real MySQL
 * (tests/WpSetup/docker-compose.yml), the plugin loaded as a must-use plugin before WordPress boots.
 *
 * tests/Unit stays what it is — no WordPress, no database, milliseconds. This suite is for what
 * only WordPress can answer: queries, slugs, permalinks, capabilities, hooks.
 */
if ( PHP_SAPI !== 'cli' ) {
    exit;
}

$root = dirname( __DIR__, 2 );
require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$tests_dir = $root . '/vendor/wp-phpunit/wp-phpunit';

require_once $tests_dir . '/includes/functions.php';

/*
 * The other plugins Fabrement integrates with, loaded next to it as on a real site. Free ones come
 * from WordPress.org through Composer (require-dev, vendor/wp-plugins/, pinned in composer.lock).
 *
 *   FABREMENT_TEST_PLUGINS=none                    only Fabrement (what a site without them sees)
 *   FABREMENT_TEST_PLUGINS=wordpress-seo           a subset
 *   FABREMENT_TEST_PLUGINS_DIR=C:/path/plugins     one more folder to look in — for plugins that
 *                                                  cannot be committed (WPML: a local-only suite)
 */
// In a function: bare variables here would be globals WordPress itself reads while it boots.
$fabrement_test_plugins = ( static function () use ( $root ): array {
    $wanted = getenv( 'FABREMENT_TEST_PLUGINS' );
    $wanted = $wanted === false || $wanted === '' ? [ 'contact-form-7', 'wordpress-seo' ] : array_filter( array_map( 'trim', explode( ',', $wanted ) ), static fn( $s ) => $s !== '' && $s !== 'none' );
    $dirs   = array_filter( [ $root . '/vendor/wp-plugins', (string) getenv( 'FABREMENT_TEST_PLUGINS_DIR' ) ] );
    $main   = [ 'contact-form-7' => 'wp-contact-form-7.php', 'wordpress-seo' => 'wp-seo.php' ];
    $load   = [];
    foreach ( $wanted as $slug ) {
        $found = '';
        foreach ( $dirs as $dir ) {
            $file = $dir . '/' . $slug . '/' . ( $main[ $slug ] ?? $slug . '.php' );
            if ( is_file( $file ) ) {
                $found = $file;
                break;
            }
        }
        if ( $found === '' ) {
            fwrite( STDERR, "Test plugin '$slug' not found — composer install, or FABREMENT_TEST_PLUGINS_DIR.\n" );
            exit( 1 );
        }
        $load[ $slug ] = $found;
    }
    // WordPress loads plugins in folder-name order; so do the tests.
    $load['fabrement'] = $root . '/fabrement-lite.php';
    ksort( $load );
    return $load;
} )();
define( 'FABREMENT_TEST_LOADED_PLUGINS', array_keys( $fabrement_test_plugins ) );

tests_add_filter(
    'muplugins_loaded',
    static function () use ( $fabrement_test_plugins ): void {
        // The plugin boots its MCP tools only for a request to its MCP route (McpBootstrap),
        // so the suite presents itself as one: the tests call those tools.
        $_SERVER['REQUEST_URI'] = '/wp-json/fabrement/v1/mcp';
        foreach ( $fabrement_test_plugins as $file ) {
            require $file;
        }
        // Activated BEFORE it boots, as on a site: activation is a request of its own that writes
        // Yoast's options and version, and only later requests start Yoast. Booting it first on the
        // fresh test database makes it take the site for one upgraded from nothing: it runs every
        // upgrade routine, and each creates another copy of its option objects (they share one
        // static instance), whose extra validators undo a term's reset to the default.
        if ( defined( 'WPSEO_VERSION' ) && function_exists( 'wpseo_activate' ) ) {
            wpseo_activate( false );
        }
    }
);

require $tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/FakeLanguages.php';
require dirname( __DIR__ ) . '/Support/McpCalls.php';
require dirname( __DIR__ ) . '/Support/PageHead.php';
require dirname( __DIR__ ) . '/Support/CommittedCleanup.php';
require dirname( __DIR__ ) . '/Support/WebRequests.php';
// The suite's web server (docker-compose.yml, service "web"): the same site over real HTTP.
define( 'FABREMENT_TEST_WEB', getenv( 'FABREMENT_TEST_WEB' ) ?: 'http://127.0.0.1:18090' );

// The MCP server starts the way a request to its route starts it: on rest_api_init, before anything
// asked for the abilities. A test that read an ability first would start the abilities registry
// ahead of the MCP adapter, which then misses its own default tools.
rest_get_server();

// A fresh test database: let each plugin create its tables and options as activation would.
// Before any test, outside the per-test transaction (CREATE TABLE would commit it).
if ( defined( 'WPCF7_VERSION' ) && function_exists( 'wpcf7_install' ) ) {
    wpcf7_install();
}
