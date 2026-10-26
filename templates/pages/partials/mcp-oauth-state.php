<?php
/**
 * OAuth state for the client on screen, shared by the Recommended guide (mcp-oauth.php)
 * and the Advanced OAuth options (mcp-oauth-advanced.php).
 *
 * @var string $fabrement_mcp_client The AI client the screen shows, when the including template set it.
 */
defined( 'ABSPATH' ) || exit;

$fabrement_oauth_enabled       = \Fabrement\Modules\Mcp\OAuth\Config::enabled();
$fabrement_oauth_https         = \Fabrement\Modules\Mcp\OAuth\Config::https();
$fabrement_oauth_storage_ready = ( new \Fabrement\Modules\Mcp\OAuth\Store() )->ready();
$fabrement_oauth_clients       = \Fabrement\Modules\Mcp\McpConnectionConfig::OAUTH_CLIENTS;
// This query parameter only chooses which public setup information to show.
$fabrement_oauth_client = isset( $_GET['fabrement_oauth_client'] ) && is_string( $_GET['fabrement_oauth_client'] ) ? sanitize_key( wp_unslash( $_GET['fabrement_oauth_client'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; nothing is changed.
if ( ! isset( $fabrement_oauth_clients[ $fabrement_oauth_client ] ) ) {
    $fabrement_oauth_client = '';
}
if ( isset( $fabrement_mcp_client, $fabrement_oauth_clients[ $fabrement_mcp_client ] ) ) {
    $fabrement_oauth_client = $fabrement_mcp_client;
}
$fabrement_oauth_setup = $fabrement_oauth_enabled && $fabrement_oauth_client !== ''
    ? \Fabrement\Modules\Mcp\McpConnectionConfig::oauth_connection( $fabrement_oauth_client, \Fabrement\Modules\Mcp\OAuth\Config::resource(), get_bloginfo( 'name' ) ) : null;
