<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Framework\Keys;

/**
 * GLOBAL BLOCKS — WordPress synced patterns (`wp_block` posts).
 *
 * A page places one as `<!-- wp:block {"ref":ID} /-->`: the pattern's content renders there, and
 * editing the pattern changes every page that places it. WordPress owns the post type, the editor
 * UI ("Edit original" / "Detach") and rendering; this class only answers the questions the tools
 * ask about them.
 */
final class SyncedPatterns {

    public const POST_TYPE = 'wp_block';

    /** Core blocks are everybody's; any other belongs to a theme or a plugin. */
    private const NEUTRAL_PREFIX = 'core/';

    /**
     * Every block name in a body, nesting included.
     *
     * @return list<string>
     */
    public static function block_names( string $content ): array {
        $names = [];
        $walk  = static function ( array $blocks ) use ( &$walk, &$names ): void {
            foreach ( $blocks as $block ) {
                $name = (string) ( $block['blockName'] ?? '' );
                if ( $name !== '' ) {
                    $names[] = $name;
                }
                if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                    $walk( $block['innerBlocks'] );
                }
            }
        };
        $walk( parse_blocks( $content ) );

        return array_values( array_unique( $names ) );
    }

    /**
     * What a pattern holds, as the tools report it.
     *
     * `foreign` — a block that is not core (a theme's or a plugin's). Such a pattern belongs to
     * something else as well, and changing it is a call the caller has to make on purpose.
     *
     * @return array{id:int,title:string,status:string,blocks:list<string>,foreign:list<string>}
     */
    public static function describe( \WP_Post $post ): array {
        $names   = self::block_names( (string) $post->post_content );
        $foreign = array_values(
            array_filter(
                $names,
                static fn( string $n ): bool => strncmp( $n, self::NEUTRAL_PREFIX, strlen( self::NEUTRAL_PREFIX ) ) !== 0
            )
        );

        return [
            'id'      => (int) $post->ID,
            'title'   => (string) $post->post_title,
            'status'  => (string) $post->post_status,
            'kind'    => self::kind( (int) $post->ID ),
            'categories' => self::categories( (int) $post->ID ),
            'blocks'  => $names,
            'foreign' => $foreign,
        ];
    }

    /**
     * Synced patterns, newest first.
     *
     * @return list<\WP_Post>
     */
    public static function find( string $search, int $limit ): array {
        global $wpdb;

        $where  = [ 'post_type = %s', "post_status IN ( 'publish', 'draft', 'private' )" ];
        $params = [ self::POST_TYPE ];

        if ( $search !== '' ) {
            $where[]  = 'post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }
        // Unsynced patterns are copy-on-insert templates, not global blocks.
        $where[]  = "ID NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'wp_pattern_sync_status' AND meta_value = 'unsynced' )";
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a LIKE over post content plus a sync-status subquery, which WP_Query cannot express; $where holds fixed clauses with placeholders only; every value is in $params.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where holds fixed clauses with %s placeholders only; every value is in $params.
                "SELECT ID FROM {$wpdb->posts} WHERE " . implode( ' AND ', $where ) . ' ORDER BY post_modified_gmt DESC LIMIT %d',
                $params
            )
        );

        return array_values( array_filter( array_map( 'get_post', array_map( 'intval', (array) $ids ) ) ) );
    }

    /**
     * The global blocks a content places, in order: read from its `<!-- wp:block {…} -->`
     * delimiters alone — no block is parsed — so however a delimiter is written (other attributes
     * before the ref, a quoted id) and at any depth. Another block's `ref` (core/navigation points
     * at a wp_navigation post) is not one.
     *
     * @return list<int>
     */
    public static function refs_in( string $content ): array {
        if ( strpos( $content, '"ref"' ) === false || preg_match_all( '#<!--\s+wp:(?:core/)?block\s+(\{.*?\})\s+/?-->#s', $content, $found ) < 1 ) {
            return [];
        }
        $refs = [];
        foreach ( $found[1] as $json ) {
            $attrs = json_decode( $json, true );
            $ref   = is_array( $attrs ) && is_scalar( $attrs['ref'] ?? null ) ? (int) $attrs['ref'] : 0;
            if ( $ref > 0 ) {
                $refs[ $ref ] = $ref;
            }
        }
        return array_values( $refs );
    }

    /** How many records one search for placements reads at most; past it a count is a floor. */
    public const PLACEMENTS_READ = 2000;

    /**
     * Where each of these patterns is placed — records (pages, posts, any type with blocks,
     * templates, other patterns) whose content holds a core/block with that `ref` — in ONE query,
     * however many patterns are asked about.
     *
     * The database narrows to the records naming the block and one of the ids; which pattern each
     * really places is read from its delimiters ({@see refs_in()}).
     *
     * @param list<int> $pattern_ids
     * @return array<int,list<array{id:int,title:string,postType:string,status:string}>> Keyed by pattern id.
     */
    public static function placements_of( array $pattern_ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $pattern_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        $out = array_fill_keys( $ids, [] );
        if ( $ids === [] ) {
            return $out;
        }
        $types = self::block_content_types();
        $holes = implode( ',', array_fill( 0, count( $types ), '%s' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a search of post content, which WP_Query cannot express; read live, since callers act on it before changing or deleting a pattern; $holes is a run of %s built with array_fill() from count( $types ), matched by $types at the head of the merged replacements; the pattern ids reach the REGEXP as a %s built from integers only.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_status, post_title, post_content FROM {$wpdb->posts}
                 WHERE post_type IN ( {$holes} )
                   AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
                   AND ( post_content LIKE %s OR post_content LIKE %s )
                   AND post_content REGEXP %s
                 LIMIT %d",
                array_merge(
                    $types,
                    [
                        '%' . $wpdb->esc_like( '<!-- wp:block ' ) . '%',
                        '%' . $wpdb->esc_like( '<!-- wp:core/block ' ) . '%',
                        '"ref":"?(' . implode( '|', $ids ) . ')[",}]',
                        self::PLACEMENTS_READ,
                    ]
                )
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        foreach ( (array) $rows as $row ) {
            foreach ( self::refs_in( (string) $row->post_content ) as $ref ) {
                if ( isset( $out[ $ref ] ) ) {
                    $out[ $ref ][] = [
                        'id'       => (int) $row->ID,
                        'title'    => (string) $row->post_title,
                        'postType' => (string) $row->post_type,
                        'status'   => (string) $row->post_status,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Where one pattern is placed ({@see placements_of()}).
     *
     * @return list<array{id:int,title:string,postType:string,status:string}>
     */
    public static function placements( int $pattern_id, int $limit = 50 ): array {
        return array_slice( self::placements_of( [ $pattern_id ] )[ $pattern_id ] ?? [], 0, max( 1, $limit ) );
    }

    /**
     * The post types whose content is blocks — the only ones a global block can be placed in: every
     * type with the editor, the patterns themselves and the templates. The search for placements
     * reads these alone, by the index on the type: orders, menu items, attachments and the like
     * are never opened.
     *
     * @return list<string>
     */
    public static function block_content_types(): array {
        $types = array_values( array_filter( get_post_types(), static fn( string $type ): bool => post_type_supports( $type, 'editor' ) ) );
        return array_values( array_unique( array_merge( $types, [ self::POST_TYPE, \Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType::POST_TYPE ] ) ) );
    }

    public const CATEGORY_TAXONOMY = 'wp_pattern_category';

    /**
     * The category of each kind, named as WordPress names its own ("Headers", slug `header`) — the
     * one the editor suggests when a person files a pattern, so theirs and ours are one term.
     */
    public const KINDS = [ 'header' => 'Headers', 'footer' => 'Footers' ];

    /**
     * The other pattern categories WordPress itself offers (wp-includes/block-patterns.php), slug =>
     * the name its editor shows. They change nothing in how a global block behaves: they are how
     * it is found again — in the editor's pattern list and by list-synced-patterns {category}.
     */
    public const CATEGORIES = [
        'banner'         => 'Banners',
        'call-to-action' => 'Call to action',
        'text'           => 'Text',
        'columns'        => 'Columns',
        'buttons'        => 'Buttons',
        'navigation'     => 'Navigation',
        'posts'          => 'Posts',
        'featured'       => 'Featured',
        'team'           => 'Team',
        'testimonials'   => 'Testimonials',
        'services'       => 'Services',
        'contact'        => 'Contact',
        'about'          => 'About',
        'portfolio'      => 'Portfolio',
        'gallery'        => 'Gallery',
        'media'          => 'Media',
        'videos'         => 'Videos',
        'audio'          => 'Audio',
    ];

    /** The names this plugin gave them before 1.5; still read, renamed when met. */
    private const OLD_NAMES = [ 'header' => 'Header', 'footer' => 'Footer' ];

    /**
     * The kind a category term stands for, or '': by its slug, or by its name — "Headers" or
     * "Header" under any slug (a second term WordPress made beside ours is `header-2`).
     */
    private static function kind_of_term( \WP_Term $term ): string {
        foreach ( self::KINDS as $kind => $label ) {
            if ( $term->slug === $kind || strcasecmp( $term->name, $label ) === 0 || strcasecmp( $term->name, self::OLD_NAMES[ $kind ] ) === 0 ) {
                return $kind;
            }
        }
        return '';
    }

    /**
     * "header", "footer" or "section" — what a global block IS, by its WordPress pattern category.
     *
     * The category is the one marker, set by whoever makes the pattern (save-synced-pattern
     * {kind}, or an admin in Patterns), whatever blocks the pattern holds.
     */
    public static function kind( int $pattern_id ): string {
        if ( $pattern_id < 1 || ! taxonomy_exists( self::CATEGORY_TAXONOMY ) ) {
            return 'section';
        }
        $terms = get_the_terms( $pattern_id, self::CATEGORY_TAXONOMY );
        foreach ( is_array( $terms ) ? $terms : [] as $term ) {
            $kind = self::kind_of_term( $term );
            if ( $kind !== '' ) {
                return $kind;
            }
        }
        return 'section';
    }

    /**
     * Mark a pattern as a header, a footer, or neither ("section"): WordPress's pattern category of
     * that name, which the admin's Patterns screen filters by too. Other categories it has stay.
     */
    public static function set_kind( int $pattern_id, string $kind ): void {
        $taxonomy = self::CATEGORY_TAXONOMY;
        if ( ! get_post( $pattern_id ) instanceof \WP_Post || ! taxonomy_exists( $taxonomy ) ) {
            return;
        }
        $current = get_the_terms( $pattern_id, $taxonomy );
        $current = is_array( $current ) ? $current : [];
        $keep    = [];
        foreach ( $current as $term ) {
            if ( self::kind_of_term( $term ) === '' ) {
                $keep[] = (int) $term->term_id;
            }
        }

        if ( isset( self::KINDS[ $kind ] ) ) {
            // The term WordPress's editor would use (slug `header`), else one of that name under
            // another slug — never a second one.
            $term = get_term_by( 'slug', $kind, $taxonomy ) ?: get_term_by( 'name', self::KINDS[ $kind ], $taxonomy ) ?: get_term_by( 'name', self::OLD_NAMES[ $kind ], $taxonomy );
            if ( ! $term instanceof \WP_Term ) {
                $made = wp_insert_term( self::KINDS[ $kind ], $taxonomy, [ 'slug' => $kind ] );
                $term = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], $taxonomy );
            } elseif ( $term->name === self::OLD_NAMES[ $kind ] ) {
                // The name this plugin gave it once: WordPress's own now, so the editor's
                // suggestion is this very term. Its slug, and what is filed under it, stay.
                wp_update_term( (int) $term->term_id, $taxonomy, [ 'name' => self::KINDS[ $kind ] ] );
            }
            if ( $term instanceof \WP_Term ) {
                $keep[] = (int) $term->term_id;
            }
        }
        wp_set_object_terms( $pattern_id, array_values( array_unique( $keep ) ), $taxonomy );
    }

    /**
     * The categories a global block is filed under besides Headers / Footers, as slugs — WordPress's
     * own and any a person made in the editor.
     *
     * @return list<string>
     */
    public static function categories( int $pattern_id ): array {
        $terms = $pattern_id > 0 && taxonomy_exists( self::CATEGORY_TAXONOMY ) ? get_the_terms( $pattern_id, self::CATEGORY_TAXONOMY ) : [];
        $out   = [];
        foreach ( is_array( $terms ) ? $terms : [] as $term ) {
            if ( self::kind_of_term( $term ) === '' ) {
                $out[] = (string) $term->slug;
            }
        }
        sort( $out );
        return $out;
    }

    /** Whether a slug names a category a global block can be filed under: WordPress's, or one a person made. */
    public static function is_category( string $slug ): bool {
        if ( isset( self::CATEGORIES[ $slug ] ) ) {
            return true;
        }
        $term = taxonomy_exists( self::CATEGORY_TAXONOMY ) ? get_term_by( 'slug', $slug, self::CATEGORY_TAXONOMY ) : false;
        return $term instanceof \WP_Term && self::kind_of_term( $term ) === '';
    }

    /**
     * Every category a global block can be filed under on this site, as slugs: WordPress's own,
     * then the ones a person made.
     *
     * @return list<string>
     */
    public static function category_slugs(): array {
        $own = [];
        if ( taxonomy_exists( self::CATEGORY_TAXONOMY ) ) {
            $terms = get_terms( [ 'taxonomy' => self::CATEGORY_TAXONOMY, 'hide_empty' => false ] );
            foreach ( is_array( $terms ) ? $terms : [] as $term ) {
                if ( self::kind_of_term( $term ) === '' && ! isset( self::CATEGORIES[ $term->slug ] ) ) {
                    $own[] = (string) $term->slug;
                }
            }
        }
        sort( $own );
        return array_merge( array_keys( self::CATEGORIES ), $own );
    }

    /**
     * File a global block under exactly these categories — {@see CATEGORIES} or ones a person made —
     * and no other; only its Headers / Footers category ({@see set_kind()}) stays as it is.
     *
     * @param list<string> $slugs Each one {@see is_category()}.
     */
    public static function set_categories( int $pattern_id, array $slugs ): void {
        $taxonomy = self::CATEGORY_TAXONOMY;
        if ( ! get_post( $pattern_id ) instanceof \WP_Post || ! taxonomy_exists( $taxonomy ) ) {
            return;
        }
        $current = get_the_terms( $pattern_id, $taxonomy );
        $keep    = [];
        foreach ( is_array( $current ) ? $current : [] as $term ) {
            if ( self::kind_of_term( $term ) !== '' ) {
                $keep[] = (int) $term->term_id;
            }
        }
        foreach ( $slugs as $slug ) {
            // The term the editor would use: by its slug, else one a person made under that name.
            $term = get_term_by( 'slug', $slug, $taxonomy ) ?: ( isset( self::CATEGORIES[ $slug ] ) ? get_term_by( 'name', self::CATEGORIES[ $slug ], $taxonomy ) : false );
            if ( ! $term instanceof \WP_Term && isset( self::CATEGORIES[ $slug ] ) ) {
                $made = wp_insert_term( self::CATEGORIES[ $slug ], $taxonomy, [ 'slug' => $slug ] );
                $term = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], $taxonomy );
            }
            if ( $term instanceof \WP_Term ) {
                $keep[] = (int) $term->term_id;
            }
        }
        wp_set_object_terms( $pattern_id, array_values( array_unique( $keep ) ), $taxonomy );
    }

    /** A pattern a page may place: an existing, non-trashed, synced `wp_block`. */
    public static function placeable( int $id ): ?\WP_Post {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post instanceof \WP_Post || $post->post_type !== self::POST_TYPE || $post->post_status === 'trash' ) {
            return null;
        }
        if ( get_post_meta( $id, 'wp_pattern_sync_status', true ) === 'unsynced' ) {
            return null;
        }
        return $post;
    }
}
