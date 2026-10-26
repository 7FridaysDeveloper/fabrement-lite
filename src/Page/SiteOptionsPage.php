<?php

namespace Fabrement\Page;

use Fabrement\Modules\Fields\Admin\OptionsAdmin;
use Fabrement\Modules\Fields\Schema\SchemaService;

/**
 * Fabrement → Site General Settings: the options pages (options.<slug>.json) as tabs of one screen.
 *
 * Each options page stays its own admin page (admin.php?page=fabrement-options-<slug>), registered
 * without a menu entry; the tabs link between them and the menu entry opens the first. So a page
 * keeps its address, and the menu does not grow an entry per page. OptionsAdmin owns the form, its
 * scripts and the language switch.
 *
 * Open to whoever may edit site settings (manage_options), not only to block builders: editors
 * fill these values; the pages themselves are defined by the agent (save-options-page).
 */
final class SiteOptionsPage extends AbstractAdminPage {

    public const SLUG = 'fabrement-options';

    public function get_capability(): string {
        return 'manage_options';
    }

    public function get_page_title(): string {
        return __( 'Fabrement Lite — Site General Settings', 'fabrement-lite' );
    }

    public function get_menu_slug(): string {
        return self::SLUG;
    }

    public function get_menu_parent_slug(): ?string {
        return 'fabrement';
    }

    protected function template_name(): string {
        return 'site-options';
    }

    public function register_menu(): void {
        $hook = add_submenu_page(
            $this->get_menu_parent_slug() ?? '',
            $this->get_page_title(),
            __( 'Site General Settings', 'fabrement-lite' ),
            $this->get_capability(),
            $this->get_menu_slug(),
            [ $this, 'render' ],
            25
        );
        if ( is_string( $hook ) ) {
            add_action( 'load-' . $hook, [ $this, 'open_first' ] );
        }
        // One admin page per options page, out of the menu (no parent): the tabs lead to them.
        foreach ( $this->pages() as $page ) {
            $page_hook = add_submenu_page( '', $page['title'], $page['title'], $this->get_capability(), OptionsAdmin::menu_slug( $page['slug'] ), [ $this, 'render' ] );
            if ( is_string( $page_hook ) ) {
                add_action( 'load-' . $page_hook, [ $this, 'file_under_fabrement' ] );
            }
        }
    }

    /**
     * On an options page, Fabrement is the open menu and Site General Settings the current entry.
     *
     * Runs once WordPress has checked access (load-{page}). A page with no parent sits in the
     * submenu list under '', where get_admin_page_parent() — called again right before the menu
     * is drawn — finds it and sets the menu's parent back to '', undoing the parent_file filter.
     * Taken out of that list, the lookup finds nothing and keeps the filter's answer.
     */
    public function file_under_fabrement(): void {
        $screen = OptionsAdmin::menu_slug( (string) ( $this->current()['slug'] ?? '' ) );
        // Out of the menu, WordPress cannot look the page's title up: give it before the header asks.
        $GLOBALS['title'] = (string) ( $this->current()['title'] ?? $this->get_page_title() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the admin header reads the screen title from this global.
        remove_submenu_page( '', $screen );
        add_filter( 'parent_file', static fn() => 'fabrement', 999 );
    }

    public function register_hooks(): void {
        $this->register_admin_assets(
            function (): void {
                $this->enqueue_tab_layout();
                $this->add_screen_css(
                    '.fabrement-site-options .fabrement-options-form { margin-top: 16px; }'
                    . '.fabrement-site-options .subsubsub { float: none; margin: 16px 0 0; }'
                    . '.fabrement-site-options__empty { max-width: 640px; }'
                );
                $this->enqueue_shared_topnav();
                $page = $this->current();
                if ( $page ) {
                    OptionsAdmin::enqueue_form( $page['slug'] );
                }
            }
        );
        // On an options page the entry that leads to it is the current one.
        add_filter( 'submenu_file', fn( $file ) => $this->current() ? self::SLUG : $file, 999 );
    }

    /** The menu entry opens the first options page; with none, the screen says how to make one. */
    public function open_first(): void {
        $first = $this->pages()[0] ?? null;
        if ( $first ) {
            wp_safe_redirect( OptionsAdmin::url( $first['slug'], OptionsAdmin::picked_language() ) );
            exit;
        }
    }

    /**
     * Every options page, in menu order.
     *
     * @return list<array<string, mixed>>
     */
    public function pages(): array {
        return SchemaService::options_pages();
    }

    /** The options page on screen (?page=fabrement-options-<slug>), or null. */
    public function current(): ?array {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which admin page is open; nothing is written.
        $screen = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( strpos( $screen, self::SLUG . '-' ) !== 0 ) {
            return null;
        }
        foreach ( $this->pages() as $page ) {
            if ( OptionsAdmin::menu_slug( $page['slug'] ) === $screen ) {
                return $page;
            }
        }
        return null;
    }

    public function tab_url( string $slug ): string {
        // Another page's tab opens in the language on screen, not back in the original.
        return OptionsAdmin::url( $slug, OptionsAdmin::picked_language() );
    }

    protected function matches_admin_hook( string $hook ): bool {
        return $hook === self::hook_submenu( 'fabrement', self::SLUG ) || strpos( $hook, 'admin_page_' . self::SLUG . '-' ) === 0;
    }
}
