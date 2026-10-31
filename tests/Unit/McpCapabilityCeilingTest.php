<?php

namespace Fabrement\Tests;

use Fabrement\Capabilities;
use Fabrement\Modules\Mcp\McpCapabilityCeiling;
use PHPUnit\Framework\TestCase;

final class McpCapabilityCeilingTest extends TestCase {

    private $previous;

    /** A site's Editor role, as core and Yoast set it up (trimmed). */
    private const EDITOR = [
        'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'edit_pages' => true,
        'edit_others_pages' => true, 'publish_pages' => true, 'upload_files' => true, 'manage_categories' => true,
        'unfiltered_html' => true, 'moderate_comments' => true, 'level_7' => true, 'wpseo_bulk_edit' => true,
        'wpseo_edit_advanced_metadata' => true,
    ];

    private \ReflectionProperty $role;

    protected function setUp(): void {
        $this->previous = $GLOBALS['current_user'] ?? null;
        $this->role     = new \ReflectionProperty( McpCapabilityCeiling::class, 'editor_caps' );
        $this->role->setAccessible( true );
        $this->use_editor_role( self::EDITOR );
    }

    protected function tearDown(): void {
        $GLOBALS['current_user'] = $this->previous;
        $this->role->setValue( null, null );
        WordPressState::reset();
    }

    private function use_editor_role( array $caps ): void {
        $this->role->setValue( null, static fn(): array => $caps );
    }

    private static function user( int $id ): \WP_User {
        $user     = ( new \ReflectionClass( \WP_User::class ) )->newInstanceWithoutConstructor();
        $user->ID = $id;
        return $user;
    }

    private static function admin_caps(): array {
        return [
            'administrator' => true, 'manage_options' => true, 'activate_plugins' => true, 'edit_users' => true,
            'edit_theme_options' => true, 'install_languages' => true, 'view_site_health_checks' => true, 'level_10' => true,
            'unfiltered_html' => true, 'edit_posts' => true, 'edit_others_pages' => true, 'publish_posts' => true,
            'upload_files' => true, 'manage_categories' => true, 'wpseo_manage_options' => true,
        ];
    }

    public function test_the_signed_in_administrator_keeps_an_editors_caps_only(): void {
        $admin                   = self::user( 1 );
        $GLOBALS['current_user'] = $admin;

        $caps = McpCapabilityCeiling::withhold( self::admin_caps(), [], [], $admin );

        foreach ( [ 'administrator', 'manage_options', 'activate_plugins', 'edit_users', 'edit_theme_options', 'install_languages', 'view_site_health_checks', 'level_10', 'wpseo_manage_options' ] as $cap ) {
            self::assertFalse( $caps[ $cap ], $cap );
        }
        foreach ( [ 'edit_posts', 'edit_others_pages', 'publish_posts', 'upload_files', 'manage_categories', 'unfiltered_html' ] as $cap ) {
            self::assertTrue( $caps[ $cap ], $cap );
        }
        self::assertTrue( $caps['wpseo_edit_advanced_metadata'], 'Yoast\'s advanced fields, as an Editor has them' );
    }

    public function test_another_user_and_no_signed_in_user_are_left_alone(): void {
        $GLOBALS['current_user'] = self::user( 5 );
        self::assertSame( self::admin_caps(), McpCapabilityCeiling::withhold( self::admin_caps(), [], [], self::user( 1 ) ) );

        $GLOBALS['current_user'] = null;
        self::assertSame( self::admin_caps(), McpCapabilityCeiling::withhold( self::admin_caps(), [], [], self::user( 1 ) ) );
    }

    /** Other plugins' caps an Editor does not hold read as absent: an allowlist, not a denylist. */
    public function test_caps_the_editor_role_lacks_read_as_absent(): void {
        $admin                   = self::user( 1 );
        $GLOBALS['current_user'] = $admin;
        $caps = McpCapabilityCeiling::withhold( [ 'edit_posts' => true, 'manage_woocommerce' => true, 'edit_products' => true, 'gravityforms_edit_settings' => true ], [], [], $admin );
        self::assertSame( [ 'edit_posts' => true, 'manage_woocommerce' => false, 'edit_products' => false, 'gravityforms_edit_settings' => false ], $caps );
    }

    /** An Editor role given an administrator's cap does not pass it on. */
    public function test_administrator_caps_never_count_even_in_the_editor_role(): void {
        $this->use_editor_role( self::EDITOR + [ 'manage_options' => true, 'activate_plugins' => true ] );
        $admin                   = self::user( 1 );
        $GLOBALS['current_user'] = $admin;
        $caps = McpCapabilityCeiling::withhold( self::admin_caps(), [], [], $admin );
        self::assertFalse( $caps['manage_options'] );
        self::assertFalse( $caps['activate_plugins'] );
    }

    /** No Editor role on the site: no content caps on MCP. */
    public function test_without_an_editor_role_nothing_is_kept(): void {
        $this->use_editor_role( [] );
        $editor                  = self::user( 3 );
        $GLOBALS['current_user'] = $editor;
        self::assertSame( [ 'edit_posts' => false ], McpCapabilityCeiling::withhold( [ 'edit_posts' => true ], [], [], $editor ) );
    }

    /** During an MCP request the Administrator holds an Editor's caps; who may connect is asked past that. */
    public function test_can_connect_reads_past_the_ceiling_and_only_there(): void {
        WordPressState::select_mcp_user( 1, [ 'edit_others_posts', ...array_keys( self::admin_caps() ) ] );
        self::assertFalse( current_user_can( 'manage_options' ), 'the session holds what an Editor holds' );
        self::assertTrue( current_user_can( 'edit_others_pages' ) );
        self::assertTrue( Capabilities::can_connect() );
        self::assertTrue( Capabilities::can_connect( WordPressState::$user ) );
        // The ceiling is back once the check is done.
        self::assertFalse( current_user_can( 'manage_options' ) );
        self::assertTrue( current_user_can( 'edit_others_posts' ), 'the caps a client needs survive the ceiling' );

        // An Editor gains nothing past the ceiling.
        WordPressState::select_mcp_user( 3, WordPressState::EDITOR_ROLE );
        self::assertTrue( current_user_can( 'edit_others_posts' ) );
        self::assertFalse( Capabilities::can_connect() );
    }

    public function test_uncapped_restores_the_ceiling_after_a_failed_check(): void {
        WordPressState::select_mcp_user( 1, array_keys( self::admin_caps() ) );
        try {
            McpCapabilityCeiling::uncapped( static function (): void { throw new \RuntimeException( 'check failed' ); } );
        } catch ( \RuntimeException $error ) {
            self::assertSame( 'check failed', $error->getMessage() );
        }
        self::assertFalse( current_user_can( 'manage_options' ) );
    }

    public function test_a_user_without_yoast_settings_gains_nothing(): void {
        $editor                  = self::user( 3 );
        $GLOBALS['current_user'] = $editor;
        $caps                    = McpCapabilityCeiling::withhold( [ 'edit_posts' => true ], [], [], $editor );
        self::assertSame( [ 'edit_posts' => true ], $caps );
    }
}
