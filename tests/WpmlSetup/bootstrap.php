<?php
/**
 * Bootstrap of the WPML suite (tests/Wpml): Fabrement on a three-language site with the real WPML,
 * WPML String Translation and Yoast SEO Multilingual beside Yoast and Contact Form 7.
 *
 * The twin of tests/WpSetup, sharing nothing but vendor/: its own database (docker, port 14307),
 * its own plugin folder outside git (composer test:wpml:runtime). Each run starts from an empty
 * database — WordPress installed, the plugins activated, WPML set up (install-wpml.php) — in
 * processes of their own, before this one loads WordPress.
 */
if ( PHP_SAPI !== 'cli' ) {
    exit;
}

$root = dirname( __DIR__, 2 );
require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$config = __DIR__ . '/wp-tests-config.php';
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $config );
$tests_dir = $root . '/vendor/wp-phpunit/wp-phpunit';
require_once $tests_dir . '/includes/functions.php';

( static function () use ( $config, $tests_dir ): void {
    $ini    = php_ini_loaded_file();
    $binary = escapeshellarg( PHP_BINARY ) . ( $ini ? ' -c ' . escapeshellarg( $ini ) : '' );
    $wpml   = $binary . ' ' . escapeshellarg( __DIR__ . '/install-wpml.php' ) . ' ' . escapeshellarg( $config );
    foreach ( [
        $wpml . ' reset',
        $binary . ' ' . escapeshellarg( $tests_dir . '/includes/install.php' ) . ' ' . escapeshellarg( $config ) . ' no_ms_tests no_core_tests',
        $wpml . ' activate',
        $wpml . ' setup',
    ] as $command ) {
        system( $command, $code );
        if ( $code !== 0 ) {
            exit( $code );
        }
    }
} )();
putenv( 'WP_TESTS_SKIP_INSTALL=1' );

tests_add_filter(
    'muplugins_loaded',
    static function (): void {
        // The plugin boots its MCP tools only for a request to its MCP route (McpBootstrap).
        $_SERVER['REQUEST_URI'] = '/wp-json/fabrement/v1/mcp';
    }
);

require $tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/WpmlTestCase.php';
require dirname( __DIR__ ) . '/Support/McpCalls.php';
require dirname( __DIR__ ) . '/Support/PageHead.php';
require dirname( __DIR__ ) . '/Support/CommittedCleanup.php';
// The suite's web server (docker-compose.yml, service "web"): the same site over real HTTP.
define( 'FABREMENT_TEST_WEB', getenv( 'FABREMENT_TEST_WEB' ) ?: 'http://127.0.0.1:18091' );
require dirname( __DIR__ ) . '/Support/WebRequests.php';

// The MCP server starts the way a request to its route starts it: on rest_api_init, before anything
// asked for the abilities. A test that read an ability first would start the abilities registry
// ahead of the MCP adapter, which then misses its own default tools.
rest_get_server();

if ( ! did_action( 'wpml_loaded' ) || count( (array) apply_filters( 'wpml_active_languages', [] ) ) !== 3 ) {
    fwrite( STDERR, "WPML is not running with three languages in the test process.\n" );
    exit( 1 );
}
