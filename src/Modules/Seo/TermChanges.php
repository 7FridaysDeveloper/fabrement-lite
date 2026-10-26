<?php

namespace Fabrement\Modules\Seo;

/**
 * Fabrement's own event when one of ITS tools changes a record's terms — set-post-terms, and
 * delete-term for every record the deleted term leaves — so an SEO plugin's integration keeps what
 * it derives from them (Yoast: the main category of the breadcrumbs) in step with the change.
 *
 *     do_action( 'fabrement_post_terms_changed', int $post_id, string $taxonomy )
 *
 * Only for Fabrement's own changes. Terms changed by the editor, WP-CLI, an import or another plugin
 * are the SEO plugin's business, as on any site: Fabrement does not correct them.
 */
final class TermChanges {

    public const ACTION = 'fabrement_post_terms_changed';

    /** Tell the integrations that Fabrement changed the `$taxonomy` terms of record `$post_id`. */
    public static function announce( int $post_id, string $taxonomy ): void {
        // Terms classify users and links too; only a record's terms are announced.
        $post_type = (string) get_post_type( $post_id );
        if ( $post_type === '' || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
            return;
        }
        do_action( self::ACTION, $post_id, $taxonomy ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is fabrement_post_terms_changed.
    }
}
