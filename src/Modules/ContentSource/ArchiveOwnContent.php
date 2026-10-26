<?php

namespace Fabrement\Modules\ContentSource;

use Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding;
use Fabrement\Modules\Translation\Translation;
use WP_Post;

/**
 * A listing view's OWN content — the blocks written on the page that stands for it. Only the posts
 * index has one: the page WordPress names under Settings → Reading (`page_for_posts`). Post-type
 * and term archives, author, date and search have none and answer "" here — they are dressed by
 * a theme file or not at all.
 *
 *   - no theme file wired for the view → the content slot prints these blocks, exactly as it
 *     prints a page's own blocks on a singular view;
 *   - a file IS wired → the file prints everything and decides where these blocks go, by
 *     calling `fabrement_archive_content()` — the listing's answer to `the_content()`.
 *
 * WHY A SEPARATE CALL AND NOT `the_content()`. On a listing the global `$post` is whichever record
 * the loop is on — `the_content()` there prints one arbitrary post. The page has to be named, and
 * only the platform knows which one it is.
 */
final class ArchiveOwnContent {

    /**
     * The page whose blocks are this view's own content, in the reader's language, or 0.
     *
     * @param string $context A view context ("blog-home", "archive:book"); "" asks the request.
     */
    public static function page_id( string $context = '' ): int {
        if ( $context === '' ) {
            $context = ArchiveTemplateBinding::current_context();
        }

        return $context === 'blog-home' ? self::posts_page_id() : 0;
    }

    /**
     * This view's own blocks, rendered, or "" when the view has no page or the page is empty.
     *
     * THE GLOBAL `$post` IS SAVED AND PUT BACK, rather than reset with wp_reset_postdata(). The
     * difference matters exactly once, and it is the footgun this method exists to defuse: called
     * from INSIDE the listing loop, wp_reset_postdata() would repoint `$post` at the main query's
     * FIRST record, not the one the loop is on — so the rest of that iteration would draw a card
     * out of someone else's data, and it would read as the pagination being broken rather than as
     * a line in a theme file. Saved and restored, the call is safe anywhere in the file.
     *
     * @param string $context A view context; "" asks the request.
     */
    public static function render( string $context = '' ): string {
        $page_id = self::page_id( $context );
        if ( $page_id <= 0 ) {
            return '';
        }

        $page = get_post( $page_id );
        if ( ! $page instanceof WP_Post ) {
            return '';
        }

        // Nothing written: return before touching the globals at all. A posts page that was
        // never filled in is the normal case, not an edge one.
        if ( trim( (string) $page->post_content ) === '' ) {
            return '';
        }

        $previous = $GLOBALS['post'] ?? null;

        // The record becomes the current post for the render, so blocks inside it that reach for
        // the loop (get_the_ID(), a heading that prints the title) see the record they were
        // written on rather than a listing row.
        $GLOBALS['post'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the posts page becomes the current post for its render, restored in the finally below.
        setup_postdata( $page );

        try {
            return ContentSourceBlock::content_of( $page_id );
        } finally {
            if ( $previous instanceof WP_Post ) {
                $GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the post that was current before the render.
                setup_postdata( $previous );
            } else {
                // No post was set up before us — an archive view before its loop starts. Putting
                // one back would be inventing state the request did not have.
                $GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the empty state the request had before the render.
            }
        }
    }

    /**
     * The posts index's page: the one assigned under Settings → Reading, in the reader's
     * language. 0 when the site has no separate posts page — then the blog home is the front page
     * and has no record of its own to print.
     */
    private static function posts_page_id(): int {
        $id = (int) get_option( 'page_for_posts' );
        if ( $id <= 0 ) {
            return 0;
        }

        $bridge = Translation::bridge();
        $lang   = $bridge->current_language();
        if ( $lang !== '' ) {
            $translated = $bridge->version_in( $id, 'page', $lang );
            if ( $translated > 0 && get_post_status( $translated ) === 'publish' ) {
                return $translated;
            }
        }

        return get_post_status( $id ) === 'publish' ? $id : 0;
    }
}
