<?php
/**
 * The one-language test site (tests/Site/docker-compose.yml, service "plain"): an ordinary
 * wp-config.php, as on a host. Errors go to wp-content/debug.log, which the tests read: a page that
 * raised a notice fails its test.
 */
define( 'DB_NAME', 'site_plain' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', 'mysql:3306' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wp_';

define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
// WordPress core is root's here and PHP runs as www-data: say how to write files, as a host does.
define( 'FS_METHOD', 'direct' );
define( 'DISABLE_WP_CRON', true );   // cron is run by the tests, when they mean it (wp cron event run)
// A test site asks no server for plugin updates (the plugin's update checker, PUC): a hook added
// before WordPress loads, the way WordPress takes them from wp-config.
$GLOBALS['wp_filter']['puc_check_now-fabrement'][10]['fabrement-tests'] = [ 'function' => '__return_false', 'accepted_args' => 1 ];

foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ] as $fabrement_key ) {
    define( $fabrement_key, 'fabrement-site-plain-' . $fabrement_key );
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/html/' );
}
require_once ABSPATH . 'wp-settings.php';
