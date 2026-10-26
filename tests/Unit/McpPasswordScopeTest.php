<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\McpPasswordScope;
use PHPUnit\Framework\TestCase;

final class McpPasswordScopeTest extends TestCase {

    private const MCP = [ 'uuid' => 'mcp', 'app_id' => McpPasswordScope::APP_ID, 'name' => 'Fabrement MCP (Claude) - 2026-10-26 10:00' ];
    /** Named like ours, made by hand or for another server: only the app_id marks a password. */
    private const OTHER = [ 'uuid' => 'other', 'app_id' => '', 'name' => 'Claude MCP' ];

    protected function setUp(): void {
        WordPressState::reset();
        WordPressState::select_user( 7, [ 'manage_options' ] );
        WordPressState::$passwords[7] = [ self::MCP, self::OTHER ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp'], $_SERVER['REQUEST_URI'] );
        WordPressState::reset();
    }

    public function test_mcp_password_logs_in_on_mcp_route(): void {
        $_SERVER['REQUEST_URI'] = '/subsite/wp-json/fabrement/v1/mcp';
        $error = new \WP_Error();

        McpPasswordScope::refuse_outside_mcp( $error, null, self::MCP );

        self::assertFalse( $error->has_errors() );
    }

    public function test_mcp_password_is_refused_on_other_addresses(): void {
        foreach ( [ '/subsite/wp-json/wp/v2/plugins', '/subsite/wp-json/batch/v1', '/subsite/xmlrpc.php' ] as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            $error = new \WP_Error();
            McpPasswordScope::refuse_outside_mcp( $error, null, self::MCP );
            self::assertSame( [ 'fabrement_mcp_password_scope' ], $error->get_error_codes(), $uri );
        }
    }

    /**
     * XML-RPC runs whatever ?rest_route= the address carries.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_mcp_password_is_refused_on_xmlrpc_even_with_mcp_rest_route(): void {
        define( 'XMLRPC_REQUEST', true );
        $_GET['rest_route']     = '/fabrement/v1/mcp';
        $_SERVER['REQUEST_URI'] = '/xmlrpc.php?rest_route=/fabrement/v1/mcp';
        $error = new \WP_Error();

        McpPasswordScope::refuse_outside_mcp( $error, null, self::MCP );

        self::assertSame( [ 'fabrement_mcp_password_scope' ], $error->get_error_codes() );
    }

    public function test_other_passwords_are_left_alone(): void {
        $_SERVER['REQUEST_URI'] = '/subsite/wp-json/wp/v2/plugins';
        $error = new \WP_Error();

        McpPasswordScope::refuse_outside_mcp( $error, null, self::OTHER );

        self::assertFalse( $error->has_errors() );
    }

    public function test_served_route_other_than_mcp_is_refused(): void {
        $this->serve( '/wp/v2/users', 'mcp' );

        $result = McpPasswordScope::refuse_other_route( null );

        self::assertInstanceOf( \WP_Error::class, $result );
        self::assertSame( 403, $result->get_error_data()['status'] );
    }

    public function test_served_mcp_route_passes(): void {
        $this->serve( '/fabrement/v1/mcp/', 'mcp' );

        self::assertNull( McpPasswordScope::refuse_other_route( null ) );
    }

    public function test_other_password_or_no_password_passes(): void {
        $this->serve( '/wp/v2/plugins', 'other' );
        self::assertNull( McpPasswordScope::refuse_other_route( null ) );

        unset( $GLOBALS['wp_rest_application_password_uuid'] );
        self::assertTrue( McpPasswordScope::refuse_other_route( true ) );
    }

    public function test_earlier_error_is_kept(): void {
        $this->serve( '/wp/v2/plugins', 'mcp' );
        $earlier = new \WP_Error( 'earlier' );

        self::assertSame( $earlier, McpPasswordScope::refuse_other_route( $earlier ) );
    }

    private function serve( string $route, string $uuid ): void {
        $GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => $route ] ];
        $GLOBALS['wp_rest_application_password_uuid'] = $uuid;
    }
}
