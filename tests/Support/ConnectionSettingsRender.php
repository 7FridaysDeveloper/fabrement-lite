<?php

// Template tests use synthetic values only and never load wp-load.php or a database.
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function esc_url( $text, $protocols = null ) { return esc_html( $text ); }
function esc_textarea( $text ) { return esc_html( $text ); }
function esc_js( $text ) { return addslashes( $text ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="synthetic-nonce">'; }
function wp_nonce_url( $url, $action ) { return $url; }

function fabrement_render_connection_access( array $oauth_grants = [], array $mcp_passwords = [], bool $enabled = true, bool $https = true, bool $storage_ready = true ): string {
    \Fabrement\Tests\WordPressState::reset();
    \Fabrement\Tests\WordPressState::$options[ \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_SCHEMA ] = \Fabrement\Modules\Mcp\OAuth\Store::SCHEMA_VERSION;
    $GLOBALS['wpdb']->table_exists = $storage_ready;
    \Fabrement\Tests\WordPressState::$options[ \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_ENABLED ] = $enabled;
    if ( ! $https ) { \Fabrement\Tests\WordPressState::$options['home'] = 'http://example.test'; }
    // The names the including template sets before it requires the partial.
    $fabrement_oauth_grants  = $oauth_grants;
    $fabrement_mcp_passwords = $mcp_passwords;
    ob_start();
    try {
        require dirname( __DIR__, 2 ) . '/templates/pages/partials/mcp-access.php';
        return ob_get_contents();
    } finally { ob_end_clean(); }
}

/** Render the MCP Connection screen for one client. */
function fabrement_render_connection_settings( string $client, bool $password = false, bool $https = true ): string {
    \Fabrement\Tests\WordPressState::reset();
    \Fabrement\Tests\WordPressState::$options[ \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_SCHEMA ] = \Fabrement\Modules\Mcp\OAuth\Store::SCHEMA_VERSION;
    \Fabrement\Tests\WordPressState::select_user( 12, [ 'manage_options' ] );
    \Fabrement\Tests\WordPressState::$options[ \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_ENABLED ] = true;
    if ( ! $https ) { \Fabrement\Tests\WordPressState::$options['home'] = 'http://example.test'; }
    $query = $_GET;
    $_GET = [ 'fabrement_oauth_client' => $client ];
    $page = new class( $client, $password ) {
        private string $client;
        private bool $password;
        public function __construct( string $client, bool $password ) {
            $this->client = $client;
            $this->password = $password;
        }
        public function get_mcp_passwords(): array { return []; }
        public function consume_mcp_connection(): ?array {
            return $this->password ? \Fabrement\Modules\Mcp\McpConnectionConfig::connection(
                $this->client, 'https://example.test/wp-json/fabrement/v1/mcp', 'example', 'SYNTHETIC_NOT_A_REAL_PASSWORD', get_bloginfo( 'name' )
            ) : null;
        }
    };
    ob_start();
    try {
        require dirname( __DIR__, 2 ) . '/templates/pages/mcp-connection.php';
        return ob_get_contents();
    } finally {
        ob_end_clean();
        $_GET = $query;
    }
}
