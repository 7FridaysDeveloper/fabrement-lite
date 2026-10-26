<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Translation\Translation;

/**
 * Which Fabrement site template dresses each ARCHIVE view.
 *
 * Singular views resolve their template via a per-page pick or the post type's
 * site default (see the appliers). Archives have no post to store a pick on, so
 * the binding lives in one site option: a map of archive CONTEXT → template id.
 *
 * Context keys (same dialect as the content-source rules):
 *   - "archive:<postType>"       — the post-type archive (needs has_archive);
 *   - "blog-home"                — the posts index (home.php territory);
 *   - "tax:<taxonomy>:<term>"    — one term's archive;
 *   - "tax:<taxonomy>"           — every term archive of that taxonomy;
 *   - "tax"                      — every term archive of every public taxonomy.
 *
 * TERM ARCHIVES RESOLVE THROUGH A CHAIN, most specific first — the same idea as
 * the theme template hierarchy (taxonomy-genre-fantasy.php → taxonomy-genre.php →
 * taxonomy.php): "tax:genre:12" → "tax:genre" → "tax". The first bound,
 * published template wins; no binding anywhere in the chain = the theme renders.
 *
 * Resolution happens at render time (the appliers ask template_id_for()), so
 * re-binding or unbinding re-dresses the archive instantly — nothing is ever
 * written into posts, mirroring the singular site-default philosophy.
 */
final class ArchiveTemplateBinding {

    /** A valid archive context key: "archive:<postType>", "blog-home", "tax", "tax:<taxonomy>" or "tax:<taxonomy>:<term>". */
    private const CONTEXT_RE = '/^(archive:[a-z0-9_-]+|blog-home|tax(:[a-z0-9_-]+(:[a-z0-9%_-]+)?)?)$/D';

    /**
     * The full binding map.
     *
     * @return array<string,int> context => template id.
     */
    public static function all(): array {
        $raw = get_option( Keys::OPTION_ARCHIVE_TEMPLATES, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $context => $id ) {
            if ( is_string( $context ) && preg_match( self::CONTEXT_RE, $context ) === 1 && (int) $id > 0 ) {
                $out[ $context ] = (int) $id;
            }
        }
        return $out;
    }

    /**
     * The option EXACTLY as stored — every row, valid or not.
     *
     * {@see all()} is the reading view: it drops rows whose key does not match CONTEXT_RE or whose
     * value is not a positive id, which is right for resolving a template and wrong for writing.
     * Every writer here starts from the stored map and puts the whole thing back, so starting from
     * the filtered one deletes whatever the filter rejected — an uppercase slug, a shape from an
     * older version, a value stored as a string — permanently, and usually during an operation
     * about something else entirely.
     *
     * @return array<string,mixed>
     */
    private static function raw(): array {
        $stored = get_option( Keys::OPTION_ARCHIVE_TEMPLATES, [] );

        return is_array( $stored ) ? $stored : [];
    }

    /** The template bound to one archive context, or 0 when unbound. */
    public static function template_id_for( string $context ): int {
        return self::all()[ $context ] ?? 0;
    }

    /**
     * Bind (template id > 0) or unbind (0) an archive context. Returns false for
     * a malformed context key.
     */
    public static function set( string $context, int $template_id ): bool {
        if ( preg_match( self::CONTEXT_RE, $context ) !== 1 ) {
            return false;
        }
        $map = self::raw();
        if ( $template_id > 0 ) {
            $map[ $context ] = $template_id;
        } else {
            unset( $map[ $context ] );
        }
        update_option( Keys::OPTION_ARCHIVE_TEMPLATES, $map, false );
        return true;
    }

    /** Every context bound to one template (for listings / delete cleanup). */
    public static function contexts_for_template( int $template_id ): array {
        return array_keys( array_filter( self::all(), static fn( int $id ): bool => $id === $template_id ) );
    }

    /** Drop every binding that points at a deleted template. */
    public static function forget_template( int $template_id ): void {
        $map     = self::raw();
        $changed = false;
        foreach ( $map as $context => $id ) {
            if ( (int) $id === $template_id ) {
                unset( $map[ $context ] );
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( Keys::OPTION_ARCHIVE_TEMPLATES, $map, false );
        }
    }

    /**
     * The archive context of the CURRENT main query, or "" when the request is
     * not an archive view this system dresses. Shared by both appliers and the
     * early-enqueue resolver so they can never disagree.
     */
    public static function current_context(): string {
        // A missing address — whether the theme is about to show its 404 or the not-found PAGE has
        // taken the main query over (then is_404() is already false and the query looks singular).
        // First, so a not-found request never reads as the page that stands in for it.
        if ( is_404() || \Fabrement\Modules\NotFound\NotFoundPage::serving() ) {
            return '404';
        }
        if ( is_home() ) {
            return 'blog-home';
        }
        if ( is_post_type_archive() ) {
            $type = get_query_var( 'post_type' );
            $type = is_array( $type ) ? (string) reset( $type ) : (string) $type;
            if ( $type !== '' ) {
                return 'archive:' . $type;
            }
        }
        if ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term instanceof \WP_Term ) {
                // A binding is stored against ONE of the term's versions, and every version has
                // to resolve to the same key or a reader in another language falls through to the
                // taxonomy-wide rule. So the term is mapped to its original first.
                //
                // The ORIGINAL OF ITS GROUP, not the site default language's version. Those look
                // alike until somebody changes the default language, and then they part: the
                // original is fixed when the group is made, the default moves. Keyed on the moving
                // one, every term binding on the site silently stopped matching the moment the
                // language changed — measured, `tax:cuisine:italian` stored under an English
                // default, and both languages asking for `tax:cuisine:wloska` after it became
                // Polish.
                //
                // WP_Term::get_instance, not get_term(): the plugin hooks get_term's filter and
                // adjusts any term id back to the READER's language — which would undo the
                // mapping just made and hand the translated term back.
                $bridge   = Translation::bridge();
                $original = \WP_Term::get_instance( $bridge->original_term_id( $term->term_id, $term->taxonomy ), $term->taxonomy );
                if ( $original instanceof \WP_Term ) {
                    $term = $original;
                }
                // The term's ID, not its slug. A slug is a name somebody can change, and when
                // they do the binding it was keyed on stops matching — silently, because a missing
                // binding is indistinguishable from one that was never made.
                //
                // Lowercased to match CONTEXT_RE and the (lowercasing) header normaliser: a
                // taxonomy registered with uppercase must still hit the binding an admin stored.
                return strtolower( 'tax:' . $term->taxonomy . ':' . $term->term_id );
            }
        }
        return '';
    }

    /**
     * A context expanded into its RESOLUTION CHAIN, most specific first. Only term
     * contexts fan out ("tax:genre:12" → itself, "tax:genre", "tax") — every
     * other context is its own single step. This is the one place that knows the
     * order, shared by the frame binding and the content-slot rules so the two can
     * never resolve a view differently.
     *
     * @return list<string>
     */
    public static function context_chain( string $context ): array {
        if ( $context === '' ) {
            return [];
        }
        if ( strncmp( $context, 'tax:', 4 ) !== 0 ) {
            return [ $context ];
        }
        $chain = [ $context ];
        $parts = explode( ':', $context );

        if ( count( $parts ) === 3 ) {
            $chain[] = 'tax:' . $parts[1];
        }

        $chain[] = 'tax';

        return $chain;
    }

    /**
     * The template record bound to the current archive view, or null. Walks
     * the context chain (term → taxonomy → tax); a binding whose template is gone
     * or not published is skipped, falling through to the next step.
     */
    public static function current_record(): ?\Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord {
        foreach ( self::context_chain( self::current_context() ) as $context ) {
            // The not-found view's frame is chosen like a post type's default ("Default for: 404"),
            // not bound like an archive — one decision for the whole site.
            $id = $context === '404'
                ? \Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType::default_template_id_for( '404' )
                : self::template_id_for( $context );
            if ( $id <= 0 ) {
                continue;
            }
            $record = SiteTemplateRegistry::find( $id );
            if ( $record !== null ) {
                return $record;
            }
        }
        return null;
    }
}
