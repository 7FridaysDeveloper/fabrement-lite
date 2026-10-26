<?php

namespace Fabrement\Tests;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Mcp\OAuth\{Bootstrap, Clients, Config, OAuthError, Protocol, Store};
use PHPUnit\Framework\TestCase;

final class MemoryOAuthStore extends Store {
    public array $rows = [];

    public function put( string $kind, string $key, array $payload, int $expires, string $family = '', int $user_id = 0 ): void {
        $this->rows[ hash( 'sha256', $key ) ] = compact( 'kind', 'payload', 'expires', 'family', 'user_id' ) + [ 'used' => 0, 'revoked' => 0 ];
    }

    public function get( string $kind, string $key ): ?array {
        $row = $this->rows[ hash( 'sha256', $key ) ] ?? null;
        return $row && $kind === $row['kind'] && ( $row['expires'] > time()
            || ( self::NO_EXPIRY === $row['expires'] && in_array( $kind, [ 'grant', 'refresh' ], true ) ) ) ? $row : null;
    }

    public function consume( string $kind, string $key ): bool {
        $row = $this->get( $kind, $key );
        if ( ! $row || $row['used'] || $row['revoked'] ) { return false; }
        $this->rows[ hash( 'sha256', $key ) ]['used'] = 1;
        return true;
    }

    public function revoke( string $family ): void {
        foreach ( $this->rows as &$row ) {
            if ( $row['family'] === $family ) { $row['revoked'] = 1; }
        }
    }

    public function revoke_all(): void {
        foreach ( $this->rows as &$row ) { $row['revoked'] = 1; }
    }
}

final class McpOAuthTest extends TestCase {
    private MemoryOAuthStore $store;
    private Clients $clients;
    private Protocol $protocol;
    private array $client;
    private string $verifier;

    protected function setUp(): void {
        WordPressState::reset();
        WordPressState::$options[ Keys::OPTION_MCP_OAUTH_SCHEMA ] = Store::SCHEMA_VERSION;
        WordPressState::select_user( 12, [ 'manage_options' ] );
        $this->store = new MemoryOAuthStore();
        $this->clients = new Clients( $this->store );
        $this->protocol = new Protocol( $this->store, $this->clients );
        $this->client = $this->clients->register( [
            'client_name' => 'Test Claude', 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ],
            'token_endpoint_auth_method' => 'none',
        ] );
        $this->verifier = str_repeat( 'x', 64 );
    }

    private function request( array $changes = [] ): array {
        return $changes + [
            'client_id' => $this->client['client_id'], 'redirect_uri' => $this->client['redirect_uris'][0],
            'resource' => Config::resource(), 'response_type' => 'code', 'scope' => Config::SCOPE,
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $this->verifier, true ) ), '+/', '-_' ), '=' ),
            'state' => 'client-state-123',
        ];
    }

    private function exchange( array $changes = [] ): array {
        $code = $this->protocol->approve( $this->protocol->authorization( $this->request() ), 12 );
        return $changes + [ 'grant_type' => 'authorization_code', 'code' => $code,
            'resource' => Config::resource(), 'client_id' => $this->client['client_id'],
            'redirect_uri' => $this->client['redirect_uris'][0], 'code_verifier' => $this->verifier ];
    }

    private function refresh( array $tokens, array $changes = [] ): array {
        return $changes + [ 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
            'client_id' => $this->client['client_id'], 'resource' => Config::resource() ];
    }

    private function error( string $expected, callable $call ): void {
        try { $call(); } catch ( OAuthError $error ) {
            self::assertSame( $expected, $error->error );
            return;
        }
        self::fail( 'Expected OAuth error: ' . $expected );
    }

    public function test_code_and_rotating_refresh_authenticate_and_revocation_is_immediate(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        self::assertSame( 12, $this->protocol->authenticate( $tokens['access_token'] ) );
        self::assertSame( 'Bearer', $tokens['token_type'] );
        self::assertSame( Config::SCOPE, $tokens['scope'] );
        self::assertLessThanOrEqual( 3600, $tokens['expires_in'] );
        $next = $this->protocol->token( $this->refresh( $tokens ) );
        self::assertNotSame( $tokens['refresh_token'], $next['refresh_token'] );
        self::assertSame( 12, $this->protocol->authenticate( $next['access_token'] ) );
        $this->protocol->revoke( [ 'token' => $next['refresh_token'], 'client_id' => $this->client['client_id'] ] );
        foreach ( [ $tokens, $next ] as $set ) {
            $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $set['access_token'] ) );
        }
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $next ) ) );
    }

    public function test_code_replay_revokes_its_grant(): void {
        $input = $this->exchange();
        $tokens = $this->protocol->token( $input );
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $input ) );
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
    }

    public function test_refresh_replay_revokes_descendants(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        $next = $this->protocol->token( $this->refresh( $tokens ) );
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $tokens ) ) );
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $next['access_token'] ) );
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $next ) ) );
    }

    /** @dataProvider invalid_authorization */
    public function test_invalid_authorization( array $change, string $error ): void {
        $this->error( $error, fn() => $this->protocol->authorization( $this->request( $change ) ) );
    }

    public static function invalid_authorization(): array {
        return [
            [ [ 'code_challenge_method' => 'plain' ], 'invalid_request' ],
            [ [ 'code_challenge' => '' ], 'invalid_request' ],
            [ [ 'code_challenge' => str_repeat( 'a', 44 ) ], 'invalid_request' ],
            [ [ 'resource' => '' ], 'invalid_target' ],
            [ [ 'resource' => 'https://attacker.test/mcp' ], 'invalid_target' ],
            [ [ 'resource' => [ 'https://attacker.test/mcp' ] ], 'invalid_request' ],
            [ [ 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback?next=evil' ], 'invalid_request' ],
            [ [ 'response_type' => 'token' ], 'unsupported_response_type' ],
            [ [ 'scope' => 'fabrement:mcp admin' ], 'invalid_scope' ],
            [ [ 'client_id' => '' ], 'invalid_client' ],
            [ [ 'state' => str_repeat( 'x', 4097 ) ], 'invalid_request' ],
        ];
    }

    /** @dataProvider invalid_exchange */
    public function test_invalid_exchange_does_not_consume_legitimate_code( array $change, string $error ): void {
        $input = $this->exchange();
        $this->error( $error, fn() => $this->protocol->token( $change + $input ) );
        self::assertSame( 12, $this->protocol->authenticate( $this->protocol->token( $input )['access_token'] ) );
    }

    public static function invalid_exchange(): array {
        return [
            [ [ 'code_verifier' => str_repeat( 'z', 64 ) ], 'invalid_grant' ],
            [ [ 'code_verifier' => '' ], 'invalid_grant' ],
            [ [ 'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect' ], 'invalid_grant' ],
            [ [ 'client_id' => 'wrong-client' ], 'invalid_grant' ],
            [ [ 'resource' => '' ], 'invalid_target' ],
            [ [ 'scope' => 'other' ], 'invalid_scope' ],
            [ [ 'grant_type' => 'password' ], 'unsupported_grant_type' ],
        ];
    }

    public function test_refresh_cannot_change_client_resource_or_scope(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        foreach ( [ 'client_id' => 'invalid_grant', 'resource' => 'invalid_target', 'scope' => 'invalid_scope' ] as $key => $error ) {
            $this->error( $error, fn() => $this->protocol->token( $this->refresh( $tokens, [ $key => 'other' ] ) ) );
        }
        self::assertSame( 12, $this->protocol->authenticate( $tokens['access_token'] ) );
    }

    public function test_capability_loss_and_issuer_change_immediately_invalidate_access(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        WordPressState::select_user( 12, [ 'read' ] );
        $this->error( 'access_denied', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
        $this->error( 'access_denied', fn() => $this->protocol->token( $this->refresh( $tokens ) ) );
        WordPressState::select_user( 12, [ 'manage_options' ] );
        WordPressState::$options['home'] = 'https://different.test';
        $this->error( 'invalid_grant', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
    }

    public function test_non_administrator_cannot_approve(): void {
        WordPressState::select_user( 12, [ 'edit_posts' ] );
        $this->error( 'access_denied', fn() => $this->exchange() );
    }

    public function test_access_and_refresh_tokens_cannot_be_used_interchangeably(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $tokens['refresh_token'] ) );
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $tokens, [ 'refresh_token' => $tokens['access_token'] ] ) ) );
    }

    public function test_new_grants_and_rotating_refresh_have_no_expiry_but_access_remains_short_lived(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        $family = $this->store->get( 'refresh', $tokens['refresh_token'] )['family'];
        self::assertSame( Store::NO_EXPIRY, $this->store->get( 'grant', $family )['expires'] );
        $this->store->rows[ hash( 'sha256', $family ) ]['payload']['created'] = time() - 365 * 86400;
        for ( $rotation = 0; $rotation < 3; ++$rotation ) {
            self::assertSame( Store::NO_EXPIRY, $this->store->get( 'refresh', $tokens['refresh_token'] )['expires'] );
            self::assertGreaterThan( 0, $tokens['expires_in'] );
            self::assertLessThanOrEqual( 3600, $tokens['expires_in'] );
            self::assertSame( 12, $this->protocol->authenticate( $tokens['access_token'] ) );
            $tokens = $this->protocol->token( $this->refresh( $tokens ) );
        }
    }

    public function test_revoking_one_permanent_connection_leaves_another_working(): void {
        $first = $this->protocol->token( $this->exchange() );
        $second = $this->protocol->token( $this->exchange() );
        $this->protocol->revoke( [ 'token' => $first['refresh_token'], 'client_id' => $this->client['client_id'] ] );
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $first ) ) );
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $first['access_token'] ) );
        self::assertSame( 12, $this->protocol->authenticate( $second['access_token'] ) );
        $next = $this->protocol->token( $this->refresh( $second ) );
        self::assertSame( Store::NO_EXPIRY, $this->store->get( 'refresh', $next['refresh_token'] )['expires'] );
    }

    public function test_zero_expiry_cannot_make_codes_or_access_tokens_permanent(): void {
        $input = $this->exchange();
        $this->store->rows[ hash( 'sha256', $input['code'] ) ]['expires'] = Store::NO_EXPIRY;
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $input ) );
        $tokens = $this->protocol->token( $this->exchange() );
        $this->store->rows[ hash( 'sha256', $tokens['access_token'] ) ]['expires'] = Store::NO_EXPIRY;
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
    }

    public function test_rotation_of_a_legacy_grant_issues_a_permanent_refresh_token(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        $family = $this->store->get( 'refresh', $tokens['refresh_token'] )['family'];
        $this->store->rows[ hash( 'sha256', $family ) ]['expires'] = time() + 30;
        $next = $this->protocol->token( $this->refresh( $tokens ) );
        self::assertGreaterThan( 30, $next['expires_in'] );
        self::assertLessThanOrEqual( 3600, $next['expires_in'] );
        self::assertSame( Store::NO_EXPIRY, $this->store->get( 'refresh', $next['refresh_token'] )['expires'] );
    }

    public function test_disable_generation_prevents_concurrent_grant_from_surviving_reenable(): void {
        $authorization = $this->protocol->authorization( $this->request() );
        WordPressState::$options[ Keys::OPTION_MCP_OAUTH_GENERATION ] = 'new-generation-after-disable';
        $code = $this->protocol->approve( $authorization, 12 );
        $this->error( 'invalid_grant', fn() => $this->protocol->token( [ 'code' => $code ] + $this->exchange() ) );
    }

    public function test_expired_codes_access_tokens_and_grants_are_rejected(): void {
        $input = $this->exchange();
        $this->store->rows[ hash( 'sha256', $input['code'] ) ]['expires'] = time() - 1;
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $input ) );
        $tokens = $this->protocol->token( $this->exchange() );
        $this->store->rows[ hash( 'sha256', $tokens['access_token'] ) ]['expires'] = time() - 1;
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
        $family = $this->store->get( 'refresh', $tokens['refresh_token'] )['family'];
        $this->store->rows[ hash( 'sha256', $family ) ]['expires'] = time() - 1;
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $tokens ) ) );
    }

    public function test_disable_revokes_pending_state_and_tokens_and_unknown_revocation_is_harmless(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        $this->protocol->revoke( [ 'token' => 'unknown', 'client_id' => $this->client['client_id'] ] );
        $this->protocol->revoke( [ 'token' => $tokens['access_token'], 'client_id' => 'wrong' ] );
        self::assertSame( 12, $this->protocol->authenticate( $tokens['access_token'] ) );
        $this->store->revoke_all();
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
    }

    /** @dataProvider bad_callbacks */
    public function test_callback_allowlist( string $uri ): void {
        $this->error( 'invalid_redirect_uri', fn() => $this->clients->register( [ 'redirect_uris' => [ $uri ] ] ) );
    }

    public static function bad_callbacks(): array {
        return array_map( fn( $uri ) => [ $uri ], [
            'http://claude.ai/api/mcp/auth_callback', 'https://claude.ai.evil.test/api/mcp/auth_callback',
            'https://claude.ai@evil.test/api/mcp/auth_callback', 'https://claude.ai/api/mcp/auth_callback#fragment',
            'https://claude.ai/api/mcp/auth_callback?evil=1', 'https://127.0.0.1/callback',
            'https://chatgpt.com/connector/oauth/*', 'https://chatgpt.com/connector/oauth/../evil',
        ] );
    }

    /** @dataProvider bad_metadata_urls */
    public function test_metadata_fetch_cannot_reach_arbitrary_hosts( string $url ): void {
        $this->error( 'invalid_client', fn() => $this->clients->resolve( $url ) );
        self::assertSame( [], WordPressState::$http_requests );
    }

    public static function bad_metadata_urls(): array {
        return array_map( fn( $url ) => [ $url ], [ 'https://127.0.0.1/client.json',
            'https://169.254.169.254/latest/', 'http://chatgpt.com/oauth/client.json',
            'https://chatgpt.com:443/oauth/client.json', 'https://chatgpt.com.evil.test/client.json',
            'https://user:pass@chatgpt.com/client.json', 'https://claude.ai/client.json#fragment',
        ] );
    }

    public function test_chat_gpt_metadata_negotiates_none_from_plural_methods(): void {
        $id = 'https://chatgpt.com/oauth/client.json';
        WordPressState::$http_responses[ $id ] = [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [
            'client_id' => $id, 'client_name' => 'ChatGPT',
            'redirect_uris' => [ 'https://chatgpt.com/connector_platform_oauth_redirect' ],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_methods_supported' => [ 'none', 'private_key_jwt' ],
        ] ) ];
        self::assertSame( 'none', $this->clients->resolve( $id )['token_endpoint_auth_method'] );
        $authorization = $this->protocol->authorization( $this->request( [ 'client_id' => $id,
            'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect' ] ) );
        self::assertSame( $id, $authorization['client_id'] );
    }

    public function test_metadata_redirect_or_identity_mismatch_is_rejected(): void {
        $id = 'https://claude.ai/client.json';
        WordPressState::$http_responses[ $id ] = [ 'response' => [ 'code' => 302 ], 'body' => '' ];
        $this->error( 'invalid_client', fn() => $this->clients->resolve( $id ) );
        WordPressState::$http_responses[ $id ] = [ 'response' => [ 'code' => 200 ], 'body' => '{"client_id":"other"}' ];
        $this->error( 'invalid_client', fn() => $this->clients->resolve( $id ) );
    }

    private function claude_metadata( array $changes = [] ): array {
        return $changes + json_decode( file_get_contents( dirname( __DIR__ ) . '/Fixtures/claude-oauth-client-metadata.json' ), true, 512, JSON_THROW_ON_ERROR );
    }

    public function test_claude_metadata_can_advertise_additional_grants_without_enabling_them(): void {
        $metadata = $this->claude_metadata();
        $id = $metadata['client_id'];
        WordPressState::$http_responses[ $id ] = [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $metadata ) ];
        $authorization = $this->protocol->authorization( $this->request( [ 'client_id' => $id ] ) );
        self::assertSame( 'Claude', $authorization['client_name'] );
        $input = [ 'grant_type' => 'authorization_code', 'code' => $this->protocol->approve( $authorization, 12 ),
            'code_verifier' => $this->verifier, 'client_id' => $id, 'resource' => Config::resource(),
            'redirect_uri' => $metadata['redirect_uris'][0] ];
        $tokens = $this->protocol->token( $input );
        self::assertSame( 12, $this->protocol->authenticate( $tokens['access_token'] ) );
        $next = $this->protocol->token( $this->refresh( $tokens, [ 'client_id' => $id ] ) );
        self::assertNotSame( $tokens['refresh_token'], $next['refresh_token'] );
        $this->error( 'unsupported_grant_type', fn() => $this->protocol->token( [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'client_id' => $id, 'resource' => Config::resource(),
        ] ) );
        self::assertSame( [ 'authorization_code', 'refresh_token' ], Config::authorization_metadata()['grant_types_supported'] );
    }

    public function test_additional_metadata_response_types_do_not_enable_implicit_authorization(): void {
        $metadata = $this->claude_metadata( [ 'response_types' => [ 'code', 'token' ] ] );
        $id = $metadata['client_id'];
        WordPressState::$http_responses[ $id ] = [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $metadata ) ];
        self::assertSame( 'Claude', $this->clients->resolve( $id )['client_name'] );
        $this->error( 'unsupported_response_type', fn() => $this->protocol->authorization( $this->request( [
            'client_id' => $id, 'response_type' => 'token',
        ] ) ) );
    }

    /** @dataProvider incompatible_metadata_types */
    public function test_metadata_still_requires_well_formed_compatible_types( array $changes ): void {
        $metadata = $this->claude_metadata( $changes );
        $id = $metadata['client_id'];
        WordPressState::$http_responses[ $id ] = [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $metadata ) ];
        $this->error( 'invalid_client_metadata', fn() => $this->clients->resolve( $id ) );
    }

    public static function incompatible_metadata_types(): array {
        return [
            [ [ 'grant_types' => [] ] ], [ [ 'grant_types' => 'authorization_code' ] ],
            [ [ 'grant_types' => [ 'authorization_code', [] ] ] ],
            [ [ 'grant_types' => [ 'refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer' ] ] ],
            [ [ 'response_types' => [] ] ], [ [ 'response_types' => 'code' ] ],
            [ [ 'response_types' => [ 'code', [] ] ] ], [ [ 'response_types' => [ 'token' ] ] ],
        ];
    }

    public function test_dynamic_registration_still_rejects_unsupported_grants_and_responses(): void {
        $this->error( 'invalid_client_metadata', fn() => $this->clients->register( $this->claude_metadata() ) );
        $this->error( 'invalid_client_metadata', fn() => $this->clients->register( $this->claude_metadata( [
            'grant_types' => [ 'authorization_code' ], 'response_types' => [ 'code', 'token' ],
        ] ) ) );
    }

    /** @dataProvider local_client_metadata */
    public function test_desktop_metadata_allows_an_ephemeral_port_but_token_exchange_binds_the_actual_redirect( string $id, string $registered, string $actual ): void {
        WordPressState::$http_responses[ $id ] = [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [
            'client_id' => $id, 'client_name' => 'Desktop MCP client', 'redirect_uris' => [ $registered ],
            'token_endpoint_auth_method' => 'none',
        ] ) ];
        $authorization = $this->protocol->authorization( $this->request( [ 'client_id' => $id, 'redirect_uri' => $actual ] ) );
        $code = $this->protocol->approve( $authorization, 12 );
        $input = [ 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $this->verifier,
            'client_id' => $id, 'resource' => Config::resource(), 'redirect_uri' => $actual ];
        $this->error( 'invalid_grant', fn() => $this->protocol->token( [ 'redirect_uri' => $registered ] + $input ) );
        self::assertSame( 12, $this->protocol->authenticate( $this->protocol->token( $input )['access_token'] ) );
    }

    public static function local_client_metadata(): array {
        return [
            [ 'https://chatgpt.com/oauth/codex/site-id/client.json', 'http://127.0.0.1/callback/site-id', 'http://127.0.0.1:51234/callback/site-id' ],
            [ 'https://chatgpt.com/oauth/codex/client.json', 'http://127.0.0.1/callback', 'http://127.0.0.1:51234/callback' ],
            [ 'https://claude.ai/oauth/claude-code-client-metadata', 'http://localhost/callback', 'http://localhost:51234/callback' ],
        ];
    }

    public function test_dynamic_registration_supports_local_clients(): void {
        foreach ( [ 'http://127.0.0.1:51234/callback/site-id', 'http://localhost:49152/callback', 'http://[::1]:54321/callback' ] as $uri ) {
            $client = $this->clients->register( [ 'client_name' => 'Local client', 'redirect_uris' => [ $uri ] ] );
            $authorization = $this->protocol->authorization( $this->request( [ 'client_id' => $client['client_id'], 'redirect_uri' => $uri ] ) );
            self::assertSame( $uri, $authorization['redirect_uri'] );
        }
    }

    /** @dataProvider mismatched_loopbacks */
    public function test_loopback_matching_never_changes_host_or_path( string $registered, string $requested ): void {
        self::assertFalse( Clients::redirect_matches( $requested, [ $registered ] ) );
    }

    public static function mismatched_loopbacks(): array {
        return [
            [ 'http://127.0.0.1/callback/a', 'http://127.0.0.1:12345/callback/b' ],
            [ 'http://127.0.0.1/callback', 'http://localhost:12345/callback' ],
            [ 'http://localhost/callback', 'http://localhost.evil.test:12345/callback' ],
            [ 'http://127.0.0.1/callback', 'http://127.0.0.1:12345/callback?next=evil' ],
            [ 'http://127.0.0.1/callback', 'http://127.0.0.1:12345/callback#fragment' ],
            [ 'http://127.0.0.1/callback', 'http://127.0.0.1:12345/callback/../callback' ],
            [ 'http://127.0.0.1/callback', 'http://127.0.0.1@evil.test:12345/callback' ],
        ];
    }

    /** @dataProvider invalid_local_callbacks */
    public function test_only_canonical_loopback_callbacks_can_be_registered( string $uri ): void {
        self::assertFalse( Clients::allowed_redirect( $uri ) );
    }

    public static function invalid_local_callbacks(): array {
        return array_map( fn( $uri ) => [ $uri ], [ 'http://127.1:12345/callback', 'http://0.0.0.0:12345/callback',
            'http://192.168.1.1/callback', 'http://2130706433/callback', 'http://localhost.:12345/callback',
            'http://localhost:0/callback', 'http://localhost:65536/callback', 'http://localhost:01234/callback',
            'http://localhost/callback%2fa', 'http://localhost:12345/callback\n', 'http://user@localhost:12345/callback',
            'http://[::ffff:127.0.0.1]:12345/callback', 'http://localhost:12345/other',
        ] );
    }

    public function test_malformed_client_metadata_is_rejected_without_type_warnings(): void {
        foreach ( [ [ 'grant_types' => [ [] ] ], [ 'redirect_uris' => [ [] ] ],
            [ 'token_endpoint_auth_method' => 'client_secret_basic' ], [ 'client_name' => [] ] ] as $change ) {
            try { $this->clients->register( $change + [ 'redirect_uris' => $this->client['redirect_uris'] ] ); }
            catch ( OAuthError $error ) { self::assertSame( 400, $error->status ); continue; }
            self::fail( 'Invalid metadata was accepted.' );
        }
    }

    /** @dataProvider ambiguous_forms */
    public function test_form_rejects_ambiguous_parameters( string $body ): void {
        $this->error( 'invalid_request', fn() => Bootstrap::form( $body ) );
    }

    public static function ambiguous_forms(): array {
        return [ [ 'code=one&code=two' ], [ 'resource%5B%5D=one' ], [ 'client_id=one&client%5fid=two' ], [ 'client.id=one' ] ];
    }

    public function test_form_keeps_exact_resource_and_state(): void {
        $params = [ 'resource' => Config::resource() . '?rest_route=/fabrement/v1/mcp', 'state' => 'a+b &c' ];
        self::assertSame( $params, Bootstrap::form( http_build_query( $params ) ) );
    }

    public function test_disabled_o_auth_is_explicit_and_enabled_challenge_is_namespaced(): void {
        self::assertFalse( Config::enabled() );
        $request = new \WP_REST_Request( 'POST', Config::ROUTE );
        WordPressState::select_user( 0, [] );
        $disabled = Bootstrap::authenticate( null, null, $request );
        self::assertSame( 403, $disabled->get_error_data()['status'] );
        self::assertStringContainsString( 'OAuth is turned off', $disabled->get_error_message() );
        WordPressState::$options[ Keys::OPTION_MCP_OAUTH_ENABLED ] = true;
        $response = Bootstrap::authenticate( null, null, $request );
        self::assertSame( 401, $response->get_error_data()['status'] );
        $response = Bootstrap::response( new \WP_REST_Response( [], 401 ), null, $request );
        self::assertStringContainsString( Config::endpoint( 'resource' ), $response->get_headers()['WWW-Authenticate'] );
        self::assertSame( Config::issuer(), Config::authorization_metadata()['issuer'] );
        self::assertTrue( Config::authorization_metadata()['authorization_response_iss_parameter_supported'] );
        self::assertSame( Config::resource(), Config::resource_metadata()['resource'] );
    }

    public function test_o_auth_does_not_touch_other_routes_or_existing_authentication(): void {
        WordPressState::$options[ Keys::OPTION_MCP_OAUTH_ENABLED ] = true;
        $request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
        $request->set_header( 'authorization', 'Bearer fb_oauth_at_invalid' );
        self::assertNull( Bootstrap::authenticate( null, null, $request ) );
        self::assertSame( 12, get_current_user_id() );
        $request = new \WP_REST_Request( 'POST', Config::ROUTE );
        $request->set_header( 'authorization', 'Basic ' . base64_encode( 'user:password' ) );
        self::assertNull( Bootstrap::authenticate( null, null, $request ) );
        $existing = new \WP_Error( 'denied' );
        self::assertSame( $existing, Bootstrap::authenticate( $existing, null, $request ) );
    }

    public function test_o_auth_tool_metadata_only_changes_fabrement_responses_when_enabled(): void {
        $request = new \WP_REST_Request( 'POST', Config::ROUTE );
        $data = [ 'jsonrpc' => '2.0', 'id' => 1, 'result' => [ 'tools' => [ [ 'name' => 'sample', '_meta' => [ 'kept' => true ] ] ] ] ];
        $response = new \WP_REST_Response( $data );
        self::assertSame( $data, Bootstrap::response( $response, null, $request )->get_data() );
        WordPressState::$options[ Keys::OPTION_MCP_OAUTH_ENABLED ] = true;
        $tool = Bootstrap::response( $response, null, $request )->get_data()['result']['tools'][0];
        self::assertTrue( $tool['_meta']['kept'] );
        self::assertSame( Config::SCOPE, $tool['securitySchemes'][0]['scopes'][0] );
        $other = new \WP_REST_Response( $data );
        self::assertSame( $data, Bootstrap::response( $other, null, new \WP_REST_Request( 'POST', '/other/mcp' ) )->get_data() );
    }

    public function test_adapter_object_shapes_and_empty_schemas_are_preserved(): void {
        WordPressState::$options[ Keys::OPTION_MCP_OAUTH_ENABLED ] = true;
        $data = (object) [ 'result' => (object) [ 'tools' => [ (object) [ 'name' => 'sample',
            'inputSchema' => (object) [ 'type' => 'object', 'properties' => new \stdClass() ],
        ] ] ] ];
        $response = Bootstrap::response( new \WP_REST_Response( $data ), null, new \WP_REST_Request( 'POST', Config::ROUTE ) );
        $tool = $response->get_data()->result->tools[0];
        self::assertInstanceOf( \stdClass::class, $tool->inputSchema->properties );
        self::assertSame( Config::SCOPE, $tool->securitySchemes[0]['scopes'][0] );
    }

    public function test_root_discovery_paths_are_left_for_other_plugins(): void {
        WordPressState::$options[ Keys::OPTION_MCP_OAUTH_ENABLED ] = true;
        $this->expectOutputString( '' );
        foreach ( [ '/.well-known/oauth-protected-resource', '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration' ] as $path ) {
            $_SERVER['REQUEST_URI'] = $path;
            Bootstrap::discovery();
        }
        unset( $_SERVER['REQUEST_URI'] );
    }

    public function test_o_auth_settings_require_administrator(): void {
        WordPressState::select_user( 12, [ 'edit_posts' ] );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Administrator access is required.' );
        Bootstrap::settings();
    }

    public function test_o_auth_settings_require_nonce(): void {
        WordPressState::$valid_nonce = false;
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Invalid nonce.' );
        Bootstrap::settings();
    }

    public function test_o_auth_cannot_be_enabled_on_http(): void {
        WordPressState::$options['home'] = 'http://example.test';
        $_POST['oauth_command'] = 'enable';
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'HTTPS and a compatible MCP Adapter are required.' );
        Bootstrap::settings();
    }

    public function test_unknown_connect_client_cannot_enable_o_auth(): void {
        $_POST = [ 'oauth_command' => 'connect', 'oauth_client' => 'other' ];
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Choose ChatGPT, Codex or Claude.' );
        Bootstrap::settings();
    }

    public function test_connect_requires_https_before_changing_state(): void {
        WordPressState::$options['home'] = 'http://example.test';
        $_POST = [ 'oauth_command' => 'connect', 'oauth_client' => 'codex' ];
        try { Bootstrap::settings(); self::fail( 'HTTP OAuth must be rejected.' ); }
        catch ( \RuntimeException $error ) {
            self::assertSame( 'HTTPS and a compatible MCP Adapter are required.', $error->getMessage() );
        }
        self::assertFalse( Config::enabled() );
        self::assertSame( [], WordPressState::$created_passwords );
    }
}
