<?php

namespace Fabrement\Modules\Taxonomy;

use WP_Error;

/**
 * The TERMS inside any registered taxonomy — the site's own or another plugin's. Terms are
 * ordinary content: WordPress owns them, they live in `wp_terms`, and adding "Fiction" to
 * `category` is no different from adding it to any other taxonomy.
 */
final class TermWriter {

    /** Width of `wp_terms.name`: varchar(200), counted in characters. */
    public const MAX_TERM_NAME = 200;

    /**
     * Why the term tools may not work in this taxonomy, or null when they may.
     *
     * Only taxonomies with an admin screen (show_ui): the same terms a person can edit in
     * wp-admin. The rest is WordPress's own plumbing — a menu is a `nav_menu` term, block
     * templates hang on `wp_theme` and `wp_template_part_area` — where a term edit deletes a menu
     * past delete-menu or detaches a theme's templates.
     */
    public static function refuse_term_taxonomy( string $taxonomy ): ?WP_Error {
        $object = get_taxonomy( $taxonomy );
        if ( ! $object instanceof \WP_Taxonomy ) {
            return new WP_Error( 'unknown_taxonomy', sprintf( 'Taxonomy "%s" is not registered — see list-taxonomies.', $taxonomy ) );
        }
        if ( ! $object->show_ui ) {
            return new WP_Error(
                'taxonomy_not_editable',
                sprintf( 'Taxonomy "%s" has no admin screen: its terms are WordPress or plugin internals, not a classification.', $taxonomy )
                    . ( $taxonomy === 'nav_menu' ? ' Menus: list-menus, update-menu, delete-menu.' : '' )
            );
        }

        return null;
    }

    /**
     * The term NAME, measured before the write reaches the column it has to fit.
     *
     * `wp_terms.name` is varchar(200). Sent something longer, the database refuses the row and
     * WordPress reports its own `db_insert_error` — "Could not insert term into the database" —
     * which reads like the database is broken and sends the caller off to check the connection,
     * the permissions, the disk. Nothing is broken: 201 characters will never fit, and that is
     * worth saying in those words.
     *
     * COUNTED IN CHARACTERS. The column is utf8mb4, so its 200 is 200 characters and a Cyrillic
     * name measuring 400 in bytes fits perfectly well.
     *
     * THE SLUG IS NOT CHECKED HERE, deliberately. `wp_terms.slug` has the same width, but every
     * slug on the way in has been through sanitize_title(), which truncates to 200 itself
     * (utf8_uri_encode( $title, 200 )) — measured: 201 "b" comes back as 200 characters, and 200
     * Cyrillic letters come back as 198, cut on a character boundary rather than mid-sequence. A
     * check here could never fire.
     *
     * @param string $name The term name as it will be stored ('' to skip).
     * @return WP_Error|null
     */
    public static function refuse_long_term_name( string $name ) {
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
        if ( $length <= self::MAX_TERM_NAME ) {
            return null;
        }

        return new WP_Error(
            'fabrement_term_name_too_long',
            sprintf(
                /* translators: 1: the limit, 2: the length sent. */
                __( 'A term name is at most %1$d characters — the width of the column it is stored in; this one is %2$d. Shorten the name (the description field is the place for the long version).', 'fabrement-lite' ),
                self::MAX_TERM_NAME,
                $length
            ),
            array( 'status' => 400 )
        );
    }

    /**
     * Add a term, or return the one that already carries this name.
     *
     * IDEMPOTENT on purpose: an agent building a catalogue re-runs its own steps, and a second
     * "add Fiction" must not fail the run — the goal state ("a Fiction term exists") is already
     * reached. Works in ANY registered taxonomy.
     *
     * @param array<string,mixed> $input { name, slug?, parent?, description? }
     * @return array<string,mixed>|WP_Error
     */
    public static function create_term( string $taxonomy, array $input ) {
        $taxonomy = sanitize_key( $taxonomy );
        $refused  = self::refuse_term_taxonomy( $taxonomy );
        if ( $refused !== null ) {
            return $refused;
        }

        $name = self::clean( (string) ( $input['name'] ?? '' ) );
        if ( $name === '' ) {
            return new WP_Error( 'missing_name', 'A term needs a name (e.g. "Fiction").' );
        }

        $args = array();
        $slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
        if ( $slug !== '' ) {
            $args['slug'] = $slug;
        }

        $long = self::refuse_long_term_name( $name );
        if ( $long instanceof WP_Error ) {
            return $long;
        }
        if ( array_key_exists( 'description', $input ) ) {
            $args['description'] = wp_kses_post( (string) $input['description'] );
        }

        $parent = self::resolve_parent( $taxonomy, $input['parent'] ?? null );
        if ( is_wp_error( $parent ) ) {
            return $parent;
        }
        if ( $parent > 0 ) {
            $args['parent'] = $parent;
        }

        $created = wp_insert_term( $name, $taxonomy, $args );
        if ( is_wp_error( $created ) ) {
            // term_exists carries the id of the term already there — the idempotent answer.
            $existing = $created->get_error_data( 'term_exists' );
            if ( is_numeric( $existing ) ) {
                // …unless it is a term in ANOTHER language. WordPress keeps term slugs unique across
                // the whole taxonomy and the translation plugin answers term_exists() across every
                // language at once, so "already there" can mean a term this call would never have
                // made: asking for a Polish "Fikcja" hands back the English "Fiction" and reports it
                // as an idempotent success. A translation is made by translate-term, which joins it
                // to its original; there is nothing idempotent about being given a different term.
                $clash = self::term_language_other_than_request( (int) $existing, $taxonomy );
                if ( $clash !== '' ) {
                    return new WP_Error(
                        'term_exists_in_another_language',
                        sprintf(
                            'A term with this name or slug already exists in "%s" (id %d, language "%s") — term slugs are unique across every language at once. To give THAT term a version in another language use translate-term; to make a separate term here, pick another name and slug.',
                            $taxonomy,
                            (int) $existing,
                            $clash
                        )
                    );
                }

                return self::term_summary( (int) $existing, $taxonomy, false );
            }
            return $created;
        }

        return self::term_summary( (int) $created['term_id'], $taxonomy, true, $slug );
    }

    /**
     * Rename / re-slug / re-parent / re-describe an existing term. Posts keep their assignment:
     * the relationship row points at the term id, which never changes here.
     *
     * @return array<string,mixed>|WP_Error
     */
    public static function update_term( string $taxonomy, $term, array $input ) {
        $taxonomy = sanitize_key( $taxonomy );
        $refused  = self::refuse_term_taxonomy( $taxonomy );
        if ( $refused !== null ) {
            return $refused;
        }

        $id = self::term_id( $taxonomy, $term );
        if ( $id === 0 ) {
            return new WP_Error( 'term_not_found', sprintf( 'No term "%s" in taxonomy "%s".', is_scalar( $term ) ? (string) $term : '', $taxonomy ) );
        }

        $args = array();
        if ( array_key_exists( 'name', $input ) ) {
            $name = self::clean( (string) $input['name'] );
            if ( $name === '' ) {
                return new WP_Error( 'missing_name', 'A term name cannot be empty.' );
            }
            $args['name'] = $name;
        }
        if ( array_key_exists( 'slug', $input ) ) {
            $slug = sanitize_title( (string) $input['slug'] );
            if ( $slug === '' ) {
                return new WP_Error( 'bad_slug', 'The slug must contain at least one URL-safe character.' );
            }
            $args['slug'] = $slug;
        }
        if ( array_key_exists( 'description', $input ) ) {
            $args['description'] = wp_kses_post( (string) $input['description'] );
        }

        $long = self::refuse_long_term_name( (string) ( $args['name'] ?? '' ) );
        if ( $long instanceof WP_Error ) {
            return $long;
        }
        if ( array_key_exists( 'parent', $input ) ) {
            $parent = self::resolve_parent( $taxonomy, $input['parent'] );
            if ( is_wp_error( $parent ) ) {
                return $parent;
            }
            if ( $parent === $id ) {
                return new WP_Error( 'parent_is_self', 'A term cannot be its own parent.' );
            }
            if ( $parent > 0 && in_array( $id, get_ancestors( $parent, $taxonomy, 'taxonomy' ), true ) ) {
                return new WP_Error( 'parent_is_descendant', 'That parent is a descendant of this term — it would create a loop.' );
            }
            $args['parent'] = $parent;
        }
        if ( $args === array() ) {
            return new WP_Error( 'nothing_to_update', 'Provide at least one of name, slug, description, parent.' );
        }

        // Snapshot BEFORE the write: `changed` must report what actually changed (a
        // name resent verbatim is not a change), and the slug can move without being
        // asked to — core re-slugs on a parent move when the slug collides with a
        // sibling. After the write nothing remembers what the term used to be called.
        // Unadjusted, like the id that is about to be written: comparing the term the request's
        // language happens to resolve to would report `changed` about a different row.
        $before_term = \WP_Term::get_instance( $id, $taxonomy );
        $before      = array(
            'name'        => $before_term instanceof \WP_Term ? $before_term->name : '',
            'slug'        => $before_term instanceof \WP_Term ? $before_term->slug : '',
            'description' => $before_term instanceof \WP_Term ? $before_term->description : '',
            'parent'      => $before_term instanceof \WP_Term ? (int) $before_term->parent : 0,
        );

        // IN THE TERM'S OWN LANGUAGE, for the same reason delete_term() is. wp_update_term()
        // checks slug uniqueness through term_exists(), which the translation plugin adjusts: run
        // from another language it answers about a DIFFERENT version of this group and reads that
        // as a collision. Measured: renaming a Polish version from an English request failed with
        // `duplicate_term_slug` naming the version's own slug as taken by "another term" — itself.
        $bridge = \Fabrement\Modules\Translation\Translation::bridge();
        $lang   = $bridge->term_language( $id, $taxonomy );

        $updated = $bridge->in_language(
            $lang,
            static function () use ( $id, $taxonomy, $args ) {
                return wp_update_term( $id, $taxonomy, $args );
            }
        );
        if ( is_wp_error( $updated ) ) {
            // Core messages carry curly-quote entities (&#8220;) — decode so the
            // agent reads prose, not markup.
            return new WP_Error( $updated->get_error_code(), html_entity_decode( $updated->get_error_message(), ENT_QUOTES ), $updated->get_error_data() );
        }

        // BOTH caches, and BEFORE the summary — term_summary() calls get_term_link().
        //
        // Core's is the obvious one. The other is not: a translation plugin memoises the links it
        // filters, and that copy survives clean_term_cache() and a wp_cache_delete() of the term
        // alike. Measured on a rename: the term read back with the new slug while its link kept
        // spelling the old one, so the answer carried a URL that had just started 404ing — beside
        // a note telling the caller to relink to it.
        clean_term_cache( $id, $taxonomy );
        $bridge->forget_term_link( $id, $taxonomy );

        $summary = self::term_summary( $id, $taxonomy, false );
        unset( $summary['created'] );
        $after = \WP_Term::get_instance( $id, $taxonomy );
        $changed = array();
        foreach ( $before as $field => $was ) {
            $now = $after instanceof \WP_Term ? ( $field === 'parent' ? (int) $after->parent : (string) $after->{$field} ) : $was;
            if ( $now !== $was ) {
                $changed[] = $field;
            }
        }
        $summary['changed']      = $changed;
        $summary['previousSlug'] = $before['slug'];
        return $summary;
    }

    /**
     * Remove a term by id or slug. The posts keep existing — only the assignment goes.
     *
     * @return array<string,mixed>|WP_Error
     */
    public static function delete_term( string $taxonomy, $term ) {
        $taxonomy = sanitize_key( $taxonomy );
        $refused  = self::refuse_term_taxonomy( $taxonomy );
        if ( $refused !== null ) {
            return $refused;
        }

        $id = self::term_id( $taxonomy, $term );
        if ( $id === 0 ) {
            return new WP_Error( 'term_not_found', sprintf( 'No term "%s" in taxonomy "%s".', is_scalar( $term ) ? (string) $term : '', $taxonomy ) );
        }

        // IN THE TERM'S OWN LANGUAGE. wp_delete_term() is language-adjusted like everything else
        // that takes a term id: called from one language about a term in another, it resolves to
        // that term's counterpart and deletes THAT row instead — so asking to delete a Polish
        // version from an English request destroys the English original, and the answer reports
        // the id that was asked for as deleted.
        //
        // The test harness has wrapped its own deletes this way from the start (see purge() in
        // tests/taxonomy-translation.php, and the four other places this same adjustment bit us);
        // the production path had not.
        $bridge = \Fabrement\Modules\Translation\Translation::bridge();
        $lang   = $bridge->term_language( $id, $taxonomy );

        // The records it is on, before it goes: each loses the term, and delete-term tells the SEO
        // plugin's integration so (a main category that was this term is dropped).
        $records = array_map( 'intval', (array) get_objects_in_term( $id, $taxonomy ) );
        $deleted = $bridge->in_language(
            $lang,
            static function () use ( $id, $taxonomy ) {
                return wp_delete_term( $id, $taxonomy );
            }
        );
        if ( is_wp_error( $deleted ) ) {
            return $deleted;
        }
        foreach ( $records as $record ) {
            \Fabrement\Modules\Seo\TermChanges::announce( $record, $taxonomy );
        }

        return array(
            'deleted'  => true,
            'termId'   => $id,
            'taxonomy' => $taxonomy,
        );
    }

    /** A term id from an id, a slug or a name. 0 when nothing matches. */
    /**
     * PUBLIC so a caller can resolve the SAME term this class would delete, before asking it to.
     * A tool that refuses on what a term is still used for has to look at the term the delete will
     * actually reach — resolving "italian" a second time by its own rules is how the check and the
     * deletion end up talking about different rows.
     */
    public static function term_id( string $taxonomy, $term ): int {
        if ( is_numeric( $term ) ) {
            // WP_Term::get_instance, NOT get_term(): get_term() is language-adjusted, so asking it
            // about a Polish version from an English request hands back the English original —
            // and an id given by the caller then silently becomes a different term. Measured:
            // delete-term on a version reported the ORIGINAL's id as deleted, and deleted it.
            //
            // An id is the one form that needs no interpretation. A slug or a name below still
            // goes through the language-aware lookup, which is right for them: those are searches,
            // and a search belongs to the language it runs in.
            $found = \WP_Term::get_instance( (int) $term, $taxonomy );
            return $found instanceof \WP_Term ? (int) $found->term_id : 0;
        }
        $value = (string) $term;
        foreach ( array( 'slug', 'name' ) as $field ) {
            $found = get_term_by( $field, $value, $taxonomy );
            if ( $found instanceof \WP_Term ) {
                return $found->term_id;
            }
        }
        return 0;
    }

    /**
     * @return int|WP_Error The parent term id, or 0 when there is none.
     */
    private static function resolve_parent( string $taxonomy, $parent ) {
        if ( $parent === null || $parent === '' || $parent === 0 ) {
            return 0;
        }
        if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
            return new WP_Error(
                'not_hierarchical',
                sprintf( 'Taxonomy "%s" is flat (tag-like), so its terms cannot have a parent.', $taxonomy )
            );
        }
        $id = self::term_id( $taxonomy, $parent );
        if ( $id === 0 ) {
            return new WP_Error( 'parent_not_found', sprintf( 'No parent term "%s" in taxonomy "%s" — create it first.', is_scalar( $parent ) ? (string) $parent : '', $taxonomy ) );
        }
        return $id;
    }

    /** @return array<string,mixed> */
    /**
     * @param string $asked the slug the caller asked for, when it asked for one
     */
    private static function term_summary( int $term_id, string $taxonomy, bool $created, string $asked = '' ): array {
        // WP_Term::get_instance, NOT get_term(): this describes the term the caller ASKED about,
        // and get_term() is language-adjusted — it hands back whichever version the request's
        // language resolves to. Measured: translate-term created a Polish term and the answer
        // carried the English one's id, name and slug, because the request ran as English.
        $term = \WP_Term::get_instance( $term_id, $taxonomy );

        $warnings = array();
        if ( $asked !== '' && $term instanceof \WP_Term && $term->slug !== $asked ) {
            // Said out loud, because the address is the point of a slug and this one silently
            // became a different address. WordPress keeps term slugs unique across the WHOLE
            // taxonomy, and with a translation plugin that is every language at once — so a slug
            // another language's term already holds comes back suffixed. Measured: asking for
            // "fiction" beside an English term of that slug produced "fiction-2".
            $other = self::foreign_language_term( $term_id, $taxonomy );

            $warnings[] = $other !== ''
                ? sprintf(
                    'The slug became "%s", not "%s": a term in another language already holds that one, and term slugs are unique across every language at once. If you meant to give THAT term a version in this language, use translate-term — this made a separate term instead.',
                    $term->slug,
                    $asked
                )
                : sprintf( 'The slug became "%s", not "%s": that one was already taken in this taxonomy.', $term->slug, $asked );
        }

        return array(
            'termId'   => $term_id,
            'taxonomy' => $taxonomy,
            'name'     => $term instanceof \WP_Term ? $term->name : '',
            'slug'     => $term instanceof \WP_Term ? $term->slug : '',
            'parent'   => $term instanceof \WP_Term ? (int) $term->parent : 0,
            'created'  => $created,
            // In the term's OWN language: get_term_link() is adjusted the same way, and a Polish term
            // would answer with the English address.
            'url'      => $term instanceof \WP_Term
                ? \Fabrement\Modules\Translation\Translation::term_link( $term, \Fabrement\Modules\Translation\Translation::bridge()->term_language( $term_id, $taxonomy ) )
                : '',
        ) + ( $warnings === array() ? array() : array( 'warnings' => $warnings ) );
    }

    /**
     * The language of a term that is NOT the one this request is working in, or ''.
     *
     * The question create_term() actually has. It is holding the term WordPress handed back as
     * "already exists", and needs to know whether that is its own term (a real idempotent repeat)
     * or another language's, which term_exists() answers across every language at once.
     *
     * {@see foreign_language_term()} answers a different question — "who took the slug I asked
     * for" — and was being asked this one. Given the holder itself it looked for a SECOND holder of
     * the same slug, found none, and returned '' every time: the guard never fired in the case it
     * was written for, and a Polish "Fikcja" went on being reported as the English "Fiction",
     * idempotently.
     */
    private static function term_language_other_than_request( int $term_id, string $taxonomy ): string {
        if ( ! \Fabrement\Modules\Translation\Translation::is_multilingual() ) {
            return '';
        }

        $bridge = \Fabrement\Modules\Translation\Translation::bridge();
        $lang   = $bridge->term_language( $term_id, $taxonomy );
        if ( $lang === '' ) {
            return '';   // An untranslated taxonomy: its terms have no language, so none is foreign.
        }

        $here = $bridge->current_language();

        return ( $here !== '' && $lang !== $here ) ? $lang : '';
    }

    /**
     * The language of whoever took the slug this term asked for — for the "it became fiction-2"
     * warning, where the term already exists and the holder is somebody else.
     */
    private static function foreign_language_term( int $term_id, string $taxonomy ): string {
        if ( ! \Fabrement\Modules\Translation\Translation::is_multilingual() ) {
            return '';
        }

        global $wpdb;

        $bridge = \Fabrement\Modules\Translation\Translation::bridge();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read past the translation plugin's get_term() language adjustment: the term's own slug, whatever the current language.
        $slug   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->terms} WHERE term_id = %d", $term_id ) );

        // Whoever holds the slug this one had to give way to — read past every language filter,
        // because the whole question is which OTHER language is in the way.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the holder of the slug in any language, read past every language filter (see above).
        $holder = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = %s AND t.slug = %s AND t.term_id <> %d LIMIT 1",
                $taxonomy,
                preg_replace( '/-\d+$/', '', $slug ),
                $term_id
            )
        );

        if ( $holder <= 0 ) {
            return '';
        }

        $lang = $bridge->term_language( $holder, $taxonomy );

        return ( $lang !== '' && $lang !== $bridge->term_language( $term_id, $taxonomy ) ) ? $lang : '';
    }

    private static function clean( string $value ): string {
        return trim( sanitize_text_field( $value ) );
    }
}
