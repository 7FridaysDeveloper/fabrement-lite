<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Lifecycle\SingleSiteOnly;
use PHPUnit\Framework\TestCase;

/**
 * Fabrement stores everything per install — one blocks folder, plain options for the credentials
 * and the project binding, an OAuth table built from this site's prefix. A network breaks those
 * assumptions quietly, so the plugin refuses to run on one rather than half-working.
 */
final class SingleSiteOnlyTest extends TestCase {

    protected function setUp(): void {
        WordPressState::reset();
        $GLOBALS['current_screen'] = (object) [ 'id' => 'plugins' ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['current_screen'] );
    }

    private function admin( bool $multisite ): void {
        WordPressState::select_user( 1, [ 'activate_plugins', 'manage_options' ], $multisite );
    }

    public function test_a_plain_install_is_the_supported_one(): void {
        $this->admin( false );

        self::assertTrue( SingleSiteOnly::supported() );
    }

    public function test_a_network_is_not(): void {
        $this->admin( true );

        self::assertFalse( SingleSiteOnly::supported() );
    }

    public function test_a_plain_install_is_never_stopped_from_activating(): void {
        $this->admin( false );

        // wp_die() throws in this harness, so returning normally IS the behaviour under test.
        SingleSiteOnly::refuse_activation();

        self::assertTrue( SingleSiteOnly::supported() );
    }

    public function test_the_notice_says_why_and_names_multisite(): void {
        $this->admin( true );

        ob_start();
        SingleSiteOnly::notice();
        $html = (string) ob_get_clean();

        self::assertStringContainsString( 'multisite', $html );
        self::assertStringContainsString( 'notice-error', $html );
    }

    public function test_nothing_is_said_on_an_install_that_is_fine(): void {
        $this->admin( false );

        ob_start();
        SingleSiteOnly::notice();

        self::assertSame( '', (string) ob_get_clean() );
    }

    public function test_the_notice_stays_off_other_admin_screens(): void {
        $this->admin( true );
        $GLOBALS['current_screen'] = (object) [ 'id' => 'dashboard' ];

        ob_start();
        SingleSiteOnly::notice();

        self::assertSame( '', (string) ob_get_clean() );
    }

    public function test_a_visitor_who_could_not_act_on_it_is_not_shown_it(): void {
        WordPressState::select_user( 2, [ 'read' ], true );

        ob_start();
        SingleSiteOnly::notice();

        self::assertSame( '', (string) ob_get_clean() );
    }

    public function test_both_guards_give_the_same_reason(): void {
        $this->admin( true );

        ob_start();
        SingleSiteOnly::notice();
        $html = (string) ob_get_clean();

        // One sentence, one source: an admin must not get two different explanations depending on
        // which way they ran into it.
        self::assertStringContainsString( esc_html( SingleSiteOnly::message() ), $html );
    }
}
