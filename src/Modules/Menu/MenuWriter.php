<?php

namespace Fabrement\Modules\Menu;

use Fabrement\Modules\Fields\Types\WordsType;
use WP_Error;

/**
 * The write path for WordPress navigation menus — create / rename+replace-items /
 * delete — used by the MCP menu abilities. Items are the SAME nested shape
 * list-menus returns: [{ label, url | postId | termId | archive, newTab?, children? }]
 * (any depth, as WordPress). A `url` is a custom link; postId / termId / archive attach the item to
 * that page, term or post type archive, as the WordPress menu screen does — its address and,
 * without a label, its title follow the object.
 */
final class MenuWriter {

    private const FIELDS = [ 'label', 'url', 'postId', 'termId', 'archive', 'newTab', 'children' ];
    private const MAX_ITEMS = 200;

    /**
     * Create a menu and seed its items.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>|WP_Error
     */
    public static function create( string $name, array $items ) {
        $name = trim( $name );
        if ( $name === '' ) {
            return new WP_Error( 'fabrement_menu_name', __( 'Menu name is required.', 'fabrement-lite' ), [ 'status' => 400 ] );
        }
        $count = 0;
        $error = self::check_level( $items, 1, $count );
        if ( $error instanceof WP_Error ) {
            return $error;
        }
        // wp_create_nav_menu returns a WP_Error when a menu with this name exists.
        $menu_id = wp_create_nav_menu( $name );
        if ( is_wp_error( $menu_id ) ) {
            return $menu_id;
        }
        $error = self::write_items( (int) $menu_id, $items, false );
        if ( $error instanceof WP_Error ) {
            return $error;
        }
        return self::summary( (int) $menu_id );
    }

    /**
     * Rename a menu and/or REPLACE its items. Pass items:null to leave items untouched.
     *
     * @param array<int,array<string,mixed>>|null $items
     * @return array<string,mixed>|WP_Error
     */
    public static function update( int $menu_id, ?string $name, ?array $items ) {
        return self::of_menu( $menu_id, static fn() => self::change( $menu_id, $name, $items ) );
    }

    /**
     * @param array<int,array<string,mixed>>|null $items
     * @return array<string,mixed>|WP_Error
     */
    private static function change( int $menu_id, ?string $name, ?array $items ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            return new WP_Error( 'fabrement_menu_not_found', __( 'Menu not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }
        if ( $name !== null ) {
            $name = trim( $name );
            if ( $name === '' ) {
                return new WP_Error( 'fabrement_menu_name', __( 'Menu name cannot be empty.', 'fabrement-lite' ), [ 'status' => 400 ] );
            }
        }
        // Nothing is written, the name too, until the whole tree is accepted.
        if ( $items !== null ) {
            $count = 0;
            $error = self::check_level( $items, 1, $count );
            if ( $error instanceof WP_Error ) {
                return $error;
            }
        }
        if ( $name !== null ) {
            $res = wp_update_nav_menu_object( $menu_id, [ 'menu-name' => $name ] );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
        }
        if ( $items !== null ) {
            $error = self::write_items( $menu_id, $items, true );
            if ( $error instanceof WP_Error ) {
                return $error;
            }
        }
        return self::summary( $menu_id );
    }

    /**
     * Delete a menu (its items go with it). A block bound to it via {source:"wp",
     * menuId} falls back to its stored items.
     *
     * @return array{deleted:true,id:int}|WP_Error
     */
    public static function delete( int $menu_id ) {
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            return new WP_Error( 'fabrement_menu_not_found', __( 'Menu not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }
        $res = wp_delete_nav_menu( $menu_id );
        if ( is_wp_error( $res ) || $res === false ) {
            return new WP_Error( 'fabrement_menu_delete', __( 'Could not delete the menu.', 'fabrement-lite' ), [ 'status' => 500 ] );
        }
        return [ 'deleted' => true, 'id' => $menu_id ];
    }

    /**
     * Write the nested items into a menu. When $replace, existing items are removed
     * first. wp_update_nav_menu_item() lives in wp-admin/includes — load it (the MCP
     * runs in a REST context where admin includes are not auto-loaded).
     *
     * @param array<int,array<string,mixed>> $items
     */
    private static function write_items( int $menu_id, array $items, bool $replace ): ?WP_Error {
        require_once ABSPATH . 'wp-admin/includes/nav-menu.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- WordPress core admin include, needed in the REST context.

        // The whole tree is checked first: a refused item must not leave the menu emptied.
        $count = 0;
        $error = self::check_level( $items, 1, $count );
        if ( $error instanceof WP_Error ) {
            return $error;
        }

        if ( $replace ) {
            // Every published item, those whose page is in the trash too (wp_get_nav_menu_items()
            // leaves them out off wp-admin); a Customizer draft item is not ours to remove.
            $existing = get_posts( [
                'post_type'        => 'nav_menu_item',
                'post_status'      => 'publish',
                'numberposts'      => -1,
                'fields'           => 'ids',
                'tax_query'        => [ [ 'taxonomy' => 'nav_menu', 'field' => 'term_id', 'terms' => $menu_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one menu's items.
            ] );
            foreach ( $existing as $item_id ) {
                wp_delete_post( (int) $item_id, true );
            }
        }

        $count = 0;
        $error = self::insert_level( $menu_id, $items, 0, 1, $count );
        self::refresh_count( $menu_id );
        return $error;
    }

    /**
     * @param array<int,mixed> $items
     */
    private static function check_level( array $items, int $depth, int &$count, string $at = '' ): ?WP_Error {
        foreach ( $items as $i => $item ) {
            $pos = ltrim( $at . '.' . ( $i + 1 ), '.' );
            if ( ! is_array( $item ) || ( $item !== [] && array_is_list( $item ) ) ) {
                return self::refuse( sprintf( 'Menu item %s: give an item {label, url | postId | termId | archive, newTab, children}, not %s.', $pos, get_debug_type( $item ) ) );
            }
            $unknown = array_diff( array_keys( $item ), self::FIELDS );
            if ( $unknown !== [] ) {
                return self::refuse( sprintf( 'Menu item %s: unknown field "%s" — an item takes %s.', $pos, (string) reset( $unknown ), implode( ', ', self::FIELDS ) ) );
            }
            if ( array_key_exists( 'label', $item ) && ! is_string( $item['label'] ) ) {
                return self::refuse( sprintf( 'Menu item %s: label is text, not %s.', $pos, get_debug_type( $item['label'] ) ) );
            }
            if ( array_key_exists( 'newTab', $item ) && ! is_bool( $item['newTab'] ) ) {
                return self::refuse( sprintf( 'Menu item %s: newTab is true or false, not %s.', $pos, get_debug_type( $item['newTab'] ) ) );
            }
            if ( array_key_exists( 'children', $item ) && ( ! is_array( $item['children'] ) || ! array_is_list( $item['children'] ) ) ) {
                return self::refuse( sprintf( 'Menu item %s: children is a list of items, not %s.', $pos, is_array( $item['children'] ) ? 'an object' : get_debug_type( $item['children'] ) ) );
            }
            $target = self::target( $item, trim( WordsType::words( (string) ( $item['label'] ?? '' ) ) ), $pos );
            if ( $target instanceof WP_Error ) {
                return $target;
            }
            if ( ++$count > self::MAX_ITEMS ) {
                return new WP_Error(
                    'fabrement_menu_too_many',
                    sprintf( /* translators: %d: item cap */ __( 'A menu may hold at most %d items.', 'fabrement-lite' ), self::MAX_ITEMS ),
                    [ 'status' => 400 ]
                );
            }
            $error = self::check_level( is_array( $item['children'] ?? null ) ? $item['children'] : [], $depth + 1, $count, $pos );
            if ( $error instanceof WP_Error ) {
                return $error;
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private static function insert_level( int $menu_id, array $items, int $parent, int $depth, int &$count ): ?WP_Error {
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            // As WordPress's menu screen saves a label: as typed for someone who may post unfiltered
            // HTML ("Kids <12 & more"), through kses for anyone else; sanitize_text_field() cut "<…".
            $label  = trim( WordsType::words( (string) ( $item['label'] ?? '' ) ) );
            $target = self::target( $item, $label );
            if ( $target instanceof WP_Error ) {
                return $target;
            }
            if ( ++$count > self::MAX_ITEMS ) {
                return new WP_Error(
                    'fabrement_menu_too_many',
                    sprintf( /* translators: %d: item cap */ __( 'A menu may hold at most %d items.', 'fabrement-lite' ), self::MAX_ITEMS ),
                    [ 'status' => 400 ]
                );
            }

            $item_id = wp_update_nav_menu_item(
                $menu_id,
                0,
                $target + [
                    // Slashed: wp_insert_post() unslashes what it is given.
                    'menu-item-title'     => wp_slash( $label ),
                    'menu-item-parent-id' => $parent,
                    'menu-item-position'  => $count,
                    'menu-item-status'    => 'publish',
                    'menu-item-target'    => ! empty( $item['newTab'] ) ? '_blank' : '',
                ]
            );
            if ( is_wp_error( $item_id ) ) {
                return $item_id;
            }

            $children = is_array( $item['children'] ?? null ) ? $item['children'] : [];
            if ( $children !== [] ) {
                $error = self::insert_level( $menu_id, $children, (int) $item_id, $depth + 1, $count );
                if ( $error instanceof WP_Error ) {
                    return $error;
                }
            }
        }
        return null;
    }

    /**
     * What one item links to, as wp_update_nav_menu_item() arguments — a custom url, or one page,
     * term or archive. A url sent beside a link (a list-menus row sent back) must be its address.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>|WP_Error
     */
    private static function target( array $item, string $label, string $pos = '' ) {
        $name  = trim( $pos . ' ' . ( $label !== '' ? '"' . $label . '"' : ( $pos === '' ? 'with no label' : '' ) ) );
        $links = array_values( array_intersect( [ 'postId', 'termId', 'archive' ], array_keys( $item ) ) );
        $url   = array_key_exists( 'url', $item ) ? $item['url'] : null;
        if ( $url !== null && ! is_string( $url ) ) {
            return self::refuse( sprintf( 'Menu item %s: url must be a string.', $name ) );
        }
        $url = $url === null ? '' : trim( $url );

        if ( count( $links ) > 1 ) {
            return self::refuse( sprintf( 'Menu item %s: give one of postId, termId or archive, not %s.', $name, implode( ' and ', $links ) ) );
        }
        if ( $links === [] ) {
            // As WordPress keeps a custom link: no words and no address are allowed (it is then not
            // shown); an address WordPress would strip (javascript:…) is refused, not emptied.
            $clean = $url === '' ? '' : esc_url_raw( $url );
            if ( $url !== '' && $clean === '' ) {
                return self::refuse( sprintf( 'Menu item %s: url %s is not an address WordPress allows.', $name, $url ) );
            }
            return [ 'menu-item-type' => 'custom', 'menu-item-url' => $clean ];
        }

        $link  = $links[0];
        $value = $item[ $link ];
        if ( $link === 'postId' ) {
            $post = is_int( $value ) && $value > 0 ? get_post( $value ) : null;
            if ( ! $post instanceof \WP_Post || $post->post_status === 'trash' || ! is_post_type_viewable( $post->post_type ) || $post->post_type === 'attachment' ) {
                return self::refuse( sprintf( 'Menu item %s: postId %s is not a page or record a visitor can open. See list-content.', $name, wp_json_encode( $value ) ) );
            }
            $args    = [ 'menu-item-type' => 'post_type', 'menu-item-object' => $post->post_type, 'menu-item-object-id' => $post->ID ];
            $address = (string) self::there( static fn() => get_permalink( $post ) );
        } elseif ( $link === 'termId' ) {
            $term = is_int( $value ) && $value > 0 ? get_term( $value ) : null;
            if ( ! $term instanceof \WP_Term || ! is_taxonomy_viewable( $term->taxonomy ) ) {
                return self::refuse( sprintf( 'Menu item %s: termId %s is not a term a visitor can open. See list-terms.', $name, wp_json_encode( $value ) ) );
            }
            $args    = [ 'menu-item-type' => 'taxonomy', 'menu-item-object' => $term->taxonomy, 'menu-item-object-id' => $term->term_id ];
            $address = (string) self::there( static fn() => get_term_link( $term ) );
        } else {
            $address = is_string( $value ) && post_type_exists( $value ) ? self::there( static fn() => get_post_type_archive_link( $value ) ) : false;
            if ( ! is_string( $address ) || $address === '' ) {
                return self::refuse( sprintf( 'Menu item %s: archive %s is not a post type with an archive. See list-post-types.', $name, wp_json_encode( $value ) ) );
            }
            $args = [ 'menu-item-type' => 'post_type_archive', 'menu-item-object' => $value ];
        }
        if ( $url !== '' && untrailingslashit( $url ) !== untrailingslashit( $address ) ) {
            return self::refuse( sprintf( 'Menu item %s: url %s is not the address of its %s (%s) — send one of them.', $name, $url, $link, $address ) );
        }
        return $args;
    }

    /**
     * The language of the menu being read or written, '' where the site has one. A menu is drawn
     * on pages of its own language, and an address depends on the language it is asked in (the
     * front page's version is that language's home, an archive has an address per language) — so
     * the addresses a menu's items are read, compared and answered with are asked in it.
     */
    private static string $language = '';

    /** Run $fn knowing the language of this menu. */
    private static function of_menu( int $menu_id, callable $fn ) {
        $bridge = \Fabrement\Modules\Translation\Translation::bridge();
        $before = self::$language;
        self::$language = $bridge->is_active() ? $bridge->term_language( $menu_id, 'nav_menu' ) : '';
        try {
            return $fn();
        } finally {
            self::$language = $before;
        }
    }

    /** Ask WordPress something in the menu's language — reads only. */
    private static function there( callable $read ) {
        return self::$language === '' ? $read() : \Fabrement\Modules\Translation\Translation::bridge()->in_language( self::$language, $read );
    }

    private static function refuse( string $message ): WP_Error {
        return new WP_Error( 'fabrement_menu_item', $message, [ 'status' => 400 ] );
    }

    /**
     * Re-count the nav_menu term and drop its cached copy.
     *
     * wp_get_nav_menu_items() short-circuits to an EMPTY array when the term's
     * `count` is 0 (wp-includes/nav-menu.php: `if ( $menu->count > 0 )`) — it does
     * not even run the query. The count for `nav_menu` is maintained by a deferred
     * callback, so within the request that just wrote the items it is still the
     * value from before the write. Reading the menu back here would then answer
     * `items: []` for a menu whose items are on disk and show up fine on the next
     * request. So the count is forced now, and the stale term object dropped.
     */
    private static function refresh_count( int $menu_id ): void {
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            return;
        }
        wp_update_term_count_now( [ (int) $menu->term_taxonomy_id ], 'nav_menu' );
        clean_term_cache( [ (int) $menu->term_id ], 'nav_menu' );
    }

    /**
     * The menu as list-menus returns it: { id, name, items } with nested children.
     *
     * @return array<string,mixed>
     */
    public static function summary( int $menu_id ): array {
        $menu  = wp_get_nav_menu_object( $menu_id );
        // Read in the menu's language: WordPress works an item's address out as it reads it, and
        // the translation plugin may hand an attached item over as the request language's version
        // of its page — the menu would then answer with another language's pages and addresses.
        $items = $menu ? self::of_menu( $menu_id, static fn() => self::there( static fn() => wp_get_nav_menu_items( $menu_id ) ?: [] ) ) : [];
        return [
            'id'    => $menu_id,
            'name'  => $menu ? (string) $menu->name : '',
            'items' => self::read_tree( self::by_parent( $items ), '0', 1 ),
        ];
    }

    /**
     * An attached item with no label of its own gives its object's raw title — what
     * wp_update_nav_menu_item() compares with, so sending it back keeps the item following the
     * object. The shown title is texturized ("It’s") and would be stored as a label of its own.
     */
    private static function label_of( \WP_Post $item ): string {
        if ( $item->type !== 'custom' && (string) $item->post_title === '' ) {
            if ( $item->type === 'post_type' ) {
                return (string) get_post_field( 'post_title', (int) $item->object_id, 'raw' );
            }
            if ( $item->type === 'taxonomy' ) {
                $name = get_term_field( 'name', (int) $item->object_id, (string) $item->object, 'raw' );
                return is_string( $name ) ? $name : '';
            }
            $type = get_post_type_object( (string) $item->object );
            return $type ? (string) $type->labels->archives : '';
        }
        // A label saved through kses holds "&lt;" / "&amp;": read back as the words it shows.
        return html_entity_decode( (string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /**
     * The items under each parent, as the site shows them: an item whose parent is not in the
     * menu, or is itself, stands at the top — so a list sent back keeps every item it holds.
     *
     * @param array<int,\WP_Post> $items Flat wp_get_nav_menu_items() list.
     * @return array<string,list<\WP_Post>>
     */
    private static function by_parent( array $items ): array {
        $ids = array_flip( array_map( static fn( $item ): string => (string) $item->ID, $items ) );
        $out = [];
        foreach ( $items as $item ) {
            $parent = (string) $item->menu_item_parent;
            if ( $parent === '' || $parent === '0' || $parent === (string) $item->ID || ! isset( $ids[ $parent ] ) ) {
                $parent = '0';
            }
            $out[ $parent ][] = $item;
        }
        return $out;
    }

    /**
     * The tree, at any depth (WordPress sets none).
     *
     * @param array<string,list<\WP_Post>> $by_parent
     * @return list<array<string,mixed>>
     */
    private static function read_tree( array $by_parent, string $parent, int $level ): array {
        $out = [];
        foreach ( $by_parent[ $parent ] ?? [] as $item ) {
            $out[] = self::row( $item, self::read_tree( $by_parent, (string) $item->ID, $level + 1 ) );
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $children
     * @return array<string,mixed>
     */
    private static function row( \WP_Post $item, array $children ): array {
        return array_filter(
            [
                'label'    => self::label_of( $item ),
                'url'      => (string) $item->url,
                'postId'   => 'post_type' === $item->type ? (int) $item->object_id : null,
                'termId'   => 'taxonomy' === $item->type ? (int) $item->object_id : null,
                'archive'  => 'post_type_archive' === $item->type ? (string) $item->object : null,
                'newTab'   => '_blank' === $item->target ? true : null,
                'children' => [] !== $children ? $children : null,
            ],
            static fn( $v ) => null !== $v
        );
    }
}
