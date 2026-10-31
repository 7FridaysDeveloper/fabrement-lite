<?php

namespace Fabrement\Modules\Mcp\OAuth;

use Fabrement\Framework\Keys;
use Fabrement\Capabilities;
use Fabrement\Modules\Mcp\McpBootstrap;
use Fabrement\Modules\Mcp\McpConnectionConfig;

final class Bootstrap {
    /** Why the access token of this request was refused, for the 401/403 the route answers with. */
    private static ?OAuthError $token_error = null;

    /** Guards determine_current_user against itself while a token is checked. */
    private static bool $resolving = false;

    /** The user an access token signed in on this request (0: none). */
    private static int $token_user = 0;

    public static function register(): void {
        add_action( 'admin_notices', [ Lifecycle::class, 'notice' ] );
        add_action( 'admin_post_fabrement_mcp_oauth_settings', [ self::class, 'settings' ] );
        add_action( 'admin_post_fabrement_mcp_authorize', [ self::class, 'authorize' ] );
        add_action( 'admin_post_nopriv_fabrement_mcp_authorize', [ self::class, 'authorize' ] );
        add_action( 'fabrement_mcp_oauth_cleanup', static function (): void { ( new Store() )->cleanup(); } );
        // A changed or reset password ends the user's OAuth connections.
        add_action( 'after_password_reset', [ self::class, 'password_reset' ] );
        add_action( 'wp_set_password', [ self::class, 'password_set' ], 10, 3 );
        add_action( 'profile_update', [ self::class, 'profile_updated' ], 10, 2 );
        add_action( 'init', [ self::class, 'discovery' ], 0 );
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
        // After core's cookie (10) and application-password (20) checks, as a login of its own.
        add_filter( 'determine_current_user', [ self::class, 'determine_user' ], 30 );
        // After core's own checks (cookie, application password).
        add_filter( 'rest_authentication_errors', [ self::class, 'refuse_other_route' ], 100 );
        add_filter( 'rest_request_before_callbacks', [ self::class, 'authenticate' ], 5, 3 );
        add_filter( 'rest_post_dispatch', [ self::class, 'response' ], 20, 3 );
    }

    public static function password_reset( $user ): void {
        if ( $user instanceof \WP_User ) {
            self::revoke_user( $user->ID );
        }
    }

    public static function password_set( $password, $user_id, $old_user_data = null ): void {
        // Core rehashes the same password on login (6.8+): not a change.
        if ( $old_user_data instanceof \WP_User && wp_check_password( (string) $password, $old_user_data->user_pass, (int) $user_id ) ) {
            return;
        }
        self::revoke_user( (int) $user_id );
    }

    /** A hash saved through wp_insert_user() fires no wp_set_password: compare the stored hashes. */
    public static function profile_updated( $user_id, $old_user ): void {
        $user = get_userdata( (int) $user_id );
        if ( $user && $old_user instanceof \WP_User && $old_user->user_pass !== $user->user_pass ) {
            self::revoke_user( (int) $user_id );
        }
    }

    public static function revoke_user( int $user_id, ?Store $store = null ): void {
        $store = $store ?? new Store();
        if ( $user_id > 0 && $store->ready() ) {
            // A failed write must not break the password change or the login that triggered it.
            try {
                $store->revoke_user( $user_id );
            } catch ( OAuthError $error ) {
                \Fabrement\Framework\Log::debug( '[fabrement][oauth] Could not revoke the connections of user ' . $user_id . ': ' . $error->getMessage() );
            }
        }
    }

    public static function routes(): void {
        foreach ( [ 'metadata', 'resource', 'register', 'token', 'revoke' ] as $name ) {
            register_rest_route( Config::NAMESPACE, '/' . $name, [
                'methods' => in_array( $name, [ 'metadata', 'resource' ], true ) ? 'GET' : 'POST',
                // Public by the OAuth standard (RFC 8414, 7591, 6749, 7009): a client calls them before it
                // has a user. They do nothing unless an administrator enabled OAuth, are rate limited,
                // and a token is issued only for a code a signed-in user approved (authorize() below).
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

    /**
     * determine_current_user: an access token on the MCP route signs its owner in, the way core
     * signs in an application password. Not on any other route, never over a user already found.
     *
     * @param int|false $user_id
     * @return int|false
     */
    public static function determine_user( $user_id ) {
        // Only REST requests, as core does for application passwords.
        if ( $user_id || self::$resolving || ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! McpBootstrap::is_mcp_request() ) {
            return $user_id;
        }
        $header = \Fabrement\Modules\Mcp\BearerApplicationPasswordAuth::authorization_header();
        if ( ! preg_match( '/\ABearer\s+(' . Config::ACCESS_PREFIX . '\S+)\z/iD', $header, $matches ) ) {
            return $user_id;
        }
        self::$resolving = true;
        try {
            self::available();
            self::$token_user = ( new Protocol( new Store() ) )->authenticate( $matches[1] );
            return self::$token_user;
        } catch ( OAuthError $error ) {
            self::$token_error = $error;
            return $user_id;
        } finally {
            self::$resolving = false;
        }
    }

    /**
     * At dispatch: an access token opens the MCP route only. Checked against the route WordPress
     * actually serves, which the address alone does not decide (a POSTed `rest_route`, /batch/v1).
     */
    public static function refuse_other_route( $result ) {
        // The user first: it runs determine_current_user, which is what sets $token_user.
        $user_id = get_current_user_id();
        if ( is_wp_error( $result ) || self::$token_user === 0 || $user_id !== self::$token_user ) {
            return $result;
        }
        // What rest_api_loaded() hands to the server.
        $route = rtrim( (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' ), '/' );
        if ( Config::ROUTE === $route ) {
            return $result;
        }
        return new \WP_Error( 'fabrement_oauth_scope', __( 'This access token works only for the Fabrement MCP endpoint.', 'fabrement-lite' ), [ 'status' => 403 ] );
    }

    /** On the MCP route: a refused token, or no login at all, is answered before any tool runs. */
    public static function authenticate( $result, $server, \WP_REST_Request $request ) {
        if ( rtrim( $request->get_route(), '/' ) !== Config::ROUTE || null !== $result ) {
            return $result;
        }
        if ( self::$token_error !== null ) {
            $error = self::$token_error;
            return in_array( $error->error, [ 'access_denied', 'temporarily_unavailable' ], true )
                ? new \WP_Error( 'fabrement_oauth_unavailable', $error->getMessage(), [ 'status' => $error->status ] )
                : self::challenge();
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
            // Raw on purpose: OAuth needs the exact values and must refuse a repeated parameter, which $_GET / $_POST
            // would merge. Each value is then checked against a strict format: here and in Protocol::authorization().
            $input = self::form( 'POST' === $method ? (string) file_get_contents( 'php://input' ) : (string) ( $_SERVER['QUERY_STRING'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- OAuth parameters must reach Protocol exactly as sent; Protocol validates them.
            // GET only keeps the client's request for 10 minutes and shows the consent page; the POST that approves is checked
            // below: a logged-in user who may connect (current_user_can) and the consent form's nonce.
            $pending = Protocol::value( $input, 'pending' );
            if ( '' !== $pending && ! preg_match( '/\Afabrement_pending_[a-f0-9]{64}\z/D', $pending ) ) {
                throw new OAuthError( 'invalid_request', esc_html__( 'This authorization request has expired. Reconnect your client.', 'fabrement-lite' ) );
            }
            if ( ! in_array( Protocol::value( $input, 'decision' ), [ '', 'allow', 'deny' ], true ) ) {
                throw new OAuthError( 'invalid_request', esc_html__( 'Start a new authorization request.', 'fabrement-lite' ) );
            }
            if ( '' === $pending ) {
                if ( 'GET' !== $method ) {
                    throw new OAuthError( 'invalid_request', esc_html__( 'Start a new authorization request.', 'fabrement-lite' ) );
                }
                self::throttle( $store, 'authorize', 60 );
                $authorization = $protocol->authorization( $input );
                $pending = 'fabrement_pending_' . bin2hex( random_bytes( 32 ) );
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
            if ( ! Capabilities::can_connect() ) {
                throw new OAuthError( 'access_denied', esc_html__( 'Only Administrators can connect an agent.', 'fabrement-lite' ), 403 );
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
        if ( ! Capabilities::can_connect() ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'fabrement-lite' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'fabrement_mcp_oauth_settings' );
        $client = '';
        try {
            $store = new Store();
            // Only the fields this form sends; the nonce was checked above.
            $form = [
                'oauth_command' => isset( $_POST['oauth_command'] ) && is_string( $_POST['oauth_command'] ) ? sanitize_key( wp_unslash( $_POST['oauth_command'] ) ) : '',
                'oauth_client' => isset( $_POST['oauth_client'] ) && is_string( $_POST['oauth_client'] ) ? sanitize_key( wp_unslash( $_POST['oauth_client'] ) ) : '',
                'family' => isset( $_POST['family'] ) && is_string( $_POST['family'] ) ? sanitize_text_field( wp_unslash( $_POST['family'] ) ) : '',
            ];
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
