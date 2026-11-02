<?php

// Template tests use synthetic values only and never load wp-load.php or a database.
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function esc_url( $text, $protocols = null ) { return esc_html( $text ); }
function esc_textarea( $text ) { return esc_html( $text ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="synthetic-nonce">'; }

function fabrement_render_connection_access( array $oauth_grants = [], bool $enabled = true, bool $https = true, bool $storage_ready = true, array $caps = [ 'manage_options', 'edit_posts', 'edit_others_posts' ] ): string {
    \Fabrement\Tests\WordPressState::reset();
    \Fabrement\Tests\WordPressState::select_user( 12, $caps );
    \Fabrement\Tests\WordPressState::$options[ \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_SCHEMA ] = \Fabrement\Modules\Mcp\OAuth\Store::SCHEMA_VERSION;
    $GLOBALS['wpdb']->table_exists = $storage_ready;
    \Fabrement\Tests\WordPressState::$options[ \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_ENABLED ] = $enabled;
    if ( ! $https ) { \Fabrement\Tests\WordPressState::$options['home'] = 'http://example.test'; }
    // The name the including template sets before it requires the partial.
    $fabrement_oauth_grants = $oauth_grants;
    ob_start();
    try {
        require dirname( __DIR__, 2 ) . '/templates/pages/partials/mcp-access.php';
        return ob_get_contents();
    } finally { ob_end_clean(); }
}

/** Render the MCP Connection screen for one client; $setup is the ?fabrement_setup= tab asked for. */
function fabrement_render_connection_settings( string $client, bool $https = true, string $setup = '' ): string {
    \Fabrement\Tests\WordPressState::reset();
    \Fabrement\Tests\WordPressState::$options[ \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_SCHEMA ] = \Fabrement\Modules\Mcp\OAuth\Store::SCHEMA_VERSION;
    \Fabrement\Tests\WordPressState::select_user( 12, [ 'manage_options', 'edit_posts', 'edit_others_posts' ] );
    \Fabrement\Tests\WordPressState::$options[ \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_ENABLED ] = true;
    if ( ! $https ) { \Fabrement\Tests\WordPressState::$options['home'] = 'http://example.test'; }
    $query = $_GET;
    $_GET = [ 'fabrement_oauth_client' => $client ] + ( $setup !== '' ? [ 'fabrement_setup' => $setup ] : [] );
    $page = null; // The navigation reads it; the screen itself needs nothing from the page object.
    ob_start();
    try {
        require dirname( __DIR__, 2 ) . '/templates/pages/mcp-connection.php';
        return ob_get_contents();
    } finally {
        ob_end_clean();
        $_GET = $query;
    }
}
