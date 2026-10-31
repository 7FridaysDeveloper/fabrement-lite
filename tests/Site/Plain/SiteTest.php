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
            $password = trim( $this->site()->wp( 'user', 'application-password', 'create', (string) $user, 'qa', '--porcelain' ) );
            $answer   = ( new McpClient( $this->site(), 'qa-' . $role, $password ) )->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'qa', 'version' => '1' ] ] );
            $this->assertSame( 403, $answer['_http']['status'], $role );
        }
    }

    public function test_the_sites_own_code_runs_through_wp_cli(): void {
        $this->assertSame( rtrim( $this->site()->url( '' ), '/' ), $this->site()->php( 'echo home_url();' ) );
    }
}
