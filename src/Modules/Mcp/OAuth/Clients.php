<?php

namespace Fabrement\Modules\Mcp\OAuth;

class Clients {
    private Store $store;

    public function __construct( Store $store ) {
        $this->store = $store;
    }

    public function resolve( string $id ): array {
        if ( strlen( $id ) > 512 ) {
            throw new OAuthError( 'invalid_client', 'Invalid client ID.' );
        }
        $saved = $this->store->get( 'client', $id );
        // Client rows are never revoked: only a connection's family is, and clients have none.
        if ( $saved ) {
            return $saved['payload'];
        }
        // Only these providers are fetched, without redirects, cookies or credentials.
        $url = wp_parse_url( $id );
        if ( ! is_array( $url ) || ( $url['scheme'] ?? '' ) !== 'https'
            || ! in_array( $url['host'] ?? '', [ 'chatgpt.com', 'claude.ai' ], true )
            || isset( $url['port'] ) || isset( $url['user'] ) || isset( $url['pass'] )
            || isset( $url['fragment'] ) || isset( $url['query'] ) || empty( $url['path'] ) || '/' === $url['path'] ) {
            throw new OAuthError( 'invalid_client', 'This OAuth client is not supported.' );
        }
        $response = wp_safe_remote_get( $id, [ 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 32768,
            'headers' => [ 'Accept' => 'application/json' ], 'cookies' => [] ] );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            throw new OAuthError( 'invalid_client', 'Client metadata could not be verified.' );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || ( $data['client_id'] ?? null ) !== $id ) {
            throw new OAuthError( 'invalid_client', 'Client metadata does not match the client ID.' );
        }
        return $this->validate( $data, true );
    }

    public function register( array $data ): array {
        $client = $this->validate( $data );
        $client['client_id'] = 'fb_client_' . bin2hex( random_bytes( 24 ) );
        $client['client_id_issued_at'] = time();
        $client['grant_types'] = [ 'authorization_code', 'refresh_token' ];
        $client['response_types'] = [ 'code' ];
        $this->store->put( 'client', $client['client_id'], $client, time() + 365 * 86400 );
        return $client;
    }

    private function validate( array $data, bool $metadata_document = false ): array {
        $methods = $data['token_endpoint_auth_methods_supported'] ?? [ $data['token_endpoint_auth_method'] ?? 'none' ];
        if ( ! is_array( $methods ) || ! in_array( 'none', $methods, true ) ) {
            throw new OAuthError( 'invalid_client_metadata', 'Only public clients with PKCE are supported.' );
        }
        foreach ( [ 'grant_types' => [ 'authorization_code', 'refresh_token' ], 'response_types' => [ 'code' ] ] as $field => $allowed ) {
            if ( ! array_key_exists( $field, $data ) ) {
                continue;
            }
            $types = $data[ $field ];
            if ( ! is_array( $types ) || ! $types || count( array_filter( $types, 'is_string' ) ) !== count( $types )
                || in_array( '', $types, true ) ) {
                throw new OAuthError( 'invalid_client_metadata', 'Unsupported grant or response type.' );
            }
            // A public metadata document describes capabilities across servers, not a request to enable them here.
            if ( $metadata_document ? ! in_array( $allowed[0], $types, true ) : (bool) array_diff( $types, $allowed ) ) {
                throw new OAuthError( 'invalid_client_metadata', 'Unsupported grant or response type.' );
            }
        }
        $redirects = $data['redirect_uris'] ?? null;
        if ( ! is_array( $redirects ) || count( $redirects ) < 1 || count( $redirects ) > 10 ) {
            throw new OAuthError( 'invalid_redirect_uri', 'A supported callback is required.' );
        }
        foreach ( $redirects as $redirect ) {
            if ( ! is_string( $redirect ) || ! self::allowed_redirect( $redirect ) ) {
                throw new OAuthError( 'invalid_redirect_uri', 'Use a supported provider callback or a local MCP client callback.' );
            }
        }
        $name = $data['client_name'] ?? 'MCP client';
        if ( ! is_string( $name ) || strlen( $name ) > 200 ) {
            throw new OAuthError( 'invalid_client_metadata', 'Invalid client name.' );
        }
        return [ 'client_name' => sanitize_text_field( $name ), 'redirect_uris' => array_values( $redirects ),
            'token_endpoint_auth_method' => 'none' ];
    }

    public static function allowed_redirect( string $url ): bool {
        return null !== self::loopback_identity( $url )
            || (bool) preg_match( '~\Ahttps://(?:claude\.ai/api/mcp/auth_callback|chatgpt\.com/(?:connector_platform_oauth_redirect|connector/oauth/[A-Za-z0-9_-]+))\z~D', $url );
    }

    public static function redirect_matches( string $requested, array $registered ): bool {
        if ( ! self::allowed_redirect( $requested ) ) {
            return false;
        }
        $loopback = self::loopback_identity( $requested );
        foreach ( $registered as $uri ) {
            if ( $requested === $uri || ( null !== $loopback && $loopback === self::loopback_identity( $uri ) ) ) {
                return true;
            }
        }
        return false;
    }

    /** RFC 8252 allows only the listener port to vary; never normalize the host or callback path. */
    private static function loopback_identity( string $url ): ?string {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'http'
            || ! in_array( $parts['host'] ?? '', [ '127.0.0.1', '[::1]', 'localhost' ], true )
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
            || ! preg_match( '~\A/callback(?:/[A-Za-z0-9_-]+)?\z~D', $parts['path'] ?? '' )
            || ( isset( $parts['port'] ) && ( $parts['port'] < 1 || $parts['port'] > 65535 ) ) ) {
            return null;
        }
        $origin = 'http://' . $parts['host'];
        $canonical = $origin . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $parts['path'];
        return $canonical === $url ? $origin . $parts['path'] : null;
    }
}
