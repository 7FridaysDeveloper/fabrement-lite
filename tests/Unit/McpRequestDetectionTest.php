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
        unset( $_GET['rest_route'], $_SERVER['REQUEST_URI'] );
    }

    public function test_the_mcp_route_by_path_or_by_parameter(): void {
        $_SERVER['REQUEST_URI'] = '/wp-json/fabrement/v1/mcp';
        self::assertTrue( McpBootstrap::is_mcp_request() );
        unset( $_SERVER['REQUEST_URI'] );
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        self::assertTrue( McpBootstrap::is_mcp_request() );
    }

    public function test_the_mcp_path_with_another_route_in_the_parameter_is_not_mcp(): void {
        $_SERVER['REQUEST_URI'] = '/wp-json/fabrement/v1/mcp?rest_route=/contact-form-7/v1/contact-forms/5/feedback';
        $_GET['rest_route']     = '/contact-form-7/v1/contact-forms/5/feedback';
        self::assertFalse( McpBootstrap::is_mcp_request() );
    }
}
