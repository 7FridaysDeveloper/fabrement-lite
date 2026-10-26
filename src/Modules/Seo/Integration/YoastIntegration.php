<?php

namespace Fabrement\Modules\Seo\Integration;

use Fabrement\Modules\Seo\Content\ContentImages;
use Fabrement\Modules\Seo\Contract\SeoIntegration;
use Fabrement\Modules\Seo\Content\FieldText;
use Fabrement\Modules\Seo\SiteQueryVars;
use Fabrement\Modules\NotFound\NotFoundPage;

/**
 * Yoast SEO, seen from the page it renders. Only Yoast's hooks and shapes live here; what to give
 * comes from Fabrement's own services. YoastBridge is the other half — Yoast's stored DATA, which
 * the SEO tools read and write.
 */
final class YoastIntegration implements SeoIntegration {

    public function is_active(): bool {
        return defined( 'WPSEO_VERSION' );
    }

    public function register(): void {
        // Yoast's main category follows a record's terms, whoever changes them.
        add_action( \Fabrement\Modules\Seo\TermChanges::ACTION, [ YoastPrimaryTerm::class, 'terms_changed' ], 10, 2 );
        // Our schema.org nodes as a piece of Yoast's graph (its Schema API).
        add_filter( 'wpseo_schema_graph_pieces', [ self::class, 'schema_pieces' ], 20, 2 );
        // The not-found page: noindex, no canonical, out of the sitemap.
        add_filter( 'wpseo_robots', [ self::class, 'not_found_robots' ] );
        add_filter( 'wpseo_canonical', [ self::class, 'not_found_canonical' ] );
        add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', [ self::class, 'not_found_out_of_sitemap' ] );

        add_filter( 'wpseo_frontend_presentation', [ self::class, 'remember_presentation' ], 1 );
        add_filter( 'wpseo_add_opengraph_additional_images', [ self::class, 'open_graph_image' ] );
        add_filter( 'wpseo_sitemap_urlimages', [ self::class, 'sitemap_images' ], 10, 2 );
        add_filter( 'Yoast\WP\SEO\allowlist_permalink_vars', [ self::class, 'allowed_query_vars' ] );
        add_action( 'wpseo_register_extra_replacements', [ self::class, 'register_variables' ] );
    }

    /** @param string $robots */
    public static function not_found_robots( $robots ) {
        return NotFoundPage::shown() ? 'noindex, follow' : $robots;
    }

    /** A missing address has no canonical — least of all the not-found page's own URL. */
    public static function not_found_canonical( $canonical ) {
        return NotFoundPage::serving() ? false : $canonical;
    }

    /** @param int[] $ids */
    public static function not_found_out_of_sitemap( $ids ) {
        return array_merge( (array) $ids, NotFoundPage::all_versions() );
    }

    /**
     * @param mixed $pieces  Yoast's graph pieces.
     * @param mixed $context Yoast's meta-tags context (the piece receives it from Yoast).
     * @return mixed
     */
    public static function schema_pieces( $pieces, $context = null ) {
        if ( ! is_array( $pieces ) || ! class_exists( \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece::class ) ) {
            return $pieces;
        }
        $pieces[] = new YoastSchemaPiece();
        return $pieces;
    }

    /**
     * The presentation Yoast is about to read, and so the record it is for. Yoast's image filter
     * gets only the image container; every path that reads a presentation's values (the page's
     * <head>, `YoastSEO()->meta->for_post()` in get-seo, the REST head route) passes it through
     * this filter first, and the images are generated right after, from it.
     *
     * @var object|null
     */
    private static $presentation = null;

    /**
     * @param mixed $presentation
     * @return mixed
     */
    public static function remember_presentation( $presentation ) {
        self::$presentation = is_object( $presentation ) ? $presentation : null;
        return $presentation;
    }

    /**
     * A social preview picture for a page built of blocks. Yoast's own order runs first — the image
     * set for the page, then its featured image, a gallery image or the first <img> in the stored
     * content, then the image set for its post type (Yoast Premium) — and only when all of that
     * found nothing does the page's first image in a global block or field step in, before the
     * site's default. A picture chosen by a person is never replaced.
     *
     * @param mixed $container Yoast's Images value object.
     * @return mixed
     */
    public static function open_graph_image( $container ) {
        if ( ! is_object( $container ) || ! method_exists( $container, 'has_images' ) || $container->has_images() ) {
            return $container;
        }
        $post_id = self::presented_post();
        if ( $post_id <= 0 ) {
            return $container;
        }
        $first = ContentImages::of_post( $post_id, 1 );
        if ( $first !== [] && method_exists( $container, 'add_image_by_id' ) ) {
            try {
                $container->add_image_by_id( $first[0] );
            } catch ( \Throwable $e ) {
                // A fallback picture is not worth a broken <head>.
            }
        }
        return $container;
    }

    /**
     * The record whose images are being generated; 0 for a term, an archive, or when a type image
     * (which Yoast adds AFTER this filter) is set. Without a remembered presentation — a caller that
     * went around Yoast's filter — the singular page being viewed.
     */
    private static function presented_post(): int {
        $presentation = self::$presentation;
        if ( $presentation === null ) {
            return is_singular() ? (int) get_queried_object_id() : 0;
        }
        try {
            $model = $presentation->model ?? null;
            if ( ! is_object( $model ) || ( $model->object_type ?? '' ) !== 'post' ) {
                return 0;
            }
            // Yoast's add_from_templates(): runs after us and stops at the first image, so ours
            // would hide the type's picture. It reads exactly these two.
            if ( $presentation->open_graph_image_id || $presentation->open_graph_image ) {
                return 0;
            }
            return (int) $model->object_id;
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    /**
     * The images in a record's blocks and fields, added to its sitemap entry. Yoast lists what it
     * found in the stored content; these are the ones it could not see. Each goes through the same
     * filters as Yoast's own (a CDN rewriting `wpseo_xml_sitemap_img_src` rewrites ours too), so
     * a picture both lists is listed once.
     *
     * @param mixed $images  Yoast's list: [['src' => url, 'alt' => …], …].
     * @param mixed $post_id
     * @return mixed
     */
    public static function sitemap_images( $images, $post_id ) {
        $post = get_post( (int) $post_id );
        if ( ! is_array( $images ) || ! $post instanceof \WP_Post ) {
            return $images;
        }
        $seen = [];
        foreach ( $images as $image ) {
            if ( is_array( $image ) && isset( $image['src'] ) ) {
                $seen[ (string) $image['src'] ] = true;
            }
        }
        foreach ( ContentImages::of_post( $post->ID ) as $id ) {
            $src = (string) wp_get_attachment_image_url( $id, 'full' );
            if ( $src === '' ) {
                continue;
            }
            // Yoast's own hooks for every sitemap image (Sitemap_Image_Parser::get_image_item()).
            $src = (string) apply_filters( 'wpseo_xml_sitemap_img_src', $src, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast's own filter, applied as Yoast applies it.
            if ( $src === '' || isset( $seen[ $src ] ) ) {
                continue;
            }
            $seen[ $src ] = true;
            $item         = [ 'src' => $src ];
            $alt          = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
            if ( $alt !== '' ) {
                $item['alt'] = $alt;
            }
            $item = apply_filters( 'wpseo_xml_sitemap_img', $item, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast's own filter, applied as Yoast applies it.
            if ( is_array( $item ) && ! empty( $item['src'] ) ) {
                $images[] = $item;
            }
        }
        return $images;
    }

    /**
     * Yoast's "remove unregistered URL parameters" cleanup 301s away every argument not on ITS
     * list; the ones a theme or plugin registered are added to it, so they keep working.
     * WordPress's own are left to Yoast.
     *
     * @param mixed $vars
     * @return mixed
     */
    public static function allowed_query_vars( $vars ) {
        return is_array( $vars ) ? array_values( array_unique( array_merge( $vars, SiteQueryVars::added() ) ) ) : $vars;
    }

    /** `%%fab_…%%`, on `wpseo_register_extra_replacements`. */
    public static function register_variables(): void {
        if ( ! function_exists( 'wpseo_register_var_replacement' ) ) {
            return;
        }
        static $registered = [];
        foreach ( array_keys( FieldText::names() ) as $name ) {
            if ( isset( $registered[ $name ] ) ) {
                continue;
            }
            $registered[ $name ] = true;
            wpseo_register_var_replacement( $name, [ self::class, 'variable' ], 'advanced', FieldText::describe( $name ) );
        }
    }

    /**
     * Yoast's callback: `$args` is what it renders for — a post (ID), a term or an archive (none).
     * Null makes Yoast drop the variable, and a doubled separator with it.
     *
     * @param mixed $name
     * @param mixed $args
     */
    public static function variable( $name, $args = null ): ?string {
        $text = FieldText::for_variable( (string) $name, is_object( $args ) ? (int) ( $args->ID ?? 0 ) : 0 );
        // Yoast strips tags from the whole title after the variables are in, so "Kids <12" would be
        // cut at "<": "<" goes in as "&lt;", which the strip leaves and esc_html() prints as "<".
        return $text === null ? null : str_replace( '<', '&lt;', $text );
    }
}
