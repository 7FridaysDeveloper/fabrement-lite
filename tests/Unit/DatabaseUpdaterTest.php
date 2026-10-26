<?php

namespace Fabrement\Tests;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Mcp\OAuth\{Config, Store};
use Fabrement\Modules\Updates\DatabaseUpdater;
use PHPUnit\Framework\TestCase;

final class UpdateTestStore extends Store {
    public int $installs = 0;
    public bool $fail = false;

    public function install(): void {
        ++$this->installs;
        if ( $this->fail ) { throw new \RuntimeException( 'Synthetic database failure.' ); }
        $GLOBALS['wpdb']->table_exists = true;
        update_option( Keys::OPTION_MCP_OAUTH_SCHEMA, self::SCHEMA_VERSION, false );
    }
}

final class DatabaseUpdaterTest extends TestCase {
    protected function setUp(): void { WordPressState::reset(); }

    public function test_upgrade_from_release_without_database_creates_storage_once(): void {
        $store = new UpdateTestStore();
        self::assertTrue( DatabaseUpdater::maybe_upgrade( $store ) );
        self::assertSame( DatabaseUpdater::VERSION, get_option( Keys::OPTION_DB_VERSION ) );
        self::assertTrue( Config::enabled() );
        $queries = $GLOBALS['wpdb']->queries;
        self::assertTrue( DatabaseUpdater::maybe_upgrade( $store ) );
        self::assertSame( 1, $store->installs );
        self::assertSame( $queries, $GLOBALS['wpdb']->queries );
    }

    public function test_current_version_does_not_query_or_repair_tables(): void {
        update_option( Keys::OPTION_DB_VERSION, DatabaseUpdater::VERSION );
        $GLOBALS['wpdb']->table_exists = false;
        self::assertTrue( DatabaseUpdater::maybe_upgrade( new UpdateTestStore() ) );
        self::assertSame( [], $GLOBALS['wpdb']->queries );
        self::assertFalse( Config::enabled() );
    }

    public function test_version_two_runs_the_o_auth_installer_once_to_lift_legacy_expiry(): void {
        update_option( Keys::OPTION_DB_VERSION, 2 );
        $store = new UpdateTestStore();
        self::assertTrue( DatabaseUpdater::maybe_upgrade( $store ) );
        self::assertTrue( DatabaseUpdater::maybe_upgrade( $store ) );
        self::assertSame( 1, $store->installs );
        self::assertSame( DatabaseUpdater::VERSION, get_option( Keys::OPTION_DB_VERSION ) );
    }

    public function test_update_preserves_existing_off_generation_and_cleanup_schedule(): void {
        update_option( Keys::OPTION_MCP_OAUTH_ENABLED, false );
        update_option( Keys::OPTION_MCP_OAUTH_GENERATION, 'preserved' );
        WordPressState::$scheduled['fabrement_mcp_oauth_cleanup'] = 123;
        self::assertTrue( DatabaseUpdater::maybe_upgrade( new UpdateTestStore() ) );
        self::assertFalse( get_option( Keys::OPTION_MCP_OAUTH_ENABLED ) );
        self::assertSame( 'preserved', Config::generation() );
        self::assertSame( 123, wp_next_scheduled( 'fabrement_mcp_oauth_cleanup' ) );
    }

    public function test_future_version_is_never_downgraded_even_on_activation(): void {
        update_option( Keys::OPTION_DB_VERSION, DatabaseUpdater::VERSION + 1 );
        $store = new UpdateTestStore();
        self::assertTrue( DatabaseUpdater::maybe_upgrade( $store ) );
        self::assertTrue( DatabaseUpdater::install_site( $store ) );
        self::assertSame( 0, $store->installs );
        self::assertSame( DatabaseUpdater::VERSION + 1, get_option( Keys::OPTION_DB_VERSION ) );
    }

    public function test_concurrent_updater_does_not_run_installer_or_release_another_lock(): void {
        $GLOBALS['wpdb']->lock_result = 0;
        $store = new UpdateTestStore();
        self::assertFalse( DatabaseUpdater::maybe_upgrade( $store ) );
        self::assertSame( 0, $store->installs );
        self::assertSame( [ 'SELECT GET_LOCK(%s, 0)' ], $GLOBALS['wpdb']->queries );
        self::assertFalse( get_option( Keys::OPTION_DB_UPDATE_ERROR ) );
    }

    public function test_version_is_rechecked_after_obtaining_lock(): void {
        $GLOBALS['wpdb']->on_lock = static function (): void {
            update_option( Keys::OPTION_DB_VERSION, DatabaseUpdater::VERSION );
        };
        $store = new UpdateTestStore();
        self::assertTrue( DatabaseUpdater::maybe_upgrade( $store ) );
        self::assertSame( 0, $store->installs );
        self::assertSame( 'SELECT RELEASE_LOCK(%s)', end( $GLOBALS['wpdb']->queries ) );
    }

    public function test_lock_failure_leaves_version_unchanged_and_backs_off(): void {
        $GLOBALS['wpdb']->lock_result = null;
        self::assertFalse( DatabaseUpdater::maybe_upgrade( new UpdateTestStore() ) );
        self::assertFalse( get_option( Keys::OPTION_DB_VERSION ) );
        self::assertStringContainsString( 'lock', get_option( Keys::OPTION_DB_UPDATE_ERROR ) );
        self::assertGreaterThan( time(), get_option( Keys::OPTION_DB_RETRY_AFTER )['after'] );
    }

    public function test_failure_preserves_previous_version_releases_lock_and_waits_before_retry(): void {
        update_option( Keys::OPTION_DB_VERSION, 0 );
        $store = new UpdateTestStore();
        $store->fail = true;
        self::assertFalse( DatabaseUpdater::maybe_upgrade( $store ) );
        self::assertSame( 0, get_option( Keys::OPTION_DB_VERSION ) );
        self::assertSame( 'Synthetic database failure.', get_option( Keys::OPTION_DB_UPDATE_ERROR ) );
        self::assertSame( 'SELECT RELEASE_LOCK(%s)', end( $GLOBALS['wpdb']->queries ) );
        $queries = $GLOBALS['wpdb']->queries;
        self::assertFalse( DatabaseUpdater::maybe_upgrade( $store ) );
        self::assertSame( 1, $store->installs );
        self::assertSame( $queries, $GLOBALS['wpdb']->queries );
    }

    public function test_expired_backoff_retries_and_clears_error(): void {
        update_option( Keys::OPTION_DB_UPDATE_ERROR, 'Previous failure' );
        update_option( Keys::OPTION_DB_RETRY_AFTER, [ 'version' => DatabaseUpdater::VERSION, 'after' => time() - 1 ] );
        self::assertTrue( DatabaseUpdater::maybe_upgrade( new UpdateTestStore() ) );
        self::assertFalse( get_option( Keys::OPTION_DB_UPDATE_ERROR ) );
        self::assertFalse( get_option( Keys::OPTION_DB_RETRY_AFTER ) );
    }

    public function test_new_schema_version_is_not_delayed_by_an_older_failure(): void {
        update_option( Keys::OPTION_DB_RETRY_AFTER, [ 'version' => 0, 'after' => time() + 300 ] );
        self::assertTrue( DatabaseUpdater::maybe_upgrade( new UpdateTestStore() ) );
    }

    public function test_activation_can_repair_immediately_without_waiting_for_retry(): void {
        update_option( Keys::OPTION_DB_RETRY_AFTER, [ 'version' => DatabaseUpdater::VERSION, 'after' => time() + 300 ] );
        self::assertTrue( DatabaseUpdater::install_site( new UpdateTestStore() ) );
        self::assertFalse( get_option( Keys::OPTION_DB_RETRY_AFTER ) );
    }

    public function test_failed_manual_repair_invalidates_current_version_for_automatic_retry(): void {
        update_option( Keys::OPTION_DB_VERSION, DatabaseUpdater::VERSION );
        $store = new UpdateTestStore();
        $store->fail = true;
        self::assertFalse( DatabaseUpdater::install_site( $store ) );
        self::assertFalse( get_option( Keys::OPTION_DB_VERSION ) );
    }

    public function test_failed_version_write_does_not_report_success(): void {
        WordPressState::$failed_options[] = Keys::OPTION_DB_VERSION;
        self::assertFalse( DatabaseUpdater::maybe_upgrade( new UpdateTestStore() ) );
        self::assertFalse( get_option( Keys::OPTION_DB_VERSION ) );
        self::assertStringContainsString( 'record the database version', get_option( Keys::OPTION_DB_UPDATE_ERROR ) );
    }

    public function test_failed_cron_setup_does_not_report_success(): void {
        WordPressState::$fail_schedule = true;
        self::assertFalse( DatabaseUpdater::maybe_upgrade( new UpdateTestStore() ) );
        self::assertFalse( get_option( Keys::OPTION_DB_VERSION ) );
        self::assertFalse( Config::enabled() );
        self::assertStringContainsString( 'schedule', get_option( Keys::OPTION_DB_UPDATE_ERROR ) );
    }

    public function test_uninstall_removes_only_updater_state(): void {
        foreach ( [ Keys::OPTION_DB_VERSION, Keys::OPTION_DB_UPDATE_ERROR, Keys::OPTION_DB_RETRY_AFTER ] as $option ) {
            update_option( $option, 1 );
        }
        update_option( Keys::OPTION_MCP_OAUTH_ENABLED, false );
        DatabaseUpdater::uninstall();
        self::assertSame( [ Keys::OPTION_MCP_OAUTH_ENABLED => false ], WordPressState::$options );
    }
}
