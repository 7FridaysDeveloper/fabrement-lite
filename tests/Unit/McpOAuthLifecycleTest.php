<?php

namespace Fabrement\Tests;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Mcp\OAuth\{Bootstrap, Config, OAuthError, Store};
use Fabrement\Modules\Updates\DatabaseUpdater;
use PHPUnit\Framework\TestCase;

final class ActivationOAuthStore extends Store {
    public int $installs = 0;
    public bool $fail = false;

    public function install(): void {
        ++$this->installs;
        if ( $this->fail ) { throw new OAuthError( 'server_error', 'Synthetic installation failure.', 500 ); }
        $GLOBALS['wpdb']->table_exists = true;
        update_option( Keys::OPTION_MCP_OAUTH_SCHEMA, self::SCHEMA_VERSION, false );
    }
}

final class McpOAuthLifecycleTest extends TestCase {
    protected function setUp(): void { WordPressState::reset(); }

    public function test_activation_creates_storage_then_persists_enabled_and_generation(): void {
        $GLOBALS['wpdb']->table_exists = false;
        $store = new ActivationOAuthStore();
        DatabaseUpdater::install_site( $store );
        self::assertSame( 1, $store->installs );
        self::assertSame( '1', get_option( Keys::OPTION_MCP_OAUTH_ENABLED ) );
        self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', Config::generation() );
        self::assertTrue( Config::enabled() );
        self::assertArrayHasKey( 'fabrement_mcp_oauth_cleanup', WordPressState::$scheduled );
    }

    public function test_reactivation_preserves_explicit_off_and_existing_generation(): void {
        update_option( Keys::OPTION_MCP_OAUTH_ENABLED, false );
        update_option( Keys::OPTION_MCP_OAUTH_GENERATION, 'existing-generation' );
        $store = new ActivationOAuthStore();
        DatabaseUpdater::install_site( $store );
        DatabaseUpdater::install_site( $store );
        self::assertSame( 2, $store->installs );
        self::assertFalse( get_option( Keys::OPTION_MCP_OAUTH_ENABLED ) );
        self::assertSame( 'existing-generation', Config::generation() );
        self::assertFalse( Config::enabled() );
        self::assertCount( 1, WordPressState::$scheduled );
    }

    public function test_reactivation_does_not_invalidate_existing_enabled_connections(): void {
        $store = new ActivationOAuthStore();
        DatabaseUpdater::install_site( $store );
        $generation = Config::generation();
        DatabaseUpdater::install_site( $store );
        self::assertSame( $generation, Config::generation() );
        self::assertTrue( Config::enabled() );
    }

    public function test_failed_creation_does_not_enable_o_auth_or_record_success(): void {
        $store = new ActivationOAuthStore();
        $store->fail = true;
        DatabaseUpdater::install_site( $store );
        self::assertFalse( get_option( Keys::OPTION_MCP_OAUTH_ENABLED ) );
        self::assertFalse( get_option( Keys::OPTION_MCP_OAUTH_SCHEMA ) );
        self::assertSame( 'Synthetic installation failure.', get_option( Keys::OPTION_DB_UPDATE_ERROR ) );
        self::assertFalse( Config::enabled() );
        self::assertSame( [], WordPressState::$scheduled );
    }

    public function test_failed_default_write_does_not_leave_storage_marked_ready(): void {
        $GLOBALS['wpdb']->fail_writes = true;
        DatabaseUpdater::install_site( new ActivationOAuthStore() );
        self::assertFalse( get_option( Keys::OPTION_MCP_OAUTH_SCHEMA ) );
        self::assertFalse( Config::enabled() );
        self::assertNotFalse( get_option( Keys::OPTION_DB_UPDATE_ERROR ) );
    }

    public function test_activation_retry_clears_error_without_overwriting_off(): void {
        update_option( Keys::OPTION_DB_UPDATE_ERROR, 'Previous failure' );
        update_option( Keys::OPTION_MCP_OAUTH_ENABLED, false );
        DatabaseUpdater::install_site( new ActivationOAuthStore() );
        self::assertFalse( get_option( Keys::OPTION_DB_UPDATE_ERROR ) );
        self::assertFalse( get_option( Keys::OPTION_MCP_OAUTH_ENABLED ) );
        self::assertTrue( ( new Store() )->ready() );
    }

    public function test_defaults_cannot_be_written_without_storage(): void {
        $this->expectException( OAuthError::class );
        ( new Store() )->initialize_options();
    }

    public function test_https_is_still_required_after_default_enablement(): void {
        WordPressState::$options['home'] = 'http://example.test';
        DatabaseUpdater::install_site( new ActivationOAuthStore() );
        self::assertSame( '1', get_option( Keys::OPTION_MCP_OAUTH_ENABLED ) );
        self::assertFalse( Config::enabled() );
    }

    public function test_missing_table_disables_o_auth_despite_stale_options(): void {
        update_option( Keys::OPTION_MCP_OAUTH_SCHEMA, Store::SCHEMA_VERSION );
        update_option( Keys::OPTION_MCP_OAUTH_ENABLED, true );
        $GLOBALS['wpdb']->table_exists = false;
        self::assertFalse( Config::enabled() );
        $response = Bootstrap::endpoint( 'metadata', new \WP_REST_Request( 'GET' ) );
        self::assertSame( 503, $response->get_status() );
        self::assertStringContainsString( 'reactivate Fabrement', $response->get_data()['error_description'] );
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            self::assertStringStartsWith( 'SHOW TABLES LIKE', $query );
        }
    }

    public function test_disabled_login_and_refresh_never_enable_o_auth(): void {
        DatabaseUpdater::install_site( new ActivationOAuthStore() );
        update_option( Keys::OPTION_MCP_OAUTH_ENABLED, false );
        $queries = count( $GLOBALS['wpdb']->queries );
        foreach ( [ 'metadata', 'register', 'token' ] as $endpoint ) {
            $response = Bootstrap::endpoint( $endpoint, new \WP_REST_Request( 'POST' ) );
            self::assertSame( 403, $response->get_status() );
            self::assertSame( 'access_denied', $response->get_data()['error'] );
            self::assertStringContainsString( 'OAuth is turned off for this site', $response->get_data()['error_description'] );
            self::assertStringContainsString( 'Fabrement → MCP Connection', $response->get_data()['error_description'] );
        }
        self::assertFalse( get_option( Keys::OPTION_MCP_OAUTH_ENABLED ) );
        self::assertCount( $queries, $GLOBALS['wpdb']->queries );
    }

    public function test_rejected_o_auth_callback_restores_existing_word_press_user(): void {
        WordPressState::select_user( 12, [ 'manage_options' ] );
        update_option( Keys::OPTION_MCP_OAUTH_ENABLED, false );
        $request = new \WP_REST_Request( 'POST', Config::ROUTE );
        $request->set_header( 'authorization', 'Bearer fb_oauth_at_synthetic' );
        $response = Bootstrap::authenticate( null, [], $request );
        self::assertInstanceOf( \WP_Error::class, $response );
        self::assertSame( 0, get_current_user_id() );
        self::assertSame( $response, Bootstrap::restore_user( $response, [], $request ) );
        self::assertSame( 12, get_current_user_id() );
    }

    public function test_cleanup_does_not_query_an_absent_table(): void {
        update_option( Keys::OPTION_MCP_OAUTH_SCHEMA, Store::SCHEMA_VERSION );
        $GLOBALS['wpdb']->table_exists = false;
        ( new Store() )->cleanup();
        self::assertSame( [ 'SHOW TABLES LIKE %s' ], $GLOBALS['wpdb']->queries );
    }

    public function test_disconnect_does_not_read_an_absent_table(): void {
        WordPressState::select_user( 12, [ 'manage_options' ] );
        $GLOBALS['wpdb']->table_exists = false;
        $_POST = [ 'oauth_command' => 'revoke', 'family' => 'synthetic' ];
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'OAuth storage is not ready.' );
        Bootstrap::settings();
    }

    /** @dataProvider failed_settings */
    public function test_disable_reports_failed_setting_writes( string $failed_option ): void {
        WordPressState::select_user( 12, [ 'manage_options' ] );
        update_option( Keys::OPTION_MCP_OAUTH_ENABLED, true );
        update_option( Keys::OPTION_MCP_OAUTH_GENERATION, 'previous-generation' );
        WordPressState::$failed_options[] = $failed_option;
        $_POST['oauth_command'] = 'disable';
        try {
            Bootstrap::settings();
            self::fail( 'Failed settings writes must not redirect as success.' );
        } catch ( \RuntimeException $error ) {
            self::assertSame( 'Could not save the OAuth settings. Please try again.', $error->getMessage() );
        }
        self::assertTrue( get_option( Keys::OPTION_MCP_OAUTH_ENABLED ) );
        self::assertSame( [], $GLOBALS['wpdb']->queries );
    }

    public function failed_settings(): array {
        return [ [ Keys::OPTION_MCP_OAUTH_GENERATION ], [ Keys::OPTION_MCP_OAUTH_ENABLED ] ];
    }

    public function test_installation_is_wired_to_activation_and_version_check_not_o_auth_endpoints(): void {
        $root = dirname( __DIR__, 2 );
        $bootstrap = file_get_contents( $root . '/src/Modules/Mcp/OAuth/Bootstrap.php' );
        self::assertStringNotContainsString( '->install(', $bootstrap );
        self::assertStringNotContainsString( 'Lifecycle::activate', $bootstrap );
        $plugin = file_get_contents( $root . '/fabrement-lite.php' );
        self::assertStringContainsString( 'DatabaseUpdater::activate()', $plugin );
        self::assertStringContainsString( "DatabaseUpdater::class, 'maybe_upgrade' ], 2, 0", $plugin );
    }
}
