<?php

namespace Fabrement\Modules\Mcp;

/**
 * Lets clients that only expose Bearer-token MCP setup use WP Application Passwords:
 * `Authorization: Bearer base64(login:password)` on the MCP route.
 *
 * Core's own check runs on it (wp_authenticate_application_password()), so its hooks fire as for
 * Basic auth: McpPasswordScope's checks, the REST status core reads for a 401, the usage record.
 */
final class BearerApplicationPasswordAuth {

    public static function register(): void {
        // Before core's Basic-auth check (20); a user found here makes that one return early.
        add_filter( 'determine_current_user', [ self::class, 'authenticate' ], 19 );
    }

    /** @return int|false|null The user ID, or what came in. */
    public static function authenticate( $user_id ) {
        if ( $user_id || ! self::is_mcp_request() || self::has_basic_auth() || ! wp_is_application_passwords_available() ) {
            return $user_id;
        }

        $authorization = self::authorization_header();
        if ( stripos( $authorization, 'Bearer ' ) !== 0 ) {
            return $user_id;
        }

        $token = trim( substr( $authorization, 7 ) );
        if ( str_starts_with( $token, OAuth\Config::ACCESS_PREFIX ) ) {
            return $user_id;
        }
        $decoded = base64_decode( $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the token format: base64 of login:password.
        if ( ! is_string( $decoded ) || strpos( $decoded, ':' ) === false ) {
            return $user_id;
        }

        [ $login, $password ] = explode( ':', $decoded, 2 );
        if ( $login === '' || $password === '' ) {
            return $user_id;
        }

        $user = wp_authenticate_application_password( null, $login, $password );
        return $user instanceof \WP_User ? $user->ID : $user_id;
    }

    /** Basic credentials are core's to check. */
    private static function has_basic_auth(): bool {
        return ! empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['PHP_AUTH_PW'] );
    }

    /** The raw Authorization header of this request, sanitized; '' when there is none. */
    public static function authorization_header(): string {
        foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
            if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
                $value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                if ( '' !== $value ) {
                    return $value;
                }
            }
        }

        return '';
    }

    private static function is_mcp_request(): bool {
        return McpBootstrap::is_mcp_request();
    }
}
