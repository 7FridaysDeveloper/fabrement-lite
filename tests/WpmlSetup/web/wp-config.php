<?php
/**
 * WordPress of the WPML suite's web server: the database, table prefix, plugins and wp-content of
 * tests/WpmlSetup/wp-tests-config.php, seen from inside the container. Mounted one level above
 * ABSPATH, where WordPress looks for it.
 */
define( 'DB_NAME', 'wordpress_wpml_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', 'mysql:3306' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wpmltests_';

define( 'WP_CONTENT_DIR', '/var/www/content' );
define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );
define( 'WP_PLUGIN_DIR', '/var/www/plugins' );
define( 'WP_PLUGIN_URL', 'http://example.org/wp-content/plugins' );
// The PHPUnit run's wp-content has no mu-plugins; this server's own are kept apart from it.
define( 'WPMU_PLUGIN_DIR', '/var/www/web-mu-plugins' );
define( 'WPMU_PLUGIN_URL', 'http://example.org/web-mu-plugins' );

define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', '/tmp/wp-debug.log' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'DISABLE_WP_CRON', true );

// Keys and salts: a throwaway test site.
foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ] as $fabrement_key ) {
    define( $fabrement_key, 'fabrement-lite-wpml-tests-' . $fabrement_key );
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/html/' );
}
require_once ABSPATH . 'wp-settings.php';
