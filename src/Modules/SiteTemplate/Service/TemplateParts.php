<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Framework\Keys;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * The GLOBAL BLOCKS a template places.
 *
 * A template is its content: blocks in order, one of them the content slot. A global block (a
 * synced pattern, `wp_block`) is placed with `<!-- wp:block {"ref":ID} /-->`; a header or footer
 * is one filed under the pattern category Headers or Footers. Several templates placing the same
 * pattern share it: change it once, every template changes. Nothing of this is stored beside the
 * content: "where is this global block used" is a search of the templates' content
 * ({@see templates_using()}).
 *
 * LANGUAGES. Every language version of a template places the ORIGINAL pattern's id, the way pages
 * do; the translation plugin swaps it for the pattern's version in the reader's language. A
 * language with no version shows the original's words.
 */
final class TemplateParts {

    /**
     * Templates (not in the bin) whose content places `$pattern_id`. On a multilingual site each
     * row says its `language`, and a language version its `originalId` — the versions of one
     * template are one template to whoever counts them.
     *
     * @return list<array{id:int,title:string,language?:string,originalId?:int}>
     */
    public static function templates_using( int $pattern_id ): array {
        if ( $pattern_id < 1 ) {
            return [];
        }
        $out = [];
        foreach ( self::template_refs() as $id => $template ) {
            if ( in_array( $pattern_id, $template['refs'], true ) ) {
                $out[] = [ 'id' => $id, 'title' => $template['title'] ];
            }
        }
        if ( $out === [] || ! Translation::is_multilingual() ) {
            return $out;
        }
        $bridge = Translation::bridge();
        $facts  = $bridge->version_facts( array_column( $out, 'id' ), SiteTemplatePostType::POST_TYPE );
        foreach ( $out as $i => $row ) {
            $out[ $i ]['language'] = (string) ( $facts[ $row['id'] ]['language'] ?? '' );
            if ( empty( $facts[ $row['id'] ]['isOriginal'] ) ) {
                $original = $bridge->original_of( $row['id'], SiteTemplatePostType::POST_TYPE );
                if ( $original > 0 && $original !== $row['id'] ) {
                    $out[ $i ]['originalId'] = $original;
                }
            }
        }
        return $out;
    }

    /**
     * A global block a template places is not moved to the bin or deleted from
     * WordPress's own screens (Patterns, the list table, the REST API) — every page wearing that
     * template would lose its frame. The tools refuse the same with the templates named.
     *
     * @param mixed    $check null to let WordPress go on.
     * @param \WP_Post $post
     * @return mixed
     */
    public static function guard_delete( $check, $post ) {
        if ( $check !== null || ! $post instanceof \WP_Post || $post->post_type !== SyncedPatterns::POST_TYPE ) {
            return $check;
        }
        $templates = self::templates_using( (int) $post->ID );
        if ( $templates === [] ) {
            return $check;
        }
        if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            wp_die(
                esc_html( self::in_use_sentence( $post, $templates ) ),
                esc_html__( 'This global block is in use', 'fabrement-lite' ),
                [ 'response' => 409, 'back_link' => true ]
            );
        }
        // The editor's delete is answered by guard_rest_delete() with the same sentence; anything
        // else simply does not delete it.
        return false;
    }

    /** The sentence a person reads when a global block templates place is not deleted. */
    private static function in_use_sentence( \WP_Post $post, array $templates ): string {
        return sprintf(
            /* translators: 1: pattern title, 2: template titles. */
            __( '"%1$s" is placed in these templates: %2$s. Take it out of them, or put another in its place, first (Fabrement → Templates), then delete it.', 'fabrement-lite' ),
            $post->post_title,
            implode( ', ', array_column( $templates, 'title' ) )
        );
    }

    /**
     * The same refusal for the editor, which deletes over REST (DELETE /wp/v2/blocks/<id>): without
     * it WordPress only says "The post cannot be deleted."
     *
     * @param mixed            $response
     * @param array            $handler
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function guard_rest_delete( $response, $handler, $request ) {
        if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request || $request->get_method() !== 'DELETE'
            || preg_match( '#^/wp/v2/blocks/(\d+)$#', (string) $request->get_route(), $found ) !== 1 ) {
            return $response;
        }
        $post      = get_post( (int) $found[1] );
        $templates = $post instanceof \WP_Post && $post->post_type === SyncedPatterns::POST_TYPE ? self::templates_using( (int) $post->ID ) : [];
        if ( $templates === [] ) {
            return $response;
        }
        return new WP_Error( 'fabrement_global_block_in_use', self::in_use_sentence( $post, $templates ), [ 'status' => 409 ] );
    }

    /** @var array<int,array{title:string,refs:list<int>}>|null Every template's placed global blocks, read once a request. */
    private static ?array $template_refs = null;

    /**
     * The global blocks each template (not in the bin) places — one read for a whole listing of
     * patterns. Forgotten whenever a template or a global block is written ({@see forget()},
     * {@see SiteTemplateRegistry::flush()}).
     *
     * @return array<int,array{title:string,refs:list<int>}>
     */
    private static function template_refs(): array {
        global $wpdb;
        if ( self::$template_refs !== null ) {
            return self::$template_refs;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the templates of every status but the bin whose content names a ref at all; which refs is read from the parsed blocks below. Kept for this request only.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_content FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND post_content LIKE %s",
                SiteTemplatePostType::POST_TYPE,
                '%' . $wpdb->esc_like( '"ref"' ) . '%'
            )
        );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row->ID ] = [ 'title' => (string) $row->post_title, 'refs' => self::refs( (string) $row->post_content ) ];
        }
        return self::$template_refs = $out;
    }

    /** Forget which global blocks the templates place: a template was written. */
    public static function forget_templates(): void {
        self::$template_refs = null;
    }

    /** Forget what was read from global blocks: one of them was written. */
    public static function forget(): void {
        self::$template_refs = null;
        SiteTemplateRegistry::flush();
    }

    /**
     * Whether WordPress draws this global block: it exists, is synced, published and has no
     * password.
     */
    public static function draws( int $pattern_id ): bool {
        $post = SyncedPatterns::placeable( $pattern_id );
        return $post !== null && $post->post_status === 'publish' && (string) $post->post_password === '';
    }

    /**
     * What is wrong with a global block a template places, in a sentence for whoever fixes it; ''
     * when WordPress draws it.
     */
    public static function problem( int $pattern_id ): string {
        $post = $pattern_id > 0 ? get_post( $pattern_id ) : null;
        if ( ! $post instanceof \WP_Post || $post->post_type !== SyncedPatterns::POST_TYPE ) {
            /* translators: %d: synced pattern ID. */
            return sprintf( __( 'Its global block (id %d) was deleted. Choose another global block.', 'fabrement-lite' ), $pattern_id );
        }
        $title = (string) $post->post_title;
        if ( $post->post_status === 'trash' ) {
            /* translators: %s: synced pattern title. */
            return sprintf( __( 'The global block "%s" is in the trash. Restore it, or choose another global block.', 'fabrement-lite' ), $title );
        }
        if ( get_post_meta( $pattern_id, 'wp_pattern_sync_status', true ) === 'unsynced' ) {
            /* translators: %s: synced pattern title. */
            return sprintf( __( 'The global block "%s" is not a synced pattern any more. Choose another global block.', 'fabrement-lite' ), $title );
        }
        if ( $post->post_status !== 'publish' ) {
            /* translators: 1: synced pattern title, 2: post status. */
            return sprintf( __( 'The global block "%1$s" is not published (%2$s). Publish it, or choose another global block.', 'fabrement-lite' ), $title, $post->post_status );
        }
        if ( (string) $post->post_password !== '' ) {
            /* translators: %s: synced pattern title. */
            return sprintf( __( 'The global block "%s" is password-protected, so it is not drawn. Remove its password, or choose another global block.', 'fabrement-lite' ), $title );
        }
        return '';
    }

    /**
     * The global blocks a content places, at any depth, in order ({@see SyncedPatterns::refs_in()}).
     *
     * @return list<int>
     */
    public static function refs( string $content ): array {
        return SyncedPatterns::refs_in( $content );
    }


    /**
     * The original of a global block's language group — what a template or a page places; the id
     * itself on a single-language site, or when it is the original.
     */
    public static function original_of( int $pattern_id ): int {
        if ( ! Translation::is_multilingual() || Translation::bridge()->is_original( $pattern_id, SyncedPatterns::POST_TYPE ) ) {
            return $pattern_id;
        }
        // The bridge reads the translation row, so an original in the bin is still found.
        $original = Translation::bridge()->original_of( $pattern_id, SyncedPatterns::POST_TYPE );
        return $original > 0 ? $original : $pattern_id;
    }

}
