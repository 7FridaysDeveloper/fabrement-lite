<?php

namespace Fabrement\Modules\Seo\Content;

/**
 * The images a record SHOWS that an SEO plugin cannot see: the ones inside the global blocks it
 * places.
 *
 * SEO plugins look for images in the stored post_content — a featured image, `<img>` tags,
 * `[gallery]`. A global block's images are in another record. This finds them, in the order a
 * visitor meets them: blocks top to bottom.
 */
final class ContentImages {

    /** A record with more than this is a gallery page; sitemaps and previews need the first ones. */
    private const MAX = 50;

    /** Ids gathered before the image check; some may be files or gone. */
    private const CANDIDATES = 200;

    /** @var array<int,list<int>> post id => its images (all of them, up to MAX), for this request */
    private static array $memo = [];

    /**
     * Attachment ids of images, first seen first, no repeats. Read once per record per request: a
     * page view asks for its first one, a sitemap page for all of each record's.
     *
     * @return list<int>
     */
    public static function of_post( int $post_id, int $limit = self::MAX ): array {
        if ( $limit <= 0 ) {
            return [];
        }
        if ( ! isset( self::$memo[ $post_id ] ) ) {
            if ( count( self::$memo ) > 500 ) {
                self::$memo = []; // a long CLI run: memory over hits
            }
            self::hook();
            self::$memo[ $post_id ] = self::collect( $post_id );
        }
        return array_slice( self::$memo[ $post_id ], 0, $limit );
    }

    /**
     * A record saved in this request is read afresh. Hooked by the memo itself the first time it
     * holds something — whoever asks, and only then.
     */
    private static function hook(): void {
        if ( has_action( 'clean_post_cache', [ self::class, 'forget' ] ) !== false ) {
            return;
        }
        add_action( 'clean_post_cache', [ self::class, 'forget' ] );
    }

    /** @param mixed $post_id */
    public static function forget( $post_id ): void {
        unset( self::$memo[ (int) $post_id ] );
    }

    public static function flush(): void {
        self::$memo = [];
    }

    /** @return list<int> */
    private static function collect( int $post_id ): array {
        $post = get_post( $post_id );
        // A locked record's pictures stay behind its password, as its words do.
        if ( ! $post instanceof \WP_Post || (string) $post->post_password !== '' ) {
            return [];
        }
        // Candidates first (ids only), then one query primes every attachment, then only images stay.
        $ids = [];
        self::from_blocks( parse_blocks( (string) $post->post_content ), $post_id, $ids );
        $ids = array_keys( $ids );
        if ( $ids === [] ) {
            return [];
        }
        _prime_post_caches( $ids, false, true );
        $images = [];
        foreach ( $ids as $id ) {
            if ( wp_attachment_is_image( $id ) ) {
                $images[] = $id;
                if ( count( $images ) >= self::MAX ) {
                    break;
                }
            }
        }
        return $images;
    }

    /**
     * The core images of the global blocks the record places ({@see ContentBlocks}). A core image
     * of the record's own content is in its saved HTML, where SEO plugins already look.
     *
     * @param array<int,array> $blocks
     * @param array<int,true>  $ids    Collected so far, id => true (keeps first-seen order).
     */
    private static function from_blocks( array $blocks, int $post_id, array &$ids ): void {
        foreach ( ContentBlocks::with_pattern_images( $blocks, $post_id ) as $block ) {
            if ( count( $ids ) >= self::CANDIDATES ) {
                return;
            }
            $id = $block['attrs']['id'] ?? null;
            self::add( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ? (int) $id : 0, $ids );
        }
    }

    /** @param array<int,true> $ids */
    private static function add( int $id, array &$ids ): void {
        if ( $id > 0 && ! isset( $ids[ $id ] ) ) {
            $ids[ $id ] = true;
        }
    }
}
