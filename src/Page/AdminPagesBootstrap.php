<?php

namespace Fabrement\Page;

/**
 * Registers {@see AbstractAdminPage} screens. Top-level items first (`get_menu_parent_slug()` is null),
 * then submenus when the parent slug is already registered (order of {@see register()} calls does not matter).
 *
 * Each screen should be its own class extending {@see AbstractAdminPage}.
 *
 * Call {@see register()} before {@see init()} (see `src/Page/index.php`).
 *
 */
final class AdminPagesBootstrap {

    /** @var list<AbstractAdminPage> */
    private static array $pages = [];

    public static function register( AbstractAdminPage $page ): void {
        self::$pages[] = $page;
    }

    /**
     * @throws \LogicException
     */
    public static function init(): void {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        if ( self::$pages === [] ) {
            return;
        }

        self::assert_unique_menu_slugs();

        add_action( 'admin_menu', [ self::class, 'register_menus' ] );

        foreach ( self::$pages as $page ) {
            $page->register_hooks();
        }
    }

    /**
     * @throws \LogicException When two pages use the same {@see AbstractAdminPage::get_menu_slug()}.
     */
    private static function assert_unique_menu_slugs(): void {
        $seen = [];

        foreach ( self::$pages as $page ) {
            $slug = $page->get_menu_slug();

            if ( isset( $seen[ $slug ] ) ) {
                throw new \LogicException(
                    sprintf(
                        'Duplicate admin menu slug "%s" — each page must return a unique get_menu_slug().',
                        esc_html( $slug )
                    )
                );
            }

            $seen[ $slug ] = true;
        }
    }

    /**
     * Must be public: registered as `admin_menu` callback (WordPress invokes from outside this class).
     */
    public static function register_menus(): void {
        $roots = [];
        $subs  = [];

        foreach ( self::$pages as $page ) {
            $parent = $page->get_menu_parent_slug();

            if ( $parent === null || $parent === '' ) {
                $roots[] = $page;
            } else {
                $subs[] = $page;
            }
        }

        $registered_slugs = [];

        foreach ( $roots as $page ) {
            $page->register_menu();
            $registered_slugs[ $page->get_menu_slug() ] = true;
        }

        while ( $subs !== [] ) {
            $pending  = [];
            $progress = false;

            foreach ( $subs as $page ) {
                $parent_slug = $page->get_menu_parent_slug();
                if ( is_string( $parent_slug ) && isset( $registered_slugs[ $parent_slug ] ) ) {
                    $page->register_menu();
                    $registered_slugs[ $page->get_menu_slug() ] = true;
                    $progress = true;
                } else {
                    $pending[] = $page;
                }
            }

            $subs = $pending;

            if ( ! $progress ) {
                foreach ( $subs as $page ) {
                    $page->register_menu();
                    $registered_slugs[ $page->get_menu_slug() ] = true;
                }

                break;
            }
        }
    }
}
