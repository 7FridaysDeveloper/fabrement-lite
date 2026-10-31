<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\McpBootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Whether this request is an MCP call: it decides that the site's generated code stays off. WordPress
 * runs the ?rest_route= route whatever the path says, so when it is there it alone decides — or the
 * MCP path with another route behind it would run that route with the site's own code switched off.
 */
final class McpRequestDetectionTest extends TestCase {

    protected function tearDown(): void {
        unset( $_GET['rest_route'], $_POST['rest_route'], $_SERVER['REQUEST_URI'] );
    }

    public function test_the_mcp_route_by_path_or_by_parameter(): void {
        // The test site's home is https://example.test/subsite.
        foreach ( [ '/subsite/wp-json/fabrement/v1/mcp', '/subsite/wp-json/fabrement/v1/mcp/', '/subsite/index.php/wp-json/fabrement/v1/mcp' ] as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            self::assertTrue( McpBootstrap::is_mcp_request(), $uri );
        }
        unset( $_SERVER['REQUEST_URI'] );
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        self::assertTrue( McpBootstrap::is_mcp_request() );
    }

    public function test_the_mcp_path_with_another_route_in_the_parameter_is_not_mcp(): void {
        $_SERVER['REQUEST_URI'] = '/wp-json/fabrement/v1/mcp?rest_route=/contact-form-7/v1/contact-forms/5/feedback';
        $_GET['rest_route']     = '/contact-form-7/v1/contact-forms/5/feedback';
        self::assertFalse( McpBootstrap::is_mcp_request() );
    }

    /** WP::parse_request() takes a POSTed rest_route over the path: the route served is that one. */
    public function test_the_mcp_path_with_a_posted_rest_route_is_not_mcp(): void {
        $_SERVER['REQUEST_URI'] = '/subsite/wp-json/fabrement/v1/mcp';
        $_POST['rest_route']    = '/wp/v2/users/me/application-passwords';
        self::assertFalse( McpBootstrap::is_mcp_request() );
    }

    public function test_only_the_exact_mcp_path_under_home_is_mcp(): void {
        foreach ( [ '/elsewhere/wp-json/fabrement/v1/mcp', '/subsite/x/wp-json/fabrement/v1/mcp', '/subsite/wp-json/fabrement/v1/mcp/extra', '/wp-json/fabrement/v1/mcp' ] as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            self::assertFalse( McpBootstrap::is_mcp_request(), $uri );
        }
    }
}
