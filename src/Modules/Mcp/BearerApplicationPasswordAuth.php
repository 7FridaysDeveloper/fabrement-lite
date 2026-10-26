<?php

namespace Fabrement\Modules\Mcp;

/**
 * Lets clients that only expose Bearer-token MCP setup use WP Application Passwords.
 */
final class BearerApplicationPasswordAuth {

    public static function register(): void {
        add_filter( 'determine_current_user', [ self::class, 'map_bearer_to_basic_auth' ], 19 );
    }

    public static function map_bearer_to_basic_auth( $user_id ) {
        if ( $user_id || ! self::is_mcp_request() || self::has_basic_auth() ) {
            return $user_id;
        }

        $authorization = self::authorization_header();
        if ( ! is_string( $authorization ) || stripos( $authorization, 'Bearer ' ) !== 0 ) {
            return $user_id;
        }

        $token = trim( substr( $authorization, 7 ) );
        if ( str_starts_with( $token, OAuth\Config::ACCESS_PREFIX ) ) {
            return $user_id;
        }
        $decoded = base64_decode( $token, true );
        if ( ! is_string( $decoded ) || strpos( $decoded, ':' ) === false ) {
            return $user_id;
        }

        [ $login, $password ] = explode( ':', $decoded, 2 );
        if ( $login === '' || $password === '' ) {
            return $user_id;
        }

        $_SERVER['PHP_AUTH_USER'] = $login;
        $_SERVER['PHP_AUTH_PW']   = $password;

        return $user_id;
    }

    private static function has_basic_auth(): bool {
        return isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] )
            && $_SERVER['PHP_AUTH_USER'] !== ''
            && $_SERVER['PHP_AUTH_PW'] !== '';
    }

    private static function authorization_header(): ?string {
        foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
            if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && $_SERVER[ $key ] !== '' ) {
                return $_SERVER[ $key ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- credentials: any cleaning would change them and fail the login.
            }
        }

        return null;
    }

    private static function is_mcp_request(): bool {
        return McpBootstrap::is_mcp_request();
    }
}
