<?php

namespace Fabrement\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

/** Client-specific OAuth setup text. */
final class McpConnectionConfig {

    public const OAUTH_CLIENTS = [ 'claude' => 'Claude', 'chatgpt' => 'ChatGPT', 'codex' => 'Codex' ];

    /** Keep one readable, shell-safe name in the UI, CLI and TOML/JSON configuration. */
    public static function server_name( string $url, string $site_name = '' ): string {
        $site_name = html_entity_decode( $site_name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $site_name ) ), '-' );
        if ( '' === $slug ) {
            $slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ), '-' );
        }
        return 'fabrement-' . ( rtrim( substr( $slug, 0, 72 ), '-' ) ?: 'site' );
    }

    /** OAuth setup contains no password, static Authorization header or provider credentials. */
    public static function oauth_connection( string $client, string $url, string $site_name = '' ): array {
        if ( ! isset( self::OAUTH_CLIENTS[ $client ] ) ) {
            throw new \InvalidArgumentException( 'Unsupported OAuth client.' );
        }
        $name = self::server_name( $url, $site_name );
        // ChatGPT needs only the name and URL: its guide links to its own settings page.
        $connection = [ 'name' => $name, 'url' => $url ];
        if ( 'codex' === $client ) {
            $connection['cli_command'] = 'codex mcp add ' . $name . ' --url "' . self::shell_url_double( $url )
                . '"' . "\ncodex mcp login {$name}";
            $connection['file_config'] = "[mcp_servers.{$name}]\nurl = " . self::encode( $url ) . "\n";
            $connection['agent_instructions'] = "Connect my WordPress site to Codex using the Fabrement MCP server over Streamable HTTP with OAuth.\n\n"
                . "Server name: {$name}\nMCP server URL: {$url}\n\n"
                . "Preserve all existing MCP servers in my local Codex settings. Inspect this server name first. If it points to another URL or uses a different authentication method, ask me before changing it. If the same OAuth connection already exists, reuse it and skip the add command.\n\n"
                . "For a new connection, run the add command below, then start OAuth login:\n"
                . $connection['cli_command'] . "\n\n"
                . "Let me sign in to WordPress and review and approve the requested permissions in my browser. Wait for the login to finish. Do not ask me to paste my WordPress password, authorization code or tokens into chat.\n"
                . "Use OAuth only. Do not add a static authentication header. Let Codex manage its OAuth tokens; do not print, upload or commit them.\n"
                . "For a local site, run Codex on a computer that can reach this URL and trusts its HTTPS certificate. Do not bypass certificate verification.\n"
                . "After login, verify the connection and tools/list only. Do not create, edit or delete WordPress content during setup. If this session cannot load the new tools, ask me to start a new Codex session and verify there.\n"
                . "If you cannot configure my local Codex directly, give me the local steps. Do not claim the site is connected until it has been verified.";
        } elseif ( 'claude' === $client ) {
            // Opens Claude's Add custom connector form with the name and URL already filled in.
            $connection['settings_url'] = 'https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName='
                . rawurlencode( $name ) . '&connectorUrl=' . rawurlencode( $url );
            $connection['cli_command'] = 'claude mcp add --transport http --scope user ' . $name . ' "'
                . self::shell_url_double( $url ) . '"' . "\nclaude mcp login {$name}";
            $connection['file_config'] = self::encode( [ 'mcpServers' => [ $name => [ 'type' => 'http', 'url' => $url ] ] ], JSON_PRETTY_PRINT ) . "\n";
        }
        return $connection;
    }

    /**
     * Encode what a double-quoted argument would still interpret — `"`, `$` and backtick in
     * PowerShell and POSIX shells, `\` in POSIX — plus control characters and the apostrophe, so
     * the URL cannot break out of its argument in any of the three shells.
     */
    private static function shell_url_double( string $url ): string {
        return preg_replace_callback( '/[\x00-\x20\x7f"$`\\\\\x27]/', static function ( array $match ): string {
            return rawurlencode( $match[0] );
        }, $url );
    }

    /** JSON strings are also valid TOML basic strings for these UTF-8 values. */
    private static function encode( $value, int $flags = 0 ): string {
        return json_encode( $value, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
    }
}
