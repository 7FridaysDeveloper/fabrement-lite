<?php

namespace Fabrement\Modules\Mcp\OAuth;

use Fabrement\Framework\Keys;

final class Config {
    public const SCOPE = 'fabrement:mcp';
    public const ROUTE = '/fabrement/v1/mcp';
    public const NAMESPACE = 'fabrement-oauth/v1';
    public const ACCESS_PREFIX = 'fb_oauth_at_';

    public static function resource(): string {
        return rest_url( ltrim( self::ROUTE, '/' ) );
    }

    public static function issuer(): string {
        return home_url( '/fabrement/oauth' );
    }

    public static function endpoint( string $name ): string {
        return rest_url( self::NAMESPACE . '/' . $name );
    }

    public static function authorize_url(): string {
        return add_query_arg( [ 'action' => 'fabrement_mcp_authorize' ], admin_url( 'admin-post.php' ) );
    }

    public static function enabled(): bool {
        return (bool) get_option( Keys::OPTION_MCP_OAUTH_ENABLED, false ) && self::https() && ( new Store() )->ready();
    }

    public static function generation(): string {
        return (string) get_option( Keys::OPTION_MCP_OAUTH_GENERATION, '' );
    }

    public static function https(): bool {
        foreach ( [ self::issuer(), self::resource(), self::authorize_url() ] as $url ) {
            if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
                return false;
            }
        }
        return true;
    }

    /** The MCP Connection screen. */
    public static function settings_url(): string {
        return admin_url( 'admin.php?page=fabrement-credentials' );
    }

    public static function authorization_metadata(): array {
        return [
            'issuer' => self::issuer(),
            'authorization_endpoint' => self::authorize_url(),
            'token_endpoint' => self::endpoint( 'token' ),
            'registration_endpoint' => self::endpoint( 'register' ),
            'revocation_endpoint' => self::endpoint( 'revoke' ),
            'response_types_supported' => [ 'code' ],
            'grant_types_supported' => [ 'authorization_code', 'refresh_token' ],
            'token_endpoint_auth_methods_supported' => [ 'none' ],
            'revocation_endpoint_auth_methods_supported' => [ 'none' ],
            'code_challenge_methods_supported' => [ 'S256' ],
            'scopes_supported' => [ self::SCOPE ],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    public static function resource_metadata(): array {
        return [
            'resource' => self::resource(),
            'authorization_servers' => [ self::issuer() ],
            'scopes_supported' => [ self::SCOPE ],
            'bearer_methods_supported' => [ 'header' ],
        ];
    }
}
