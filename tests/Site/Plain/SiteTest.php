<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\McpClient;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The test site itself, as a host would have it: pages over HTTPS through its theme, the MCP server
 * open to an application password and closed to anyone else, the site's own code run by WP-CLI.
 */
final class SiteTest extends SiteTestCase {

    public function test_the_home_page_is_served_over_https_through_the_theme(): void {
        $home = $this->get( '/' );
        $this->assertSame( 200, $home['status'] );
        $this->assertMatchesRegularExpression( '/<html[^>]+lang="en-US"/', $home['body'] );
        $this->assertSame( 200, $this->get( '/wp-content/themes/twentytwentyfive/style.css' )['status'] );
        $this->assertNotNull( self::node( self::json_ld( $home['body'] ), 'WebSite' ), 'Yoast prints its graph' );
    }

    public function test_the_agent_signs_in_with_an_application_password_and_lists_the_tools(): void {
        $names = array_column( $this->client()->tools(), 'name' );
        $this->assertContains( 'fabrement-set-field-values', $names );
        $this->assertContains( 'fabrement-save-content', $names );
        // The adapter drops a tool whose schema the protocol cannot carry: every registered one is listed.
        $registered = $this->site_runs( 'return \Fabrement\Framework\App::container()[ \Fabrement\Modules\Mcp\Registrar::class ]->ability_names();' );
        $this->assertNotEmpty( $registered );
        $this->assertEqualsCanonicalizing( str_replace( '/', '-', $registered ), $names );
    }

    public function test_a_wrong_password_gets_no_session(): void {
        $answer = ( new McpClient( $this->site(), 'admin', 'wrong password' ) )->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'x', 'version' => '1' ] ] );
        $this->assertContains( $answer['_http']['status'], [ 401, 403 ] );
    }

    public function test_the_sites_own_code_runs_through_wp_cli(): void {
        $this->assertSame( rtrim( $this->site()->url( '' ), '/' ), $this->site()->php( 'echo home_url();' ) );
    }
}
