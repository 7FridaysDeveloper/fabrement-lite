<?php
/**
 * Configuration of the WordPress test library for the WPML suite (tests/Wpml).
 *
 * Its own database (tests/WpmlSetup/docker-compose.yml, port 14307) and its own plugin folder:
 * `runtime/lite-wp-plugins` beside the repository (composer test:wpml:runtime), where WPML, its add-ons,
 * Yoast, Contact Form 7 and Fabrement sit side by side as on a real site — WPML reads each active
 * plugin's wpml-config.xml from WP_PLUGIN_DIR. The library DROPS the tables of this prefix on each
 * run: never point it at a real site's database.
 */
$root = dirname( __DIR__, 2 );

define( 'ABSPATH', $root . '/vendor/johnpbloch/wordpress-core/' );
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    $content = sys_get_temp_dir() . '/fabrement-lite-wpml-tests/wp-content';
    if ( ! is_dir( $content ) ) {
        mkdir( $content, 0777, true );
    }
    define( 'WP_CONTENT_DIR', $content );
    define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );
}
define( 'WP_PLUGIN_DIR', str_replace( '\\', '/', getenv( 'FABREMENT_WPML_PLUGINS_DIR' ) ?: dirname( $root ) . '/runtime/lite-wp-plugins' ) );
define( 'WP_PLUGIN_URL', 'http://example.org/wp-content/plugins' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
// WPML calls home (its update server, ATE, telemetry): none of that belongs in a test run.
define( 'WP_HTTP_BLOCK_EXTERNAL', true );

define( 'DB_NAME', getenv( 'WP_WPML_TESTS_DB_NAME' ) ?: 'wordpress_wpml_test' );
define( 'DB_USER', getenv( 'WP_WPML_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_WPML_TESTS_DB_PASSWORD' ) ?: 'root' );
define( 'DB_HOST', getenv( 'WP_WPML_TESTS_DB_HOST' ) ?: '127.0.0.1:14307' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wpmltests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Fabrement WPML Tests' );

/** How WPML puts the language in the address: directory (/fr/), parameter (?lang=fr), domain (fr.example.org). */
define( 'FABREMENT_WPML_URL_MODE', getenv( 'FABREMENT_WPML_URL_MODE' ) ?: 'directory' );

$ini = php_ini_loaded_file();
define( 'WP_PHP_BINARY', escapeshellarg( PHP_BINARY ) . ( $ini ? ' -c ' . escapeshellarg( $ini ) : '' ) );

// Never a Local site's MySQL (their ports are 10000+), never a plugin folder inside the repository.
if ( preg_match( '/:(1\d{4})$/', DB_HOST, $port ) && (int) $port[1] < 13000 ) {
    fwrite( STDERR, 'Refusing: ' . DB_HOST . " looks like a Local site's database.\n" );
    exit( 1 );
}
if ( strpos( WP_PLUGIN_DIR, str_replace( '\\', '/', $root ) . '/' ) === 0 ) {
    fwrite( STDERR, 'Refusing: WP_PLUGIN_DIR ' . WP_PLUGIN_DIR . " is inside the repository.\n" );
    exit( 1 );
}
