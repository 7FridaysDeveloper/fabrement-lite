<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Browser;
use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\McpClient;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * An AI client connecting to the site over OAuth, all of it over HTTPS as it happens for real: the
 * client registers, the administrator signs in and allows it on the consent page, the client
 * trades the code for tokens and works the MCP server with them — and nothing else. Then refresh,
 * replay, desktop clients on a loopback port, and OAuth switched off.
 */
final class McpOAuthTest extends SiteTestCase {

    private const CLOUD = 'https://claude.ai/api/mcp/auth_callback';

    private string $verifier = '';

    protected function setUp(): void {
        parent::setUp();
        $this->verifier = bin2hex( random_bytes( 32 ) );
    }

    private function challenge(): string {
        return rtrim( strtr( base64_encode( hash( 'sha256', $this->verifier, true ) ), '+/', '-_' ), '=' );
    }

    /** POST to an OAuth endpoint. @return array{status:int, data:array} */
    private function oauth( string $endpoint, array $fields, bool $json = false ): array {
        $answer = Http::request( $this->site(), 'POST', $this->site()->url( '/wp-json/fabrement-oauth/v1/' . $endpoint ),
            [ 'Content-Type' => $json ? 'application/json' : 'application/x-www-form-urlencoded' ],
            $json ? (string) json_encode( $fields ) : http_build_query( $fields ) );
        return [ 'status' => $answer['status'], 'data' => (array) json_decode( $answer['body'], true ) ];
    }

    private function register( string $name, string $redirect ): string {
        $answer = $this->oauth( 'register', [ 'client_name' => $name, 'redirect_uris' => [ $redirect ], 'token_endpoint_auth_method' => 'none' ], true );
        $this->assertContains( $answer['status'], [ 200, 201 ], json_encode( $answer ) );
        return (string) $answer['data']['client_id'];
    }

    /** The administrator signs in, opens the consent page and allows the client. @return string The code the callback gets. */
    private function approve( string $client, string $callback ): string {
        $browser = new Browser( $this->site() );
        $browser->login( 'admin', 'admin' );
        $consent = $browser->get( $this->site()->url( '/wp-admin/admin-post.php' ) . '?' . http_build_query( [
            'action' => 'fabrement_mcp_authorize', 'response_type' => 'code', 'client_id' => $client, 'redirect_uri' => $callback,
            'resource' => $this->site()->url( '/wp-json/fabrement/v1/mcp' ), 'code_challenge_method' => 'S256', 'code_challenge' => $this->challenge(), 'state' => 'qa',
        ] ) );
        $this->assertSame( 200, $consent['status'], substr( $consent['body'], 0, 500 ) );
        $this->assertStringContainsString( 'Access remains active until disconnected.', $consent['body'] );
        $allowed = $browser->post( $this->site()->url( '/wp-admin/admin-post.php' ), Browser::form( $consent['body'] ) + [ 'decision' => 'allow' ] );
        $this->assertSame( 302, $allowed['status'] );
        parse_str( (string) parse_url( $allowed['headers']['location'], PHP_URL_QUERY ), $query );
        $this->assertStringStartsWith( $callback, $allowed['headers']['location'], 'back to the client\'s own callback' );
        $this->assertSame( 'qa', $query['state'] ?? null );
        return (string) $query['code'];
    }

    /** @return array The tokens of a client the administrator allowed. */
    private function connect( string $name = 'Claude', string $callback = self::CLOUD ): array {
        $client = $this->register( $name, $callback );
        $token  = $this->oauth( 'token', [ 'grant_type' => 'authorization_code', 'code' => $this->approve( $client, $callback ), 'code_verifier' => $this->verifier,
            'redirect_uri' => $callback, 'client_id' => $client, 'resource' => $this->site()->url( '/wp-json/fabrement/v1/mcp' ) ] );
        $this->assertSame( 200, $token['status'], json_encode( $token ) );
        return $token['data'] + [ 'client_id' => $client ];
    }

    public function test_an_allowed_client_gets_tokens_and_works_the_mcp_server_with_them(): void {
        $tokens = $this->connect();
        $this->assertLessThanOrEqual( 3600, $tokens['expires_in'], 'an access token lasts at most an hour' );
        $mcp = new McpClient( $this->site(), bearer: $tokens['access_token'] );
        $this->assertContains( 'fabrement-set-field-values', array_column( $mcp->tools(), 'name' ) );

        $rows = (string) json_encode( $this->site()->rows( 'SELECT key_hash, payload FROM wp_fabrement_mcp_oauth' ) );
        $this->assertStringNotContainsString( $tokens['access_token'], $rows, 'tokens are stored hashed' );
        $this->assertStringNotContainsString( $tokens['refresh_token'], $rows );
    }

    public function test_the_token_opens_the_mcp_server_and_no_other_route(): void {
        $tokens = $this->connect();
        $other  = Http::request( $this->site(), 'GET', $this->site()->url( '/wp-json/wp/v2/users/me' ), [ 'Authorization' => 'Bearer ' . $tokens['access_token'] ] );
        $this->assertSame( 401, $other['status'] );
    }

    public function test_a_refresh_rotates_and_a_replay_revokes_access_at_once(): void {
        $tokens  = $this->connect();
        $mcp     = new McpClient( $this->site(), bearer: $tokens['access_token'] );
        $this->assertNotEmpty( $mcp->tools() );
        $refresh = [ 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $tokens['client_id'], 'resource' => $this->site()->url( '/wp-json/fabrement/v1/mcp' ) ];
        $next    = $this->oauth( 'token', $refresh );
        $this->assertSame( 200, $next['status'] );
        $this->assertNotSame( $tokens['refresh_token'], $next['data']['refresh_token'] );

        $replay = $this->oauth( 'token', $refresh );
        $this->assertSame( 'invalid_grant', $replay['data']['error'] ?? null );
        $this->assertSame( 401, $mcp->rpc( 'tools/list', [] )['_http']['status'], 'the replay revoked the session at once' );
    }

    public function test_a_desktop_client_is_bound_to_the_loopback_port_it_used(): void {
        foreach ( [
            [ 'Codex', 'http://127.0.0.1/callback/test-client', 'http://127.0.0.1:49152/callback/test-client' ],
            [ 'Claude Code', 'http://localhost/callback', 'http://localhost:49153/callback' ],
            [ 'IPv6 client', 'http://[::1]/callback', 'http://[::1]:49154/callback' ],
        ] as [ $name, $registered, $callback ] ) {
            $client   = $this->register( $name, $registered );
            $exchange = [ 'grant_type' => 'authorization_code', 'code' => $this->approve( $client, $callback ), 'code_verifier' => $this->verifier,
                'client_id' => $client, 'resource' => $this->site()->url( '/wp-json/fabrement/v1/mcp' ), 'redirect_uri' => $registered ];
            $this->assertSame( 'invalid_grant', $this->oauth( 'token', $exchange )['data']['error'] ?? null, $name . ': the token call must name the port it used' );
        }
    }

    public function test_a_desktop_client_on_its_port_is_connected(): void {
        $client = $this->register( 'Codex', 'http://127.0.0.1/callback/test-client' );
        $port   = 'http://127.0.0.1:49152/callback/test-client';
        $token  = $this->oauth( 'token', [ 'grant_type' => 'authorization_code', 'code' => $this->approve( $client, $port ), 'code_verifier' => $this->verifier,
            'client_id' => $client, 'resource' => $this->site()->url( '/wp-json/fabrement/v1/mcp' ), 'redirect_uri' => $port ] );
        $this->assertSame( 200, $token['status'] );
        $this->assertNotEmpty( ( new McpClient( $this->site(), bearer: $token['data']['access_token'] ) )->tools() );
    }

    public function test_oauth_switched_off_says_so_and_keeps_its_connections_out(): void {
        $tokens = $this->connect();
        $this->site()->wp( 'option', 'update', 'fabrement_mcp_oauth_enabled', '0' );
        $off = $this->oauth( 'token', [ 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $tokens['client_id'] ] );
        $this->assertSame( 403, $off['status'] );
        $this->assertStringContainsString( 'OAuth is turned off', (string) ( $off['data']['error_description'] ?? '' ) );
        $open = ( new McpClient( $this->site(), bearer: $tokens['access_token'] ) )->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'qa', 'version' => '1' ] ] );
        $this->assertSame( 403, $open['_http']['status'], 'an earlier connection is kept out' );
        $this->assertSame( 'fabrement_oauth_unavailable', $open['code'] ?? null );
        $this->site()->wp( 'option', 'update', 'fabrement_mcp_oauth_enabled', '1' );
    }
}
