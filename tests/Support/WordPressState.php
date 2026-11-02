<?php

namespace Fabrement\Tests;

/** In-memory WordPress boundaries; no database, HTTP or filesystem mutation. */
final class WordPressState {
    public static \WP_User $user;
    public static bool $multisite = false;
    public static bool $file_mods = true;
    public static bool $valid_nonce = true;
    public static array $types = [];
    public static array $posts = [];
    public static array $transients = [];
    public static array $options = [];
    public static array $scheduled = [];
    public static array $failed_options = [];
    public static bool $fail_schedule = false;
    public static array $post_meta = [];
    public static array $http_requests = [];
    public static array $http_responses = [];

    public static function reset(): void {
        $_POST = [];
        $_GET = [];
        self::$types = self::$posts = self::$transients = [];
        self::$options = self::$post_meta = [];
        self::$scheduled = [];
        self::$failed_options = [];
        self::$fail_schedule = false;
        $GLOBALS['wpdb'] = new OAuthDatabase();
        self::$http_requests = self::$http_responses = [];
        self::$valid_nonce = true;
        self::select_user( 0, [] );
        // Leaves no MCP session behind (select_mcp_user).
        $GLOBALS['current_user'] = null;
        $role = new \ReflectionProperty( \Fabrement\Modules\Mcp\McpCapabilityCeiling::class, 'editor_caps' );
        $role->setAccessible( true );
        $role->setValue( null, null );
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

    /** A site's Editor role (trimmed), the most an MCP session holds. */
    public const EDITOR_ROLE = [
        'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'edit_pages', 'edit_others_pages',
        'publish_pages', 'upload_files', 'manage_categories', 'unfiltered_html', 'moderate_comments',
    ];

    /**
     * Signs the user in on the MCP route: their caps pass McpCapabilityCeiling, as its
     * user_has_cap filter does on a site, with EDITOR_ROLE as the site's Editor role.
     */
    public static function select_mcp_user( int $id, array $caps ): void {
        self::select_user( $id, $caps );
        $user = ( new \ReflectionClass( CappedUser::class ) )->newInstanceWithoutConstructor();
        foreach ( get_object_vars( self::$user ) as $key => $value ) { $user->$key = $value; }
        self::$user = $user;
        $GLOBALS['current_user'] = $user;
        $role = new \ReflectionProperty( \Fabrement\Modules\Mcp\McpCapabilityCeiling::class, 'editor_caps' );
        $role->setAccessible( true );
        $role->setValue( null, static fn(): array => array_fill_keys( self::EDITOR_ROLE, true ) );
    }
}

/** A user whose caps go through the MCP ceiling, as core's has_cap with the ceiling's filter. */
final class CappedUser extends \WP_User {
    public function has_cap( $cap, ...$args ) {
        $caps = map_meta_cap( $cap, $this->ID, ...$args );
        $all  = \Fabrement\Modules\Mcp\McpCapabilityCeiling::withhold( $this->allcaps, $caps, array_merge( [ $cap, $this->ID ], $args ), $this );
        foreach ( $caps as $one ) {
            if ( empty( $all[ $one ] ) ) { return false; }
        }
        return true;
    }
}

final class TestRedirect extends \RuntimeException {}
