<?php

namespace Fabrement\Modules\Seo\Bridge\Yoast;

/**
 * What Yoast derives and prints, through its runtime (`YoastSEO()`): the output of a record or a
 * term, the context its schema graph is built from, its own index (indexables) and caches. The
 * part of Yoast most likely to move between releases, so every call is guarded and a failure
 * answers "nothing" rather than breaking the tool. Part of
 * {@see \Fabrement\Modules\Seo\Bridge\YoastBridge}; nothing else calls it.
 *
 * @internal
 */
final class Indexables {

    /**
     * What Yoast will actually OUTPUT for the post (after templates, fallbacks and the indexable) —
     * title, description, canonical, robots, social, and — when `$with_schema` — the schema graph,
     * which Yoast builds only when read (every piece, ours included).
     *
     * @return array<string, mixed>
     */
    public static function effective_post( int $post_id, bool $with_schema ): array {
        if ( ! function_exists( 'YoastSEO' ) ) {
            return [];
        }
        // Yoast memoizes the context per indexable id for the request. An indexable that is not
        // persisted yet (fresh install, migrations pending) has NO id, so every post would share
        // one cache slot and the second for_post() would return the first post's values.
        // Clearing first costs one rebuild and makes the read exact.
        self::clear_meta_cache();
        try {
            $meta = YoastSEO()->meta->for_post( $post_id );
        } catch ( \Throwable $e ) {
            return [];
        }
        if ( ! $meta ) {
            return [];
        }
        $robots = $meta->robots;
        return [
            'title'          => (string) $meta->title,
            'description'    => (string) $meta->description,
            'canonical'      => (string) $meta->canonical,
            'robots'         => is_array( $robots ) ? array_values( array_filter( array_map( 'strval', $robots ) ) ) : (string) $robots,
            'ogTitle'        => (string) $meta->open_graph_title,
            'ogDescription'  => (string) $meta->open_graph_description,
            'ogImage'        => self::first_image_url( $meta->open_graph_images ),
            'twitterTitle'   => (string) $meta->twitter_title,
            'twitterDescription' => (string) $meta->twitter_description,
            'schema'         => $with_schema && is_array( $meta->schema ) ? $meta->schema : [],
        ];
    }

    /** What Yoast will output for a term archive. */
    public static function effective_term( int $term_id ): array {
        if ( ! function_exists( 'YoastSEO' ) ) {
            return [];
        }
        self::clear_meta_cache();
        try {
            $meta = YoastSEO()->meta->for_term( $term_id );
        } catch ( \Throwable $e ) {
            return [];
        }
        if ( ! $meta ) {
            return [];
        }
        $robots = $meta->robots;
        return [
            'title'       => (string) $meta->title,
            'description' => (string) $meta->description,
            'canonical'   => (string) $meta->canonical,
            'robots'      => is_array( $robots ) ? array_values( array_filter( array_map( 'strval', $robots ) ) ) : (string) $robots,
            'ogTitle'     => (string) $meta->open_graph_title,
            'ogImage'     => self::first_image_url( $meta->open_graph_images ),
        ];
    }

    /**
     * The page tokens of a record as Yoast's context holds them ({@see \Fabrement\Modules\Seo\Contract\SeoBridge::schema_values()}).
     *
     * @param mixed $context
     * @return array<string,string>
     */
    public static function schema_values( int $post_id, $context ): array {
        if ( ! function_exists( 'YoastSEO' ) ) {
            return [];
        }
        if ( ! is_object( $context ) ) {
            // Outside a page view (get-seo, set-schema): the context Yoast builds for this record,
            // in the record's language — what it would print for it. The context itself, as
            // Meta_Surface::for_post() gets it: its values are computed on first read, which the
            // Meta surface's own getter does not do for them.
            self::clear_meta_cache();
            try {
                $indexable = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class )->find_by_id_and_type( $post_id, 'post' );
                $context   = $indexable ? YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class )->get( $indexable, 'Post_Type' ) : null;
            } catch ( \Throwable $e ) {
                return [];
            }
            if ( ! is_object( $context ) ) {
                return [];
            }
        }
        $post  = $context->post ?? null;
        $post  = $post instanceof \WP_Post ? $post : get_post( $post_id );
        $dates = YoastSEO()->helpers->date;

        return [
            'url'            => (string) ( $context->main_schema_id ?? '' ),
            'site_url'       => (string) ( $context->site_url ?? '' ),
            'title'          => $post instanceof \WP_Post ? self::name_of( $post ) : '',
            'excerpt'        => (string) ( $context->description ?? '' ),
            'featured_image' => (string) ( $context->main_image_url ?? '' ),
            'date_published' => $post instanceof \WP_Post ? (string) $dates->format( $post->post_date_gmt ) : '',
            'date_modified'  => $post instanceof \WP_Post ? (string) $dates->format( $post->post_modified_gmt ) : '',
        ];
    }

    /**
     * Rebuild ONE post's indexable now. Yoast's meta watcher does this on shutdown, which is after
     * this request has already read back "effective" values — so a set-seo would report the OLD
     * title. Best effort — quietly does nothing on versions without the builder.
     */
    public static function reindex_post( int $post_id ): bool {
        if ( ! function_exists( 'YoastSEO' ) ) {
            return false;
        }
        try {
            $container = YoastSEO()->classes;
            $indexable = $container->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class )->find_by_id_and_type( $post_id, 'post', false );
            $container->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class )->build_for_id_and_type( $post_id, 'post', $indexable ?: null );
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Bring what Yoast derives from a record's main terms up to date: its primary-term row is
     * rebuilt from the meta now, and the record is marked outdated so Yoast rebuilds it —
     * breadcrumb trail included — the next time it is read.
     *
     * Not the trail itself here: Yoast builds a record's trail at most once per request, and a save
     * earlier in the same request (a REST save sets the terms AFTER the post) has used that once
     * already. An outdated record is rebuilt in a request of its own.
     */
    public static function rebuild_primary_terms( int $post_id ): void {
        if ( ! function_exists( 'YoastSEO' ) ) {
            return;
        }
        try {
            YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Primary_Term_Builder::class )->build( $post_id );
        } catch ( \Throwable $e ) {
            // The meta is right; Yoast's row catches up when the record is next saved.
        }
        self::outdate( $post_id, 'post' );
    }

    /**
     * Mark one stored indexable outdated, so Yoast rebuilds it the next time it is read — in a
     * request of its own. Not rebuilt here: on a WPML site Yoast SEO Multilingual answers this
     * request with a copy of the term store made before the write, and a rebuild now would save
     * the OLD values until the term is next edited. WPML invalidates the same way.
     */
    public static function outdate( int $id, string $type ): void {
        if ( ! defined( 'WPSEO_VERSION' ) || ! function_exists( 'YoastSEO' ) ) {
            return;
        }
        try {
            $indexable = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class )->find_by_id_and_type( $id, $type, false );
            if ( $indexable ) {
                $indexable->version = 0;
                $indexable->save();
            }
        } catch ( \Throwable $e ) {
            // Stale at worst until the term is next edited in wp-admin.
        }
    }

    /**
     * The record's NAME: WordPress prefixes a locked or private record's title with "Protected: "
     * / "Private: " for the reader of the page, which is not part of the name.
     */
    private static function name_of( \WP_Post $post ): string {
        $plain = static fn(): string => '%s';
        add_filter( 'protected_title_format', $plain, PHP_INT_MAX );
        add_filter( 'private_title_format', $plain, PHP_INT_MAX );
        $title = html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES );
        remove_filter( 'protected_title_format', $plain, PHP_INT_MAX );
        remove_filter( 'private_title_format', $plain, PHP_INT_MAX );
        return $title;
    }

    /**
     * Drop Yoast's per-request meta-tags cache, so a read after a write sees the write. Internal to
     * Yoast (no public API); when it is gone the read still works, it may just be stale.
     */
    private static function clear_meta_cache(): void {
        try {
            YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class )->clear();
        } catch ( \Throwable $e ) {
            // Stale at worst; get-seo in a request of its own is exact.
        }
    }

    /** @param mixed $images */
    private static function first_image_url( $images ): string {
        if ( ! is_array( $images ) ) {
            return '';
        }
        foreach ( $images as $img ) {
            if ( is_array( $img ) && ! empty( $img['url'] ) ) {
                return (string) $img['url'];
            }
        }
        return '';
    }
}
