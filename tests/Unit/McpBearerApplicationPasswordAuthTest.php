<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\BearerApplicationPasswordAuth;
use PHPUnit\Framework\TestCase;

final class McpBearerApplicationPasswordAuthTest extends TestCase {

    protected function tearDown(): void {
        unset(
            $_GET['rest_route'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['PHP_AUTH_USER'],
            $_SERVER['PHP_AUTH_PW']
        );
    }

    public function test_bearer_token_maps_to_basic_auth_on_mcp_route(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' );

        self::assertNull( BearerApplicationPasswordAuth::map_bearer_to_basic_auth( null ) );
        self::assertSame( 'editor', $_SERVER['PHP_AUTH_USER'] );
        self::assertSame( 'SYNTHETIC_PASSWORD', $_SERVER['PHP_AUTH_PW'] );
    }

    public function test_bearer_token_maps_to_basic_auth_on_pretty_rest_url(): void {
        $_SERVER['REQUEST_URI'] = '/subsite/wp-json/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' );

        self::assertNull( BearerApplicationPasswordAuth::map_bearer_to_basic_auth( null ) );
        self::assertSame( 'editor', $_SERVER['PHP_AUTH_USER'] );
        self::assertSame( 'SYNTHETIC_PASSWORD', $_SERVER['PHP_AUTH_PW'] );
    }

    public function test_bearer_token_is_ignored_outside_mcp_route(): void {
        $_GET['rest_route'] = '/wp/v2/posts';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' );

        self::assertNull( BearerApplicationPasswordAuth::map_bearer_to_basic_auth( null ) );
        self::assertArrayNotHasKey( 'PHP_AUTH_USER', $_SERVER );
        self::assertArrayNotHasKey( 'PHP_AUTH_PW', $_SERVER );
    }

    public function test_existing_basic_auth_and_logged_in_users_are_left_alone(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' );
        $_SERVER['PHP_AUTH_USER'] = 'existing';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        self::assertSame( 123, BearerApplicationPasswordAuth::map_bearer_to_basic_auth( 123 ) );
        self::assertSame( 'existing', $_SERVER['PHP_AUTH_USER'] );
        self::assertSame( 'secret', $_SERVER['PHP_AUTH_PW'] );
    }

    public function test_malformed_bearer_token_is_ignored(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-base64';

        self::assertNull( BearerApplicationPasswordAuth::map_bearer_to_basic_auth( null ) );
        self::assertArrayNotHasKey( 'PHP_AUTH_USER', $_SERVER );
        self::assertArrayNotHasKey( 'PHP_AUTH_PW', $_SERVER );
    }

    public function test_o_auth_access_token_is_not_an_application_password(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fb_oauth_at_' . str_repeat( 'a', 64 );
        self::assertNull( BearerApplicationPasswordAuth::map_bearer_to_basic_auth( null ) );
        self::assertArrayNotHasKey( 'PHP_AUTH_USER', $_SERVER );
    }
}
