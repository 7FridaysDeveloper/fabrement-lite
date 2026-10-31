<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\BearerApplicationPasswordAuth;
use PHPUnit\Framework\TestCase;

final class McpBearerApplicationPasswordAuthTest extends TestCase {

    protected function setUp(): void {
        WordPressState::reset();
    }

    protected function tearDown(): void {
        unset(
            $_GET['rest_route'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['PHP_AUTH_USER'],
            $_SERVER['PHP_AUTH_PW']
        );
        WordPressState::reset();
    }

    public function test_bearer_token_logs_in_through_core_on_mcp_route(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' );

        self::assertSame( 5, BearerApplicationPasswordAuth::authenticate( null ) );
        self::assertSame( [ [ 'editor', 'SYNTHETIC_PASSWORD' ] ], WordPressState::$password_logins );
        self::assertArrayNotHasKey( 'PHP_AUTH_USER', $_SERVER );
        self::assertArrayNotHasKey( 'PHP_AUTH_PW', $_SERVER );
    }

    public function test_bearer_token_logs_in_on_pretty_rest_url_and_redirect_header(): void {
        $_SERVER['REQUEST_URI'] = '/subsite/wp-json/fabrement/v1/mcp';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' );

        self::assertSame( 5, BearerApplicationPasswordAuth::authenticate( null ) );
    }

    public function test_wrong_password_leaves_the_user_undetermined(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:WRONG' );

        self::assertNull( BearerApplicationPasswordAuth::authenticate( null ) );
        self::assertFalse( BearerApplicationPasswordAuth::authenticate( false ) );
        self::assertCount( 2, WordPressState::$password_logins );
    }

    public function test_bearer_token_is_ignored_outside_mcp_route(): void {
        $_GET['rest_route'] = '/wp/v2/posts';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' );

        self::assertNull( BearerApplicationPasswordAuth::authenticate( null ) );
        self::assertSame( [], WordPressState::$password_logins );
    }

    public function test_existing_basic_auth_and_logged_in_users_are_left_alone(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' );
        self::assertSame( 123, BearerApplicationPasswordAuth::authenticate( 123 ) );

        $_SERVER['PHP_AUTH_USER'] = 'existing';
        $_SERVER['PHP_AUTH_PW'] = 'secret';
        self::assertNull( BearerApplicationPasswordAuth::authenticate( null ) );
        self::assertSame( [], WordPressState::$password_logins );
    }

    public function test_nothing_is_checked_when_application_passwords_are_off(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . base64_encode( 'editor:SYNTHETIC_PASSWORD' );
        WordPressState::$passwords_available = false;

        self::assertNull( BearerApplicationPasswordAuth::authenticate( null ) );
        self::assertSame( [], WordPressState::$password_logins );
    }

    public function test_malformed_bearer_token_is_ignored(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        foreach ( [ 'Bearer not-base64', 'Bearer ' . base64_encode( 'no-colon' ), 'Bearer ' . base64_encode( ':password' ), 'Basic abc' ] as $header ) {
            $_SERVER['HTTP_AUTHORIZATION'] = $header;
            self::assertNull( BearerApplicationPasswordAuth::authenticate( null ), $header );
        }
        self::assertSame( [], WordPressState::$password_logins );
    }

    public function test_o_auth_access_token_is_not_an_application_password(): void {
        $_GET['rest_route'] = '/fabrement/v1/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fabrement_oauth_at_' . str_repeat( 'a', 64 );
        self::assertNull( BearerApplicationPasswordAuth::authenticate( null ) );
        self::assertSame( [], WordPressState::$password_logins );
    }
}
