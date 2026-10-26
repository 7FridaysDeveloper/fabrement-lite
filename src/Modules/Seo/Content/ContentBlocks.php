<?php

namespace Fabrement\Modules\Seo\Content;

use Fabrement\Modules\Translation\Translation;

/**
 * The core images inside the global blocks (synced patterns) a record's content places.
 *
 * A synced pattern sits in the content as `<!-- wp:block {"ref":N} /-->`: no inner blocks, its
 * own live in the `wp_block` post N. Reading the content alone, an image inside a global block is
 * not there — nothing for a sitemap. This opens every pattern the way the page renders it:
 *
 *   - in the record's language: pages keep the ORIGINAL pattern's id and the translation plugin
 *     swaps it for the reader's version on render, falling back to the original where there is
 *     none (WPML's `render_block_data`, whatever the version's status);
 *   - only a published pattern without a password, as `render_block_core_block()` shows;
 *   - a pattern inside itself once, and no deeper than MAX_DEPTH.
 *
 * An image of the record's own content is in its saved HTML, where SEO plugins look themselves.
 */
final class ContentBlocks {

    private const MAX_DEPTH = 5;

    /** A page's worth; a content that places more is cut here rather than walked to the end. */
    private const MAX_BLOCKS = 1000;

    /** Patterns opened for one record, repeats included: a pattern of patterns of patterns… stops. */
    private const MAX_OPENED = 200;

    private const POST_TYPE = 'wp_block';

    /** @var array<int,array<int,array>> pattern id => its parsed blocks, for this request */
    private static array $patterns = [];

    /**
     * A pattern saved in this request is read afresh — and so are the images of every page. Hooked
     * by the memo itself the first time it holds a pattern.
     */
    private static function hook(): void {
        if ( has_action( 'clean_post_cache', [ self::class, 'forget' ] ) === false ) {
            add_action( 'clean_post_cache', [ self::class, 'forget' ], 10, 2 );
        }
    }

    /**
     * @param mixed $post_id
     * @param mixed $post
     */
    public static function forget( $post_id, $post = null ): void {
        unset( self::$patterns[ (int) $post_id ] );
        if ( $post instanceof \WP_Post && $post->post_type === self::POST_TYPE ) {
            ContentImages::flush(); // the pages placing it read it afresh
        }
    }

    public static function flush(): void {
        self::$patterns = [];
    }

    /**
     * @param array<int,array> $blocks parse_blocks() of the record's content
     * @param int              $post_id the record, for its language
     * @return list<array> core/image blocks, in reading order
     */
    public static function with_pattern_images( array $blocks, int $post_id ): array {
        $out    = [];
        $opened = 0;
        self::collect( $blocks, self::language( $post_id ), [], $out, $opened );
        return $out;
    }

    /**
     * @param array<int,array> $blocks
     * @param array<int,true>  $open   patterns being walked, the one this is inside last
     * @param list<array>      $out
     * @param int              $opened patterns opened so far
     */
    private static function collect( array $blocks, string $lang, array $open, array &$out, int &$opened ): void {
        foreach ( $blocks as $block ) {
            if ( count( $out ) >= self::MAX_BLOCKS ) {
                return;
            }
            $name = (string) ( $block['blockName'] ?? '' );
            if ( $name === 'core/block' ) {
                $id = self::pattern_id( $block['attrs']['ref'] ?? null, $lang );
                if ( $id > 0 && ! isset( $open[ $id ] ) && count( $open ) < self::MAX_DEPTH && $opened < self::MAX_OPENED ) {
                    ++$opened;
                    self::collect( self::pattern_blocks( $id ), $lang, $open + [ $id => true ], $out, $opened );
                }
                continue;
            }
            if ( $open !== [] && $name === 'core/image' ) {
                $out[] = $block;
                continue;
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                self::collect( $block['innerBlocks'], $lang, $open, $out, $opened );
            }
        }
    }

    /**
     * The pattern a page in `$lang` shows for `$ref`: its version in that language, else the
     * original. 0 when the ref names nothing.
     *
     * @param mixed $ref
     */
    private static function pattern_id( $ref, string $lang ): int {
        // A number however the JSON spelled it (5, "5", 5.0), as WPML and core read it.
        $id = is_numeric( $ref ) && (int) $ref == $ref ? (int) $ref : 0; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- 5.0 and "5" are the id 5.
        if ( $id <= 0 || $lang === '' ) {
            return $id;
        }
        $version = Translation::bridge()->version_in( $id, self::POST_TYPE, $lang );
        return $version > 0 ? $version : $id;
    }

    /** @return array<int,array> */
    private static function pattern_blocks( int $id ): array {
        if ( ! isset( self::$patterns[ $id ] ) ) {
            self::hook();
            if ( count( self::$patterns ) > 200 ) {
                self::$patterns = []; // a long CLI run: memory over hits
            }
            $post                  = get_post( $id );
            self::$patterns[ $id ] = $post instanceof \WP_Post
                && $post->post_type === self::POST_TYPE
                && $post->post_status === 'publish'
                && (string) $post->post_password === ''
                ? parse_blocks( (string) $post->post_content )
                : [];
        }
        return self::$patterns[ $id ];
    }

    private static function language( int $post_id ): string {
        return Translation::is_multilingual() ? Translation::live_language_of( $post_id ) : '';
    }
}
