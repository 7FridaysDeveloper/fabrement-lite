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
        return $row && $kind === $row['kind'] && $row['expires'] > time() ? $row : null;
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

    public function revoke_user( int $user_id ): void {
        foreach ( $this->rows as &$row ) {
            if ( $row['user_id'] === $user_id ) { $row['revoked'] = 1; }
        }
    }

    public function extend( string $family, int $expires ): void {
        if ( ! isset( $this->rows[ hash( 'sha256', $family ) ] ) ) { return; }
        $row = &$this->rows[ hash( 'sha256', $family ) ];
        if ( 'grant' === $row['kind'] && ! $row['revoked'] && $row['expires'] > time() ) { $row['expires'] = $expires; }
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
        WordPressState::select_user( 12, [ 'manage_options', 'edit_posts', 'edit_others_posts' ] );
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

    private function exchange( array $changes = [], int $user_id = 12 ): array {
        $code = $this->protocol->approve( $this->protocol->authorization( $this->request() ), $user_id );
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
            [ [ 'state' => str_repeat( 'x', 1025 ) ], 'invalid_request' ],
            [ [ 'state' => "a\nb" ], 'invalid_request' ],
            [ [ 'client_id' => 'fabrement_client_unknown' ], 'invalid_client' ],
            [ [ 'client_id' => 'fb_client_' . str_repeat( 'a', 48 ) ], 'invalid_client' ],
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
        WordPressState::select_user( 12, [ 'manage_options', 'edit_posts', 'edit_others_posts' ] );
        WordPressState::$options['home'] = 'https://different.test';
        $this->error( 'invalid_grant', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
    }

    public function test_user_who_cannot_edit_posts_cannot_approve(): void {
        WordPressState::select_user( 12, [ 'read' ] );
        $this->error( 'access_denied', fn() => $this->exchange() );
    }

    /** Only an Administrator connects: an Editor or an Author cannot approve, use or refresh a token. */
    public function test_an_editor_or_author_cannot_approve_or_use_a_token(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        $roles = [
            'editor' => [ 'edit_posts', 'edit_others_posts', 'edit_others_pages', 'publish_posts' ],
            'author' => [ 'edit_posts', 'publish_posts', 'upload_files' ],
        ];
        foreach ( $roles as $caps ) {
            WordPressState::select_user( 12, $caps );
            $this->error( 'access_denied', fn() => $this->exchange() );
            $this->error( 'access_denied', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
            $this->error( 'access_denied', fn() => $this->protocol->token( $this->refresh( $tokens ) ) );
        }
    }

    public function test_an_administrator_approves_and_the_token_acts_with_an_editors_caps(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        // The token's requests run on the MCP route, under the ceiling.
        WordPressState::select_mcp_user( 12, [ 'manage_options', 'activate_plugins', 'edit_posts', 'edit_others_posts', 'edit_others_pages' ] );
        self::assertSame( 12, $this->protocol->authenticate( $tokens['access_token'] ) );
        self::assertSame( 12, $this->protocol->authenticate( $this->protocol->token( $this->refresh( $tokens ) )['access_token'] ) );
        self::assertTrue( current_user_can( 'edit_others_posts' ) );
        self::assertFalse( current_user_can( 'manage_options' ) );
        self::assertFalse( current_user_can( 'activate_plugins' ) );
    }


    public function test_access_and_refresh_tokens_cannot_be_used_interchangeably(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $tokens['refresh_token'] ) );
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $tokens, [ 'refresh_token' => $tokens['access_token'] ] ) ) );
    }

    /** Moves a connection's clock: every row of its family ends $seconds earlier, as if that time passed unused. */
    private function age( array $tokens, int $seconds ): string {
        $family = $this->store->get( 'refresh', $tokens['refresh_token'] )['family'];
        foreach ( $this->store->rows as &$row ) {
            if ( $row['family'] === $family ) { $row['expires'] -= $seconds; }
        }
        return $family;
    }

    private function grant_expires( string $family ): int {
        return $this->store->rows[ hash( 'sha256', $family ) ]['expires'];
    }

    public function test_grant_and_refresh_end_after_ninety_idle_days_and_access_stays_short(): void {
        $before = time();
        $tokens = $this->protocol->token( $this->exchange() );
        $family = $this->store->get( 'refresh', $tokens['refresh_token'] )['family'];
        self::assertSame( 90 * 86400, Config::IDLE_EXPIRY );
        self::assertGreaterThanOrEqual( $before + Config::IDLE_EXPIRY, $this->grant_expires( $family ) );
        self::assertLessThanOrEqual( time() + Config::IDLE_EXPIRY, $this->grant_expires( $family ) );
        self::assertGreaterThanOrEqual( $before + Config::IDLE_EXPIRY, $this->store->get( 'refresh', $tokens['refresh_token'] )['expires'] );
        self::assertGreaterThan( 0, $tokens['expires_in'] );
        self::assertLessThanOrEqual( 3600, $tokens['expires_in'] );
    }

    public function test_refresh_after_more_than_ninety_idle_days_is_refused(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        $this->age( $tokens, Config::IDLE_EXPIRY + 1 );
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $tokens ) ) );
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
    }

    public function test_refresh_inside_ninety_days_works_and_extends_the_window(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        // 89 days and 23 hours unused: a minute is left.
        $family = $this->age( $tokens, Config::IDLE_EXPIRY - 60 );
        self::assertLessThanOrEqual( time() + 60, $this->grant_expires( $family ) );
        $before = time();
        $next = $this->protocol->token( $this->refresh( $tokens ) );
        self::assertGreaterThanOrEqual( $before + Config::IDLE_EXPIRY, $this->grant_expires( $family ) );
        self::assertGreaterThanOrEqual( $before + Config::IDLE_EXPIRY, $this->store->get( 'refresh', $next['refresh_token'] )['expires'] );
        self::assertSame( 12, $this->protocol->authenticate( $next['access_token'] ) );
        // The extended window is a full one again: another 89 days later, the next refresh still works.
        $this->age( $next, Config::IDLE_EXPIRY - 60 );
        self::assertSame( 12, $this->protocol->authenticate( $this->protocol->token( $this->refresh( $next ) )['access_token'] ) );
    }

    public function test_token_use_extends_the_window_at_most_once_a_day(): void {
        $tokens = $this->protocol->token( $this->exchange() );
        $family = $this->store->get( 'refresh', $tokens['refresh_token'] )['family'];
        $recent = time() + Config::IDLE_EXPIRY - 3600;
        $this->store->rows[ hash( 'sha256', $family ) ]['expires'] = $recent;
        self::assertSame( 12, $this->protocol->authenticate( $tokens['access_token'] ) );
        self::assertSame( $recent, $this->grant_expires( $family ) );
        $this->store->rows[ hash( 'sha256', $family ) ]['expires'] = time() + 3 * 86400;
        $before = time();
        self::assertSame( 12, $this->protocol->authenticate( $tokens['access_token'] ) );
        self::assertGreaterThanOrEqual( $before + Config::IDLE_EXPIRY, $this->grant_expires( $family ) );
    }

    public function test_revoking_one_connection_leaves_another_working(): void {
        $first = $this->protocol->token( $this->exchange() );
        $second = $this->protocol->token( $this->exchange() );
        $this->protocol->revoke( [ 'token' => $first['refresh_token'], 'client_id' => $this->client['client_id'] ] );
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $first ) ) );
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $first['access_token'] ) );
        self::assertSame( 12, $this->protocol->authenticate( $second['access_token'] ) );
        self::assertSame( 12, $this->protocol->authenticate( $this->protocol->token( $this->refresh( $second ) )['access_token'] ) );
    }

    public function test_zero_expiry_is_expired_for_every_kind(): void {
        $input = $this->exchange();
        $this->store->rows[ hash( 'sha256', $input['code'] ) ]['expires'] = 0;
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $input ) );
        $tokens = $this->protocol->token( $this->exchange() );
        $this->store->rows[ hash( 'sha256', $tokens['access_token'] ) ]['expires'] = 0;
        $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
        $this->store->rows[ hash( 'sha256', $tokens['refresh_token'] ) ]['expires'] = 0;
        $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $tokens ) ) );
    }

    public function test_password_change_revokes_that_users_connections_only(): void {
        WordPressState::select_user( 13, [ 'manage_options', 'edit_posts', 'edit_others_posts' ] );
        $other = $this->protocol->token( $this->exchange( [], 13 ) );
        WordPressState::select_user( 12, [ 'manage_options', 'edit_posts', 'edit_others_posts' ] );
        $first = $this->protocol->token( $this->exchange() );
        $second = $this->protocol->token( $this->exchange() );

        Bootstrap::revoke_user( 12, $this->store );

        foreach ( [ $first, $second ] as $tokens ) {
            $this->error( 'invalid_token', fn() => $this->protocol->authenticate( $tokens['access_token'] ) );
            $this->error( 'invalid_grant', fn() => $this->protocol->token( $this->refresh( $tokens ) ) );
        }
        WordPressState::select_user( 13, [ 'manage_options', 'edit_posts', 'edit_others_posts' ] );
        self::assertSame( 13, $this->protocol->authenticate( $other['access_token'] ) );
        self::assertSame( 13, $this->protocol->authenticate( $this->protocol->token( $this->refresh( $other ) )['access_token'] ) );
    }

    public function test_password_hooks_revoke_the_changed_users_connections(): void {
        $old = ( new \ReflectionClass( \WP_User::class ) )->newInstanceWithoutConstructor();
        $old->ID = 12;
        $old->data = (object) [ 'user_pass' => '$wp$old-hash' ];
        WordPressState::$user->data->user_pass = '$wp$old-hash';
        Bootstrap::profile_updated( 12, $old );
        self::assertSame( [], $GLOBALS['wpdb']->revoked_users, 'a profile save without a new password' );

        WordPressState::$user->data->user_pass = '$wp$new-hash';
        Bootstrap::profile_updated( 12, $old );
        Bootstrap::password_reset( WordPressState::$user );
        Bootstrap::password_set( 'new password', 12 );
        self::assertSame( [ 12, 12, 12 ], $GLOBALS['wpdb']->revoked_users );
    }

    public function test_a_login_rehash_of_the_same_password_keeps_the_connections(): void {
        $old = ( new \ReflectionClass( \WP_User::class ) )->newInstanceWithoutConstructor();
        $old->ID = 12;
        $old->data = (object) [ 'user_pass' => '$wp$same password' ];

        Bootstrap::password_set( 'same password', 12, $old );
        self::assertSame( [], $GLOBALS['wpdb']->revoked_users, 'core rehashing the same password on login' );

        Bootstrap::password_set( 'new password', 12, $old );
        self::assertSame( [ 12 ], $GLOBALS['wpdb']->revoked_users, 'a new password' );
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

    /** @dataProvider unusable_fields */
    public function test_fields_refuse_arrays_non_ascii_and_over_long_values( array $source ): void {
        $this->error( 'invalid_request', fn() => Bootstrap::fields( $source, [ 'code', 'state' ] ) );
    }

    public static function unusable_fields(): array {
        return [ [ [ 'code' => [ 'one' ] ] ], [ [ 'state' => "a\tb" ] ], [ [ 'state' => 'é' ] ], [ [ 'code' => str_repeat( 'a', 4097 ) ] ] ];
    }

    public function test_fields_keep_exact_resource_and_state_and_drop_others(): void {
        $params = [ 'resource' => Config::resource() . '?rest_route=/fabrement/v1/mcp', 'state' => 'a+b &c' ];
        self::assertSame( $params, Bootstrap::fields( $params + [ 'other' => 'x' ], [ 'resource', 'state' ] ) );
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
        $request->set_header( 'authorization', 'Bearer fabrement_oauth_at_invalid' );
        self::assertNull( Bootstrap::authenticate( null, null, $request ) );
        self::assertSame( 12, get_current_user_id() );
        // On the MCP route a user signed in some other way (cookie, Basic) is refused: only the token lets in.
        $request = new \WP_REST_Request( 'POST', Config::ROUTE );
        $request->set_header( 'authorization', 'Basic ' . base64_encode( 'user:password' ) );
        self::assertSame( 401, Bootstrap::authenticate( null, null, $request )->get_error_data()['status'] ?? null );
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
        foreach ( [ [], [ 'pagename' => 'about' ], [ Bootstrap::META_VAR => 'openid-configuration' ], [ Bootstrap::META_VAR => [ 'x' ] ] ] as $vars ) {
            Bootstrap::discovery( (object) [ 'query_vars' => $vars ] );
        }
        Bootstrap::discovery( null );
    }

    public function test_o_auth_settings_require_a_user_who_can_connect(): void {
        WordPressState::select_user( 12, [ 'read' ] );
        $_POST['oauth_command'] = 'revoke';
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'You do not have permission to perform this action.' );
        Bootstrap::settings();
    }

    public function test_only_an_administrator_turns_o_auth_on(): void {
        // An Editor cannot connect, so is refused before the command is read.
        WordPressState::select_user( 12, [ 'edit_posts', 'edit_others_posts' ] );
        $_POST['oauth_command'] = 'enable';
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'You do not have permission to perform this action.' );
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
    }
}
