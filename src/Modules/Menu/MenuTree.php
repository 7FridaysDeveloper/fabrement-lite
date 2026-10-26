<?php

namespace Fabrement\Modules\Menu;

/**
 * Navigation menu trees in one normalized node shape: {label, url, newTab?, children?[]}, with
 * `current` (this page) and `currentAncestor` (the current item is below it), as WordPress
 * marks them for wp_nav_menu(). Every item at any depth, as wp_nav_menu() shows them.
 */
final class MenuTree {

    /**
     * A menu value ({source, menuId, items}) resolved to its tree.
     *
     * @param mixed $value Safe for any shape.
     * @return list<array<string,mixed>>
     */
    public static function tree( $value ): array {
        return self::resolve( $value );
    }

    /**
     * Resolves a menu value ({source, menuId, items}) to a tree.
     *
     * The given `items` are used unless the value names a WordPress menu (source "wp" AND a
     * menuId > 0). There is no "first menu" guess: menuId 0 means none was named. A named menu
     * that no longer exists or is empty falls back to `items`.
     *
     * @param mixed $value
     * @return list<array<string,mixed>>
     */
    private static function resolve( $value ): array {
        $value = is_array( $value ) ? $value : [];
        $items = is_array( $value['items'] ?? null ) ? $value['items'] : [];

        if ( ( $value['source'] ?? 'manual' ) === 'wp' ) {
            $menu_id = (int) ( $value['menuId'] ?? 0 );
            $tree    = $menu_id > 0 ? self::wp_menu_tree( $menu_id ) : [];
            if ( $tree !== [] ) {
                return $tree;
            }
        }

        return self::mark_current( self::shown( self::normalize_items( $items ) ) );
    }

    /**
     * Items passed as an array are custom links to WordPress: they get the same
     * rule wp_nav_menu() gives a custom link (address of this request, ancestors).
     *
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function mark_current( array $tree ): array {
        $items = [];
        $flat  = static function ( array $nodes, int $parent ) use ( &$flat, &$items ): void {
            foreach ( $nodes as $node ) {
                $id = count( $items ) + 1;
                $items[ $id ] = (object) [
                    'ID' => $id, 'db_id' => $id, 'menu_item_parent' => $parent, 'post_parent' => 0,
                    'object_id' => 0, 'object' => 'custom', 'type' => 'custom', 'url' => (string) $node['url'], 'classes' => [],
                ];
                $flat( $node['children'] ?? [], $id );
            }
        };
        $flat( $tree, 0 );
        if ( $items === [] ) {
            return $tree;
        }
        self::classify( $items );

        // current is WordPress's custom-link rule; ancestors come from this tree, since WordPress
        // looks them up in the database, where typed-in items are not.
        $next = 0;
        $put  = static function ( array $nodes ) use ( &$put, &$next, $items ): array {
            foreach ( $nodes as $i => $node ) {
                $item     = $items[ ++$next ];
                $children = isset( $node['children'] ) ? $put( $node['children'] ) : [];
                $nodes[ $i ]['current']         = ! empty( $item->current );
                $nodes[ $i ]['currentAncestor'] = (bool) array_filter( $children, static fn( array $c ): bool => $c['current'] || $c['currentAncestor'] );
                if ( isset( $node['children'] ) ) {
                    $nodes[ $i ]['children'] = $children;
                }
            }
            return $nodes;
        };
        return $put( $tree );
    }

    /** Outside a page request (no main query) nothing is current. */
    private static function classify( array &$items ): void {
        if ( ( $GLOBALS['wp_query'] ?? null ) instanceof \WP_Query && function_exists( '_wp_menu_item_classes_by_context' ) ) {
            _wp_menu_item_classes_by_context( $items );
        }
    }

    /** WordPress's parent/ancestor marks, and current_page_parent (the blog page on a post). */
    private static function is_ancestor( object $item ): bool {
        return ! empty( $item->current_item_ancestor ) || ! empty( $item->current_item_parent )
            || ( empty( $item->current ) && in_array( 'current_page_parent', (array) ( $item->classes ?? [] ), true ) && self::in_blog() );
    }

    /** A label as text: its tags left out, its words kept ("Kids <12 & more" stays whole). */
    private static function text_of( string $title ): string {
        $text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $title );
        $text = (string) preg_replace( '@</?[a-zA-Z][^<>]*>|<!--.*?-->@s', '', $text );
        return trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }

    /**
     * WordPress gives the posts page current_page_parent on every view that is not a page (a 404, a
     * search, another type's records) for wp_page_menu()'s sake; it is a parent only of the blog's.
     */
    private static function in_blog(): bool {
        return is_singular( 'post' ) || is_category() || is_tag() || is_date() || is_author() || is_home();
    }

    /**
     * The bounded recursive tree of one WP menu — read fresh on every call so
     * Appearance → Menus edits propagate.
     *
     * @return list<array<string,mixed>>
     */
    public static function wp_menu_tree( int $menu_id ): array {
        if ( $menu_id <= 0 ) {
            return [];
        }

        // The picked menu is one language's menu, and the caller passes the same id in every
        // language — so it keeps pointing at the original's menu unless it is resolved here. Falls back to the picked one where no translation exists,
        // which is the single-language answer and also the right one mid-translation: a nav that
        // is briefly in the wrong language still navigates, an empty one does not.
        $menu_id = \Fabrement\Modules\Translation\Translation::bridge()->version_in( $menu_id, 'nav_menu' );

        $items = wp_get_nav_menu_items( $menu_id );
        if ( ! is_array( $items ) || $items === [] ) {
            return [];
        }
        // As wp_nav_menu() prepares them: WordPress's current-item rule, then the
        // wp_nav_menu_objects filter themes and plugins change menus with.
        self::classify( $items );
        $args  = (object) [
            'menu' => wp_get_nav_menu_object( $menu_id ), 'theme_location' => '', 'depth' => 0, 'echo' => false,
            'container' => false, 'menu_class' => 'menu', 'menu_id' => '', 'fallback_cb' => false, 'walker' => '',
            'before' => '', 'after' => '', 'link_before' => '', 'link_after' => '', 'items_wrap' => '%3$s', 'item_spacing' => 'preserve',
        ];
        $items = apply_filters( 'wp_nav_menu_objects', $items, $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, applied as wp_nav_menu() applies it.
        if ( ! is_array( $items ) ) {
            return [];
        }

        // Bucket by parent, ids compared as Walker compares them — as given: WPML's language items
        // carry ids like "wpml-ls-12-fr". An item whose parent is not in the menu, or is itself,
        // stands at the top, as Walker::walk() shows it. Labels as the Walker prints them, as text.
        $ids       = array_flip( array_map( static fn( $item ): string => (string) $item->ID, $items ) );
        $by_parent = [];
        foreach ( $items as $item ) {
            $id     = (string) $item->ID;
            $title  = apply_filters( 'the_title', (string) $item->title, (int) $item->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, applied as wp_nav_menu() applies it.
            $title  = apply_filters( 'nav_menu_item_title', $title, $item, $args, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, applied as wp_nav_menu() applies it.
            $label  = self::text_of( (string) $title );
            $url    = trim( (string) $item->url );
            $parent = (string) $item->menu_item_parent;
            if ( $parent === '' || $parent === '0' || $parent === $id || ! isset( $ids[ $parent ] ) ) {
                $parent = '0';
            }
            $by_parent[ $parent ][] = [
                'id'              => $id,
                'label'           => $label,
                'url'             => $url,
                'newTab'          => trim( (string) $item->target ) === '_blank',
                'current'         => ! empty( $item->current ),
                'currentAncestor' => self::is_ancestor( $item ),
            ];
        }

        return self::shown( self::assemble( $by_parent, '0' ) );
    }

    /**
     * The items a visitor can use: an item with nothing under it and no words, or nowhere to go
     * ('' or '#'), is left out. A parent stays with its submenu, even with no words or address;
     * an anchor (#booking) stays.
     *
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function shown( array $tree ): array {
        $out = [];
        foreach ( $tree as $node ) {
            if ( isset( $node['children'] ) ) {
                $node['children'] = self::shown( $node['children'] );
                if ( $node['children'] === [] ) {
                    unset( $node['children'] );
                }
            }
            $leaf = ! isset( $node['children'] );
            if ( $leaf && ( $node['label'] === '' || $node['url'] === '' || $node['url'] === '#' ) ) {
                continue;
            }
            $out[] = $node;
        }
        return $out;
    }

    /* ── internals ─────────────────────────────────────────────────────── */

    /**
     * One level of the WP tree, and its children at any depth.
     *
     * @param array<string,list<array<string,mixed>>> $by_parent
     * @return list<array<string,mixed>>
     */
    private static function assemble( array $by_parent, string $parent_id ): array {
        if ( empty( $by_parent[ $parent_id ] ) ) {
            return [];
        }

        $out = [];
        foreach ( $by_parent[ $parent_id ] as $item ) {
            $entry = [ 'label' => $item['label'], 'url' => $item['url'] ];
            if ( $item['newTab'] ) {
                $entry['newTab'] = true;
            }
            $entry['current']         = $item['current'];
            $entry['currentAncestor'] = $item['currentAncestor'];
            $children = self::assemble( $by_parent, $item['id'] );
            if ( $children !== [] ) {
                $entry['children'] = $children;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Normalizes manual/attribute items into the same node shape.
     *
     * @param array<int,mixed> $items
     * @return list<array<string,mixed>>
     */
    private static function normalize_items( array $items ): array {
        $out = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $label = trim( (string) ( $item['label'] ?? '' ) );
            $url   = trim( (string) ( $item['url'] ?? '' ) );
            $entry = [ 'label' => $label, 'url' => $url ];
            if ( ! empty( $item['newTab'] ) ) {
                $entry['newTab'] = true;
            }
            if ( is_array( $item['children'] ?? null ) && $item['children'] !== [] ) {
                $children = self::normalize_items( $item['children'] );
                if ( $children !== [] ) {
                    $entry['children'] = $children;
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

}
