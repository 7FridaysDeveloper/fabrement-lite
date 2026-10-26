<?php

namespace Fabrement\Modules\Mcp\OAuth;

use Fabrement\Framework\Keys;
use Fabrement\Capabilities;
use Fabrement\Modules\Mcp\McpBootstrap;
use Fabrement\Modules\Mcp\McpConnectionConfig;

final class Bootstrap {
    private static array $previous_users = [];

    public static function register(): void {
        add_action( 'admin_notices', [ Lifecycle::class, 'notice' ] );
        add_action( 'admin_post_fabrement_mcp_oauth_settings', [ self::class, 'settings' ] );
        add_action( 'admin_post_fabrement_mcp_authorize', [ self::class, 'authorize' ] );
        add_action( 'admin_post_nopriv_fabrement_mcp_authorize', [ self::class, 'authorize' ] );
        add_action( 'fabrement_mcp_oauth_cleanup', static function (): void { ( new Store() )->cleanup(); } );
        add_action( 'init', [ self::class, 'discovery' ], 0 );
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
        add_filter( 'rest_request_before_callbacks', [ self::class, 'authenticate' ], 5, 3 );
        add_filter( 'rest_request_after_callbacks', [ self::class, 'restore_user' ], PHP_INT_MAX, 3 );
        add_filter( 'rest_post_dispatch', [ self::class, 'response' ], 20, 3 );
    }

    public static function routes(): void {
        foreach ( [ 'metadata', 'resource', 'register', 'token', 'revoke' ] as $name ) {
            register_rest_route( Config::NAMESPACE, '/' . $name, [
                'methods' => in_array( $name, [ 'metadata', 'resource' ], true ) ? 'GET' : 'POST',
                // Public by the OAuth standard (RFC 8414, 7591, 6749, 7009): a client calls them before it
                // has a user. They do nothing unless an administrator enabled OAuth, are rate limited,
                // and a token is issued only for a code an administrator approved (authorize() below).
                'permission_callback' => '__return_true',
                'callback' => static function ( \WP_REST_Request $request ) use ( $name ): \WP_REST_Response {
                    return self::endpoint( $name, $request );
                },
            ] );
        }
    }

    public static function endpoint( string $name, \WP_REST_Request $request ): \WP_REST_Response {
        try {
            self::available();
            if ( 'metadata' === $name || 'resource' === $name ) {
                return self::json( 'metadata' === $name ? Config::authorization_metadata() : Config::resource_metadata() );
            }
            if ( strlen( $request->get_body() ) > 32768 ) {
                throw new OAuthError( 'invalid_request', 'Request is too large.', 413 );
            }
            $store = new Store();
            self::throttle( $store, $name, 'register' === $name ? 20 : 240 );
            if ( 'register' === $name ) {
                $input = $request->get_json_params();
                if ( ! is_array( $input ) ) {
                    throw new OAuthError( 'invalid_request', 'JSON client metadata is required.' );
                }
                return self::json( ( new Clients( $store ) )->register( $input ), 201 );
            }
            $content_type = strtolower( explode( ';', (string) $request->get_header( 'content-type' ) )[0] );
            if ( 'application/x-www-form-urlencoded' !== trim( $content_type ) ) {
                throw new OAuthError( 'invalid_request', 'Form-encoded parameters are required.' );
            }
            $input = self::form( $request->get_body() );
            if ( Protocol::value( $input, 'client_secret' ) !== '' || (string) $request->get_header( 'authorization' ) !== '' ) {
                throw new OAuthError( 'invalid_client', 'Use a public client with PKCE, without a client secret.' );
            }
            $protocol = new Protocol( $store );
            if ( 'revoke' === $name ) {
                $protocol->revoke( $input );
                return self::json( new \stdClass() );
            }
            return self::json( $protocol->token( $input ) );
        } catch ( OAuthError $error ) {
            return self::json( [ 'error' => $error->error, 'error_description' => $error->getMessage() ], $error->status );
        }
    }

    /** Do not let PHP collapse duplicate or array-valued OAuth parameters. */
    public static function form( string $raw ): array {
        $params = [];
        if ( strlen( $raw ) > 32768 ) {
            throw new OAuthError( 'invalid_request', 'Request is too large.', 413 );
        }
        foreach ( explode( '&', $raw ) as $part ) {
            if ( '' === $part ) {
                continue;
            }
            $pair = explode( '=', $part, 2 );
            $key = urldecode( $pair[0] );
            if ( ! preg_match( '/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $key ) || array_key_exists( $key, $params ) ) {
                throw new OAuthError( 'invalid_request', 'Duplicate or invalid OAuth parameter.' );
            }
            $params[ $key ] = urldecode( $pair[1] ?? '' );
        }
        return $params;
    }

    public static function discovery(): void {
        $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
        $as = '/.well-known/oauth-authorization-server' . wp_parse_url( Config::issuer(), PHP_URL_PATH );
        // Plain permalinks share a path with unrelated resources; discovery then uses the 401 URL only.
        $resource = wp_parse_url( Config::resource(), PHP_URL_QUERY ) ? null
            : '/.well-known/oauth-protected-resource' . wp_parse_url( Config::resource(), PHP_URL_PATH );
        if ( $path !== $as && $path !== $resource ) {
            return;
        }
        nocache_headers();
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
        if ( 'OPTIONS' === $method ) {
            status_header( 204 );
            exit;
        }
        if ( 'GET' !== $method ) {
            wp_send_json( [ 'error' => 'invalid_request' ], 400 );
        }
        try {
            self::available();
        } catch ( OAuthError $error ) {
            wp_send_json( [ 'error' => $error->error, 'error_description' => $error->getMessage() ], $error->status );
        }
        wp_send_json( $path === $as ? Config::authorization_metadata() : Config::resource_metadata() );
    }

    public static function authenticate( $result, $server, \WP_REST_Request $request ) {
        if ( rtrim( $request->get_route(), '/' ) !== Config::ROUTE || null !== $result ) {
            return $result;
        }
        $header = (string) $request->get_header( 'authorization' );
        if ( preg_match( '/\ABearer\s+(' . Config::ACCESS_PREFIX . '\S+)\z/iD', $header, $matches ) ) {
            $request_id = spl_object_id( $request );
            self::$previous_users[ $request_id ][] = get_current_user_id();
            try {
                self::available();
                $user = ( new Protocol( new Store() ) )->authenticate( $matches[1] );
                // Scope this identity to the matched callback, including inside WordPress batches.
                wp_set_current_user( $user );
                return $result;
            } catch ( OAuthError $error ) {
                wp_set_current_user( 0 );
                return in_array( $error->error, [ 'access_denied', 'temporarily_unavailable' ], true )
                    ? new \WP_Error( 'fabrement_oauth_unavailable', $error->getMessage(), [ 'status' => $error->status ] )
                    : self::challenge();
            }
        }
        if ( ! get_current_user_id() ) {
            try {
                self::available();
            } catch ( OAuthError $error ) {
                return new \WP_Error( 'fabrement_oauth_unavailable', $error->getMessage(), [ 'status' => $error->status ] );
            }
            return self::challenge();
        }
        return $result;
    }

    public static function restore_user( $result, $handler, \WP_REST_Request $request ) {
        $request_id = spl_object_id( $request );
        if ( isset( self::$previous_users[ $request_id ] ) ) {
            wp_set_current_user( array_pop( self::$previous_users[ $request_id ] ) );
            if ( ! self::$previous_users[ $request_id ] ) {
                unset( self::$previous_users[ $request_id ] );
            }
        }
        return $result;
    }

    public static function response( $response, $server, \WP_REST_Request $request ) {
        if ( ! $response instanceof \WP_REST_Response || rtrim( $request->get_route(), '/' ) !== Config::ROUTE ) {
            return $response;
        }
        if ( ! Config::enabled() ) {
            return $response;
        }
        $headers = array_change_key_case( $response->get_headers(), CASE_LOWER );
        if ( 401 === $response->get_status() && ! isset( $headers['www-authenticate'] ) ) {
            $response->header( 'WWW-Authenticate', self::challenge_header() );
        }
        $data = $response->get_data();
        $message = is_object( $data ) ? (array) $data : $data;
        $result = is_array( $message ) ? ( $message['result'] ?? null ) : null;
        $fields = is_object( $result ) ? (array) $result : $result;
        if ( is_array( $fields ) && isset( $fields['tools'] ) && is_array( $fields['tools'] ) ) {
            foreach ( $fields['tools'] as &$tool ) {
                $definition = (array) $tool;
                $definition['securitySchemes'] = [ [ 'type' => 'oauth2', 'scopes' => [ Config::SCOPE ] ] ];
                $definition['_meta'] = (array) ( $definition['_meta'] ?? [] );
                $definition['_meta']['securitySchemes'] = $definition['securitySchemes'];
                $tool = is_object( $tool ) ? (object) $definition : $definition;
            }
            $message['result'] = is_object( $result ) ? (object) $fields : $fields;
            $response->set_data( is_object( $data ) ? (object) $message : $message );
        }
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    private static function challenge(): \WP_Error {
        return new \WP_Error( 'fabrement_oauth_required', 'Authenticate to use Fabrement MCP.', [ 'status' => 401 ] );
    }

    private static function challenge_header(): string {
        return 'Bearer resource_metadata="' . Config::endpoint( 'resource' ) . '", scope="' . Config::SCOPE . '"'
            . ', error="invalid_token"';
    }

    private static function json( $data, int $status = 200 ): \WP_REST_Response {
        return new \WP_REST_Response( $data, $status, [ 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache' ] );
    }

    private static function available(): void {
        if ( ! get_option( Keys::OPTION_MCP_OAUTH_ENABLED, false ) ) {
            throw new OAuthError( 'access_denied', esc_html__( 'OAuth is turned off for this site. A site administrator can enable it in Fabrement → MCP Connection.', 'fabrement-lite' ), 403 );
        }
        if ( ! ( new Store() )->ready() ) {
            throw new OAuthError( 'temporarily_unavailable', esc_html__( 'OAuth storage is not ready. Ask a site administrator to deactivate and reactivate Fabrement Lite.', 'fabrement-lite' ), 503 );
        }
        if ( ! Config::https() || ! is_ssl() ) {
            throw new OAuthError( 'access_denied', esc_html__( 'OAuth requires HTTPS. Ask a site administrator to configure HTTPS for this site.', 'fabrement-lite' ), 403 );
        }
    }

    private static function throttle( Store $store, string $action, int $limit ): void {
        // REMOTE_ADDR is intentional: untrusted forwarding headers must not bypass the limit.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
        $ip = '' !== $ip ? $ip : 'unknown';
        $store->throttle( 'global:' . $action, $limit * 10, 3600 );
        $store->throttle( $action . ':' . hash( 'sha256', $ip ), $limit, 3600 );
    }

    public static function authorize(): void {
        nocache_headers();
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Frame-Options: DENY' );
        // The page loads one stylesheet of the plugin's own; named by its origin in case assets are served from another host.
        $assets = wp_parse_url( \Fabrement\Framework\App::url( '' ) );
        $styles = "'self'" . ( ! empty( $assets['host'] ) ? ' ' . ( $assets['scheme'] ?? 'https' ) . '://' . $assets['host'] . ( isset( $assets['port'] ) ? ':' . $assets['port'] : '' ) : '' );
        header( "Content-Security-Policy: default-src 'none'; style-src " . $styles . "; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" );
        try {
            self::available();
            $store = new Store();
            $protocol = new Protocol( $store );
            $method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
            if ( ! in_array( $method, [ 'GET', 'POST' ], true ) ) {
                throw new OAuthError( 'invalid_request', esc_html__( 'Unsupported request method.', 'fabrement-lite' ) );
            }
            $input = self::form( 'POST' === $method ? (string) file_get_contents( 'php://input' ) : (string) ( $_SERVER['QUERY_STRING'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- OAuth parameters must reach Protocol exactly as sent; Protocol validates them.
            // GET only keeps the client's request for 10 minutes and shows the consent page; the POST that approves is checked
            // below: a logged-in administrator (current_user_can) and the consent form's nonce.
            $pending = Protocol::value( $input, 'pending' );
            if ( '' === $pending ) {
                if ( 'GET' !== $method ) {
                    throw new OAuthError( 'invalid_request', esc_html__( 'Start a new authorization request.', 'fabrement-lite' ) );
                }
                self::throttle( $store, 'authorize', 60 );
                $authorization = $protocol->authorization( $input );
                $pending = 'fb_pending_' . bin2hex( random_bytes( 32 ) );
                $store->put( 'pending', $pending, $authorization, time() + 600 );
            } else {
                $row = $store->get( 'pending', $pending );
                if ( ! $row || $row['used'] || $row['revoked'] ) {
                    throw new OAuthError( 'invalid_request', esc_html__( 'This authorization request has expired. Reconnect your client.', 'fabrement-lite' ) );
                }
                $authorization = $row['payload'];
            }
            if ( ! Clients::allowed_redirect( $authorization['redirect_uri'] ) ) {
                throw new OAuthError( 'invalid_request', esc_html__( 'Unsupported callback.', 'fabrement-lite' ) );
            }
            // Permit the form's redirect to this validated callback, including its ephemeral local port.
            // This header replaces the one above, so it names the stylesheet's origin again.
            header( "Content-Security-Policy: default-src 'none'; style-src " . $styles . "; form-action 'self' "
                . $authorization['redirect_uri'] . "; frame-ancestors 'none'; base-uri 'none'" );
            if ( ! is_user_logged_in() ) {
                $resume = add_query_arg( [ 'action' => 'fabrement_mcp_authorize', 'pending' => $pending ], admin_url( 'admin-post.php' ) );
                wp_safe_redirect( wp_login_url( $resume ) );
                exit;
            }
            if ( ! current_user_can( Capabilities::ADMIN ) ) {
                throw new OAuthError( 'access_denied', esc_html__( 'Only site administrators can authorize Fabrement agents.', 'fabrement-lite' ), 403 );
            }
            if ( 'POST' === $method ) {
                check_admin_referer( 'fabrement_mcp_consent_' . $pending );
                if ( ! $store->consume( 'pending', $pending ) ) {
                    throw new OAuthError( 'invalid_request', esc_html__( 'This authorization request has already been used.', 'fabrement-lite' ) );
                }
                $args = [ 'iss' => Config::issuer() ];
                if ( $authorization['state'] !== '' ) {
                    $args['state'] = $authorization['state'];
                }
                if ( 'allow' === Protocol::value( $input, 'decision' ) ) {
                    $args['code'] = $protocol->approve( $authorization, get_current_user_id() );
                } else {
                    $args['error'] = 'access_denied';
                }
                // The external callback was checked against the client's exact, allowlisted URI.
                // wp_safe_redirect() would send it to wp-admin: the host is another site's, and
                // wp_validate_redirect() cannot allow the [::1] loopback callback even when told to.
                // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated by Clients::allowed_redirect() above.
                wp_redirect( add_query_arg( $args, $authorization['redirect_uri'] ), 302, 'Fabrement OAuth' );
                exit;
            }
            // A standalone page with no wp_head(): its template prints this one sheet itself.
            $sheet = 'assets/admin/oauth-consent.css';
            wp_register_style( 'fabrement-oauth-consent', \Fabrement\Framework\App::url( $sheet ), [], is_readable( \Fabrement\Framework\App::path( $sheet ) ) ? (string) filemtime( \Fabrement\Framework\App::path( $sheet ) ) : false );
            require dirname( __DIR__, 4 ) . '/templates/mcp-oauth-consent.php';
            exit;
        } catch ( OAuthError $error ) {
            // Never redirect malformed requests to an unverified callback.
            wp_die( esc_html( $error->getMessage() ), esc_html__( 'Fabrement authorization', 'fabrement-lite' ), [ 'response' => (int) $error->status ] );
        }
    }

    public static function settings(): void {
        if ( ! current_user_can( Capabilities::ADMIN ) ) {
            wp_die( esc_html__( 'Administrator access is required.', 'fabrement-lite' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'fabrement_mcp_oauth_settings' );
        $client = '';
        try {
            $store = new Store();
            $form = wp_unslash( $_POST );
            $command = Protocol::value( $form, 'oauth_command' );
            if ( 'connect' === $command ) {
                $client = Protocol::value( $form, 'oauth_client' );
                if ( ! isset( McpConnectionConfig::OAUTH_CLIENTS[ $client ] ) ) {
                    throw new OAuthError( 'invalid_request', esc_html__( 'Choose ChatGPT, Codex or Claude.', 'fabrement-lite' ) );
                }
                $command = 'enable';
            }
            if ( 'enable' === $command ) {
                if ( ! Config::https() || ! McpBootstrap::is_supported() ) {
                    throw new OAuthError( 'access_denied', esc_html__( 'HTTPS and a compatible MCP Adapter are required.', 'fabrement-lite' ) );
                }
                if ( ! $store->ready() ) {
                    throw new OAuthError( 'temporarily_unavailable', esc_html__( 'OAuth storage is not ready. Deactivate and reactivate Fabrement Lite before enabling OAuth.', 'fabrement-lite' ), 503 );
                }
                if ( '' === Config::generation() ) {
                    self::save_setting( Keys::OPTION_MCP_OAUTH_GENERATION, bin2hex( random_bytes( 32 ) ) );
                }
                self::save_setting( Keys::OPTION_MCP_OAUTH_ENABLED, true );
                if ( ! wp_next_scheduled( 'fabrement_mcp_oauth_cleanup' ) ) {
                    wp_schedule_event( time() + 3600, 'daily', 'fabrement_mcp_oauth_cleanup' );
                }
            } elseif ( 'disable' === $command ) {
                // Also invalidates grants created by a concurrent request after the revoke query.
                self::save_setting( Keys::OPTION_MCP_OAUTH_GENERATION, bin2hex( random_bytes( 32 ) ) );
                self::save_setting( Keys::OPTION_MCP_OAUTH_ENABLED, false );
                if ( $store->ready() ) {
                    $store->revoke_all();
                }
            } elseif ( 'revoke' === $command ) {
                if ( ! $store->ready() ) {
                    throw new OAuthError( 'temporarily_unavailable', esc_html__( 'OAuth storage is not ready. Deactivate and reactivate Fabrement Lite to repair it.', 'fabrement-lite' ), 503 );
                }
                $family = Protocol::value( $form, 'family' );
                $grant = $store->get( 'grant', $family );
                // Any administrator may revoke any connection, not only their own.
                if ( ! $grant ) {
                    throw new OAuthError( 'invalid_request', esc_html__( 'This connection no longer exists.', 'fabrement-lite' ), 404 );
                }
                $store->revoke( $family );
            } else {
                throw new OAuthError( 'invalid_request', esc_html__( 'Unknown OAuth setting.', 'fabrement-lite' ) );
            }
        } catch ( OAuthError $error ) {
            wp_die( esc_html( $error->getMessage() ), '', [ 'response' => (int) $error->status ] );
        }
        $destination = $client !== ''
            ? add_query_arg( [ 'page' => 'fabrement-credentials', 'fabrement_oauth_client' => $client ], admin_url( 'admin.php' ) )
            : Config::settings_url();
        wp_safe_redirect( $destination . ( $client !== '' ? '#fabrement-oauth' : '#fabrement-access' ) );
        exit;
    }

    private static function save_setting( string $name, $value ): void {
        update_option( $name, $value, false );
        $saved = get_option( $name, null );
        if ( null === $saved || (string) $saved !== (string) $value ) {
            throw new OAuthError( 'server_error', esc_html__( 'Could not save the OAuth settings. Please try again.', 'fabrement-lite' ), 500 );
        }
    }
}
