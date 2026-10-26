<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\McpConnectionConfig;
use Fabrement\Modules\Mcp\McpPasswordScope;
use Fabrement\Page\McpConnectionPage;
use PHPUnit\Framework\TestCase;

final class McpCredentialsPageTest extends TestCase {
    private McpConnectionPage $page;

    protected function setUp(): void {
        WordPressState::reset();
        WordPressState::select_user( 1, [ 'manage_options', 'edit_themes' ] );
        $this->page = ( new \ReflectionClass( McpConnectionPage::class ) )->newInstanceWithoutConstructor();
    }

    public static function clients(): iterable {
        foreach ( McpConnectionConfig::CLIENTS as $client => $label ) { yield $client => [ $client, $label ]; }
    }

    /**
     * The list titles a password with the client it was made for; the stamp this page
     * appends goes under the title, with the other dates. Only that exact stamp is
     * dropped — a renamed password keeps every word of its name, and a name
     * that is nothing but a stamp keeps it rather than becoming an empty row.
     *
     * @dataProvider password_labels
     */
    public function test_password_label_drops_only_the_stamp_this_page_appends( string $name, string $expected ): void {
        self::assertSame( $expected, McpConnectionPage::password_label( $name ) );
    }

    public static function password_labels(): iterable {
        yield 'stamped' => [ 'Fabrement MCP (Cursor) - 2026-09-22 08:22', 'Fabrement MCP (Cursor)' ];
        yield 'no stamp' => [ 'Fabrement MCP (Codex)', 'Fabrement MCP (Codex)' ];
        yield 'renamed' => [ 'Fabrement MCP - laptop', 'Fabrement MCP - laptop' ];
        yield 'date inside the name' => [ 'Fabrement MCP 2026-09-22 08:22 (Codex)', 'Fabrement MCP 2026-09-22 08:22 (Codex)' ];
        yield 'stamp only' => [ '2026-09-22 08:22', '2026-09-22 08:22' ];
    }

    /** @dataProvider clients */
    public function test_generation_names_key_and_produces_correct_one_time_payload( string $client, string $label ): void {
        $_POST['fabrement_mcp_client'] = $client;
        WordPressState::$options['blogname'] = 'My Site &amp; Studio';
        $this->generate();
        self::assertSame( [ [ 1, [ 'name' => "Fabrement MCP ($label) - 2026-09-14 12:00", 'app_id' => McpPasswordScope::APP_ID ] ] ], WordPressState::$created_passwords );
        $transient = WordPressState::$transients['fabrement_mcp_command_1'];
        self::assertSame( 300, $transient['ttl'] );
        $expected = [
            'client' => $client,
            'content' => McpConnectionConfig::build( $client, rest_url( 'fabrement/v1/mcp' ), 'security-user', 'SYNTHETIC_PASSWORD', get_bloginfo( 'name' ) ),
            'name' => 'fabrement-my-site-studio',
            'agent_instructions' => McpConnectionConfig::application_password_instructions( $client, rest_url( 'fabrement/v1/mcp' ), 'Basic ' . base64_encode( 'security-user:SYNTHETIC_PASSWORD' ), get_bloginfo( 'name' ) ),
            'url' => rest_url( 'fabrement/v1/mcp' ),
            'authorization' => 'Basic ' . base64_encode( 'security-user:SYNTHETIC_PASSWORD' ),
            'file_config' => McpConnectionConfig::file_config(
                $client,
                rest_url( 'fabrement/v1/mcp' ),
                'Basic ' . base64_encode( 'security-user:SYNTHETIC_PASSWORD' ),
                get_bloginfo( 'name' )
            ),
        ];
        if ( $client === 'codex' ) {
            $expected['cli_command'] = McpConnectionConfig::codex_windows_cli_command(
                rest_url( 'fabrement/v1/mcp' ),
                base64_encode( 'security-user:SYNTHETIC_PASSWORD' ),
                get_bloginfo( 'name' )
            );
            $expected['cli_command_posix'] = McpConnectionConfig::codex_posix_cli_command(
                rest_url( 'fabrement/v1/mcp' ),
                base64_encode( 'security-user:SYNTHETIC_PASSWORD' ),
                get_bloginfo( 'name' )
            );
        } elseif ( $client === 'claude' ) {
            $expected['cli_command'] = McpConnectionConfig::build( 'claude', rest_url( 'fabrement/v1/mcp' ), 'security-user', 'SYNTHETIC_PASSWORD', get_bloginfo( 'name' ) );
        } else {
            $expected['install_url'] = McpConnectionConfig::cursor_install_url( rest_url( 'fabrement/v1/mcp' ), 'Basic ' . base64_encode( 'security-user:SYNTHETIC_PASSWORD' ), get_bloginfo( 'name' ) );
        }
        WordPressState::$options['blogname'] = 'Renamed after generation';
        $actual = $this->page->consume_mcp_connection();
        ksort( $expected );
        ksort( $actual );
        self::assertSame( $expected, $actual );
        self::assertNull( $this->page->consume_mcp_connection() );
    }

    public function test_old_form_without_client_still_generates_claude_connection(): void {
        $this->generate();
        self::assertSame( 'claude', $this->page->consume_mcp_connection()['client'] );
    }

    public static function invalid_clients(): iterable {
        yield 'unknown' => [ 'other' ];
        yield 'array' => [ [ 'codex' ] ];
        yield 'empty' => [ '' ];
    }

    /** @dataProvider invalid_clients */
    public function test_invalid_client_is_rejected_before_creating_credentials( $client ): void {
        $_POST['fabrement_mcp_client'] = $client;
        $this->assert_generation_denied( 'Choose a supported MCP client.' );
    }

    public static function denied_contexts(): iterable {
        yield 'guest' => [ 'guest', 'You do not have permission to perform this action.' ];
        yield 'bad nonce' => [ 'nonce', 'Invalid nonce.' ];
        yield 'passwords unavailable' => [ 'unavailable', 'Application passwords are not available for this user on this site.' ];
    }

    /** @dataProvider denied_contexts */
    public function test_authorization_checks_precede_password_creation( string $context, string $message ): void {
        if ( $context === 'guest' ) { WordPressState::select_user( 0, [] ); }
        if ( $context === 'nonce' ) { WordPressState::$valid_nonce = false; }
        if ( $context === 'unavailable' ) { WordPressState::$passwords_available = false; }
        $this->assert_generation_denied( $message );
    }

    public function test_password_creation_failure_does_not_cache_setup(): void {
        WordPressState::$password_error = new \WP_Error( 'failed', 'Password creation failed.' );
        try {
            $this->page->handle_generate_mcp_command();
            self::fail( 'Generation must stop on a WordPress error.' );
        } catch ( \RuntimeException $error ) {
            self::assertSame( 'Password creation failed.', $error->getMessage() );
        }
        self::assertSame( [], WordPressState::$transients );
    }

    public function test_transient_is_scoped_to_user(): void {
        set_transient( 'fabrement_mcp_command_2', [ 'client' => 'claude', 'content' => 'command' ], 300 );
        self::assertNull( $this->page->consume_mcp_connection() );
        self::assertArrayHasKey( 'fabrement_mcp_command_2', WordPressState::$transients );
        WordPressState::select_user( 2, [ 'manage_options' ] );
        self::assertSame( [ 'client' => 'claude', 'content' => 'command' ], $this->page->consume_mcp_connection() );
        self::assertNull( $this->page->consume_mcp_connection() );
    }

    public function test_plain_string_payload_is_consumed_without_rendering(): void {
        set_transient( 'fabrement_mcp_command_1', 'plain command', 300 );
        self::assertNull( $this->page->consume_mcp_connection() );
        self::assertSame( [], WordPressState::$transients );
    }

    public function test_malformed_payload_is_consumed_without_rendering(): void {
        set_transient( 'fabrement_mcp_command_1', [ 'client' => [ 'codex' ], 'content' => 'bad' ], 300 );
        self::assertNull( $this->page->consume_mcp_connection() );
        self::assertSame( [], WordPressState::$transients );
    }

    public static function legacy_metadata(): iterable {
        yield 'no metadata' => [ [] ];
        yield 'missing authorization' => [ [ 'url' => 'https://example.test' ] ];
        yield 'missing url' => [ [ 'authorization' => 'Basic SYNTHETIC' ] ];
        yield 'invalid authorization' => [ [ 'url' => 'https://example.test', 'authorization' => [ 'bad' ] ] ];
        yield 'invalid url' => [ [ 'url' => [ 'bad' ], 'authorization' => 'Basic SYNTHETIC' ] ];
    }

    /** @dataProvider legacy_metadata */
    public function test_incomplete_metadata_falls_back_to_configuration_text( array $metadata ): void {
        $legacy = [ 'client' => 'codex', 'content' => 'legacy config' ];
        set_transient( 'fabrement_mcp_command_1', $legacy + $metadata, 300 );
        self::assertSame( $legacy, $this->page->consume_mcp_connection() );
        self::assertNull( $this->page->consume_mcp_connection() );
    }

    /** Only the app_id marks a password this page issued; the name a person can give any password. */
    public static function issued_passwords(): iterable {
        yield 'claude' => [ 'Fabrement MCP (Claude) - now', McpPasswordScope::APP_ID, true ];
        yield 'renamed' => [ 'Laptop', McpPasswordScope::APP_ID, true ];
        yield 'our name, made by hand' => [ 'Fabrement MCP (Codex) - now', '', false ];
        yield 'another server' => [ 'Claude MCP', '', false ];
        yield 'another app' => [ 'Fabrement MCP', '0f8e3c52-6a1d-4f3b-9d2e-7c4b5a6e8f90', false ];
    }

    /** @dataProvider issued_passwords */
    public function test_listing_and_revocation_cover_only_issued_passwords( string $name, string $app_id, bool $managed ): void {
        WordPressState::$passwords[1] = [ [ 'uuid' => 'test-key', 'app_id' => $app_id, 'name' => $name, 'created' => 123, 'password' => 'MUST_NOT_LEAVE_STORE' ] ];
        $passwords = $this->page->get_mcp_passwords();
        self::assertCount( $managed ? 1 : 0, $passwords );
        self::assertStringNotContainsString( 'MUST_NOT_LEAVE_STORE', json_encode( $passwords ) );
        $_GET['uuid'] = 'test-key';
        try { $this->page->handle_revoke_mcp_password(); } catch ( TestRedirect $redirect ) {
            self::assertStringContainsString( 'fabrement_mcp=revoked', $redirect->getMessage() );
        }
        self::assertSame( $managed ? [ [ 1, 'test-key' ] ] : [], WordPressState::$deleted_passwords );
    }

    public function test_cannot_revoke_another_users_key(): void {
        WordPressState::$passwords[2] = [ [ 'uuid' => 'other-user-key', 'app_id' => McpPasswordScope::APP_ID, 'name' => 'Fabrement MCP (Codex)' ] ];
        $_GET['uuid'] = 'other-user-key';
        try { $this->page->handle_revoke_mcp_password(); } catch ( TestRedirect $redirect ) {
            self::assertSame( [], WordPressState::$deleted_passwords );
        }
    }

    private function generate(): void {
        try { $this->page->handle_generate_mcp_command(); } catch ( TestRedirect $redirect ) {
            self::assertStringContainsString( 'fabrement_mcp=generated', $redirect->getMessage() );
        }
    }

    private function assert_generation_denied( string $message ): void {
        try {
            $this->page->handle_generate_mcp_command();
            self::fail( 'Generation must be rejected.' );
        } catch ( \RuntimeException $error ) {
            self::assertSame( $message, $error->getMessage() );
        }
        self::assertSame( [], WordPressState::$created_passwords );
        self::assertSame( [], WordPressState::$transients );
    }
}
