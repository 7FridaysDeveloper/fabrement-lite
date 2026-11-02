<?php
/**
 * OAuth state for the client on screen, shared by the Recommended guide (mcp-oauth.php)
 * and the Advanced OAuth options (mcp-oauth-advanced.php).
 *
 * @var string $fabrement_mcp_client The AI client the screen shows: a McpConnectionConfig::OAUTH_CLIENTS key,
 *                                   set by templates/pages/mcp-connection.php.
 */
defined( 'ABSPATH' ) || exit;

$fabrement_oauth_enabled       = \Fabrement\Modules\Mcp\OAuth\Config::enabled();
$fabrement_oauth_https         = \Fabrement\Modules\Mcp\OAuth\Config::https();
$fabrement_oauth_storage_ready = ( new \Fabrement\Modules\Mcp\OAuth\Store() )->ready();
$fabrement_oauth_clients       = \Fabrement\Modules\Mcp\McpConnectionConfig::OAUTH_CLIENTS;
$fabrement_oauth_client        = $fabrement_mcp_client;
$fabrement_oauth_setup = $fabrement_oauth_enabled
    ? \Fabrement\Modules\Mcp\McpConnectionConfig::oauth_connection( $fabrement_oauth_client, \Fabrement\Modules\Mcp\OAuth\Config::resource(), get_bloginfo( 'name' ) ) : null;
