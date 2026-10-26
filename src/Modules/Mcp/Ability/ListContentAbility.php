<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Translation\Translation;
use WP_Error;
use WP_Query;

/**
 * What the site actually holds — pages, posts and custom types, with their ids.
 *
 * Everything that edits content takes an id (save-content, set-post-terms, delete-content) and
 * nothing produced one: an agent could only work with what it had created in the same session,
 * and had to guess or ask for anything older.
 */
final class ListContentAbility extends AbstractAbility {

    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT     = 100;

    public function name(): string {
        return 'fabrement/list-content';
    }

    public function label(): string {
        return 'List content';
    }

    public function description(): string {
        return 'List content — pages, posts or any type — to find ids and see what exists: id, title, postType, '
            . 'status, url, editUrl, terms, parent, menuOrder. `parent` lists one level of a hierarchical type (0 = top level). `title` answers "does a page called X exist", `slug` is the exact '
            . 'lookup, `search` scans content too. Search BEFORE creating: a second "About" beside the existing'
            . ' one is the usual failure.'
            . self::multilingual_text( ' Every language is listed; each item carries language, originalId, translations and '
            . 'missingLanguages — two rows sharing a translations map are ONE page. A missing language is made '
            . 'with translate-content, never as a new page.' );
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'id'       => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ONE record by id — the other filters are ignored, and `postType` defaults to "any". The lookup for a known page.' ],
                'postType' => [ 'type' => 'string', 'description' => 'Public post type, or "any". Default "page".' ],
                'search'   => [ 'type' => 'string', 'description' => 'Match against title AND content — broad; use `title` when you mean the title.' ],
                'title'    => [ 'type' => 'string', 'description' => 'Match the TITLE only (substring, case-insensitive).' ],
                'slug'     => [ 'type' => 'string', 'description' => 'Exact URL slug (post_name) — the precise "does this exist" lookup.' ],
                'status'   => [ 'type' => 'string', 'description' => '"any" (default), publish, draft, pending, private, trash. "any" never includes the trash — records deleted without force are found only with "trash".' ],
                'limit'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT ],
                'parent'   => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Only the direct children of this record; 0 = only top-level records. Omit for every level.' ],
                'orderby'  => [
                    'type'        => 'string',
                    'enum'        => [ 'modified', 'menuOrder', 'title' ],
                    'description' => '"modified" (default, newest first) | "menuOrder" (the site\'s own order of siblings, then title) | "title" (A→Z).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'items', 'total' ],
            'properties' => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'       => [ 'type' => 'integer' ],
                            'title'    => [ 'type' => 'string' ],
                            'postType' => [ 'type' => 'string' ],
                            'status'   => [ 'type' => 'string' ],
                            'url'      => [ 'type' => 'string' ],
                            'editUrl'  => [ 'type' => 'string' ],
                            'modified' => [ 'type' => 'string', 'description' => 'Last change, UTC, "Y-m-d H:i:s".' ],
                            'terms'    => [ 'type' => 'object', 'description' => 'taxonomy slug → [term names].' ],
                            'parent'    => [ 'type' => 'integer', 'description' => 'Parent record id; 0 = top level (always 0 on a type that is not hierarchical).' ],
                            'menuOrder' => [ 'type' => 'integer', 'description' => 'Place among its siblings (save-content `menuOrder`).' ],
                        ] + self::multilingual( [
                            // Only on a multilingual site — absent from the schema, as from the
                            // rows, on the rest: a single-language site has nothing to read here.
                            'language'     => [ 'type' => 'string', 'description' => 'Language code of THIS item.' ],
                            'translations' => [ 'type' => 'object', 'description' => 'The same content in other languages: code → id (this item included). Two rows sharing a map are one page in two languages, not a duplicate.' ],
                            'originalId'   => [ 'type' => 'integer', 'description' => 'Id of the ORIGINAL in this group (equals `id` when this row is it). The id translate-content takes.' ],
                            'missingLanguages' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Site languages this content has NO version in yet. [] = exists in every language. Create those with translate-content {contentId: originalId, language}.' ],
                        ] ),
                    ],
                ],
                'total' => [ 'type' => 'integer', 'description' => 'Matches before the limit.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $type = isset( $input['postType'] ) ? sanitize_key( (string) $input['postType'] ) : 'page';
        if ( $type === '' ) {
            $type = 'page';
        }
        // A known id: one row, whatever its type — the caller has the id and wants the record's
        // facts (its language, its group), not a search that happens to include it.
        $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
        if ( $id > 0 ) {
            $type  = 'any';
            $input = [ 'id' => $id ];
        }
        if ( $type !== 'any' && ( ! post_type_exists( $type ) || ! is_post_type_viewable( $type ) ) ) {
            return new WP_Error( 'unknown_post_type', sprintf( 'Post type "%s" is not a public type here — see list-post-types.', $type ) );
        }

        $limit = isset( $input['limit'] ) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
        $limit = max( 1, min( self::MAX_LIMIT, $limit ) );

        $args = [
            // "any" excludes auto-drafts and revisions by itself, which is what a person means
            // by "everything on the site".
            // `attachment` is public but it is MEDIA — list-media owns it, and an attachment id
            // arriving here would pass delete-content's viewable check as though it were a page.
            'post_type'      => $type === 'any'
                ? array_values( array_diff( array_values( get_post_types( [ 'public' => true ] ) ), [ 'attachment' ] ) )
                : $type,
            'post_status'    => isset( $input['status'] ) && (string) $input['status'] !== '' ? (string) $input['status'] : 'any',
            's'              => (string) ( $input['search'] ?? '' ),
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            // The agent needs titles and ids, never the rendered content — skipping the term
            // and meta caches here would only move the cost into the per-item term read below.
            'no_found_rows'  => false,
        ];

        // Exact slug: the precise answer to "does this page exist", and the one a caller wants
        // before creating a second page that would silently take an "-2" URL.
        $slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
        if ( $slug !== '' ) {
            $args['name'] = $slug;
        }
        if ( $id > 0 ) {
            $args['p'] = $id;
        }
        // 0 is a filter too ("top level only"), so the key decides, not the value.
        if ( isset( $input['parent'] ) ) {
            $args['post_parent'] = max( 0, (int) $input['parent'] );
        }
        $orderby = (string) ( $input['orderby'] ?? 'modified' );
        if ( $orderby === 'menuOrder' ) {
            // Title breaks the ties, as WordPress's own page lists do — most records share 0.
            $args['orderby'] = [ 'menu_order' => 'ASC', 'title' => 'ASC' ];
            unset( $args['order'] );
        } elseif ( $orderby === 'title' ) {
            $args['orderby'] = 'title';
            $args['order']   = 'ASC';
        }

        // TITLE-only search. WP_Query's `s` covers content and excerpt too, so asking it for
        // "About" returns every page that merely mentions the word — which reads as "the page
        // already exists" when it does not. `search_columns` narrows the SAME search to the
        // title, so `total` and the limit describe the set the caller asked about.
        //
        // Native rather than a `posts_where` of our own, because the query below is widened to
        // every language and that suppresses the clause filters — including one we added
        // ourselves, which would have quietly turned a title lookup back into "everything".
        $title = isset( $input['title'] ) ? trim( (string) $input['title'] ) : '';
        if ( $title !== '' ) {
            // Both write to the same `s`, and only one set of columns can apply to it. Said out
            // loud rather than letting one quietly win: a caller who passed both would otherwise
            // get an answer to a question they did not ask, and no sign of it.
            if ( (string) ( $input['search'] ?? '' ) !== '' ) {
                return new WP_Error(
                    'conflicting_search',
                    'Pass either `search` (title and content) or `title` (title only) — not both. `title` is the one for "does a page called X exist".'
                );
            }

            $args['s']        = $title;
            // Whole phrase, not a bag of words: `s` alone would match a title holding every word
            // somewhere, so "About Us" would find "Us and About", and a caller checking whether a
            // page exists reads that as yes.
            $args['sentence'] = true;

            // Title only. `search_columns` is understood from WP 6.2, which the plugin's
            // "Requires at least" is above — so this narrowing always takes effect rather than
            // being silently dropped into a title-OR-excerpt-OR-content search.
            $args['search_columns'] = [ 'post_title' ];
        }

        // Every language, not just the one being viewed. A page that exists only in Polish is
        // otherwise not missing from the answer but invisible in it — and the usual answer to
        // "does a page called X exist" coming back "no" is a second page called X. Untouched
        // where the site has no translation plugin.
        $bridge = Translation::bridge();
        $args   = $bridge->all_languages_args( $args );

        // A title is a literal, not a search expression. WP_Query reads a leading "-" on a search
        // term as "exclude this", even for a whole-phrase `s`, so title:"- Notes" would match
        // every post whose title is NOT "- Notes" and answer "yes, it exists" with a stranger.
        // The prefix is switched off for THIS query only; `search` keeps WP's own grammar.
        $literal = static fn(): string => '';
        if ( $title !== '' ) {
            add_filter( 'wp_query_search_exclusion_prefix', $literal );
        }
        $query = new WP_Query( $args );
        remove_filter( 'wp_query_search_exclusion_prefix', $literal );

        // One lookup per post type for the whole page of results, rather than two per row.
        // Grouped because "any" returns a mix, and a record's language is keyed by its type.
        $by_type = [];
        foreach ( $query->posts as $post ) {
            $by_type[ (string) $post->post_type ][] = (int) $post->ID;
        }
        $languages = [];
        foreach ( $by_type as $post_type => $ids ) {
            $languages += $bridge->version_facts( $ids, $post_type );
        }

        // Every row's address in one pass, each from its OWN language — grouped, so the
        // language is switched once per language rather than once per row.
        $permalinks = $bridge->permalinks_of_many(
            array_reduce(
                $query->posts,
                static function ( array $carry, $post ) use ( $languages ): array {
                    $carry[ (int) $post->ID ] = $languages[ (int) $post->ID ]['language'] ?? '';
                    return $carry;
                },
                []
            )
        );

        $items = [];
        foreach ( $query->posts as $post ) {
            $terms = [];
            // Public taxonomies only. Plugins register private ones to keep their own state on a
            // post — WPML's `translation_priority` is one — and those are not classifications of
            // the content: an agent shown them reads them as something a page can be tagged with.
            foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy_object ) {
                if ( ! $taxonomy_object->public ) {
                    continue;
                }
                $taxonomy = $taxonomy_object->name;
                $names = wp_get_object_terms( $post->ID, $taxonomy, [ 'fields' => 'names' ] );
                if ( is_array( $names ) && $names !== [] ) {
                    $terms[ $taxonomy ] = array_values( $names );
                }
            }

            $language = $languages[ (int) $post->ID ]['language'] ?? '';

            $row = [
                'id'       => (int) $post->ID,
                'title'    => (string) $post->post_title,
                'postType' => (string) $post->post_type,
                'status'   => (string) $post->post_status,
                // Addressed FROM its own language. This listing deliberately spans every
                // language, and get_permalink() answers in the language being rendered — so a
                // Polish row read during an English request would carry the English address
                // while the `language` key beside it says "pl".
                'url'      => $permalinks[ (int) $post->ID ] ?? '',
                'editUrl'  => (string) get_edit_post_link( $post->ID, 'raw' ),
                'modified' => self::modified_gmt( $post ),
                'terms'    => (object) $terms,
                'parent'    => (int) $post->post_parent,
                'menuOrder' => (int) $post->menu_order,
            ];

            // Only on a multilingual site: `language` says which one this row is in, and
            // `translations` shows the same content's other versions — so two rows with the
            // same title read as one page in two languages rather than as a duplicate.
            if ( isset( $languages[ (int) $post->ID ] ) ) {
                $facts               = $languages[ (int) $post->ID ];
                $row['language']     = $language;
                $row['translations'] = (object) $facts['translations'];
                // Which id is the source of truth in this group, and what it still lacks — the two
                // facts a "create this page in Polish" task needs, spelled out rather than left
                // to be inferred from a map of ids that says nothing about which one came first.
                $row['originalId']       = $facts['isOriginal'] ? (int) $post->ID : self::original_id( $facts, $post->post_type );
                $row['missingLanguages'] = Translation::missing_languages( $facts['translations'] );
            }

            $items[] = $row;
        }

        return [ 'items' => $items, 'total' => (int) $query->found_posts ];
    }

    /**
     * When the record last changed, in UTC. WordPress leaves post_modified_gmt at
     * "0000-00-00 00:00:00" on a draft that was never saved twice (it copies the draft's empty
     * post_date_gmt), while post_modified — site time — is always set; so the UTC one is worked
     * out from it there.
     */
    private static function modified_gmt( \WP_Post $post ): string {
        $gmt = (string) $post->post_modified_gmt;
        if ( $gmt !== '' && strpos( $gmt, '0000-00-00' ) !== 0 ) {
            return $gmt;
        }

        return (string) get_gmt_from_date( (string) $post->post_modified );
    }

    /**
     * The original's id for a translation's group. The batch lookup says only whether EACH row
     * is the original; for a translation row, its group's original is found by asking the same
     * of its siblings — cheap, as translations are few and the answer is usually in the group's
     * first id.
     *
     * @param array{translations:array<string,int>} $facts
     */
    private static function original_id( array $facts, string $post_type ): int {
        $bridge = Translation::bridge();
        foreach ( $facts['translations'] as $id ) {
            if ( $bridge->is_original( (int) $id, $post_type ) ) {
                return (int) $id;
            }
        }
        return 0;
    }
}
