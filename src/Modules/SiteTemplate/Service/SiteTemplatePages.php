<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\Translation\Translation;

/**
 * Pages × site-templates glue: which pages EXPLICITLY picked which template
 * (`_wp_page_template`), the picker listing for the SPA, and the two writes
 * the SPA offers — reconcile a template's page set and clear a deleted
 * template's picks. The site-default resolution never writes anything; this
 * class is only about explicit per-page picks.
 *
 * LANGUAGES. A translated page is a post of its own with its own `_wp_page_template`, and
 * `get_posts()` (suppress_filters on) returns every language's posts. Left alone, that makes the
 * picker list "About" and "O nas" as two unrelated rows, and a tick on one write the other
 * nothing. So a page is ONE row here — its original, with `language` and `translations` — and
 * a pick on it is written to the whole translation group, the way the translation plugin
 * itself copies this meta from an original to its translations. On a single-language site
 * the bridge answers "everything is original, nothing is translated" and every path below
 * reduces to the plain per-post behaviour.
 */
final class SiteTemplatePages {

    /** How a set of picks is applied: exactly this set, or only the named records. */
    public const MODE_REPLACE = 'replace';
    public const MODE_ADD     = 'add';
    public const MODE_REMOVE  = 'remove';

    /** The statuses a template dresses — everything an editor lists, and nothing binned. */
    public const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

    
    /** @return array<string,int> Explicit-pick counts per OUR template slug. */
    public static function counts(): array {
        global $wpdb;

        // ASKED OF THE META, not of the site. This used to load every record of every covered type
        // with posts_per_page -1 and read a meta per row, to end up with a handful of numbers —
        // and once a pick could be made on any public type, that walk grew to the whole content
        // table on every templates screen. The picks are what is being counted, and they are an
        // indexed lookup.
        $statuses = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one indexed read of the picks with their slugs across every post type (see above), which WP_Query cannot return; $statuses is a run of %s built with array_fill() from count( self::STATUSES ), matched by the spread ...self::STATUSES after the LIKE value.
        $rows     = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, m.meta_value AS slug FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                 WHERE m.meta_key = '_wp_page_template' AND m.meta_value LIKE %s
                   AND p.post_status IN ( {$statuses} )",
                $wpdb->esc_like( SiteTemplatePostType::SLUG_PREFIX ) . '%',
                ...self::STATUSES
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $rows === [] ) {
            return [];
        }

        // The slug came back with the row; asking the meta again per post would be the walk this
        // method just stopped doing, in a smaller disguise.
        $slug_of = [];
        foreach ( $rows as $row ) {
            $slug_of[ (int) $row->ID ] = (string) $row->slug;
        }

        // One fetch for every carrier, because originals() needs the posts themselves: a translated
        // page ticked once is ONE page wearing the template, in as many languages as it exists.
        $posts = get_posts(
            [
                'post__in'       => array_keys( $slug_of ),
                'post_type'      => 'any',
                'post_status'    => self::STATUSES,
                'posts_per_page' => -1,
            ]
        );

        $counts = [];
        foreach ( self::originals( $posts ) as $page ) {
            $slug = $slug_of[ (int) $page->ID ] ?? '';
            if ( SiteTemplatePostType::id_from_slug( $slug ) !== null ) {
                $counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;
            }
        }
        return $counts;
    }

    
    /**
     * Scalable picker rows: a title-search page (capped at $limit) PLUS every
     * page currently picking $include_slug — so on a site with thousands of
     * pages the panel only ever loads a bounded window, yet the template's own
     * picks always render (with correct checkbox state) regardless of search.
     *
     * @return array<int,array{id:int,title:string,status:string,template:string,isFront:bool,postType:string,typeLabel:string}>
     */
    public static function search_rows( string $search, int $limit, string $include_slug ): array {
        $types = array_keys( SiteTemplatePostType::available_post_types() );
        if ( $types === [] ) {
            return [];  // `post_type => []` is not "none" to WP_Query — it falls back to 'post'.
        }

        $limit    = max( 1, min( $limit, 100 ) );
        $front_id = self::front_page_id();
        $base     = [
            'post_type'   => $types,
            'post_status' => self::STATUSES,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ];

        // Current picks ALWAYS appear — their checkbox must stay correct even
        // when they fall outside the search window.
        $included = $include_slug === ''
            ? []
            : get_posts( $base + [
                'posts_per_page' => -1,
                'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the records that picked this template: a lookup by the page-template meta is the point.
                'meta_value'     => $include_slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the records that picked this template: a lookup by the page-template meta is the point.
            ] );

        // Empty search → first $limit pages; otherwise a bounded title search.
        $found = get_posts( $base + [ 'posts_per_page' => $limit, 's' => $search ] );

        // Merge unique by ID (current picks first), then map through the shared row.
        $pages = [];
        foreach ( array_merge( $included, $found ) as $page ) {
            $pages[ $page->ID ] = $page;
        }
        update_meta_cache( 'post', array_keys( $pages ) );

        return self::rows( array_values( $pages ), $front_id );
    }

    /**
     * Picker rows for a page set: one row per translation GROUP (the original's), carrying the
     * group's languages when the site has several. A translation that turned up on its own —
     * found by the search, or holding the slug while its original does not — is dropped: the
     * row is the original's, and its pick is the group's.
     *
     * @param \WP_Post[] $posts
     * @return array<int,array{id:int,title:string,status:string,template:string,isFront:bool,postType:string,typeLabel:string,language?:string,translations?:object}>
     */
    private static function rows( array $posts, int $front_id ): array {
        // is_active(), not is_multilingual(): the latter asks the plugin for its language list,
        // which (see SiteTemplateRegistry::languages_of_all()) swaps the global query to answer. This
        // runs inside REST and MCP listings — and reconcile() below runs from a delete hook.
        $bridge = Translation::bridge();
        if ( ! $bridge->is_active() ) {
            return array_map( static fn( \WP_Post $page ): array => self::row( $page, $front_id ), $posts );
        }

        $languages = self::languages_of( $posts );
        $rows      = [];
        foreach ( $posts as $page ) {
            $info = $languages[ (int) $page->ID ] ?? null;
            if ( $info !== null && ! $info['isOriginal'] ) {
                continue;
            }
            $row = self::row( $page, $front_id );
            // A post the plugin does not track (an untranslatable type) is its own group of
            // one — the keys are there for every row, as the schema says.
            $row['language']     = $info['language'] ?? $bridge->default_language();
            $row['translations'] = (object) ( $info['translations'] ?? [ $row['language'] => (int) $page->ID ] );
            $rows[]              = $row;
        }
        return $rows;
    }

    /**
     * The originals among $posts — on a single-language site, all of them. A post the
     * translation plugin does not track (an untranslatable post type) counts as its own original.
     *
     * @param \WP_Post[] $posts
     * @return \WP_Post[]
     */
    private static function originals( array $posts ): array {
        if ( ! Translation::bridge()->is_active() ) {
            return $posts;
        }
        $languages = self::languages_of( $posts );
        return array_values( array_filter(
            $posts,
            static fn( \WP_Post $page ): bool => $languages[ (int) $page->ID ]['isOriginal'] ?? true
        ) );
    }

    /**
     * Language facts for a page set, batched per post type (the bridge keys its lookup on the
     * type, and the set spans every type present in it).
     *
     * @param \WP_Post[] $posts
     * @return array<int,array{language:string,translations:array<string,int>,isOriginal:bool}>
     */
    private static function languages_of( array $posts ): array {
        $by_type = [];
        foreach ( $posts as $page ) {
            $by_type[ $page->post_type ][] = (int) $page->ID;
        }
        $bridge = Translation::bridge();
        $out    = [];
        foreach ( $by_type as $type => $ids ) {
            $out += $bridge->version_facts( $ids, (string) $type );
        }
        return $out;
    }

    /**
     * $page_ids plus every translation of each — the set a pick is written to. Ids that are
     * not records at all pass through untouched; reconcile() matches them against nothing and
     * they fall away there, as they always did.
     *
     * @param int[] $page_ids
     * @return int[]
     */
    private static function with_translations( array $page_ids ): array {
        $bridge = Translation::bridge();
        if ( $page_ids === [] || ! $bridge->is_active() ) {
            return $page_ids;
        }
        $out    = [];
        foreach ( $page_ids as $id ) {
            $out[] = $id;
            $post  = get_post( $id );
            if ( $post instanceof \WP_Post ) {
                foreach ( $bridge->all_versions( $id, $post->post_type ) as $translation_id ) {
                    $out[] = (int) $translation_id;
                }
            }
        }
        return array_values( array_unique( $out ) );
    }

    /** Page id set as the static front page (0 = latest-posts home). */
    private static function front_page_id(): int {
        return get_option( 'show_on_front' ) === 'page' ? (int) get_option( 'page_on_front' ) : 0;
    }

    /** One picker row for a page (raw slug; '' covers core's ''/'default' pair). */
    private static function row( \WP_Post $page, int $front_id ): array {
        $slug = (string) get_post_meta( $page->ID, '_wp_page_template', true );

        // THE TYPE IS PART OF THE ROW now that a pick can be made on any of them. A list mixing
        // pages, posts, projects and recipes, with a title alone to tell them apart, asks the
        // reader to guess — and two records may share a title honestly.
        $type  = get_post_type_object( $page->post_type );
        $label = $type instanceof \WP_Post_Type ? ( $type->labels->singular_name ?? $type->label ) : $page->post_type;

        return [
            'id'        => (int) $page->ID,
            'title'     => $page->post_title !== '' ? $page->post_title : sprintf( '(no title) #%d', $page->ID ),
            'status'    => (string) $page->post_status,
            'template'  => $slug === 'default' ? '' : $slug,
            'isFront'   => (int) $page->ID === $front_id,
            'postType'  => (string) $page->post_type,
            'typeLabel' => (string) $label,
        ];
    }

    /**
     * Make the set of pages explicitly picking $slug EXACTLY $page_ids:
     * newly selected pages get the slug written (same meta the native picker
     * writes), unselected ones that had it are reset to the default template.
     * Pages on foreign/other templates that are not in $page_ids stay intact.
     *
     * A page id stands for the page in EVERY language: its translations get the slug with it
     * and lose it with it. `assigned` / `cleared` count posts, so on a two-language site one
     * page ticked reads as 2. The set is exact per GROUP too: a translation that picked this
     * template on its own in the editor, while its original did not, is reset by a save that
     * does not tick the original — the picker shows the group by its original, and that is
     * what the save means.
     *
     * @param int[]  $page_ids
     * @param string $mode replace (the set becomes exactly this), add (only these gain it),
     *                     remove (only these lose it).
     * @return array{assigned:int,cleared:int,moved:array<string,list<int>>}
     */
    public static function reconcile( string $slug, array $page_ids, string $mode = self::MODE_REPLACE ): array {
        $named    = array_fill_keys( self::with_translations( array_map( 'intval', $page_ids ) ), true );
        $assigned = 0;
        $cleared  = 0;
        $moved    = array();  // slug this record used to carry => ids taken from it

        // THREE SHAPES, one walk. `replace` makes the set exactly what was named — the panel's
        // save, where the whole list is on screen. `add` and `remove` touch only what was named,
        // for a caller that knows its one page and not the other forty: without them, adding a
        // page means reading every other pick first and sending them back, and a caller that gets
        // that list wrong silently strips the rest. The default stays `replace` so every existing
        // caller keeps its meaning.
        $wanted = $mode === self::MODE_REMOVE ? array() : $named;

        // CLEARING WALKS THE META, NOT THE SITE. Two reasons, and the second is the bug.
        //
        // The cheap one: this used to read every page of every covered type and ask each for its
        // meta, to change the two or three that differ. On a site with thousands of records that is
        // an unbounded query and a meta read per row to do nothing.
        //
        // The one that matters: that sweep was scoped to the COVERED types, so a record whose post
        // type was later dropped from the covered-types list kept our slug with no way to reach it.
        // It was not listed, not offered in the editor, and no save could clear it — and it would
        // come back to life on the next render. Asking the meta finds it.
        foreach ( self::carriers_of( $slug ) as $id ) {
            if ( $mode === self::MODE_ADD ) {
                continue;  // `add` never takes anything off.
            }
            if ( $mode === self::MODE_REMOVE ? ! isset( $named[ $id ] ) : isset( $wanted[ $id ] ) ) {
                continue;  // Not named for removal, or wanted and already carrying it.
            }
            update_post_meta( $id, '_wp_page_template', '' );
            ++$cleared;
        }

        // WRITING IS LIMITED ONLY BY WHAT A TEMPLATE CAN DRESS. A pick is one record's own
        // choice, it outranks the type's default wherever it is set, and the editor's Template
        // dropdown offers it on every type — so narrowing it here would have made this tool
        // weaker than the screen it mirrors. What is still required is that
        // the record exists and is a real one: a revision or an auto-draft is not a page anybody
        // picked a frame for.
        $types = array_keys( SiteTemplatePostType::available_post_types() );
        foreach ( array_keys( $wanted ) as $id ) {
            $post = get_post( $id );
            if ( ! $post instanceof \WP_Post
                || ! in_array( $post->post_type, $types, true )
                || ! in_array( $post->post_status, array_merge( self::STATUSES, [ 'trash' ] ), true ) ) {
                continue;
            }
            $current = (string) get_post_meta( $id, '_wp_page_template', true );
            if ( $current === $slug ) {
                continue;  // Already picking it.
            }

            // A RECORD WEARS ONE TEMPLATE. Giving it this one takes it off whatever it had, which
            // is what the caller asked for — but `cleared` counts releases from THIS template and
            // would report 0, so a caller adding a page it had not read first could re-frame a live
            // page and see nothing about it. Named here, reported by the ability.
            // "default" IS WordPress's word for NO template — its own Template dropdown writes
            // that literal string, and row() already normalises it to ''. Counting it as a
            // template the record "left" invented a loss: the answer named the id in tookFrom and
            // told the caller to put back something that never existed.
            if ( $current !== '' && $current !== 'default' ) {
                $moved[ $current ][] = $id;
            }

            update_post_meta( $id, '_wp_page_template', $slug );
            ++$assigned;
        }

        return [ 'assigned' => $assigned, 'cleared' => $cleared, 'moved' => $moved ];
    }

    /**
     * Every record whose `_wp_page_template` IS this slug — whatever its post type.
     *
     * Read straight from the meta table rather than through WP_Query, because the point is to
     * reach rows a post-type-scoped query cannot see. Statuses are still filtered: a revision or an
     * auto-draft carrying the meta is not a page anybody picked.
     *
     * @return list<int>
     */
    private static function carriers_of( string $slug ): array {
        global $wpdb;

        // TRASHED RECORDS COUNT HERE, unlike everywhere else. Writing skips them — a template does
        // not dress the bin — but a pick made before a record was binned is still stored on it, and
        // if clearing cannot see it the row keeps a slug nothing can remove: restore the record
        // later and it carries a template that may no longer exist, which renders as bare theme
        // with no screen able to explain why. Statuses that are not records at all (revisions,
        // auto-drafts) stay out.
        $statuses = array_merge( self::STATUSES, array( 'trash' ) );
        $holes    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every carrier of every post type, trash included, read live because the caller rewrites them next; $holes is a run of %s built with array_fill() from count( $statuses ), matched by $statuses after $slug.
        return array_map(
            'intval',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                     WHERE m.meta_key = '_wp_page_template' AND m.meta_value = %s
                       AND p.post_status IN ( {$holes} )",
                    array_merge( array( $slug ), $statuses )
                )
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** Reset every page explicitly picking $slug (used when its template is deleted). */
    public static function clear_slug( string $slug ): int {
        return self::reconcile( $slug, [] )['cleared'];
    }
}
