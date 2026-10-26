<?php

namespace Fabrement\Modules\Mcp\OAuth;

use Fabrement\Capabilities;

final class Protocol {
    private Store $store;
    private Clients $clients;

    public function __construct( Store $store, ?Clients $clients = null ) {
        $this->store = $store;
        $this->clients = $clients ?? new Clients( $store );
    }

    public static function value( array $input, string $key, string $default = '' ): string {
        $value = $input[ $key ] ?? $default;
        if ( ! is_string( $value ) || strlen( $value ) > 4096 ) {
            throw new OAuthError( 'invalid_request', 'Invalid ' . esc_html( $key ) . '.' );
        }
        return $value;
    }

    public function authorization( array $input ): array {
        $id = self::value( $input, 'client_id' );
        $client = $this->clients->resolve( $id );
        $redirect = self::value( $input, 'redirect_uri' );
        if ( ! Clients::redirect_matches( $redirect, $client['redirect_uris'] ) ) {
            throw new OAuthError( 'invalid_request', 'Callback does not match the registered client.' );
        }
        if ( 'code' !== self::value( $input, 'response_type' ) ) {
            throw new OAuthError( 'unsupported_response_type', 'Only authorization codes are supported.' );
        }
        $this->resource( $input );
        $scope = self::value( $input, 'scope', Config::SCOPE );
        if ( Config::SCOPE !== $scope ) {
            throw new OAuthError( 'invalid_scope', 'Only the Fabrement MCP scope is supported.' );
        }
        $challenge = self::value( $input, 'code_challenge' );
        if ( 'S256' !== self::value( $input, 'code_challenge_method' ) || ! preg_match( '/\A[A-Za-z0-9_-]{43}\z/D', $challenge ) ) {
            throw new OAuthError( 'invalid_request', 'PKCE S256 is required.' );
        }
        return [ 'client_id' => $id, 'client_name' => $client['client_name'], 'redirect_uri' => $redirect,
            'resource' => Config::resource(), 'issuer' => Config::issuer(), 'scope' => $scope,
            'generation' => Config::generation(), 'challenge' => $challenge, 'state' => self::value( $input, 'state' ) ];
    }

    public function approve( array $authorization, int $user_id ): string {
        $this->user( $user_id );
        $family = bin2hex( random_bytes( 32 ) );
        $authorization['user_id'] = $user_id;
        $authorization['created'] = time();
        $this->store->put( 'grant', $family, $authorization, Store::NO_EXPIRY, $family, $user_id );
        $code = 'fb_code_' . bin2hex( random_bytes( 32 ) );
        $this->store->put( 'code', $code, $authorization, time() + 120, $family, $user_id );
        return $code;
    }

    public function token( array $input ): array {
        $type = self::value( $input, 'grant_type' );
        if ( ! in_array( $type, [ 'authorization_code', 'refresh_token' ], true ) ) {
            throw new OAuthError( 'unsupported_grant_type', 'Unsupported grant type.' );
        }
        $this->resource( $input );
        $kind = 'authorization_code' === $type ? 'code' : 'refresh';
        $key = self::value( $input, 'code' === $kind ? 'code' : 'refresh_token' );
        $row = $this->store->get( $kind, $key );
        if ( ! $row || $row['revoked'] || ! hash_equals( $row['payload']['client_id'], self::value( $input, 'client_id' ) ) ) {
            throw new OAuthError( 'invalid_grant', 'Invalid or expired authorization.' );
        }
        $this->grant( $row['family'] );
        if ( self::value( $input, 'scope', Config::SCOPE ) !== Config::SCOPE ) {
            throw new OAuthError( 'invalid_scope', 'Scope cannot be changed.' );
        }
        if ( 'code' === $kind ) {
            $verifier = self::value( $input, 'code_verifier' );
            $challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
            if ( ! preg_match( '/\A[A-Za-z0-9._~-]{43,128}\z/D', $verifier )
                || ! hash_equals( $row['payload']['challenge'], $challenge )
                || self::value( $input, 'redirect_uri' ) !== $row['payload']['redirect_uri'] ) {
                throw new OAuthError( 'invalid_grant', 'Invalid PKCE proof or callback.' );
            }
        }
        if ( ! $this->store->consume( $kind, $key ) ) {
            // A replay invalidates all descendants, including tokens already issued by a racing request.
            $this->store->revoke( $row['family'] );
            throw new OAuthError( 'invalid_grant', 'Authorization was already used. Reconnect the client.' );
        }
        $access = Config::ACCESS_PREFIX . bin2hex( random_bytes( 32 ) );
        $refresh = 'fb_oauth_rt_' . bin2hex( random_bytes( 32 ) );
        $expires = time() + 3600;
        $payload = [ 'client_id' => $row['payload']['client_id'] ];
        $this->store->put( 'access', $access, $payload, $expires, $row['family'], (int) $row['user_id'] );
        $this->store->put( 'refresh', $refresh, $payload, Store::NO_EXPIRY, $row['family'], (int) $row['user_id'] );
        return [ 'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $expires - time(),
            'refresh_token' => $refresh, 'scope' => Config::SCOPE ];
    }

    public function authenticate( string $token ): int {
        $row = $this->store->get( 'access', $token );
        if ( ! $row || $row['revoked'] ) {
            throw new OAuthError( 'invalid_token', 'Invalid or expired access token.', 401 );
        }
        return (int) $this->grant( $row['family'] )['user_id'];
    }

    public function revoke( array $input ): void {
        $token = self::value( $input, 'token' );
        $client = self::value( $input, 'client_id' );
        foreach ( [ 'access', 'refresh' ] as $kind ) {
            $row = $this->store->get( $kind, $token );
            if ( $row && hash_equals( $row['payload']['client_id'], $client ) ) {
                $this->store->revoke( $row['family'] );
            }
        }
    }

    private function grant( string $family ): array {
        $grant = $this->store->get( 'grant', $family );
        if ( ! $grant || $grant['revoked'] || ( $grant['payload']['resource'] ?? '' ) !== Config::resource()
            || ( $grant['payload']['generation'] ?? null ) !== Config::generation()
            || ( $grant['payload']['issuer'] ?? '' ) !== Config::issuer() ) {
            throw new OAuthError( 'invalid_grant', 'Access has expired or been revoked.' );
        }
        $this->user( (int) $grant['user_id'] );
        return $grant;
    }

    private function user( int $id ): void {
        $user = get_userdata( $id );
        if ( ! $user || ! user_can( $user, Capabilities::ADMIN ) ) {
            throw new OAuthError( 'access_denied', 'An administrator account is required.', 403 );
        }
    }

    private function resource( array $input ): void {
        if ( self::value( $input, 'resource' ) !== Config::resource() ) {
            throw new OAuthError( 'invalid_target', 'The resource must be this site\'s exact MCP URL.' );
        }
    }
}
