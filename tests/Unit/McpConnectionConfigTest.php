<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\McpConnectionConfig;
use PHPUnit\Framework\TestCase;

final class McpConnectionConfigTest extends TestCase {
    private const URL = 'https://example.test/subsite/wp-json/fabrement/v1/mcp';

    public function test_o_auth_clients_are_distinct_and_setup_contains_no_static_credentials(): void {
        self::assertSame( [ 'claude' => 'Claude', 'chatgpt' => 'ChatGPT', 'codex' => 'Codex' ], McpConnectionConfig::OAUTH_CLIENTS );
        foreach ( array_keys( McpConnectionConfig::OAUTH_CLIENTS ) as $client ) {
            $setup = McpConnectionConfig::oauth_connection( $client, self::URL );
            self::assertSame( self::URL, $setup['url'] );
            self::assertArrayNotHasKey( 'authorization', $setup );
            self::assertStringNotContainsString( 'bearer-token-env-var', json_encode( $setup ) );
            self::assertStringNotContainsString( 'Authorization', json_encode( $setup ) );
        }
    }

    public function test_codex_o_auth_setup_includes_registration_and_login_without_overwriting_legacy_entry(): void {
        $setup = McpConnectionConfig::oauth_connection( 'codex', self::URL );
        self::assertNotSame( 'wp-blocks', $setup['name'] );
        self::assertSame( 'codex mcp add ' . $setup['name'] . ' --url "' . self::URL . '"' . "\ncodex mcp login {$setup['name']}", $setup['cli_command'] );
        self::assertSame( "[mcp_servers.{$setup['name']}]\nurl = \"" . self::URL . "\"\n", $setup['file_config'] );
        self::assertNotSame( $setup['name'], McpConnectionConfig::oauth_connection( 'codex', 'https://another.test/mcp' )['name'] );
        self::assertArrayNotHasKey( 'settings_url', $setup );
    }

    public function test_chat_gpt_o_auth_setup_is_not_a_codex_configuration(): void {
        $setup = McpConnectionConfig::oauth_connection( 'chatgpt', self::URL );
        self::assertArrayNotHasKey( 'settings_url', $setup );
        self::assertArrayNotHasKey( 'cli_command', $setup );
        self::assertArrayNotHasKey( 'file_config', $setup );
    }

    public function test_codex_o_auth_instructions_use_the_generated_connection_and_require_user_consent(): void {
        $setup = McpConnectionConfig::oauth_connection( 'codex', self::URL, 'Example Studio' );
        $prompt = $setup['agent_instructions'];
        self::assertStringContainsString( 'Server name: fabrement-example-studio', $prompt );
        self::assertStringContainsString( 'MCP server URL: ' . self::URL, $prompt );
        self::assertStringContainsString( $setup['cli_command'], $prompt );
        self::assertStringContainsString( 'Preserve all existing MCP servers', $prompt );
        self::assertStringContainsString( 'ask me before changing it', $prompt );
        self::assertStringContainsString( 'review and approve the requested permissions in my browser', $prompt );
        self::assertStringContainsString( 'Use OAuth only. Do not add a static authentication header.', $prompt );
        self::assertStringContainsString( 'Do not bypass certificate verification', $prompt );
        self::assertStringContainsString( 'tools/list only', $prompt );
        self::assertStringContainsString( 'Do not create, edit or delete WordPress content', $prompt );
        foreach ( [ 'chatgpt', 'claude' ] as $client ) {
            self::assertArrayNotHasKey( 'agent_instructions', McpConnectionConfig::oauth_connection( $client, self::URL ) );
        }
    }

    public function test_o_auth_server_name_uses_the_site_name_without_a_hash(): void {
        foreach ( array_keys( McpConnectionConfig::OAUTH_CLIENTS ) as $client ) {
            $setup = McpConnectionConfig::oauth_connection( $client, self::URL, 'My Site &amp; Studio' );
            self::assertSame( 'fabrement-my-site-studio', $setup['name'] );
            if ( isset( $setup['cli_command'] ) ) {
                self::assertStringContainsString( 'mcp login fabrement-my-site-studio', $setup['cli_command'] );
                self::assertStringContainsString( 'fabrement-my-site-studio', $setup['file_config'] );
            }
        }
    }

    public function test_o_auth_server_name_falls_back_to_the_host_and_cannot_inject_shell_syntax(): void {
        self::assertSame( 'fabrement-example-test', McpConnectionConfig::oauth_connection( 'codex', 'https://example.test/mcp', '' )['name'] );
        self::assertSame( 'fabrement-example-test', McpConnectionConfig::oauth_connection( 'codex', 'https://example.test/mcp', "\u{0421}\u{0430}\u{0439}\u{0442}" )['name'] );
        $setup = McpConnectionConfig::oauth_connection( 'codex', self::URL, "A Site'; \$(whoami)\n[evil]" );
        self::assertSame( 'fabrement-a-site-whoami-evil', $setup['name'] );
        self::assertSame( 1, substr_count( $setup['cli_command'], "\n" ) );
        self::assertLessThanOrEqual( 82, strlen( McpConnectionConfig::oauth_connection( 'codex', self::URL, str_repeat( 'long ', 100 ) )['name'] ) );
    }

    public function test_claude_o_auth_setup_supports_cloud_and_code(): void {
        $setup = McpConnectionConfig::oauth_connection( 'claude', self::URL );
        self::assertSame( 'https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName='
            . rawurlencode( $setup['name'] ) . '&connectorUrl=' . rawurlencode( self::URL ), $setup['settings_url'] );
        $query = [];
        parse_str( (string) parse_url( McpConnectionConfig::oauth_connection( 'claude', self::URL . '?a=1&b=2' )['settings_url'], PHP_URL_QUERY ), $query );
        self::assertSame( self::URL . '?a=1&b=2', $query['connectorUrl'] );
        self::assertSame( 'claude mcp add --transport http --scope user ' . $setup['name'] . ' "' . self::URL
            . '"' . "\nclaude mcp login {$setup['name']}", $setup['cli_command'] );
        self::assertSame( [ 'mcpServers' => [ $setup['name'] => [ 'type' => 'http', 'url' => self::URL ] ] ], json_decode( $setup['file_config'], true ) );
    }

    public function test_o_auth_commands_escape_url_shell_characters(): void {
        $url = "https://example.test/a'b\"c`d\\e\n\$(whoami)?x=1&y=2";
        $codex = McpConnectionConfig::oauth_connection( 'codex', $url );
        self::assertStringContainsString( 'a%27b', $codex['cli_command'] );
        self::assertSame( 2, substr_count( $codex['cli_command'], '"' ) );
        self::assertStringContainsString( 'a%27b%22c%60d%5Ce%0A%24(whoami)', $codex['cli_command'] );
        $claude = McpConnectionConfig::oauth_connection( 'claude', $url );
        self::assertStringContainsString( 'a%27b%22c%60d%5Ce%0A%24(whoami)', $claude['cli_command'] );
        self::assertSame( 2, substr_count( $claude['cli_command'], '"' ) );
    }

    public function test_unknown_o_auth_client_is_rejected(): void {
        $this->expectException( \InvalidArgumentException::class );
        McpConnectionConfig::oauth_connection( 'cursor', self::URL );
    }

    public function test_every_generated_shell_command_uses_double_quotes(): void {
        $url = "https://example.test/a'b?x=1&y=2";
        foreach ( [ '', "Site's \"Title\" ` \$(whoami)" ] as $title ) {
            foreach ( [ 'codex', 'claude' ] as $client ) {
                $command = McpConnectionConfig::oauth_connection( $client, $url, $title )['cli_command'];
                self::assertStringNotContainsString( "'", $command );
                self::assertStringContainsString( '"https://example.test/a%27b?x=1&y=2"', $command );
            }
        }
    }
}
