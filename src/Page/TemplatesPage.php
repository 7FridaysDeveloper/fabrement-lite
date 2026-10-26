<?php

namespace Fabrement\Page;

use Fabrement\Capabilities;
use Fabrement\Framework\App;

/**
 * Fabrement → Templates: the templates as cards — what each is assigned to, its languages and
 * its content-source rules. A template's blocks are edited in Gutenberg, opened from its card.
 *
 * @see assets/templates-app/src/pages/site-templates-page.tsx
 */
final class TemplatesPage extends AbstractAdminPage {

    public const SLUG = 'fabrement-templates';

    private const SCRIPT = 'assets/templates-app/build/templates-app.js';

    public function get_capability(): string {
        return Capabilities::ADMIN;
    }

    public function get_page_title(): string {
        return __( 'Fabrement Lite — Templates', 'fabrement-lite' );
    }

    public function get_menu_slug(): string {
        return self::SLUG;
    }

    public function get_menu_parent_slug(): ?string {
        return 'fabrement';
    }

    protected function template_name(): string {
        return 'templates';
    }

    public function register_menu(): void {
        add_submenu_page(
            $this->get_menu_parent_slug() ?? '',
            $this->get_page_title(),
            __( 'Templates', 'fabrement-lite' ),
            $this->get_capability(),
            $this->get_menu_slug(),
            [ $this, 'render' ],
            20
        );
    }

    public function register_hooks(): void {
        $this->register_admin_assets(
            function (): void {
                $this->enqueue_tab_layout();
                $this->enqueue_shared_topnav();
                $path = App::path( self::SCRIPT );
                if ( ! is_readable( $path ) ) {
                    return;
                }
                // React comes from WordPress; the bundle carries its own styles.
                wp_enqueue_script( 'fabrement-templates-app', App::url( self::SCRIPT ), [ 'react', 'react-dom', 'wp-api-fetch', 'wp-i18n' ], (string) filemtime( $path ), true );
                wp_set_script_translations( 'fabrement-templates-app', 'fabrement-lite', App::path( 'languages' ) );
                wp_localize_script( 'fabrement-templates-app', 'fabrementTemplatesApp', [ 'restNonce' => wp_create_nonce( 'wp_rest' ) ] );
            }
        );
    }

    /** Whether the screen's bundle is in this install. */
    public function has_app(): bool {
        return is_readable( App::path( self::SCRIPT ) );
    }

    protected function matches_admin_hook( string $hook ): bool {
        return $hook === self::hook_submenu( 'fabrement', self::SLUG );
    }
}
