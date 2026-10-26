<?php

namespace Fabrement\Tests;

/** In-memory WordPress boundaries; no database, HTTP or filesystem mutation. */
final class WordPressState {
    public static \WP_User $user;
    public static bool $multisite = false;
    public static bool $file_mods = true;
    public static bool $valid_nonce = true;
    public static bool $passwords_available = true;
    public static ?\WP_Error $password_error = null;
    public static array $types = [];
    public static array $posts = [];
    public static array $transients = [];
    public static array $passwords = [];
    public static array $created_passwords = [];
    public static array $deleted_passwords = [];
    public static array $options = [];
    public static array $scheduled = [];
    public static array $failed_options = [];
    public static bool $fail_schedule = false;
    public static array $post_meta = [];
    public static array $inserted_posts = [];
    public static array $deleted_posts = [];
    public static array $http_requests = [];
    public static array $http_request_args = [];
    public static array $http_responses = [];
    public static ?\WP_Error $insert_error = null;

    public static function reset(): void {
        $_POST = [];
        $_GET = [];
        self::$types = self::$posts = self::$transients = self::$passwords = [];
        self::$created_passwords = self::$deleted_passwords = [];
        self::$options = self::$post_meta = self::$inserted_posts = self::$deleted_posts = [];
        self::$scheduled = [];
        self::$failed_options = [];
        self::$fail_schedule = false;
        $GLOBALS['wpdb'] = new OAuthDatabase();
        self::$http_requests = self::$http_responses = self::$http_request_args = [];
        self::$insert_error = null;
        self::$valid_nonce = self::$passwords_available = true;
        self::$password_error = null;
        self::select_user( 0, [] );
    }

    public static function select_user( int $id, array $caps, bool $multisite = false, bool $super = false, bool $file_mods = true ): void {
        $user = ( new \ReflectionClass( \WP_User::class ) )->newInstanceWithoutConstructor();
        $user->ID = $id;
        $user->data = (object) [ 'user_login' => 'security-user' ];
        $user->allcaps = array_fill_keys( $caps, true );
        self::$user = $user;
        self::$multisite = $multisite;
        self::$file_mods = $file_mods;
        $GLOBALS['super_admins'] = $super ? [ 'security-user' ] : [];
    }
}

final class TestRedirect extends \RuntimeException {}
