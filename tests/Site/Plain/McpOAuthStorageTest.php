<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The OAuth storage and the database update on a real site, done as they happen there: the plugin
 * activated and deactivated with WP-CLI, a release update picked up by an ordinary page request,
 * another MySQL connection holding the update lock, a table deleted by hand, cron run, uninstall.
 */
final class McpOAuthStorageTest extends SiteTestCase {

    private const TABLE   = 'wp_fabrement_mcp_oauth';
    private const VERSION = 3;   // DatabaseUpdater::VERSION

    private function option( string $name ) {
        return $this->site()->option( $name );
    }

    private function table_exists(): bool {
        return $this->site()->value( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'site_plain' AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = ?", [ self::TABLE ] ) === self::TABLE;
    }

    /** Any page: the update runs on plugins_loaded of an ordinary request. */
    private function visit(): void {
        $this->get( '/' );
    }

    private function reactivate(): void {
        $this->site()->wp( 'plugin', 'deactivate', 'fabrement' );
        $this->site()->wp( 'plugin', 'activate', 'fabrement' );
    }

    private function put( string $kind, string $key, int $expires, string $family = '', int $user = 0 ): void {
        $this->site()->php( "( new \\Fabrement\\Modules\\Mcp\\OAuth\\Store() )->put( '$kind', '$key', [], $expires, '$family', $user );" );
    }

    private function stored( string $kind, string $key ): ?array {
        return $this->site_runs( "return ( new \\Fabrement\\Modules\\Mcp\\OAuth\\Store() )->get( '$kind', '$key' );" );
    }

    private function cleanup_scheduled(): bool {
        return str_contains( (string) serialize( $this->option( 'cron' ) ), 'fabrement_mcp_oauth_cleanup' );
    }

    /**
     * The database version taken back (deleted, or set to $to) in SQL: WP-CLI boots WordPress,
     * and that boot would run the update before the request the test means to watch.
     */
    private function drop_version( ?string $to = null ): void {
        $to === null
            ? $this->site()->db()->query( "DELETE FROM wp_options WHERE option_name = 'fabrement_db_version'" )
            : $this->site()->db()->query( "UPDATE wp_options SET option_value = '" . $to . "' WHERE option_name = 'fabrement_db_version'" );
    }

    private function lock_name(): string {
        return 'fabrement-db-' . substr( hash( 'sha256', 'site_plain:wp_options' ), 0, 48 );
    }

    public function test_activation_made_the_table_turned_oauth_on_and_scheduled_cleanup(): void {
        $this->assertTrue( $this->table_exists() );
        $this->assertSame( self::VERSION, (int) $this->option( 'fabrement_db_version' ) );
        $this->assertSame( '1', (string) $this->option( 'fabrement_mcp_oauth_enabled' ), 'on, for an HTTPS site' );
        $this->assertSame( 64, strlen( (string) $this->option( 'fabrement_mcp_oauth_generation' ) ) );
        $this->assertTrue( $this->cleanup_scheduled() );
    }

    public function test_reactivation_keeps_connections_and_an_explicit_off(): void {
        $generation = $this->option( 'fabrement_mcp_oauth_generation' );
        $this->put( 'grant', 'qa-kept', time() + 600 );
        $this->reactivate();
        $this->assertNotNull( $this->stored( 'grant', 'qa-kept' ) );
        $this->assertSame( $generation, $this->option( 'fabrement_mcp_oauth_generation' ), 'connections are not invalidated' );

        $this->site()->wp( 'option', 'update', 'fabrement_mcp_oauth_enabled', '0' );
        $this->reactivate();
        $this->assertSame( '0', (string) $this->option( 'fabrement_mcp_oauth_enabled' ), 'Off survives reactivation' );
        $off = Http::request( $this->site(), 'POST', $this->site()->url( '/wp-json/fabrement-oauth/v1/token' ), [ 'Content-Type' => 'application/x-www-form-urlencoded' ], '' );
        $this->assertSame( 403, $off['status'] );
        $this->assertStringContainsString( 'OAuth is turned off', $off['body'] );
        $this->site()->wp( 'option', 'update', 'fabrement_mcp_oauth_enabled', '1' );
    }

    public function test_a_release_update_runs_on_the_next_request_and_keeps_everything(): void {
        $generation = $this->option( 'fabrement_mcp_oauth_generation' );
        $this->put( 'grant', 'qa-release', time() + 600 );
        $this->drop_version();
        $this->visit();
        $this->assertSame( self::VERSION, (int) $this->option( 'fabrement_db_version' ) );
        $this->assertSame( $generation, $this->option( 'fabrement_mcp_oauth_generation' ) );
        $this->assertNotNull( $this->stored( 'grant', 'qa-release' ) );
    }

    public function test_an_update_another_connection_is_running_is_skipped_not_stamped(): void {
        $this->drop_version( '0' );
        // Another connection — another request mid-update — holds the lock.
        $peer = new \mysqli( '127.0.0.1', 'root', 'root', 'site_plain', \Fabrement\Tests\Site\Support\Site::mysql_port() );
        try {
            $this->assertSame( '1', (string) $peer->query( "SELECT GET_LOCK('" . $this->lock_name() . "', 0)" )->fetch_row()[0] );
            $this->visit();
            $this->assertSame( 0, (int) $this->option( 'fabrement_db_version' ), 'success is not stamped' );
            $this->assertSame( '1', (string) $peer->query( "SELECT IS_USED_LOCK('" . $this->lock_name() . "') = CONNECTION_ID()" )->fetch_row()[0], 'the other connection keeps its lock' );
        } finally {
            $peer->query( "SELECT RELEASE_LOCK('" . $this->lock_name() . "')" );
            $peer->close();
        }
        $this->visit();
        $this->assertSame( self::VERSION, (int) $this->option( 'fabrement_db_version' ), 'done once the lock is free' );
    }

    public function test_a_failed_install_backs_off_and_activation_retries_at_once(): void {
        // Something else holds the table's name (a view): creating the table fails, as a real failure would.
        $this->site()->db()->query( 'DROP TABLE ' . self::TABLE );
        $this->site()->db()->query( 'CREATE VIEW ' . self::TABLE . ' AS SELECT 1 AS id' );
        $this->drop_version( '0' );
        $this->visit();
        $this->assertSame( 0, (int) $this->option( 'fabrement_db_version' ), 'the version does not move' );
        $this->assertGreaterThan( time(), (int) ( $this->option( 'fabrement_db_retry_after' )['after'] ?? 0 ), 'a retry is scheduled, not hammered' );
        $this->assertNotEmpty( $this->option( 'fabrement_db_update_error' ) );
        $this->assertSame( '1', (string) $this->site()->value( "SELECT IS_FREE_LOCK('" . $this->lock_name() . "')" ), 'the lock is released' );
        // WordPress logs the failed statements, as on any site: the failure this test made, nothing else.
        $log = $this->site()->debug_log();
        $this->assertStringContainsString( "'site_plain.wp_fabrement_mcp_oauth' is not BASE TABLE", $log );
        $this->assertSame( '', trim( (string) preg_replace( "/^.*'site_plain\\.wp_fabrement_mcp_oauth' is not BASE TABLE.*\$\\R?/m", '', $log ) ), 'no other error' );
        $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/debug.log' );

        $this->site()->db()->query( 'DROP VIEW ' . self::TABLE );
        $this->reactivate();
        $this->assertTrue( $this->table_exists(), 'activation retries at once' );
        $this->assertEmpty( $this->option( 'fabrement_db_update_error' ) );
        $this->assertSame( self::VERSION, (int) $this->option( 'fabrement_db_version' ) );
    }

    public function test_a_table_deleted_by_hand_stops_oauth_until_reactivation(): void {
        $this->site()->db()->query( 'DROP TABLE ' . self::TABLE );
        $metadata = Http::request( $this->site(), 'GET', $this->site()->url( '/wp-json/fabrement-oauth/v1/metadata' ) );
        $this->assertSame( 503, $metadata['status'], 'stale options do not turn it on' );
        $this->assertFalse( $this->table_exists(), 'a request does not create it' );
        $this->visit();
        $this->assertFalse( $this->table_exists(), 'a current version does not recreate it silently' );
        $this->reactivate();
        $this->assertTrue( $this->table_exists() );
    }

    public function test_a_site_from_before_oauth_is_set_up_by_its_next_request(): void {
        // The cron event first: every WP-CLI command boots WordPress, and a boot with no database
        // version would run the update itself. What is left is taken away in SQL, without a boot.
        $this->site()->wp( 'cron', 'event', 'unschedule', 'fabrement_mcp_oauth_cleanup' );
        $this->site()->db()->query( 'DROP TABLE ' . self::TABLE );
        $this->site()->db()->query( "DELETE FROM wp_options WHERE option_name IN ( 'fabrement_mcp_oauth_enabled', 'fabrement_mcp_oauth_generation', 'fabrement_mcp_oauth_schema', 'fabrement_db_version' )" );
        $this->assertFalse( $this->cleanup_scheduled() );

        $this->visit();
        $this->assertTrue( $this->table_exists() );
        $this->assertSame( self::VERSION, (int) $this->option( 'fabrement_db_version' ) );
        $this->assertSame( '1', (string) $this->option( 'fabrement_mcp_oauth_enabled' ), 'on, once its storage is ready' );
        $this->assertSame( 64, strlen( (string) $this->option( 'fabrement_mcp_oauth_generation' ) ) );
        $this->assertTrue( $this->cleanup_scheduled() );
    }

    public function test_an_update_repairs_a_missing_column_and_the_unique_index(): void {
        $this->site()->db()->query( 'ALTER TABLE ' . self::TABLE . ' DROP INDEX key_hash' );
        $this->site()->db()->query( 'ALTER TABLE ' . self::TABLE . ' DROP COLUMN revoked' );
        $this->drop_version();
        $this->visit();
        $this->assertContains( 'revoked', array_column( $this->site()->rows( 'SHOW COLUMNS FROM ' . self::TABLE ), 'Field' ) );
        $index = $this->site()->rows( "SHOW INDEX FROM " . self::TABLE . " WHERE Key_name = 'key_hash'" );
        $this->assertCount( 1, $index );
        $this->assertSame( '0', (string) $index[0]['Non_unique'] );
    }

    public function test_cron_cleans_what_expired_or_was_revoked_and_keeps_live_connections(): void {
        $this->put( 'grant', 'qa-expired', time() - 1 );
        $this->put( 'grant', 'qa-active', time() + 600 );
        $this->put( 'grant', 'qa-family', 0, 'qa-family', 123 );
        $this->put( 'refresh', 'qa-refresh', 0, 'qa-family', 123 );
        $this->assertSame( [ true, false ], $this->site_runs( "\$s = new \\Fabrement\\Modules\\Mcp\\OAuth\\Store(); return [ \$s->consume( 'refresh', 'qa-refresh' ), \$s->consume( 'refresh', 'qa-refresh' ) ];" ), 'consumed exactly once' );
        $this->site()->wp( 'cron', 'event', 'run', 'fabrement_mcp_oauth_cleanup' );
        $this->assertNull( $this->stored( 'grant', 'qa-expired' ) );
        $this->assertNotNull( $this->stored( 'grant', 'qa-active' ) );
        $this->assertNotNull( $this->stored( 'grant', 'qa-family' ), 'a permanent grant stays' );
        $this->assertSame( 1, (int) $this->stored( 'refresh', 'qa-refresh' )['used'], 'a used refresh token stays, for replay detection' );

        $this->put( 'access', 'qa-access', time() + 3600, 'qa-family', 123 );
        $this->assertSame( [], $this->site_runs( "\$s = new \\Fabrement\\Modules\\Mcp\\OAuth\\Store(); \$s->revoke( 'qa-family' ); return array_values( array_intersect( array_column( \$s->grants(), 'family' ), [ 'qa-family' ] ) );" ), 'gone from the list at once' );
        $this->site()->wp( 'cron', 'event', 'run', 'fabrement_mcp_oauth_cleanup' );
        $this->assertNull( $this->stored( 'grant', 'qa-family' ) );
        $this->assertNull( $this->stored( 'refresh', 'qa-refresh' ) );
        $this->assertNull( $this->stored( 'access', 'qa-access' ) );
        $this->assertNotNull( $this->stored( 'grant', 'qa-active' ), 'another connection stays' );
    }

    public function test_no_expiry_makes_no_short_lived_record_valid(): void {
        foreach ( [ 'code', 'access', 'pending', 'client' ] as $kind ) {
            $this->put( $kind, 'qa-zero-' . $kind, 0 );
            $this->assertNull( $this->stored( $kind, 'qa-zero-' . $kind ), $kind );
        }
    }

    public function test_uninstall_removes_the_table_options_and_schedule_and_a_reinstall_works(): void {
        $this->site()->wp( 'plugin', 'uninstall', 'fabrement', '--deactivate', '--skip-delete' );
        $this->assertFalse( $this->table_exists() );
        foreach ( [ 'fabrement_mcp_oauth_enabled', 'fabrement_mcp_oauth_generation', 'fabrement_mcp_oauth_schema', 'fabrement_db_version', 'fabrement_db_update_error', 'fabrement_db_retry_after' ] as $option ) {
            $this->assertNull( $this->option( $option ), $option );
        }
        $this->assertFalse( $this->cleanup_scheduled() );
        $this->site()->wp( 'plugin', 'activate', 'fabrement' );
        $this->assertTrue( $this->table_exists(), 'a clean reinstall works' );
    }
}
