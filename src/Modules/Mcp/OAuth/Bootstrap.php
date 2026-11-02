<?php

namespace Fabrement\Modules\Mcp\OAuth;

defined( 'ABSPATH' ) || exit;

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
        // The well-known metadata addresses: rewrite rules to a query var, served before the main query.
        add_action( 'init', [ self::class, 'discovery_rules' ] );
        add_filter( 'query_vars', [ self::class, 'query_vars' ] );
        add_action( 'parse_request', [ self::class, 'discovery' ], 0 );
        // A refusal of the OAuth endpoints, whoever answers it, in OAuth's own shape.
        add_filter( 'rest_post_dispatch', [ self::class, 'oauth_error_shape' ], 20, 3 );
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
        // After core's cookie (10) and application-password (20) checks, as a login of its own.
        add_filter( 'determine_current_user', [ self::class, 'determine_user' ], 30 );
        // The MCP endpoint takes only the plugin's OAuth tokens, never an application password.
        add_filter( 'application_password_is_api_request', [ self::class, 'refuse_application_password' ] );
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
        foreach ( [ 'metadata', 'resource', 'authorize', 'register', 'token', 'revoke' ] as $name ) {
            register_rest_route( Config::NAMESPACE, '/' . $name, [
                'methods' => in_array( $name, [ 'metadata', 'resource', 'authorize' ], true ) ? 'GET' : 'POST',
                // Open to clients by the OAuth standard (RFC 8414, 7591, 6749, 7009): a client calls them
                // before it has a user. Closed while OAuth is off; rate limited; a token is issued only
                // for a code a signed-in administrator approved (authorize() below).
                'permission_callback' => [ self::class, 'oauth_available' ],
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
            if ( 'authorize' === $name ) {
                return self::keep_authorization( $request );
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
            // WordPress has parsed and unslashed the form; only the parameters OAuth defines are taken.
            $input = self::fields( (array) $request->get_body_params(), self::TOKEN_FIELDS );
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

    /** What the token and revocation endpoints read (RFC 6749, 7009, 8707). */
    private const TOKEN_FIELDS = [ 'grant_type', 'code', 'refresh_token', 'code_verifier', 'redirect_uri', 'client_id',
        'client_secret', 'scope', 'resource', 'token', 'token_type_hint' ];

    /** What an authorization request carries (RFC 6749 4.1.1, 7636, 8707). */
    private const AUTHORIZE_FIELDS = [ 'client_id', 'redirect_uri', 'response_type', 'scope', 'state',
        'code_challenge', 'code_challenge_method', 'resource' ];

    /**
     * The authorization endpoint (RFC 6749 4.1.1): the external client sends the user's browser here.
     * The request is checked and kept for 10 minutes, then the browser goes on to the consent page,
     * where a signed-in administrator allows or cancels it through a form with a nonce.
     */
    private static function keep_authorization( \WP_REST_Request $request ): \WP_REST_Response {
        $store = new Store();
        self::throttle( $store, 'authorize', 60 );
        $authorization = ( new Protocol( $store ) )->authorization( self::fields( (array) $request->get_query_params(), self::AUTHORIZE_FIELDS ) );
        if ( ! Clients::allowed_redirect( $authorization['redirect_uri'] ) ) {
            throw new OAuthError( 'invalid_request', 'Unsupported callback.' );
        }
        $pending = 'fabrement_pending_' . bin2hex( random_bytes( 32 ) );
        $store->put( 'pending', $pending, $authorization, time() + 600 );
        return new \WP_REST_Response( null, 302, [ 'Location' => Config::consent_url( $pending ), 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer' ] );
    }

    /**
     * The named parameters, checked and never altered, since OAuth compares them exactly: each must
     * be a string of printable ASCII (RFC 6749 allows 0x20-0x7E in its values), at most 4096 bytes.
     * The per-field rules are Protocol's.
     *
     * @param array<string,mixed> $source  A REST request's parsed params (already unslashed).
     * @param list<string>        $keys
     * @return array<string,string>
     */
    public static function fields( array $source, array $keys ): array {
        $params = [];
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $source ) ) {
                continue;
            }
            if ( ! is_string( $source[ $key ] ) ) {
                throw new OAuthError( 'invalid_request', 'Invalid ' . esc_html( $key ) . '.' );
            }
            $value = $source[ $key ];
            if ( strlen( $value ) > 4096 || ! preg_match( '/\A[ -~]*\z/D', $value ) ) {
                throw new OAuthError( 'invalid_request', 'Invalid ' . esc_html( $key ) . '.' );
            }
            $params[ $key ] = $value;
        }
        return $params;
    }

    /** The query var the well-known rewrite rules set: which metadata the address asks for. */
    public const META_VAR = 'fabrement_oauth_meta';

    /**
     * The OAuth endpoints are open to clients while an administrator has OAuth on, and closed otherwise.
     *
     * @return true|\WP_Error
     */
    public static function oauth_available() {
        try {
            self::available();
            return true;
        } catch ( OAuthError $error ) {
            return new \WP_Error( $error->error, $error->getMessage(), [ 'status' => $error->status ] );
        }
    }

    /**
     * A refusal of an OAuth endpoint (its permission check included) as RFC 6749 5.2 shapes it.
     *
     * @param mixed $response
     * @return mixed
     */
    public static function oauth_error_shape( $response, $server, \WP_REST_Request $request ) {
        if ( ! $response instanceof \WP_REST_Response || ! $response->is_error() || ! str_starts_with( $request->get_route(), '/' . Config::NAMESPACE . '/' ) ) {
            return $response;
        }
        $data = (array) $response->get_data();
        if ( isset( $data['error'] ) ) {
            return $response;
        }
        $response->set_data( [ 'error' => (string) ( $data['code'] ?? 'invalid_request' ), 'error_description' => (string) ( $data['message'] ?? '' ) ] );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    /**
     * The well-known metadata addresses (RFC 8414, RFC 9728) under the home URL, by kind. Empty where
     * WordPress cannot serve them: in a sub-folder (they sit at the domain root) or with plain permalinks.
     *
     * @return array<string,string>
     */
    private static function well_known(): array {
        if ( ! self::at_domain_root() || '' === (string) get_option( 'permalink_structure' ) ) {
            return [];
        }
        $paths = [ 'authorization-server' => self::authorization_server_path() ];
        if ( ! wp_parse_url( Config::resource(), PHP_URL_QUERY ) ) {
            $paths['protected-resource'] = '.well-known/oauth-protected-resource' . wp_parse_url( Config::resource(), PHP_URL_PATH );
        }
        return $paths;
    }

    private static function at_domain_root(): bool {
        return '' === rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
    }

    /** The authorization-server metadata address (RFC 8414), without the leading slash. */
    private static function authorization_server_path(): string {
        return '.well-known/oauth-authorization-server' . wp_parse_url( Config::issuer(), PHP_URL_PATH );
    }

    /** Rewrite rules for the well-known addresses; flushed once when they change. */
    public static function discovery_rules(): void {
        $paths = self::well_known();
        foreach ( $paths as $kind => $path ) {
            add_rewrite_rule( preg_quote( $path, '#' ) . '/?$', 'index.php?' . self::META_VAR . '=' . $kind, 'top' );
        }
        $signature = md5( (string) wp_json_encode( $paths ) );
        if ( get_option( Keys::OPTION_MCP_OAUTH_REWRITE ) !== $signature ) {
            flush_rewrite_rules( false );
            // Read on every init: autoloaded.
            update_option( Keys::OPTION_MCP_OAUTH_REWRITE, $signature, true );
        }
    }

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
     */
    public static function query_vars( $vars ): array {
        $vars   = (array) $vars;
        $vars[] = self::META_VAR;
        return $vars;
    }

    /**
     * @param \WP|object $wp WordPress's parsed request: the well-known rewrite rules set META_VAR.
     * With plain permalinks no rewrite rule runs; a server that hands every address to index.php
     * (nginx try_files) still brings the authorization-server address here, matched by its path.
     */
    public static function discovery( $wp ): void {
        $kind = is_object( $wp ) && isset( $wp->query_vars[ self::META_VAR ] ) && is_string( $wp->query_vars[ self::META_VAR ] ) ? $wp->query_vars[ self::META_VAR ] : '';
        if ( '' === $kind && '' === (string) get_option( 'permalink_structure' ) && self::at_domain_root() ) {
            $address = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            $path    = trim( (string) wp_parse_url( $address, PHP_URL_PATH ), '/' );
            if ( '' !== $path && $path === self::authorization_server_path() ) {
                $kind = 'authorization-server';
            }
        }
        if ( ! in_array( $kind, [ 'authorization-server', 'protected-resource' ], true ) ) {
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
        wp_send_json( 'authorization-server' === $kind ? Config::authorization_metadata() : Config::resource_metadata() );
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
        $header = self::authorization_header();
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

    /** application_password_is_api_request: false on the MCP route, untouched elsewhere. */
    public static function refuse_application_password( $is_api_request ) {
        return McpBootstrap::is_mcp_request() ? false : $is_api_request;
    }

    /** The raw Authorization header of this request, sanitized; '' when there is none. */
    private static function authorization_header(): string {
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
        if ( self::is_mcp_route( $route ) ) {
            return $result;
        }
        return new \WP_Error( 'fabrement_oauth_scope', __( 'This access token works only for the Fabrement MCP endpoint.', 'fabrement-lite' ), [ 'status' => 403 ] );
    }

    /**
     * On the MCP route only the plugin's own OAuth access token lets anyone in: a refused token, no
     * login, or a login of any other kind (a browser cookie, another plugin's authentication) is
     * answered before any tool runs.
     */
    public static function authenticate( $result, $server, \WP_REST_Request $request ) {
        if ( ! self::is_mcp_route( $request->get_route() ) || null !== $result ) {
            return $result;
        }
        if ( self::$token_error !== null ) {
            $error = self::$token_error;
            return in_array( $error->error, [ 'access_denied', 'temporarily_unavailable' ], true )
                ? new \WP_Error( 'fabrement_oauth_unavailable', $error->getMessage(), [ 'status' => $error->status ] )
                : self::challenge();
        }
        if ( self::$token_user === 0 || get_current_user_id() !== self::$token_user ) {
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
        if ( ! $response instanceof \WP_REST_Response || ! self::is_mcp_route( $request->get_route() ) ) {
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

    /** WordPress matches REST routes case-insensitively, so this does too. */
    private static function is_mcp_route( string $route ): bool {
        return 0 === strcasecmp( rtrim( $route, '/' ), Config::ROUTE );
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
            if ( 'POST' === $method ) {
                // The consent form approves: its nonce is checked before anything it sent is read.
                check_admin_referer( 'fabrement_mcp_consent' );
                $input = [
                    'pending'  => isset( $_POST['pending'] ) && is_string( $_POST['pending'] ) ? sanitize_text_field( wp_unslash( $_POST['pending'] ) ) : '',
                    'decision' => isset( $_POST['decision'] ) && is_string( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '',
                ];
                if ( '' === Protocol::value( $input, 'pending' ) || ! in_array( Protocol::value( $input, 'decision' ), [ 'allow', 'deny' ], true ) ) {
                    throw new OAuthError( 'invalid_request', esc_html__( 'Start a new authorization request.', 'fabrement-lite' ) );
                }
            } elseif ( 'GET' === $method ) {
                // Only shows the consent page for a request the authorization endpoint kept; nothing is changed here.
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which kept request the consent page shows; approval needs the form's nonce.
                $input = [ 'pending' => isset( $_GET['pending'] ) && is_string( $_GET['pending'] ) ? sanitize_text_field( wp_unslash( $_GET['pending'] ) ) : '' ];
            } else {
                throw new OAuthError( 'invalid_request', esc_html__( 'Unsupported request method.', 'fabrement-lite' ) );
            }
            $pending = Protocol::value( $input, 'pending' );
            if ( ! preg_match( '/\Afabrement_pending_[a-f0-9]{64}\z/D', $pending ) ) {
                throw new OAuthError( 'invalid_request', esc_html__( 'This authorization request has expired. Reconnect your client.', 'fabrement-lite' ) );
            }
            $row = $store->get( 'pending', $pending );
            if ( ! $row || $row['used'] || $row['revoked'] ) {
                throw new OAuthError( 'invalid_request', esc_html__( 'This authorization request has expired. Reconnect your client.', 'fabrement-lite' ) );
            }
            $authorization = $row['payload'];
            if ( ! Clients::allowed_redirect( $authorization['redirect_uri'] ) ) {
                throw new OAuthError( 'invalid_request', esc_html__( 'Unsupported callback.', 'fabrement-lite' ) );
            }
            // Permit the form's redirect to this validated callback, including its ephemeral local port.
            // This header replaces the one above, so it names the stylesheet's origin again.
            header( "Content-Security-Policy: default-src 'none'; style-src " . $styles . "; form-action 'self' "
                . $authorization['redirect_uri'] . "; frame-ancestors 'none'; base-uri 'none'" );
            if ( ! is_user_logged_in() ) {
                wp_safe_redirect( wp_login_url( Config::consent_url( $pending ) ) );
                exit;
            }
            if ( ! Capabilities::can_connect() ) {
                throw new OAuthError( 'access_denied', esc_html__( 'Only Administrators can connect an agent.', 'fabrement-lite' ), 403 );
            }
            if ( 'POST' === $method ) {
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
                // add_query_arg() does not encode: the client's state may hold &, # or quotes.
                // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated by Clients::allowed_redirect() above.
                wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $authorization['redirect_uri'] ), 302, 'Fabrement OAuth' );
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
                $grant = preg_match( '/\A[a-f0-9]{64}\z/D', $family ) ? $store->get( 'grant', $family ) : null;
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

    /**
     * Ends every OAuth connection on the site: a new token generation, and every grant revoked.
     * On deactivation, so an approval given here never carries over to another edition of the
     * plugin or to a later activation without a new consent.
     */
    public static function end_all_connections(): void {
        update_option( Keys::OPTION_MCP_OAUTH_GENERATION, bin2hex( random_bytes( 32 ) ), false );
        $store = new Store();
        if ( $store->ready() ) {
            try {
                $store->revoke_all();
            } catch ( OAuthError $error ) {
                // The new generation already refuses every earlier token.
                \Fabrement\Framework\Log::debug( '[fabrement][oauth] Could not revoke connections on deactivation: ' . $error->getMessage() );
            }
        }
    }

    private static function save_setting( string $name, $value ): void {
        update_option( $name, $value, false );
        $saved = get_option( $name, null );
        if ( null === $saved || (string) $saved !== (string) $value ) {
            throw new OAuthError( 'server_error', esc_html__( 'Could not save the OAuth settings. Please try again.', 'fabrement-lite' ), 500 );
        }
    }
}
