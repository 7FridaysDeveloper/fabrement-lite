<?php
/**
 * Bootstrap of tests/Site: the plugin tested on real WordPress sites in Docker, from outside — the
 * agent through the MCP endpoint over HTTPS with an application password, the visitor through
 * pages, the site's own code through WP-CLI. No WordPress is loaded in this process.
 *
 *   composer test:site:up     the sites' containers (tests/Site/docker-compose.yml)
 *   composer test:site        this suite: syncs the code into the sites, installs them the first time
 */
if ( PHP_SAPI !== 'cli' ) {
    exit;
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
foreach ( [ 'Site', 'SiteSetup', 'Http', 'Browser', 'McpClient', 'SiteTestCase' ] as $class ) {
    require __DIR__ . '/Support/' . $class . '.php';
}

// One run at a time per copy of the sites: a test class restores the snapshot, which would pull the
// site from under another run's tests. Several people or agents may write tests side by side; their
// runs queue here. The lock is held until this process ends.
$fabrement_lock = fopen( sys_get_temp_dir() . '/' . \Fabrement\Tests\Site\Support\Site::stack() . '.lock', 'c' );
if ( ! flock( $fabrement_lock, LOCK_EX | LOCK_NB ) ) {
    fwrite( STDERR, "Another run is using these sites; waiting for it to finish…\n" );
    flock( $fabrement_lock, LOCK_EX );
}
$GLOBALS['fabrement_site_lock'] = $fabrement_lock;

\Fabrement\Tests\Site\Support\SiteSetup::prepare();
