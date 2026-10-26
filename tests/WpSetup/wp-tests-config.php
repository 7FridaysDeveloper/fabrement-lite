<?php
/**
 * Configuration of the WordPress test library (wp-phpunit) for tests/Wp.
 *
 * WordPress itself is vendor/johnpbloch/wordpress-core, the database the one in
 * tests/WpSetup/docker-compose.yml. Every value can be overridden from the environment, so CI or a
 * developer with their own MySQL points it elsewhere without editing this file. The library
 * DROPS every table with the prefix below on each run — never point it at a real site's database.
 */
$root = dirname( __DIR__, 2 );

define( 'ABSPATH', $root . '/vendor/johnpbloch/wordpress-core/' );
// What the plugin writes (field files, block folders) and uploads go to a scratch folder, never
// into vendor/: a fresh one per run, so a file a test left behind cannot decide the next run.
$content = sys_get_temp_dir() . '/fabrement-lite-wp-tests/wp-content';
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    if ( is_dir( $content . '/fabrement' ) ) {
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $content . '/fabrement', FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $it as $file ) {
            $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
        }
    }
    if ( ! is_dir( $content ) ) {
        mkdir( $content, 0777, true );
    }
    define( 'WP_CONTENT_DIR', $content );
    define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );
}
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'root' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1:14306' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Fabrement Tests' );

// The library installs WordPress in a child process. Run it with THIS PHP and THIS ini: the one
// on PATH may be another version, or lack mysqli.
$ini = php_ini_loaded_file();
define( 'WP_PHP_BINARY', escapeshellarg( PHP_BINARY ) . ( $ini ? ' -c ' . escapeshellarg( $ini ) : '' ) );
