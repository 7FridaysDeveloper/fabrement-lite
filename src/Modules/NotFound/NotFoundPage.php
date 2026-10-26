<?php

namespace Fabrement\Modules\NotFound;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Translation\Translation;

/**
 * An ordinary page, chosen in the site settings, shown for every address that does not exist.
 *
 * WHY A PAGE. A not-found view is content the owner wants to write and change — the wording, a
 * search, links back — and a page already is exactly that: built from blocks, edited in
 * Gutenberg or by save-content, dressed by the template its type or its own pick gives it,
 * translated per language. The home page works the same way (`page_on_front`).
 *
 * HOW. On a 404 request the main query is replaced by that page, so everything downstream —
 * the classic and block-theme template appliers, the content slot, block assets, the reader's
 * language — handles it as the singular page it now is. The response keeps its 404 status and
 * is noindex, so nothing about the missing address changes for browsers or search engines.
 *
 * WHEN IT GOES AWAY. Deleting or unpublishing the page in wp-admin is allowed and simply clears
 * the setting — the theme's own 404 shows again. The MCP tools refuse both while it is assigned
 * (like the front page), so an agent cannot remove it by accident.
 */
final class NotFoundPage {

    /** True while this request is rendering the not-found page for a missing address. */
    private static bool $serving = false;

    public static function init(): void {
        // After redirect_canonical (priority 10): a mistyped slug WordPress can guess still
        // redirects to the real page instead of showing "not found".
        add_action( 'template_redirect', [ self::class, 'serve' ], 20 );

        // Yoast's robots, canonical and sitemap hooks: YoastIntegration.
        add_filter( 'wp_robots', [ self::class, 'robots' ] );
        add_filter( 'wp_sitemaps_posts_query_args', [ self::class, 'exclude_from_core_sitemap' ], 10, 2 );
        add_action( 'pre_get_posts', [ self::class, 'exclude_from_search' ] );

        add_action( 'transition_post_status', [ self::class, 'forget_when_unpublished' ], 10, 3 );
        add_action( 'before_delete_post', [ self::class, 'forget_when_deleted' ] );

        add_action( 'admin_init', [ self::class, 'register_reading_setting' ] );
        add_filter( 'display_post_states', [ self::class, 'post_state' ], 10, 2 );
    }

    /** True while this request shows the not-found page in place of a missing address. */
    public static function serving(): bool {
        return self::$serving;
    }

    /** The assigned page id (the original), or 0. */
    public static function id(): int {
        return (int) get_option( Keys::OPTION_NOT_FOUND_PAGE, 0 );
    }

    /** Whether a post id is the assigned page or one of its language versions. */
    public static function is_assigned( int $post_id ): bool {
        $id = self::id();
        if ( $id <= 0 || $post_id <= 0 ) {
            return false;
        }
        if ( $post_id === $id ) {
            return true;
        }
        // The setting always holds the ORIGINAL (Translation::original_of()); a version is recognised
        // by its original — not by its default-language version, which is not the original when
        // the page was first created in another language.
        return Translation::original_of( $post_id ) === $id;
    }

    /** Replace a 404's main query with the assigned page, in the reader's language. */
    public static function serve(): void {
        if ( ! is_404() || is_admin() || wp_doing_ajax() || is_feed() || is_robots() ) {
            return;
        }
        $page_id = self::page_for_reader();
        if ( $page_id <= 0 ) {
            return;
        }

        global $wp_query, $wp_the_query, $post;
        $query = new \WP_Query( [ 'page_id' => $page_id, 'post_type' => 'page', 'post_status' => 'publish' ] );
        if ( ! $query->have_posts() ) {
            return;
        }

        $wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a 404 is served with the assigned Not Found page as its main query; status stays 404.
        $wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a 404 is served with the assigned Not Found page as its main query; status stays 404.
        $post         = $query->post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a 404 is served with the assigned Not Found page as its main query; status stays 404.
        setup_postdata( $post );

        self::$serving = true;
        status_header( 404 );
        nocache_headers();
    }

    /** The published language version of the assigned page for this request, or 0. */
    private static function page_for_reader(): int {
        $id = self::id();
        if ( $id <= 0 ) {
            return 0;
        }
        $localised = Translation::bridge()->version_in( $id, 'page' );
        $candidate = $localised > 0 ? $localised : $id;
        if ( get_post_status( $candidate ) === 'publish' ) {
            return $candidate;
        }
        return get_post_status( $id ) === 'publish' ? $id : 0;
    }

    /** Whether the page being drawn is the not-found page: served for a missing address, or opened at its own. */
    public static function shown(): bool {
        return self::$serving || ( is_singular( 'page' ) && self::is_assigned( (int) get_queried_object_id() ) );
    }

    /** @param array<string,bool|string> $robots */
    public static function robots( $robots ) {
        if ( self::shown() ) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
            unset( $robots['index'] );
        }
        return $robots;
    }

    /** @param array<string,mixed> $args */
    public static function exclude_from_core_sitemap( $args, $post_type ) {
        if ( $post_type === 'page' && self::id() > 0 ) {
            $args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? [] ), self::all_versions() ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- keeps the not-found page (all its language versions) out of the core sitemap; exclusion is the point of this filter.
        }
        return $args;
    }

    public static function exclude_from_search( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || self::id() <= 0 ) {
            return;
        }
        $query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), self::all_versions() ) );
    }

    /** Unpublished in wp-admin → the setting goes, and the theme's 404 shows again. */
    public static function forget_when_unpublished( $new_status, $old_status, $post ): void {
        if ( $old_status === 'publish' && $new_status !== 'publish' && $post instanceof \WP_Post && (int) $post->ID === self::id() ) {
            delete_option( Keys::OPTION_NOT_FOUND_PAGE );
        }
    }

    public static function forget_when_deleted( $post_id ): void {
        if ( (int) $post_id === self::id() ) {
            delete_option( Keys::OPTION_NOT_FOUND_PAGE );
        }
    }

    /** Settings → Reading: the same choice the MCP tool makes, for a person. */
    public static function register_reading_setting(): void {
        register_setting( 'reading', Keys::OPTION_NOT_FOUND_PAGE, [ 'type' => 'integer', 'sanitize_callback' => [ self::class, 'sanitize' ], 'default' => 0 ] );
        add_settings_field(
            Keys::OPTION_NOT_FOUND_PAGE,
            __( 'Page not found (404)', 'fabrement-lite' ),
            static function (): void {
                wp_dropdown_pages( [
                    'name'              => esc_attr( Keys::OPTION_NOT_FOUND_PAGE ),
                    'selected'          => absint( self::id() ),
                    'show_option_none'  => esc_html__( '— Theme default —', 'fabrement-lite' ),
                    'option_none_value' => '0',
                    'post_status'       => 'publish',
                ] );
                echo '<p class="description">' . esc_html__( 'Shown for every address that does not exist, with a 404 status and noindex.', 'fabrement-lite' ) . '</p>';
            },
            'reading',
            'default'
        );
    }

    /** @param mixed $value */
    public static function sanitize( $value ): int {
        $id = (int) $value;
        if ( $id <= 0 ) {
            return 0;
        }
        $error = self::invalid_choice( $id );
        if ( $error !== null ) {
            add_settings_error( Keys::OPTION_NOT_FOUND_PAGE, Keys::OPTION_NOT_FOUND_PAGE, $error );
            return self::id();
        }
        // The original, whichever language's version was picked.
        return Translation::original_of( $id );
    }

    /**
     * Why a page cannot be the not-found page, or null when it can. Shared with the MCP tool so a
     * person and an agent are held to the same rules.
     */
    public static function invalid_choice( int $id ): ?string {
        $page = get_post( $id );
        if ( ! $page instanceof \WP_Post || $page->post_type !== 'page' ) {
            /* translators: %d: post ID. */
            return sprintf( __( 'notFoundPage must be the id of a page (id %d is not one).', 'fabrement-lite' ), $id );
        }
        if ( $page->post_status !== 'publish' ) {
            /* translators: 1: page title, 2: post status. */
            return sprintf( __( 'notFoundPage "%1$s" is a %2$s — publish it first.', 'fabrement-lite' ), $page->post_title, $page->post_status );
        }
        // Compared as TRANSLATION GROUPS, not as ids. WPML rewrites both options to the current
        // language, so "is this page the front page" is two different numbers depending on the
        // admin's language switcher: the guard would miss a real collision in one language and
        // refuse a legitimate choice in another. Reduced to originals, both sides answer the
        // question the guard is actually asking — is this the same PAGE.
        $original = Translation::original_of( $id );
        if ( $original === Translation::original_of( (int) get_option( 'page_on_front' ) ) ) {
            return __( 'The front page cannot also be the not-found page.', 'fabrement-lite' );
        }
        if ( $original === Translation::original_of( (int) get_option( 'page_for_posts' ) ) ) {
            return __( 'The posts page cannot also be the not-found page — WordPress replaces its content with the post list.', 'fabrement-lite' );
        }
        return null;
    }

    /** "— 404 Page" next to the title in the pages list, like "— Front Page". */
    public static function post_state( $states, $post ) {
        if ( $post instanceof \WP_Post && (int) $post->ID === self::id() ) {
            $states['fabrement_not_found'] = esc_html__( '404 Page', 'fabrement-lite' );
        }
        return $states;
    }

    /** @return int[] The assigned page and every language version of it. */
    public static function all_versions(): array {
        $id = self::id();
        if ( $id <= 0 ) {
            return [];
        }
        $ids    = [ $id ];
        $bridge = Translation::bridge();
        foreach ( $bridge->languages() as $language ) {
            $version = $bridge->version_in( $id, 'page', (string) ( $language['code'] ?? '' ) );
            if ( $version > 0 ) {
                $ids[] = $version;
            }
        }
        return array_values( array_unique( $ids ) );
    }
}
