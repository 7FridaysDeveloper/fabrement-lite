<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\McpClient;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The test site itself, as a host would have it: pages over HTTPS through its theme, the MCP server
 * open to an Administrator's OAuth token and closed to anyone else (an application password too),
 * the site's own code run by WP-CLI.
 */
final class SiteTest extends SiteTestCase {

    public function test_the_home_page_is_served_over_https_through_the_theme(): void {
        $home = $this->get( '/' );
        $this->assertSame( 200, $home['status'] );
        $this->assertMatchesRegularExpression( '/<html[^>]+lang="en-US"/', $home['body'] );
        $this->assertSame( 200, $this->get( '/wp-content/themes/twentytwentyfive/style.css' )['status'] );
        $this->assertNotNull( self::node( self::json_ld( $home['body'] ), 'WebSite' ), 'Yoast prints its graph' );
    }

    public function test_the_agent_signs_in_with_an_oauth_token_and_lists_the_tools(): void {
        $names = array_column( $this->client()->tools(), 'name' );
        $this->assertContains( 'fabrement-save-content', $names );
        // The adapter drops a tool whose schema the protocol cannot carry: every registered one is listed.
        $registered = $this->site_runs( 'return \Fabrement\Framework\App::container()[ \Fabrement\Modules\Mcp\Registrar::class ]->ability_names();' );
        $this->assertNotEmpty( $registered );
        $this->assertEqualsCanonicalizing( str_replace( '/', '-', $registered ), $names );
    }

    public function test_an_unknown_token_gets_no_session(): void {
        $answer = ( new McpClient( $this->site(), 'fabrement_oauth_at_' . bin2hex( random_bytes( 32 ) ) ) )->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'x', 'version' => '1' ] ] );
        $this->assertContains( $answer['_http']['status'], [ 401, 403 ] );
    }

    /**
     * The MCP server takes only the plugin's OAuth tokens: the Administrator's own application
     * password (made with WP-CLI, as wp-admin would) gets no session there, while it still opens the
     * rest of the REST API and the OAuth token still opens MCP.
     */
    public function test_an_application_password_is_refused_on_the_mcp_server(): void {
        $password = trim( $this->site()->wp( 'user', 'application-password', 'create', 'admin', 'qa-mcp', '--porcelain' ) );
        $basic    = [ 'Authorization' => 'Basic ' . base64_encode( 'admin:' . $password ) ];
        $initialize = (string) json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'qa', 'version' => '1' ] ] ] );
        $json       = [ 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream' ];
        foreach ( [ '/wp-json/fabrement/v1/mcp', '/?rest_route=/fabrement/v1/mcp' ] as $path ) {
            $refused = Http::request( $this->site(), 'POST', $this->site()->url( $path ), $basic + $json, $initialize );
            $this->assertContains( $refused['status'], [ 401, 403 ], $path . ': ' . $refused['body'] );
            $this->assertArrayNotHasKey( 'mcp-session-id', $refused['headers'], $path );
            // The same request as a Bearer token of login:password, the way some clients send it.
            $bearer = Http::request( $this->site(), 'POST', $this->site()->url( $path ), [ 'Authorization' => 'Bearer ' . base64_encode( 'admin:' . $password ) ] + $json, $initialize );
            $this->assertContains( $bearer['status'], [ 401, 403 ], $path . ' (Bearer): ' . $bearer['body'] );
        }
        // Elsewhere the password is WordPress's own, untouched.
        $me = Http::request( $this->site(), 'GET', $this->site()->url( '/wp-json/wp/v2/users/me' ), $basic );
        $this->assertSame( 200, $me['status'], $me['body'] );
        // And the OAuth token opens the MCP server.
        $this->assertContains( 'fabrement-save-content', array_column( $this->client()->tools(), 'name' ) );
    }

    public function test_the_template_tools_are_listed_and_site_wide_ones_are_not(): void {
        $names = array_column( $this->client()->tools(), 'name' );
        $this->assertCount( 41, $names );
        foreach ( [ 'list-templates', 'get-template', 'save-template', 'delete-template', 'assign-template-pages', 'list-template-pages' ] as $tool ) {
            $this->assertContains( 'fabrement-' . $tool, $names );
        }
        // translate-template, the seventh, is listed only on a site with WPML.
        foreach ( [ 'translate-template', 'set-default-template', 'assign-template-archive', 'list-archives', 'list-theme-templates' ] as $tool ) {
            $this->assertNotContains( 'fabrement-' . $tool, $names );
        }
    }

    /** Only an Administrator connects: an Editor and anyone below is kept out. */
    public function test_an_editor_an_author_a_contributor_and_a_subscriber_get_no_session(): void {
        foreach ( [ 'editor', 'author', 'contributor', 'subscriber' ] as $role ) {
            $user     = (int) $this->site()->wp( 'user', 'create', 'qa-' . $role, 'qa-' . $role . '@example.org', '--role=' . $role, '--porcelain' );
            $this->assertGreaterThan( 0, $user );
            $answer   = ( new McpClient( $this->site(), $this->site()->access_token( 'qa-' . $role ) ) )->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'qa', 'version' => '1' ] ] );
            $this->assertSame( 403, $answer['_http']['status'], $role );
        }
    }

    public function test_the_sites_own_code_runs_through_wp_cli(): void {
        $this->assertSame( rtrim( $this->site()->url( '' ), '/' ), $this->site()->php( 'echo home_url();' ) );
    }
}
