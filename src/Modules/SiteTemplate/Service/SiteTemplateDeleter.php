<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Framework\Keys;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\Translation\Translation;

/**
 * Removing a template and everything that pointed at it.
 *
 * Written once because it was written twice: the REST route and the MCP ability each carried
 * their own copy of the same four steps, which is how the translations came to be forgotten in
 * both — a fix to one would have been a fix to one.
 */
final class SiteTemplateDeleter {

    /**
     * @param int $template_id An original — the template and every language's version of it go —
     *                         or one language's version, which goes alone, the way deleting a
     *                         translated page leaves the original and the other languages be.
     * @return int|\WP_Error How many pages had explicitly picked this template and were handed
     *                       back to the default one (0 for a version — pages pick originals).
     */
    public static function delete( int $template_id ) {
        $bridge = Translation::bridge();

        // One language's version: only that record. Pages point at originals, so no page pick
        // names it. Asked of the plugin directly (not originals(), which reads the batched map and can
        // disagree with it), and answered with a WP_Error rather than a throw: both callers hand
        // this straight to the REST layer, and a throw there is an HTTP 500 with no body.
        if ( ! $bridge->is_original( $template_id, SiteTemplatePostType::POST_TYPE ) ) {
            $post = get_post( $template_id );
            if ( ! $post || $post->post_type !== SiteTemplatePostType::POST_TYPE ) {
                return new \WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
            }
            SiteTemplatePostType::clear_template_from_defaults( $template_id );
            ArchiveTemplateBinding::forget_template( $template_id );
            wp_delete_post( $template_id, true );
            SiteTemplateRegistry::flush();

            return 0;
        }

        // Drop it from every post type's default (it may have been default for several types),
        // so no type keeps pointing at a deleted template.
        SiteTemplatePostType::clear_template_from_defaults( $template_id );
        // Same for every ARCHIVE view bound to it. The render side already fails safe (an
        // unresolvable binding hands the view back to the theme), but a leftover row would keep
        // reporting the deleted template as bound.
        ArchiveTemplateBinding::forget_template( $template_id );
        // Pages that explicitly picked this template go back to the default template — no stale
        // slugs left behind.
        $cleared = SiteTemplatePages::clear_slug( SiteTemplatePostType::slug_for( $template_id ) );

        // The other languages' versions go with it. A translation is a post of the same type
        // that no screen lists and no route can reach once its original is gone — it would sit
        // in the database forever, and resurface as a standalone assignable template if the
        // translation plugin's grouping were ever dropped. Read BEFORE the original is deleted,
        // while the group still exists to be read.
        //
        // Every id is checked before it is destroyed. The group comes from the translation
        // plugin's own table, which outlives the posts in it: a stale row there would otherwise
        // make deleting a template destroy an unrelated record — a page, a post, anything — with
        // no trash to recover it from. The inline versions this class replaced deleted exactly
        // one verified id; this keeps that guarantee for every id in the group.
        foreach ( $bridge->all_versions( $template_id, SiteTemplatePostType::POST_TYPE ) as $translation_id ) {
            $translation_id = (int) $translation_id;
            if ( $translation_id <= 0 || $translation_id === $template_id ) {
                continue;
            }

            $post = get_post( $translation_id );
            if ( ! $post || $post->post_type !== SiteTemplatePostType::POST_TYPE ) {
                continue;
            }

            SiteTemplatePostType::clear_template_from_defaults( $translation_id );
            ArchiveTemplateBinding::forget_template( $translation_id );

            wp_delete_post( $translation_id, true );
        }

        // The manifest is NOT dropped here. wp_delete_post() above fires before_delete_post, which
        // is forget() below, which does it — for this deletion and for every one we do not run
        // ourselves. Repeating it here would be a no-op anyway: by this line the postmeta holding
        // the key is gone, so there is nothing left to look up.
        wp_delete_post( $template_id, true );
        SiteTemplateRegistry::flush();

        return $cleared;
    }



    /**
     * `before_delete_post`: drop every pointer at a template WordPress is about to delete on its
     * own — trash purge, "Delete permanently" in the editor. The deletions above pass through
     * here as well; the steps are idempotent, so that costs three no-op queries and nothing else.
     *
     * Only the pointers. The other languages' versions are NOT taken along here: a purge is not
     * a decision anyone made about the template, and deleting more than WordPress was asked to
     * delete is not this hook's place.
     *
     * @param int           $post_id
     * @param \WP_Post|null $post
     */
    public static function forget( int $post_id, $post = null ): void {
        $post = $post instanceof \WP_Post ? $post : get_post( $post_id );
        if ( ! $post || $post->post_type !== SiteTemplatePostType::POST_TYPE ) {
            return;
        }

        SiteTemplatePostType::clear_template_from_defaults( $post_id );
        ArchiveTemplateBinding::forget_template( $post_id );
        SiteTemplatePages::clear_slug( SiteTemplatePostType::slug_for( $post_id ) );
        $key = SiteTemplatePostType::manifest_key( $post_id );
        if ( $key !== '' ) {
            // The last holder removes the file; deleting a translation preserves siblings.
            $holders = get_posts( [ 'post_type' => SiteTemplatePostType::POST_TYPE,
                'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => 1,
                'fields' => 'ids', 'post__not_in' => [ $post_id ], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- one row: any OTHER version holding this manifest.
                'meta_key' => Keys::META_TEMPLATE_KEY, 'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-row lookup among the site's templates, on delete only.
            ] );
            if ( $holders === [] ) { TemplateManifestStore::delete( $key ); }
        }
        SiteTemplateRegistry::flush();
    }
}
