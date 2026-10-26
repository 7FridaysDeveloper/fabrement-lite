<?php

namespace Fabrement\Modules\Mcp;

/** Client-specific setup text for OAuth and legacy application passwords. */
final class McpConnectionConfig {

    public const CLIENTS = [ 'claude' => 'Claude Code', 'codex' => 'Codex', 'cursor' => 'Cursor' ];
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
        $connection = [ 'client' => $client, 'name' => $name, 'url' => $url ];
        if ( 'chatgpt' === $client ) {
            $connection['settings_url'] = 'https://chatgpt.com/plugins';
        } elseif ( 'codex' === $client ) {
            $connection['cli_command'] = 'codex mcp add ' . $name . ' --url "' . self::shell_url_double( $url )
                . '"' . "\ncodex mcp login {$name}";
            $connection['file_config'] = "[mcp_servers.{$name}]\nurl = " . self::encode( $url ) . "\n";
            $connection['agent_instructions'] = "Connect my WordPress site to Codex using the Fabrement MCP server over Streamable HTTP with OAuth.\n\n"
                . "Server name: {$name}\nMCP server URL: {$url}\n\n"
                . "Preserve all existing MCP servers in my local Codex settings. Inspect this server name first. If it points to another URL or uses a different authentication method, ask me before changing it. If the same OAuth connection already exists, reuse it and skip the add command.\n\n"
                . "For a new connection, run the add command below, then start OAuth login:\n"
                . $connection['cli_command'] . "\n\n"
                . "Let me sign in to WordPress and review and approve the requested permissions in my browser. Wait for the login to finish. Do not ask me to paste my WordPress password, authorization code or tokens into chat.\n"
                . "Use OAuth only. Do not create an application password or add a static authentication header. Let Codex manage its OAuth tokens; do not print, upload or commit them.\n"
                . "For a local site, run Codex on a computer that can reach this URL and trusts its HTTPS certificate. Do not bypass certificate verification.\n"
                . "After login, verify the connection and tools/list only. Do not create, edit or delete WordPress content during setup. If this session cannot load the new tools, ask me to start a new Codex session and verify there.\n"
                . "If you cannot configure my local Codex directly, give me the local steps. Do not claim the site is connected until it has been verified.";
        } else {
            // Opens Claude's Add custom connector form with the name and URL already filled in.
            $connection['settings_url'] = 'https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName='
                . rawurlencode( $name ) . '&connectorUrl=' . rawurlencode( $url );
            $connection['cli_command'] = 'claude mcp add --transport http --scope user ' . $name . ' "'
                . self::shell_url_double( $url ) . '"' . "\nclaude mcp login {$name}";
            $connection['file_config'] = self::encode( [ 'mcpServers' => [ $name => [ 'type' => 'http', 'url' => $url ] ] ], JSON_PRETTY_PRINT ) . "\n";
        }
        return $connection;
    }

    /** @return array{client:string,name:string,content:string,url:string,authorization:string,file_config:string,agent_instructions:string,cli_command?:string,cli_command_posix?:string,install_url?:string} */
    public static function connection( string $client, string $url, string $login, string $password, string $site_name = '' ): array {
        if ( ! isset( self::CLIENTS[ $client ] ) ) {
            throw new \InvalidArgumentException( 'Unsupported MCP client.' );
        }

        $authorization = 'Basic ' . base64_encode( $login . ':' . $password );
        $connection = [
            'client'        => $client,
            'name'          => self::server_name( $url, $site_name ),
            'content'       => self::build( $client, $url, $login, $password, $site_name ),
            'url'           => $url,
            'authorization' => $authorization,
            'file_config'   => self::file_config( $client, $url, $authorization, $site_name ),
            'agent_instructions' => self::application_password_instructions( $client, $url, $authorization, $site_name ),
        ];

        if ( $client === 'codex' ) {
            $token = base64_encode( $login . ':' . $password );
            $connection['cli_command']       = self::codex_windows_cli_command( $url, $token, $site_name );
            $connection['cli_command_posix'] = self::codex_posix_cli_command( $url, $token, $site_name );
        } elseif ( $client === 'claude' ) {
            $connection['cli_command'] = self::claude_cli_command( $url, $authorization, $site_name );
        } else {
            $connection['install_url'] = self::cursor_install_url( $url, $authorization, $site_name );
        }

        return $connection;
    }

    public static function application_password_instructions( string $client, string $url, string $authorization, string $site_name = '' ): string {
        if ( ! isset( self::CLIENTS[ $client ] ) ) {
            throw new \InvalidArgumentException( 'Unsupported MCP client.' );
        }
        $target = [ 'codex' => '~/.codex/config.toml', 'claude' => 'Claude Code user-scope MCP settings', 'cursor' => '~/.cursor/mcp.json' ][ $client ];
        $name = self::server_name( $url, $site_name );
        return 'Connect my WordPress site to ' . self::CLIENTS[ $client ] . " using the Fabrement MCP server.\n\n"
            . "Use Streamable HTTP and the WordPress application password in the configuration below. Do not use OAuth or run an OAuth login command.\n"
            . 'Configure ' . $target . ". Preserve all existing MCP servers. Inspect {$name} first. If it already points to another site or uses another authentication method, ask me before changing it. Use a different server name for another site.\n"
            . "Use the Authorization header exactly as supplied. In a connection form, leave the Bearer token/environment-variable field empty.\n"
            . "This configuration contains a private credential. Do not print it back, upload it, commit it to Git, or put it in shared project files. Prefer private user-level settings.\n"
            . "After configuring, verify connection and tools/list only. Do not create, edit or delete WordPress content during setup. If you cannot configure this client directly, give me the local steps without repeating the credential.\n\n"
            . "Configuration:\n" . self::file_config( $client, $url, $authorization, $site_name );
    }

    public static function codex_windows_cli_command( string $url, string $bearer_token, string $site_name = '' ): string {
        $name = self::server_name( $url, $site_name );
        $variable = self::token_variable( $url, $site_name );
        $token = str_replace( [ '`', '"', '$' ], [ '``', '`"', '`$' ], $bearer_token );
        return "\$env:{$variable}=\"{$token}\"\n"
            . "[Environment]::SetEnvironmentVariable(\"{$variable}\",\$env:{$variable},\"User\")\n"
            . 'codex mcp add ' . $name . ' --url "'
            . self::shell_url_double( $url )
            . '" --bearer-token-env-var ' . $variable;
    }

    public static function codex_posix_cli_command( string $url, string $bearer_token, string $site_name = '' ): string {
        $name = self::server_name( $url, $site_name );
        $variable = self::token_variable( $url, $site_name );
        $token = str_replace( [ '\\', '"', '$', '`' ], [ '\\\\', '\\"', '\\$', '\\`' ], $bearer_token );
        return "export {$variable}=\"{$token}\"\n"
            . 'codex mcp add ' . $name . ' --url "'
            . self::shell_url_double( $url )
            . '" --bearer-token-env-var ' . $variable;
    }

    private static function token_variable( string $url, string $site_name ): string {
        // Duplicate site titles must never make two endpoints share a credential variable.
        return strtoupper( str_replace( '-', '_', self::server_name( $url, $site_name ) ) )
            . '_MCP_' . strtoupper( substr( hash( 'sha256', $url ), 0, 12 ) ) . '_TOKEN';
    }

    public static function cursor_install_url( string $url, string $authorization, string $site_name = '' ): string {
        $config = [ 'url' => $url, 'headers' => [ 'Authorization' => $authorization ] ];

        // Keep credentials on the device: use the app protocol, never a hosted redirect URL.
        return 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . rawurlencode( self::server_name( $url, $site_name ) ) . '&config='
            . rawurlencode( base64_encode( self::encode( $config ) ) );
    }

    public static function build( string $client, string $url, string $login, string $password, string $site_name = '' ): string {
        if ( ! isset( self::CLIENTS[ $client ] ) ) {
            throw new \InvalidArgumentException( 'Unsupported MCP client.' );
        }

        $authorization = 'Basic ' . base64_encode( $login . ':' . $password );

        if ( $client === 'claude' ) {
            return self::claude_cli_command( $url, $authorization, $site_name );
        }

        return self::file_config( $client, $url, $authorization, $site_name );
    }

    public static function file_config( string $client, string $url, string $authorization, string $site_name = '' ): string {
        if ( ! isset( self::CLIENTS[ $client ] ) ) {
            throw new \InvalidArgumentException( 'Unsupported MCP client.' );
        }

        $name = self::server_name( $url, $site_name );
        if ( $client === 'codex' ) {
            return "[mcp_servers.{$name}]\nurl = " . self::encode( $url )
                . "\nhttp_headers = { Authorization = " . self::encode( $authorization ) . " }\n";
        }

        if ( $client === 'cursor' ) {
            return self::encode( [
                'mcpServers' => [
                    $name => [ 'url' => $url, 'headers' => [ 'Authorization' => $authorization ] ],
                ],
            ], JSON_PRETTY_PRINT ) . "\n";
        }

        return self::encode( [
            'mcpServers' => [
                $name => [
                    'type'    => 'http',
                    'url'     => $url,
                    'headers' => [ 'Authorization' => $authorization ],
                ],
            ],
        ], JSON_PRETTY_PRINT ) . "\n";
    }

    /**
     * Name and URL BEFORE --header: the option takes several values (`--header <header...>`), so
     * placed first it swallows the server name and the URL and the CLI answers "missing required
     * argument 'name'". Double quotes, because cmd.exe — the default Windows terminal — does not
     * treat single quotes as quoting; PowerShell and POSIX shells accept double quotes too.
     */
    private static function claude_cli_command( string $url, string $authorization, string $site_name ): string {
        return 'claude mcp add --transport http --scope user ' . self::server_name( $url, $site_name ) . ' "' . self::shell_url_double( $url )
            . '" --header "Authorization: ' . $authorization . '"';
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
