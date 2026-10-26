<?php

namespace Fabrement\Modules\ContentPreview;

use Fabrement\Framework\Keys;

/**
 * A session-less look at an UNPUBLISHED record, on its real front-end address.
 *
 * A draft is invisible to anyone without a wp-admin session — which is exactly what the agent's
 * browser tooling is — so the only way it could check a page it just wrote was to publish it,
 * and publishing is the one step that must wait for the user. This mints a short-lived token
 * bound to ONE record id and lets that record through WordPress's own status check for the
 * request that carries it: the page renders through the normal template, theme and translation
 * plugin, as a visitor would see it after publishing. Nothing about the record changes.
 *
 * The response is noindex and not cached.
 */
final class ContentPreview {

    /** Query argument carrying the token. The record it unlocks is stored with it, not sent. */
    public const TOKEN_ARG = 'fabrement_preview_token';

    /** How long a minted address stays valid. */
    public const TTL = PreviewToken::TTL;

    /** @var list<string>|null The statuses the preview page this request opened asked for. */
    private static ?array $opened = null;

    public static function init(): void {
        add_filter( 'the_posts', [ self::class, 'reveal' ], 10, 2 );
        add_filter( 'fabrement_posts_statuses', [ self::class, 'statuses' ] );
    }

    /**
     * Whether a record may be shown through a preview link, which anyone holding it opens without
     * signing in: a record of a public type that is published or on its way there (draft,
     * pending, scheduled). Never a private, password-protected or trashed one. The one rule for
     * every content preview link.
     */
    public static function shareable( int $post_id ): bool {
        $post = $post_id > 0 ? get_post( $post_id ) : null;
        return $post instanceof \WP_Post
            && $post->post_type !== 'attachment'
            && is_post_type_viewable( $post->post_type )
            && in_array( $post->post_status, [ 'publish', 'draft', 'pending', 'future' ], true )
            && $post->post_password === '';
    }

    /**
     * The preview address for a record: its own (possibly unpublished) link plus a fresh token, its
     * page reading records of $statuses where its code names none (null = as WordPress shows them).
     *
     * @param list<string>|null $statuses
     */
    public static function url( int $post_id, ?array $statuses = null ): string {
        $token = wp_generate_password( 32, false, false );
        set_transient( Keys::content_preview( $token ), $post_id . PreviewToken::with_statuses( $statuses ), self::TTL );

        // The record's address IN ITS OWN LANGUAGE — a Polish draft previewed under the English
        // prefix would render with the English header, footer and strings around Polish content.
        $bridge   = \Fabrement\Modules\Translation\Translation::bridge();
        $language = \Fabrement\Modules\Translation\Translation::live_language_of( $post_id );

        return add_query_arg( self::TOKEN_ARG, $token, $bridge->permalink( $post_id, $language ) );
    }

    /**
     * Called by a preview page once it has checked that the request's token is its own: records
     * fabrement() reads on that page take the statuses the token asks for (null = as WordPress).
     *
     * @param list<string>|null $statuses
     */
    public static function open( ?array $statuses ): void {
        self::$opened = $statuses;
    }

    /**
     * fabrement_posts_statuses: the statuses an opened preview page asked for, where the page's
     * code names none. Any other page, and a token on an address it was not made for, change nothing.
     *
     * @param list<string>|null $statuses
     * @return list<string>|null
     */
    public static function statuses( $statuses ): ?array {
        return $statuses === null && self::$opened !== null ? self::$opened : $statuses;
    }

    /** The record id the request's token unlocks, or 0 when there is no live token. */
    public static function token_id(): int {
        return (int) explode( '|', self::stored(), 2 )[0];
    }

    /** @return list<string>|null The statuses the request's token asks for. */
    public static function token_statuses(): ?array {
        return PreviewToken::statuses_in( self::stored() );
    }

    private static function stored(): string {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token is the credential.
        $token = isset( $_GET[ self::TOKEN_ARG ] ) ? sanitize_text_field( (string) wp_unslash( $_GET[ self::TOKEN_ARG ] ) ) : '';
        // phpcs:enable

        return preg_match( '/^[A-Za-z0-9]{32}$/', $token ) === 1 ? (string) get_transient( Keys::content_preview( $token ) ) : '';
    }

    /**
     * WordPress empties a singular main query whose post is not public and not the current
     * user's to read — AFTER `posts_results`, BEFORE `the_posts`. For a request carrying a valid
     * token for that very record, put it back.
     *
     * @param \WP_Post[] $posts
     * @return \WP_Post[]
     */
    public static function reveal( $posts, $query ) {
        if ( is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() || ! $query->is_singular() ) {
            return $posts;
        }
        $id = self::token_id();
        if ( $id <= 0 ) {
            return $posts;
        }
        // The token must be for the record THIS address asks for: the post WordPress found, or —
        // when it hid an unpublished one — the id in the query (page_id for pages, p otherwise).
        $asked = is_array( $posts ) && isset( $posts[0] ) ? (int) $posts[0]->ID : (int) ( $query->get( 'page_id' ) ?: $query->get( 'p' ) );
        if ( $asked !== $id ) {
            return $posts;
        }
        // Checked again on every open: a record made private or password-protected after the link
        // was made is not revealed.
        if ( ! self::shareable( $id ) ) {
            return $posts;
        }
        self::open( self::token_statuses() );
        $post = get_post( $id );

        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
        add_filter( 'wp_robots', 'wp_robots_no_robots' );

        // Rendered as published for this request only — the stored status is untouched.
        $post              = clone $post;
        $post->post_status = 'publish';

        return [ $post ];
    }
}
