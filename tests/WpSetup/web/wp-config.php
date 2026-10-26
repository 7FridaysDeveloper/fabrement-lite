<?php
/**
 * WordPress of the suite's web server (tests/Wp, tests/Cases/Wp): the database, table prefix and
 * wp-content of tests/WpSetup/wp-tests-config.php, seen from inside the container. The suite loads
 * its plugins as must-use plugins, not through active_plugins: so does this server (mu-plugins/).
 */
define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', 'mysql:3306' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_CONTENT_DIR', '/var/www/content' );
define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );
define( 'WP_PLUGIN_DIR', '/var/www/plugins' );
define( 'WP_PLUGIN_URL', 'http://example.org/wp-content/plugins' );
define( 'WPMU_PLUGIN_DIR', '/var/www/web-mu-plugins' );
define( 'WPMU_PLUGIN_URL', 'http://example.org/web-mu-plugins' );

define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', '/tmp/wp-debug.log' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'DISABLE_WP_CRON', true );

foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ] as $fabrement_key ) {
    define( $fabrement_key, 'fabrement-lite-wp-tests-' . $fabrement_key );
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/html/' );
}
require_once ABSPATH . 'wp-settings.php';
