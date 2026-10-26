<?php

namespace Fabrement\Modules\Translation;

use Fabrement\Modules\Mcp\Support\ContentParent;
use WP_Error;
use WP_Post;

/**
 * Start one language's version of a page or post: a full copy of the original — blocks as they
 * are, excerpt, featured image, terms mapped to their translations, page template (SEO meta: WPML) —
 * linked to the original as its translation and left as a draft for the words to be changed.
 *
 * The counterpart of {@see \Fabrement\Modules\SiteTemplate\Service\SiteTemplateTranslator::open()}
 * for content: the same "copy, then edit" — nothing is translated here, so nothing here can be
 * translated wrong. The copy's text is the agent's next step, through save-content
 * {contentId: the copy}, which is the ordinary edit of an ordinary record.
 */
final class ContentTranslator {

    /**
     * Find, or create, the post holding `$language`'s version of `$content_id`.
     *
     * @return array{id:int,created:bool,parentDropped?:array{id:int,reason:string}}|WP_Error
     *         The version's post id (with `parentDropped`, the original's parent it could not be
     *         put under — see parent_in()), or a WP_Error whose code names the refusal and whose data may carry `screenUrl` where the translation
     *         plugin's editor owns the words.
     *
     * `$slug`, when given, is the new copy's address (sanitised, made unique among its siblings);
     * empty leaves it to WordPress, which derives it from the title at publish. An existing
     * version keeps its own.
     */
    public static function open( int $content_id, string $language, string $slug = '' ) {
        $bridge = Translation::bridge();

        $original = get_post( $content_id );
        // A synced pattern (wp_block) is not viewable on its own, but it is content a page renders
        // and is translated the same way — translate-synced-pattern opens it here.
        if ( ! $original instanceof WP_Post || ( ! is_post_type_viewable( $original->post_type ) && $original->post_type !== 'wp_block' ) || $original->post_status === 'trash' ) {
            return new WP_Error( 'fabrement_ct_not_found', __( 'Content not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }
        $type = (string) $original->post_type;

        // A type the plugin does NOT translate is one set of records for every language: a
        // "version" of one is a second record shown in every language beside the first. The
        // plugin may still hold language rows from when the type was translatable, so its word on
        // the type decides, before any existing version is handed back. Unknown (null) goes on.
        if ( $bridge->is_post_type_translatable( $type ) === false ) {
            return new WP_Error(
                'fabrement_ct_type_not_translated',
                __( 'This post type is the same in every language: the translation plugin does not translate it.', 'fabrement-lite' ),
                [ 'status' => 409, 'postType' => $type ]
            );
        }

        // Only the ORIGINAL is copied: a translation's id would ask for a translation of a
        // translation, and the group already has one source. Named in the data so the caller
        // can retry with the right id instead of guessing.
        if ( ! $bridge->is_original( $content_id, $type ) ) {
            $original_id = 0;
            foreach ( $bridge->all_versions( $content_id, $type ) as $id ) {
                if ( $bridge->is_original( (int) $id, $type ) ) {
                    $original_id = (int) $id;
                    break;
                }
            }
            return new WP_Error(
                'fabrement_ct_not_original',
                __( 'This record is itself a translation — pass its original.', 'fabrement-lite' ),
                [ 'status' => 409, 'originalId' => $original_id ]
            );
        }

        $known = array_column( $bridge->languages(), 'code' );
        if ( ! in_array( $language, $known, true ) ) {
            return new WP_Error( 'fabrement_ct_bad_language', __( 'That language is not set up on this site.', 'fabrement-lite' ), [ 'status' => 400 ] );
        }
        $own = Translation::live_language_of( $content_id, $type, $bridge->default_language() );
        if ( $own === $language ) {
            return new WP_Error( 'fabrement_ct_same_language', __( 'The original is already in that language.', 'fabrement-lite' ), [ 'status' => 400 ] );
        }

        $existing      = $bridge->all_versions( $content_id, $type );
        $existing_post = isset( $existing[ $language ] ) ? get_post( (int) $existing[ $language ] ) : null;
        if ( $existing_post && $existing_post->post_status === 'trash' ) {
            return new WP_Error(
                'fabrement_ct_version_in_trash',
                __( 'This content already has a version in that language, in the trash.', 'fabrement-lite' ),
                [ 'status' => 409, 'versionId' => (int) $existing_post->ID, 'language' => $language ]
            );
        }
        if ( $existing_post ) {
            return [ 'id' => (int) $existing_post->ID, 'created' => false ];
        }

        // Below the branch above on purpose — see SiteTemplateTranslator::open(). Where the
        // plugin's editor owns translations, a copy written here is rebuilt from the original
        // on its next completed job, with nothing to say why.
        if ( ! $bridge->writes_translations_manually( $content_id ) ) {
            return new WP_Error(
                'fabrement_ct_translation_not_manual',
                __( 'This content is translated by the translation plugin: it rewrites each language from the original, so a copy made here would be overwritten by the next job. Translate it in the plugin’s own screen instead.', 'fabrement-lite' ),
                [ 'status' => 409, 'screenUrl' => $bridge->translation_screen_url( $content_id ) ]
            );
        }

        if ( ! current_user_can( 'edit_post', $content_id ) ) {
            return new WP_Error( 'fabrement_ct_forbidden', __( 'You cannot edit this content.', 'fabrement-lite' ), [ 'status' => 403 ] );
        }

        $placed = self::parent_in( $original, $type, $language );
        $parent = $placed['parent'];
        $thumb  = (int) get_post_thumbnail_id( $original->ID );
        $own    = $thumb > 0 ? $bridge->version_in( $thumb, 'attachment', $language ) : 0;
        $thumb  = $own > 0 ? $own : $thumb;

        $copy = wp_insert_post(
            ( $thumb > 0 ? [ '_thumbnail_id' => $thumb ] : [] ) + [
                'post_type'    => $type,
                // A draft: the copy still reads in the original's language, and publishing it
                // as-is would hand readers of that language an untranslated page.
                'post_status'  => 'draft',
                'post_title'   => $original->post_title,
                // 'publish', not 'draft': wp_unique_post_slug() returns a draft's slug untouched,
                // so a taken one would only be renamed at publish (see save-content).
                'post_name'    => $slug !== '' ? wp_unique_post_slug( $slug, 0, 'publish', $type, $parent ) : '',
                'post_excerpt' => wp_slash( (string) $original->post_excerpt ),
                // wp_slash — wp_insert_post unslashes, and block delimiters carry escaped JSON.
                'post_content' => wp_slash( (string) $original->post_content ),
                'post_parent'  => $parent,
                'menu_order'   => (int) $original->menu_order,
                'post_author'  => get_current_user_id() ?: (int) $original->post_author,
            ],
            true
        );
        if ( is_wp_error( $copy ) ) {
            return $copy;
        }
        $copy = (int) $copy;

        // The link first: the term mapping below asks the copy's language.
        $bridge->link_translation( $copy, $content_id, $language, $type );

        self::copy_meta( $original, $copy, $thumb );
        self::copy_terms( $original, $copy, $language );

        // Read the link back rather than trust the write — the same guard the template path
        // keeps: a type the plugin has not yet seen as translatable makes the link a no-op, and
        // the copy is then a second ORIGINAL, i.e. the duplicate this whole scheme forbids.
        if ( $bridge->is_original( $copy, $type ) ) {
            wp_delete_post( $copy, true );
            return new WP_Error(
                'fabrement_ct_not_linked',
                __( 'The translation plugin did not accept this post type as translatable — check its Settings → Post Types Translation, then retry.', 'fabrement-lite' ),
                [ 'status' => 409 ]
            );
        }

        return [ 'id' => $copy, 'created' => true ] + ( $placed['dropped'] !== null ? [ 'parentDropped' => $placed['dropped'] ] : [] );
    }

    /**
     * The version's parent: the original's parent IN THIS LANGUAGE.
     *
     * Copying the original's parent id put a Polish page under the English one — an address that
     * mixes two languages. So the parent's own version is used; when there is none yet, or the
     * original's parent is itself gone (trashed, deleted), the copy starts at the top level and
     * `dropped` says which record and why. A parent the translation plugin does not track at all
     * is one record for every language, so it stays the parent here too.
     *
     * @return array{parent:int, dropped:?array{id:int, reason:string}}
     */
    private static function parent_in( WP_Post $original, string $type, string $language ): array {
        $parent = (int) $original->post_parent;
        if ( $parent <= 0 ) {
            return [ 'parent' => 0, 'dropped' => null ];
        }
        if ( ! ContentParent::usable( $parent, $type ) ) {
            return [ 'parent' => 0, 'dropped' => [ 'id' => $parent, 'reason' => 'unavailable' ] ];
        }

        $versions = Translation::bridge()->visible_versions( $parent, $type );
        if ( $versions === [] ) {
            return [ 'parent' => $parent, 'dropped' => null ];
        }
        // WPML's map keeps rows for records deleted since: judged, not trusted.
        $version = (int) ( $versions[ $language ] ?? 0 );
        if ( $version > 0 && ContentParent::usable( $version, $type ) ) {
            return [ 'parent' => $version, 'dropped' => null ];
        }

        return [ 'parent' => 0, 'dropped' => [ 'id' => $parent, 'reason' => 'untranslated' ] ];
    }

    /** The meta that makes a page the page it is: thumbnail (in the copy's language), page template. */
    private static function copy_meta( WP_Post $original, int $copy, int $thumb ): void {
        if ( $thumb > 0 && (int) get_post_thumbnail_id( $copy ) === 0 ) {
            set_post_thumbnail( $copy, $thumb );
        }
        $template = (string) get_post_meta( $original->ID, '_wp_page_template', true );
        if ( $template !== '' ) {
            update_post_meta( $copy, '_wp_page_template', $template );
        }

        // The SEO plugin's fields are the translation plugin's business: linking the copy (above, in
        // translate()) makes WPML copy each one as its wpml-config.xml says — robots and schema types
        // copied, the main category mapped to the copy's language, titles and descriptions left to
        // translate, the canonical never. Copying them here as well duplicated rows, carried the
        // original's canonical and words over, and overrode what the site set.
    }

    /**
     * Each term, in the copy's language: the term's translation where one exists, else the term
     * itself (a taxonomy the plugin does not translate, or a term not yet translated).
     */
    private static function copy_terms( WP_Post $original, int $copy, string $language ): void {
        $bridge = Translation::bridge();
        $own    = Translation::live_language_of( $original->ID, $original->post_type );

        foreach ( get_object_taxonomies( $original->post_type ) as $taxonomy ) {
            // Read in the ORIGINAL's language and written in the COPY's: the plugin adjusts term
            // ids to the request's language on both read and write, so from an English request
            // the Polish term set on the copy would be swapped back for its English sibling.
            $terms = $bridge->in_language( $own, static fn() => wp_get_object_terms( $original->ID, $taxonomy, [ 'fields' => 'ids' ] ) );
            if ( is_wp_error( $terms ) || $terms === [] ) {
                continue;
            }
            $mapped = [];
            foreach ( $terms as $term_id ) {
                $translated = $bridge->version_in( (int) $term_id, $taxonomy, $language );
                $mapped[]   = $translated > 0 ? $translated : (int) $term_id;
            }
            $mapped = array_values( array_unique( $mapped ) );
            $bridge->in_language( $language, static fn() => wp_set_object_terms( $copy, $mapped, $taxonomy ) );
        }
    }
}
