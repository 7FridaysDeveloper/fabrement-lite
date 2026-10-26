<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\McpConnectionConfig;
use PHPUnit\Framework\TestCase;

final class McpConnectionConfigTest extends TestCase {
    private const URL = 'https://example.test/subsite/wp-json/fabrement/v1/mcp';

    public function test_claude_command_puts_name_and_url_before_the_variadic_header(): void {
        $auth = base64_encode( 'editor:SYNTHETIC_PASSWORD' );
        self::assertSame(
            'claude mcp add --transport http --scope user fabrement-example-test "' . self::URL . '" --header "Authorization: Basic ' . $auth . '"',
            McpConnectionConfig::build( 'claude', self::URL, 'editor', 'SYNTHETIC_PASSWORD' )
        );
    }

    public function test_claude_file_config_uses_http_server_with_headers(): void {
        $config = json_decode(
            McpConnectionConfig::file_config( 'claude', self::URL, 'Basic ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' ) ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame( [ 'mcpServers' => [ 'fabrement-example-test' => [
            'type' => 'http',
            'url' => self::URL,
            'headers' => [ 'Authorization' => 'Basic ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' ) ],
        ] ] ], $config );
    }

    public function test_claude_url_cannot_break_out_of_its_shell_argument(): void {
        $url = "https://example.test/a'b\"c`d\\e\n\$(whoami)?rest_route=/fabrement/v1/mcp&x=1";
        $command = McpConnectionConfig::build( 'claude', $url, 'editor', 'SYNTHETIC_PASSWORD' );
        self::assertStringContainsString( ' fabrement-example-test "https://example.test/a%27b%22c%60d%5Ce%0A%24(whoami)?rest_route=/fabrement/v1/mcp&x=1" --header ', $command );
        self::assertSame( 4, substr_count( $command, '"' ) );
        self::assertStringNotContainsString( '$', $command );
    }

    public function test_codex_uses_toml_and_http_headers(): void {
        $auth = base64_encode( 'editor:SYNTHETIC_PASSWORD' );
        self::assertSame(
            "[mcp_servers.fabrement-example-test]\nurl = \"" . self::URL . "\"\nhttp_headers = { Authorization = \"Basic $auth\" }\n",
            McpConnectionConfig::build( 'codex', self::URL, 'editor', 'SYNTHETIC_PASSWORD' )
        );
    }

    public function test_codex_escapes_quotes_backslashes_and_newlines(): void {
        $url = "https://example.test/\"quoted\"\\path\nnext";
        $config = McpConnectionConfig::build( 'codex', $url, 'editor', 'secret' );
        $lines = explode( "\n", trim( $config ) );
        self::assertCount( 3, $lines );
        self::assertSame( $url, json_decode( substr( $lines[1], 6 ), true, 512, JSON_THROW_ON_ERROR ) );
    }

    public function test_codex_windows_cli_command_quotes_url_for_terminal_setup(): void {
        $url = "https://example.test/a'b\n\$(whoami)?rest_route=/fabrement/v1/mcp&x=1";
        $token = base64_encode( 'editor:SYNTHETIC_PASSWORD' );
        $command = McpConnectionConfig::codex_windows_cli_command( $url, $token );
        $variable = 'FABREMENT_EXAMPLE_TEST_MCP_' . strtoupper( substr( hash( 'sha256', $url ), 0, 12 ) ) . '_TOKEN';
        self::assertSame(
            "\$env:{$variable}=\"{$token}\"\n[Environment]::SetEnvironmentVariable(\"{$variable}\",\$env:{$variable},\"User\")\ncodex mcp add fabrement-example-test --url \"https://example.test/a%27b%0A%24(whoami)?rest_route=/fabrement/v1/mcp&x=1\" --bearer-token-env-var {$variable}",
            $command
        );
        self::assertStringNotContainsString( 'Authorization: Basic', $command );
        self::assertStringNotContainsString( "'", $command );
        self::assertSame( 8, substr_count( $command, '"' ) );
    }

    public function test_codex_posix_cli_command_quotes_url_for_terminal_setup(): void {
        $url = "https://example.test/a'b\n\$(whoami)?rest_route=/fabrement/v1/mcp&x=1";
        $token = base64_encode( 'editor:SYNTHETIC_PASSWORD' );
        $command = McpConnectionConfig::codex_posix_cli_command( $url, $token );
        $variable = 'FABREMENT_EXAMPLE_TEST_MCP_' . strtoupper( substr( hash( 'sha256', $url ), 0, 12 ) ) . '_TOKEN';
        self::assertSame(
            "export {$variable}=\"{$token}\"\ncodex mcp add fabrement-example-test --url \"https://example.test/a%27b%0A%24(whoami)?rest_route=/fabrement/v1/mcp&x=1\" --bearer-token-env-var {$variable}",
            $command
        );
        self::assertStringNotContainsString( 'Authorization: Basic', $command );
        self::assertStringNotContainsString( "'", $command );
        self::assertSame( 4, substr_count( $command, '"' ) );
    }

    public function test_cursor_produces_valid_json_with_basic_authentication(): void {
        $config = json_decode( McpConnectionConfig::build( 'cursor', self::URL, 'editor', 'SYNTHETIC_PASSWORD' ), true, 512, JSON_THROW_ON_ERROR );
        self::assertSame( [ 'mcpServers' => [ 'fabrement-example-test' => [
            'url' => self::URL,
            'headers' => [ 'Authorization' => 'Basic ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' ) ],
        ] ] ], $config );
    }

    public function test_unknown_client_is_rejected(): void {
        $this->expectException( \InvalidArgumentException::class );
        McpConnectionConfig::build( 'unknown', self::URL, 'editor', 'secret' );
    }

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
        self::assertSame( 'https://chatgpt.com/plugins', $setup['settings_url'] );
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
        self::assertStringContainsString( 'Do not create an application password', $prompt );
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

    public function test_application_password_instructions_contain_the_exact_client_config_and_private_setup_limits(): void {
        foreach ( McpConnectionConfig::CLIENTS as $client => $label ) {
            $connection = McpConnectionConfig::connection( $client, self::URL, 'editor', 'SYNTHETIC_PASSWORD' );
            $prompt = $connection['agent_instructions'];
            self::assertStringContainsString( $label, $prompt );
            self::assertStringContainsString( $connection['file_config'], $prompt );
            self::assertStringContainsString( $connection['authorization'], $prompt );
            self::assertStringContainsString( 'Do not use OAuth', $prompt );
            self::assertStringContainsString( 'Do not print it back', $prompt );
            self::assertStringContainsString( 'tools/list only', $prompt );
            self::assertStringContainsString( 'Preserve all existing MCP servers', $prompt );
        }
    }

    public function test_connection_fields_match_generated_configuration(): void {
        foreach ( array_keys( McpConnectionConfig::CLIENTS ) as $client ) {
            $connection = McpConnectionConfig::connection( $client, self::URL, 'editor', 'SYNTHETIC_PASSWORD' );
            self::assertSame( $client, $connection['client'] );
            self::assertSame( self::URL, $connection['url'] );
            self::assertSame( 'Basic ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' ), $connection['authorization'] );
            self::assertSame( McpConnectionConfig::build( $client, self::URL, 'editor', 'SYNTHETIC_PASSWORD' ), $connection['content'] );
            self::assertSame(
                McpConnectionConfig::file_config( $client, self::URL, 'Basic ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' ) ),
                $connection['file_config']
            );
            if ( $client === 'codex' ) {
                self::assertSame( McpConnectionConfig::codex_windows_cli_command( self::URL, base64_encode( 'editor:SYNTHETIC_PASSWORD' ) ), $connection['cli_command'] );
                self::assertSame( McpConnectionConfig::codex_posix_cli_command( self::URL, base64_encode( 'editor:SYNTHETIC_PASSWORD' ) ), $connection['cli_command_posix'] );
            } elseif ( $client === 'claude' ) {
                self::assertSame( McpConnectionConfig::build( 'claude', self::URL, 'editor', 'SYNTHETIC_PASSWORD' ), $connection['cli_command'] );
                self::assertArrayNotHasKey( 'cli_command_posix', $connection );
            } else {
                self::assertArrayNotHasKey( 'cli_command', $connection );
                self::assertArrayNotHasKey( 'cli_command_posix', $connection );
            }
        }
    }

    public function test_cursor_install_link_keeps_credentials_in_local_app_protocol(): void {
        $url = self::URL . '?rest_route=/fabrement/v1/mcp&value=\"test\"';
        $authorization = 'Basic ' . base64_encode( 'editor:SYNTHETIC_PASSWORD/+=' );
        $link = McpConnectionConfig::cursor_install_url( $url, $authorization );
        $parts = parse_url( $link );
        self::assertSame( 'cursor', $parts['scheme'] );
        self::assertSame( 'anysphere.cursor-deeplink', $parts['host'] );
        self::assertSame( '/mcp/install', $parts['path'] );
        parse_str( $parts['query'], $query );
        self::assertSame( 'fabrement-example-test', $query['name'] );
        self::assertSame( [ 'url' => $url, 'headers' => [ 'Authorization' => $authorization ] ],
            json_decode( base64_decode( $query['config'], true ), true, 512, JSON_THROW_ON_ERROR ) );
        self::assertStringNotContainsString( $authorization, $link );
    }

    public function test_application_password_setup_uses_the_same_site_name_in_every_representation(): void {
        foreach ( array_keys( McpConnectionConfig::CLIENTS ) as $client ) {
            $setup = McpConnectionConfig::connection( $client, self::URL, 'editor', 'SYNTHETIC_PASSWORD', 'My Site &amp; Studio' );
            self::assertSame( 'fabrement-my-site-studio', $setup['name'] );
            foreach ( [ 'content', 'file_config', 'agent_instructions', 'cli_command', 'cli_command_posix', 'install_url' ] as $field ) {
                if ( isset( $setup[ $field ] ) ) {
                    self::assertStringContainsString( $setup['name'], $setup[ $field ] );
                    self::assertStringNotContainsString( 'wp-blocks', $setup[ $field ] );
                    self::assertStringNotContainsString( 'WP_BLOCKS', $setup[ $field ] );
                }
            }
            self::assertStringContainsString( 'ask me before changing it', $setup['agent_instructions'] );
            if ( $client === 'cursor' ) {
                parse_str( parse_url( $setup['install_url'], PHP_URL_QUERY ), $query );
                self::assertSame( $setup['name'], $query['name'] );
            } else {
                self::assertSame( McpConnectionConfig::oauth_connection( $client, self::URL, 'My Site &amp; Studio' )['name'], $setup['name'] );
            }
        }
    }

    public function test_token_variables_do_not_collide_for_sites_with_the_same_title(): void {
        $variables = [];
        foreach ( [ self::URL, 'https://other.test/mcp', 'https://example.test/other/wp-json/fabrement/v1/mcp' ] as $url ) {
            $setup = McpConnectionConfig::connection( 'codex', $url, 'editor', 'SYNTHETIC_PASSWORD', 'Same title' );
            preg_match( '/--bearer-token-env-var ([A-Z0-9_]+)$/', $setup['cli_command'], $matches );
            $variables[] = $matches[1];
            self::assertStringEndsWith( '--bearer-token-env-var ' . $matches[1], $setup['cli_command_posix'] );
            self::assertStringStartsWith( '$env:' . $matches[1] . '="', $setup['cli_command'] );
        }
        self::assertCount( 3, array_unique( $variables ) );
    }

    public function test_every_generated_shell_command_uses_double_quotes(): void {
        $url = "https://example.test/a'b?x=1&y=2";
        foreach ( [ '', "Site's \"Title\" ` \$(whoami)" ] as $title ) {
            foreach ( [ 'codex', 'claude' ] as $client ) {
                $password = McpConnectionConfig::connection( $client, $url, 'editor', 'SYNTHETIC_PASSWORD', $title );
                $oauth = McpConnectionConfig::oauth_connection( $client, $url, $title );
                foreach ( [ $password['cli_command'], $password['cli_command_posix'] ?? '', $oauth['cli_command'] ] as $command ) {
                    self::assertStringNotContainsString( "'", $command );
                    if ( $command !== '' ) {
                        self::assertStringContainsString( '"https://example.test/a%27b?x=1&y=2"', $command );
                    }
                }
            }
        }
    }

    public function test_double_quoted_token_values_escape_shell_interpolation(): void {
        $token = 'synthetic$token`value"\\';
        $windows = explode( "\n", McpConnectionConfig::codex_windows_cli_command( self::URL, $token ) )[0];
        $posix = explode( "\n", McpConnectionConfig::codex_posix_cli_command( self::URL, $token ) )[0];
        self::assertStringEndsWith( '="synthetic`$token``value`"\\"', $windows );
        self::assertStringEndsWith( '="synthetic\\$token\\`value\\"\\\\"', $posix );
    }
}
