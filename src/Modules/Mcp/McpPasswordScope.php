<?php

namespace Fabrement\Modules\Mcp;

/**
 * An application password issued for MCP opens the MCP route only: not the rest of the REST API,
 * not XML-RPC. A leaked one cannot reach plugins, users or settings through core endpoints.
 * Other application passwords are not touched.
 */
final class McpPasswordScope {

    /** Name prefix of the passwords the connection page issues (what the Users screen shows). */
    public const PREFIX = 'Fabrement MCP';

    /** Core's `app_id` of every password issued for MCP: what marks it, whatever it is named. */
    public const APP_ID = '57baf1f6-c275-4346-a6eb-ac8b0b15e8d1';

    public static function register(): void {
        add_action( 'wp_authenticate_application_password_errors', [ self::class, 'refuse_outside_mcp' ], 10, 3 );
        // After core's own application password check (90).
        add_filter( 'rest_authentication_errors', [ self::class, 'refuse_other_route' ], 100 );
    }

    /** @param array $item A stored application password, as WP_Application_Passwords returns it. */
    public static function is_mcp_password( array $item ): bool {
        return ( $item['app_id'] ?? '' ) === self::APP_ID;
    }

    /**
     * At login: the request's address is not the MCP route (another REST path), or it is XML-RPC,
     * which runs whatever `?rest_route=` the address carries.
     */
    public static function refuse_outside_mcp( \WP_Error $error, $user, array $item ): void {
        if ( self::is_mcp_password( $item ) && ( self::is_xmlrpc() || ! McpBootstrap::is_mcp_request() ) ) {
            $error->add( 'fabrement_mcp_password_scope', __( 'This application password works only for the Fabrement MCP endpoint.', 'fabrement-lite' ) );
        }
    }

    private static function is_xmlrpc(): bool {
        return defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST;
    }

    /**
     * At dispatch: the route WordPress actually serves, which the address alone does not decide
     * (a `rest_route` field, /batch/v1 running other routes). Checked once, for the request
     * itself: rest_do_request() calls made while an MCP tool runs are not refused.
     */
    public static function refuse_other_route( $result ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $uuid = rest_get_authenticated_app_password();
        if ( ! is_string( $uuid ) || '' === $uuid ) {
            return $result;
        }
        $item = \WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
        if ( ! is_array( $item ) || ! self::is_mcp_password( $item ) ) {
            return $result;
        }
        // What rest_api_loaded() hands to the server.
        $route = rtrim( (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' ), '/' );
        if ( OAuth\Config::ROUTE === $route ) {
            return $result;
        }

        return new \WP_Error( 'fabrement_mcp_password_scope', __( 'This application password works only for the Fabrement MCP endpoint.', 'fabrement-lite' ), [ 'status' => 403 ] );
    }
}
