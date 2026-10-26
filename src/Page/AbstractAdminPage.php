<?php

namespace Fabrement\Page;

use Closure;
use Fabrement\Framework\App;

/**
 * Base for wp-admin screens: capability, view template, conditional asset enqueue.
 *
 * Subclasses implement {@see register_menu()} and {@see matches_admin_hook()} (see {@see hook_top_level()} / {@see hook_submenu()}).
 */
abstract class AbstractAdminPage {

    /**
     * Directory for admin PHP views, relative to the plugin root (`templates/pages/`).
     */
    private const TEMPLATE_PAGES_REL = 'templates/pages';

    abstract public function get_capability(): string;

    abstract public function get_page_title(): string;

    /**
     * Unique admin menu slug for this screen (`add_menu_page` / `add_submenu_page` 4th argument).
     */
    abstract public function get_menu_slug(): string;

    /**
     * For {@see AdminPagesBootstrap}: parent menu slug if this page is a submenu; `null` for a top-level item.
     */
    public function get_menu_parent_slug(): ?string {
        return null;
    }

    /**
     * View name under `templates/pages/` — only the inner path: `templates`, `templates.php`, or `nested/templates`.
     */
    abstract protected function template_name(): string;

    /**
     * Register `add_menu_page` / `add_submenu_page` and pass `[ $this, 'render' ]` as callback where needed.
     */
    abstract public function register_menu(): void;

    /**
     * Extra hooks (e.g. {@see register_admin_assets()}, `admin_init`). Called once per page from {@see AdminPagesBootstrap::init()}.
     */
    public function register_hooks(): void {
    }

    /**
     * `$hook` from `admin_enqueue_scripts` — return true only for this screen.
     */
    abstract protected function matches_admin_hook( string $hook ): bool;

    /**
     * `toplevel_page_{$menu_slug}`
     */
    protected static function hook_top_level( string $menu_slug ): string {
        return 'toplevel_page_' . $menu_slug;
    }

    /**
     * The hook of a child of a custom top-level menu, as WordPress builds it. Its first part is
     * the parent's sanitized MENU TITLE, not its slug: `fabrement_page_{$submenu_slug}` only while
     * the title reads "Fabrement". A translated title ("Фабремент") gives another prefix, and a
     * hook assembled from the slug would then match no screen — every Fabrement page would lose
     * its CSS and JS without an error.
     */
    protected static function hook_submenu( string $parent_menu_slug, string $submenu_slug ): string {
        if ( function_exists( 'get_plugin_page_hookname' ) ) {
            return get_plugin_page_hookname( $submenu_slug, $parent_menu_slug );
        }
        return $parent_menu_slug . '_page_' . $submenu_slug;
    }

    /**
     * Load `templates/pages/` + {@see template_name()} (appends `.php` if missing); in the view, `$page` is this instance.
     */
    final public function render(): void {
        if ( ! current_user_can( $this->get_capability() ) ) {
            return;
        }

        $path = App::path( $this->resolved_template_relative_path() );
        if ( ! is_readable( $path ) ) {
            return;
        }

        $page = $this;
        include $path;
    }


    /**
     * Run `$callback( $hook )` on `admin_enqueue_scripts` only when {@see matches_admin_hook()} is true.
     * Use for `wp_enqueue_script` and/or `wp_enqueue_style`.
     *
     * @param Closure(string): void $callback
     */
    protected function register_admin_assets( Closure $callback ): void {
        add_action(
            'admin_enqueue_scripts',
            function ( string $hook ) use ( $callback ): void {
                if ( ! $this->matches_admin_hook( $hook ) ) {
                    return;
                }
                $callback( $hook );
            }
        );
    }

    /**
     * The one layout every screen in the plugin's menu is built on: full-bleed page,
     * the 40/32/60 content column and the page head (see assets/admin/tab-layout.css).
     *
     * Call it FIRST in a screen's {@see register_admin_assets()} callback — before any
     * early return for a missing JS build — so the page keeps its shape even when a
     * bundle is absent. WordPress de-duplicates the handle, so calling it twice is free.
     */
    protected function enqueue_tab_layout(): void {
        $this->enqueue_admin_style( 'fabrement-tab-layout', 'tab-layout.css' );
    }

    /**
     * A few rules only this screen needs, printed with the shared layout stylesheet
     * ({@see enqueue_tab_layout()}, which must be enqueued first) instead of a <style>
     * tag in the template.
     */
    protected function add_screen_css( string $css ): void {
        wp_add_inline_style( 'fabrement-tab-layout', $css );
    }

    /**
     * The card skin — see assets/admin/credentials-card.css. Used by MCP Connection.
     */
    protected function enqueue_credentials_card(): void {
        $this->enqueue_admin_style( 'fabrement-credentials-card', 'credentials-card.css' );
    }

    /**
     * A stylesheet from assets/admin/, versioned by its modification time. Nothing when the
     * file is missing.
     *
     * @param list<string> $deps
     */
    protected function enqueue_admin_style( string $handle, string $file, array $deps = [] ): void {
        $rel  = 'assets/admin/' . $file;
        $path = App::path( $rel );
        if ( ! is_readable( $path ) ) {
            return;
        }
        wp_enqueue_style( $handle, App::url( $rel ), $deps, (string) filemtime( $path ) );
    }

    /**
     * A script from assets/admin/, in the footer: it works on the screen's markup, which is
     * printed by then. False when the file is missing, so the caller skips its data.
     *
     * @param list<string> $deps
     */
    protected function enqueue_admin_script( string $handle, string $file, array $deps = [] ): bool {
        $rel  = 'assets/admin/' . $file;
        $path = App::path( $rel );
        if ( ! is_readable( $path ) ) {
            return false;
        }
        wp_enqueue_script( $handle, App::url( $rel ), $deps, (string) filemtime( $path ), true );
        return true;
    }

    /** Lite navigation is server-rendered and has no account or chat bundle. */
    protected function enqueue_shared_topnav(): void {
        \Fabrement\Framework\UiFonts::enqueue();
        $this->enqueue_admin_style( 'fabrement-lite-nav', 'lite-nav.css' );
    }

    private function resolved_template_relative_path(): string {
        $name = trim( str_replace( '\\', '/', $this->template_name() ), '/' );
        if ( $name === '' ) {
            return '';
        }
        if ( ! str_ends_with( $name, '.php' ) ) {
            $name .= '.php';
        }

        return self::TEMPLATE_PAGES_REL . '/' . $name;
    }
}
