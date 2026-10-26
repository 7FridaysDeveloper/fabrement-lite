<?php

namespace Fabrement\Modules\Mcp\Support;

use Fabrement\Modules\Translation\Translation;

/**
 * Where a record sits in its type's tree: its parent, and its place among its siblings.
 *
 * Checked here rather than left to WordPress, because WordPress answers a bad parent by quietly
 * changing it: a loop is cut to 0 by wp_insert_post_parent, a parent of another type is stored
 * as given and breaks the address. Either way the call reports success and the page ends up
 * somewhere nobody asked for.
 */
final class ContentParent {

    /**
     * Statuses a parent may have: a draft parent is fine (the tree is often built before it goes
     * live), a trashed one is going away, and auto-draft / inherit are not content at all.
     */
    public const PARENT_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

    /**
     * The parent id to store, 0 = top level.
     *
     * @param mixed  $raw       The caller's `parent`.
     * @param string $post_type The record's type.
     * @param int    $self_id   The record's own id, 0 when it is being created.
     * @param string $language  The record's language, '' when the site has none.
     * @throws McpAbilityException
     */
    public static function resolve( $raw, string $post_type, int $self_id, string $language ): int {
        if ( ! is_int( $raw ) && ! ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
            throw new McpAbilityException( 'parent must be a record id (0 = top level) — see list-content.', [], 'invalid_parent' );
        }
        $asked = (int) $raw;
        if ( $asked < 0 ) {
            throw new McpAbilityException( 'parent must be a record id (0 = top level) — see list-content.', [], 'invalid_parent' );
        }
        if ( $asked === 0 ) {
            return 0;
        }

        if ( ! is_post_type_hierarchical( $post_type ) ) {
            throw new McpAbilityException(
                sprintf(
                    'Post type "%s" is not hierarchical, so its records have no parent. Nesting needs a hierarchical type (list-post-types `hierarchical`).',
                    esc_html( $post_type )
                ),
                [ 'postType' => $post_type ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error data array, not output.
                'not_hierarchical'
            );
        }

        $parent = get_post( $asked );
        if ( ! $parent instanceof \WP_Post || $parent->post_type !== $post_type ) {
            throw new McpAbilityException(
                sprintf( 'parent %d is not a "%s" record — a parent is a record of the SAME type (list-content {postType:"%s"}).', (int) $asked, esc_html( $post_type ), esc_html( $post_type ) ),
                [],
                'invalid_parent'
            );
        }
        if ( ! self::usable( $parent, $post_type ) ) {
            throw new McpAbilityException(
                sprintf(
                    'parent %d cannot hold children: its status is "%s". A parent is published, a draft, pending, private or scheduled.',
                    (int) $asked,
                    esc_html( (string) $parent->post_status )
                ),
                [],
                'invalid_parent'
            );
        }

        $parent_id = self::in_language( $asked, $post_type, $language );

        if ( $self_id > 0 && ( $parent_id === $self_id || in_array( $self_id, array_map( 'intval', get_post_ancestors( $parent_id ) ), true ) ) ) {
            throw new McpAbilityException(
                sprintf(
                    'parent %d%s is this record or one of its own children — that would make a loop. Pick a record outside its branch.',
                    (int) $asked,
                    $parent_id !== $asked ? sprintf( ' (its version in this language, %d)', (int) $parent_id ) : ''
                ),
                [],
                'parent_loop'
            );
        }

        return $parent_id;
    }

    /**
     * Can this record hold children of `$post_type` — does it exist, is it that type, and is it
     * content that stays? Shared with translate-content, which asks it of a parent's version.
     *
     * @param \WP_Post|int|null $post
     */
    public static function usable( $post, string $post_type ): bool {
        $post = $post instanceof \WP_Post ? $post : ( (int) $post > 0 ? get_post( (int) $post ) : null );

        return $post instanceof \WP_Post
            && $post->post_type === $post_type
            && in_array( (string) $post->post_status, self::PARENT_STATUSES, true );
    }

    /**
     * The parent's version in the record's language. A Polish page under an English parent
     * would sit at an address that mixes the two, so the parent is taken in the record's own
     * language — and refused when that version does not exist, or is gone.
     *
     * @throws McpAbilityException
     */
    private static function in_language( int $parent_id, string $post_type, string $language ): int {
        if ( $language === '' ) {
            return $parent_id;
        }
        $bridge = Translation::bridge();
        $own    = Translation::live_language_of( $parent_id, $post_type );
        if ( $own === '' || $own === $language ) {
            return $parent_id;
        }
        // The translation map is WPML's own, and it keeps rows for records deleted since — so the
        // version is judged like any parent, not trusted because the map names it.
        $version = (int) ( $bridge->visible_versions( $parent_id, $post_type )[ $language ] ?? 0 );
        if ( $version > 0 && self::usable( $version, $post_type ) ) {
            return $version;
        }
        throw new McpAbilityException(
            sprintf(
                'parent %d is in "%s" and this record is in "%s", which the parent has no version in. Make it first — translate-content {contentId: its originalId, language: "%s"} — then use that version as the parent.',
                (int) $parent_id,
                esc_html( $own ),
                esc_html( $language ),
                esc_html( $language )
            ),
            [],
            'parent_language'
        );
    }

    /**
     * `menuOrder` — the record's place among its siblings, WordPress's "Order" attribute.
     *
     * @param mixed $raw
     * @throws McpAbilityException
     */
    public static function menu_order( $raw ): int {
        if ( is_int( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && preg_match( '/^-?\d+$/', $raw ) ) {
            return (int) $raw;
        }
        throw new McpAbilityException( 'menuOrder must be an integer — lower comes first among records with the same parent.', [], 'invalid_menu_order' );
    }
}
