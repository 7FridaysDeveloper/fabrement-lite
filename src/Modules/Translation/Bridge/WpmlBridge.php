<?php

namespace Fabrement\Modules\Translation\Bridge;

use Fabrement\Modules\Translation\Contract\TranslationBridge;

/**
 * WPML behind {@see TranslationBridge}. Provider-specific field hooks live under Bridge/Wpml.
 *
 * Reached through WPML's public filters and actions wherever possible, so most of this is inert
 * without it — an unheard filter hands its argument back. The two places that touch a WPML CLASS
 * are guarded, and say why.
 *
 * WPML's element type for a post is `post_<post_type>` and for a term `tax_<taxonomy>`, while
 * `wpml_object_id` takes the BARE post type or taxonomy name. The two spellings are not
 * interchangeable and mixing them fails quietly, so the conversion lives here and callers pass
 * the plain name.
 */
final class WpmlBridge implements TranslationBridge {

    private bool $field_hooks_registered = false;

    private const MODE_TRANSLATE = 1; // WPML_CONTENT_TYPE_TRANSLATE — show only translated items
    private const MODE_FALLBACK  = 2; // WPML_CONTENT_TYPE_DISPLAY_AS_IF_TRANSLATED


    /**
     * The language list for THIS request, once asked. Null until the first call.
     *
     * Callers were told this list was cached here and used it accordingly — per row of a listing,
     * per block of a page. It was not, and the filter behind it is not a cheap read: WPML answers
     * `wpml_active_languages` by cloning and REPLACING the global $wp_query, so each extra call
     * is another chance for `is_main_query()` to come back false for the rest of the request.
     * The list cannot change mid-request, so one answer per request is both correct and the
     * cheapest thing that is.
     *
     * @var list<array{code: string, label: string, isCurrent: bool}>|null
     */
    private ?array $languages = null;

    /** @var array<string,list<array{code:string,label:string,isCurrent:bool,url:string,hasTranslation:bool}>> */
    private array $switchers = [];

    public function is_active(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' ) || did_action( 'wpml_loaded' ) > 0;
    }

    public function languages(): array {
        if ( $this->languages !== null ) {
            return $this->languages;
        }

        $languages = $this->read_languages();

        // An EMPTY answer is not cached. Empty means either "one language" — which cannot change
        // mid-request, so re-asking costs one filter — or "WPML has not registered its filters
        // yet", which happens to anything asking early (a hook on plugins_loaded, WP-CLI). Freezing
        // that would make the whole request believe the site is monolingual: the guards built on
        // originals() stop guarding and the switcher disappears, with nothing to say why.
        if ( $languages === [] ) {
            return [];
        }

        return $this->languages = $languages;
    }

    /**
     * @return list<array{code: string, label: string, isCurrent: bool}>
     */
    private function read_languages(): array {
        // `skip_missing=0` is not optional. Without it this filter answers with the LANGUAGE
        // SWITCHER's list, which WPML prunes per request to the languages the CURRENT query has
        // a translation in — and at `init`, where the template appliers first ask, the main
        // query is not parsed yet, so every language is pruned and the answer is empty. The
        // whole feature would then switch itself off exactly where it matters: an empty list
        // means "one language", every record counts as its own original, and the guards built
        // on originals() quietly stop guarding.
        $raw = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );

        // Fewer than two is not a multilingual site yet, whatever is installed: a picker with one
        // option is a control that cannot change anything, and offering it suggests otherwise.
        if ( ! is_array( $raw ) || count( $raw ) < 2 ) {
            return [];
        }

        $current = $this->current_language();
        $out     = [];

        foreach ( $raw as $key => $language ) {
            $code = is_array( $language ) && ! empty( $language['language_code'] )
                ? (string) $language['language_code']
                : (string) $key;

            if ( $code === '' ) {
                continue;
            }

            $out[] = [
                'code'      => $code,
                'label'     => $this->label_of( $language, $code ),
                'isCurrent' => $code === $current,
                // WPML's own flag image (its bundled set, or the one an admin uploaded) — '' when
                // it has none, and the screens then show the label alone.
                'flag'      => is_array( $language ) && ! empty( $language['country_flag_url'] ) ? esc_url_raw( (string) $language['country_flag_url'] ) : '',
            ];
        }

        return $out;
    }

    public function switcher( bool $translated_only ): array {
        if ( $this->languages() === [] ) {
            return [];
        }
        // Header and footer ask on one page view: one WPML call per view and flag.
        $key = ( $translated_only ? '1' : '0' ) . '|' . $this->current_language() . '|' . get_queried_object_id();
        if ( isset( $this->switchers[ $key ] ) ) {
            return $this->switchers[ $key ];
        }
        // WPML puts back a clone of $wp_query; restore the object itself so is_main_query() holds.
        $has_query = array_key_exists( 'wp_query', $GLOBALS );
        $query     = $GLOBALS['wp_query'] ?? null;
        try {
            $raw = apply_filters( 'wpml_active_languages', null, $translated_only ? 'skip_missing=1' : 'skip_missing=0' );
        } finally {
            if ( $has_query ) {
                $GLOBALS['wp_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore provider side effect.
            } else {
                unset( $GLOBALS['wp_query'] );
            }
        }
        $out = [];
        foreach ( is_array( $raw ) ? $raw : [] as $key_code => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $code = ! empty( $row['language_code'] ) ? (string) $row['language_code'] : (string) $key_code;
            if ( $code === '' || empty( $row['url'] ) ) {
                continue;
            }
            $out[] = [
                'code'           => $code,
                'label'          => $this->label_of( $row, $code ),
                'isCurrent'      => ! empty( $row['active'] ),
                'url'            => (string) $row['url'],
                // missing: WPML links that language's home page (or this page "as translated").
                'hasTranslation' => empty( $row['missing'] ),
            ];
        }
        return $this->switchers[ $key ] = $out;
    }

    public function current_language(): string {
        $code = apply_filters( 'wpml_current_language', null );

        return is_string( $code ) ? $code : '';
    }

    public function in_language( string $lang, callable $fn ) {
        $current = $this->current_language();
        if ( $lang === '' || $lang === $current ) {
            return $fn();
        }
        // The switch is INSIDE the try, and that is not a formality: a plugin hooked on
        // `wpml_switch_language` can throw, and with the switch outside, the finally that puts the
        // language back would never be registered. The site would finish the request in the wrong
        // language — every later permalink, edit link and translated string, with nothing pointing
        // here. permalinks_of_many() in this same class documents the hazard and orders it correctly;
        // this method did not.
        try {
            do_action( 'wpml_switch_language', $lang );

            return $fn();
        } finally {
            do_action( 'wpml_switch_language', $current !== '' ? $current : null );
        }
    }

    public function in_locale( string $lang, callable $fn ) {
        $locale = $lang === '' ? '' : $this->locale_of( $lang );
        if ( $locale === '' || $locale === determine_locale() ) {
            return $fn();
        }
        $switched = false;
        $switcher = $GLOBALS['wp_locale_switcher'] ?? null;
        $previous = $switcher instanceof \WP_Locale_Switcher ? $switcher->get_switched_locale() : false;
        try {
            $switched = switch_to_locale( $locale );
            return $fn();
        } finally {
            // Core pushes the locale before firing switch_locale. A listener can throw before
            // switch_to_locale returns, so its return value alone cannot tell us to restore.
            if ( $switched || ( $switcher instanceof \WP_Locale_Switcher && $switcher->get_switched_locale() !== $previous ) ) {
                restore_previous_locale();
            }
        }
    }

    /** The WordPress locale of a language ("fr" → "fr_FR"), or "" when WPML does not know it. */
    private function locale_of( string $lang ): string {
        // skip_missing=0: the whole list, not the switcher's (see read_languages()).
        // The language switcher API replaces wp_query with a clone. Looking up a locale must
        // preserve query identity, otherwise later is_main_query() checks change their answer.
        $has_query = array_key_exists( 'wp_query', $GLOBALS );
        $query     = $GLOBALS['wp_query'] ?? null;
        try {
            $raw = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
        } finally {
            if ( $has_query ) {
                $GLOBALS['wp_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore provider side effect.
            } else {
                unset( $GLOBALS['wp_query'] );
            }
        }
        $row = is_array( $raw ) ? ( $raw[ $lang ] ?? null ) : null;
        return is_array( $row ) && ! empty( $row['default_locale'] ) ? (string) $row['default_locale'] : '';
    }

    public function default_language(): string {
        $code = apply_filters( 'wpml_default_language', null );

        return is_string( $code ) ? $code : '';
    }

    public function version_in( int $object_id, string $type, string $lang = '' ): int {
        if ( $object_id <= 0 ) {
            return $object_id;
        }

        // The 3rd argument is "return the original when there is no translation" — true while
        // following the reader, false for a NAMED language. See the interface for why.
        $mapped = $lang === ''
            ? apply_filters( 'wpml_object_id', $object_id, $type, true )
            : apply_filters( 'wpml_object_id', $object_id, $type, false, $lang );

        if ( is_numeric( $mapped ) && (int) $mapped > 0 ) {
            return (int) $mapped;
        }

        return $lang === '' ? $object_id : 0;
    }

    public function home_url( string $lang ): string {
        $home = home_url( '/' );
        if ( $lang === '' ) {
            return $home;
        }

        // `wpml_permalink` converts an ADDRESS to a language and switches nothing — unlike
        // running home_url() inside a language switch, which fires WPML's own hooks and every
        // listener on them, twice, for every language in the switcher.
        $translated = apply_filters( 'wpml_permalink', $home, $lang );

        return is_string( $translated ) && $translated !== '' ? $translated : $home;
    }

    public function address_in( string $url, string $lang ): string {
        if ( $url === '' || $lang === '' ) {
            return $url;
        }
        $translated = apply_filters( 'wpml_permalink', $url, $lang );

        return is_string( $translated ) && $translated !== '' ? $translated : $url;
    }

    public function permalink( int $post_id, string $lang = '' ): string {
        if ( $post_id <= 0 ) {
            return '';
        }

        // One record is the one-row case of many. Expressed through the batch so the rule about
        // switching — and restoring — the language lives in exactly one place.
        return $this->permalinks_of_many( [ $post_id => $lang ] )[ $post_id ] ?? '';
    }

    public function permalinks_of_many( array $post_ids_to_lang ): array {
        $current = $this->current_language();

        // Group by language first, so the switch happens once per language instead of once per
        // row — the rows themselves cost nothing, it is the switching that is expensive.
        $by_lang = [];
        foreach ( $post_ids_to_lang as $id => $lang ) {
            $id = (int) $id;
            if ( $id > 0 ) {
                $by_lang[ (string) $lang ][] = $id;
            }
        }

        $out = [];

        // try/finally, and the language restored from what is ACTUALLY active rather than from
        // the language this loop started in. Leaving the site switched is not a local mistake:
        // every later permalink, every edit link and every translated string in the rest of the
        // request comes back in the wrong language, with nothing pointing here. A permalink
        // filter from another plugin throwing mid-loop is enough to cause it.
        $active = $current;
        try {
            foreach ( $by_lang as $lang => $ids ) {
                // Against the language that is active NOW. Comparing against `$current` would be
                // right only for the first group: after one switch the site is no longer in the
                // language the loop began in, so every following group would be read through the
                // previous group's language — an English row answering with a /pl/ address.
                // '' means "follow the reader" — the language the request started in, which is
                // `$current`, NOT whatever the previous group switched to. Skipping the switch
                // for it (rather than switching back) made the answer depend on the order the
                // rows happened to arrive in.
                $want = $lang !== '' ? $lang : $current;
                if ( $want !== '' && $want !== $active ) {
                    // Recorded BEFORE the switch, not after. Switching fires the plugin's own
                    // hooks and other people's listeners; if one of them throws, the assignment
                    // never runs, `finally` sees nothing to undo, and the site stays in that
                    // language for the rest of the request — the exact leak the finally exists
                    // to prevent. Claiming a switch that then failed costs one redundant restore;
                    // missing one costs the request.
                    $active = $want;
                    do_action( 'wpml_switch_language', $want );
                }

                foreach ( $ids as $id ) {
                    $permalink  = get_permalink( $id );
                    $out[ $id ] = is_string( $permalink ) ? $permalink : '';
                }
            }
        } finally {
            if ( $active !== $current ) {
                // null, not the code: WPML only honours a code that is in its active list, so a
                // `$current` of '' — which is what an unanswered filter gives — would not restore
                // anything and would leave the site in the last language switched to, for the
                // rest of the request. null is its documented reset.
                do_action( 'wpml_switch_language', $current !== '' ? $current : null );
            }
        }

        return $out;
    }

    public function all_languages_args( array $args ): array {
        // WPML scopes a query through `posts_where` / `posts_join`, and `suppress_filters` is
        // what skips those clause filters. The polarity reads backwards — TRUE means every
        // language, FALSE means the current one only — which is why `get_posts()`, whose default
        // is true, is already cross-language while a bare `WP_Query` is not.
        //
        // It is a blunt instrument: those same clause filters carry other plugins' work. That is
        // acceptable for a listing an editor reads and would not be for a page a visitor sees,
        // which is what the interface says about where to use this.
        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- WP_Query defaults to false; editor listings must see every language (see above).
        $args['suppress_filters'] = true;

        return $args;
    }

    public function version_facts( array $post_ids, string $type ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) {
            return [];
        }

        global $wpdb;

        $element     = 'post_' . $type;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // One query for the whole page of results. WPML groups every language version of one
        // record under a shared `trid`, so asking for the trids of these ids and then for
        // everything sharing them yields each row's own language AND its siblings together —
        // where the per-record API would cost two lookups per row.
        //
        // Reading the table directly is what confines this class to WPML: the schema is theirs,
        // and Polylang keeps the same facts in a taxonomy instead. That is the point of the
        // method living here rather than in the caller.
        // The JOIN is not decoration. `element_id` is nullable and WPML fills rows for queued
        // translation jobs before a post exists, while rows left behind by a hard delete keep
        // pointing at ids that are gone. Either one reaches a caller as "this language exists"
        // with an id nothing can open — `translations: {"pl": 0}` from the first, a dead id from
        // the second. The per-record method this replaced filtered both out.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's icl_translations table has no API for a whole page of ids at once (see above); $placeholders is a run of %d built with array_fill() from count( $ids ), matched by $ids at the end of the merged replacements; $ids is never empty (early return above).
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.element_id, t.trid, t.language_code, t.source_language_code, p.post_status
                   FROM {$wpdb->prefix}icl_translations t
                   INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id
                  WHERE p.post_status NOT IN ( 'trash', 'auto-draft' )
                    AND t.element_type = %s
                    AND t.trid IN (
                        SELECT trid FROM {$wpdb->prefix}icl_translations
                         WHERE element_type = %s AND element_id IN ( $placeholders )
                    )
                  ORDER BY t.trid, t.language_code, t.element_id",
                array_merge( [ $element, $element ], $ids )
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! is_array( $rows ) ) {
            return [];
        }

        // trid => [ code => id ], then read each asked-for id back out of its own group.
        $groups   = [];
        $statuses = [];
        $of_id    = [];
        foreach ( $rows as $row ) {
            $id   = (int) $row->element_id;
            $trid = (int) $row->trid;
            $code = (string) $row->language_code;

            $groups[ $trid ][ $code ]   = $id;
            // The STATUS travels with the id. Whether a language version exists and whether it is
            // published are two different questions, and a caller that can only ask the first
            // ends up telling someone a half-written draft is finished work.
            $statuses[ $trid ][ $code ] = (string) $row->post_status;
            $of_id[ $id ]             = [
                'trid'     => $trid,
                'language' => $code,
                // WPML records the language a row was translated FROM; the original was
                // translated from nothing, so this is null exactly there. Read here rather
                // than asked per record afterwards — that is the round trip this method exists
                // to avoid, and the row carrying the answer is already in hand.
                // WPML's own test is NULLIF(source_language_code,'') IS NULL — an EMPTY string
                // means original just as much as NULL does, and its own repair routine exists
                // because rows in that state really occur. Reading only NULL would call an
                // original a translation, and a template read that way disappears from the
                // pickers while pages still point at it: no header, no footer, no error.
                'original' => $row->source_language_code === null || (string) $row->source_language_code === '',
            ];
        }

        $out = [];
        foreach ( $ids as $id ) {
            if ( ! isset( $of_id[ $id ] ) ) {
                continue; // A record WPML does not track — an untranslatable post type.
            }

            $out[ $id ] = [
                'language'     => $of_id[ $id ]['language'],
                'translations' => $groups[ $of_id[ $id ]['trid'] ] ?? [],
                'statuses'     => $statuses[ $of_id[ $id ]['trid'] ] ?? [],
                'isOriginal'   => $of_id[ $id ]['original'],
            ];
        }

        return $out;
    }

    public function visible_versions( int $post_id, string $type ): array {
        if ( $post_id <= 0 ) {
            return [];
        }

        $element = 'post_' . $type;
        $trid    = apply_filters( 'wpml_element_trid', null, $post_id, $element );
        if ( ! $trid ) {
            return [];
        }

        $found = apply_filters( 'wpml_get_element_translations', null, $trid, $element );
        if ( ! is_array( $found ) ) {
            return [];
        }

        $out = [];
        foreach ( $found as $code => $translation ) {
            $id = is_object( $translation ) ? (int) ( $translation->element_id ?? 0 ) : (int) $translation;
            if ( $id > 0 ) {
                $out[ (string) $code ] = $id;
            }
        }

        return $out;
    }

    public function all_versions( int $post_id, string $type ): array {
        global $wpml_post_translations;
        if ( $post_id <= 0 || ! $wpml_post_translations instanceof \WPML_Post_Translation || get_post_type( $post_id ) !== $type ) {
            return [];
        }
        $out = [];
        foreach ( (array) $wpml_post_translations->get_element_translations( $post_id ) as $code => $id ) {
            if ( (int) $id > 0 ) {
                $out[ (string) $code ] = (int) $id;
            }
        }
        return $out;
    }

    public function load_versions( array $post_ids ): void {
        global $wpml_post_translations;
        $ids = array_values( array_filter( $post_ids, static fn( $id ): bool => is_int( $id ) && $id > 0 ) );
        if ( $ids !== [] && $wpml_post_translations instanceof \WPML_Post_Translation ) {
            $wpml_post_translations->prefetch_ids( $ids );
        }
    }

    public function original_changed( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || ! $this->is_original( $post_id, $post->post_type ) ) {
            return;
        }
        // WPML's post-save boundary compares its own content hash (including our translated
        // field texts). Calling it on a translation would instead mark that translation complete.
        do_action( 'wpml_tm_save_post', $post_id, $post, false );
    }

    public function register_field_hooks(): void {
        if ( ! $this->field_hooks_registered ) {
            $this->field_hooks_registered = true;
            Wpml\FieldHooks::init();
        }
    }

    public function pending_original( int $post_id ): int {
        $post = get_post( $post_id );
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only discovery, validated against WPML's group and the user's access.
        $trid = isset( $_GET['trid'] ) ? absint( wp_unslash( $_GET['trid'] ) ) : 0;
        $lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( ! $post || ! $trid || $lang === '' || $post->post_status !== 'auto-draft'
            || $this->is_post_type_translatable( $post->post_type ) !== true
            || ! in_array( $lang, array_column( $this->languages(), 'code' ), true ) ) {
            return 0;
        }
        $original = 0;
        foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post->post_type ) as $code => $row ) {
            if ( (string) $code === $lang && ! empty( $row->element_id ) ) {
                return 0;
            }
            if ( ! empty( $row->original ) && ! empty( $row->element_id ) ) {
                $original = (int) $row->element_id;
            }
        }
        return $original && get_post_type( $original ) === $post->post_type && current_user_can( 'read_post', $original )
            ? $original : 0;
    }

    public function duplicate_of( int $post_id ): int {
        return max( 0, (int) get_post_meta( $post_id, '_icl_lang_duplicate_of', true ) );
    }

    public function writes_translations_manually( int $post_id ): bool {
        global $sitepress;

        // WPML's own answer, not a copy of it. The setting has THREE levels — a flag on the
        // original post, then one per post type, then the global one — and their method also
        // resolves a translation back to its source before reading the first. Reimplementing
        // that here would mean refusing to write where WPML would have allowed it, or worse.
        if ( $sitepress && class_exists( \WPML_TM_Post_Edit_TM_Editor_Mode::class ) ) {
            return ! \WPML_TM_Post_Edit_TM_Editor_Mode::is_using_tm_editor( $sitepress, $post_id );
        }

        // Translation Management absent: there is no Translation Editor to lose work to.
        return true;
    }

    public function originals_of_many( array $post_ids, string $type ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        $out = [];
        global $wpdb;
        // The query original_of() makes, for a whole chunk of ids: the original is the group's row
        // translated from nothing, and one that no longer exists as a post is no answer.
        foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's icl_translations table has no batch API; $placeholders is a run of %d built with array_fill() from count( $chunk ), matched by $chunk.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT t.element_id AS id, MIN( o.element_id ) AS original
                       FROM {$wpdb->prefix}icl_translations t
                       INNER JOIN {$wpdb->prefix}icl_translations o
                         ON o.trid = t.trid AND o.element_type = t.element_type
                       INNER JOIN {$wpdb->posts} p ON p.ID = o.element_id
                      WHERE t.element_type = %s
                        AND t.element_id IN ( $placeholders )
                        AND NULLIF( o.source_language_code, '' ) IS NULL
                      GROUP BY t.element_id",
                    array_merge( [ 'post_' . $type ], $chunk )
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            foreach ( (array) $rows as $row ) {
                $out[ (int) $row->id ] = (int) $row->original;
            }
        }
        return $out;
    }

    /**
     * id => original, for this request: every id once asked, answered from here after. Emptied
     * whenever WPML writes a translation row (wpml_translation_update — a version linked, a
     * language set, a group deleted), so a page that translates and then reads in one request
     * does not read the group as it was.
     *
     * @var array<int,int>
     */
    private array $originals = [];

    private static bool $originals_hooked = false;

    public function originals_of_any( array $post_ids ): array {
        if ( ! self::$originals_hooked ) {
            self::$originals_hooked = true;
            add_action( 'wpml_translation_update', static function (): void {
                $bridge = \Fabrement\Modules\Translation\Translation::bridge();
                if ( $bridge instanceof self ) {
                    $bridge->originals = [];
                }
            } );
        }

        $ids     = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        $missing = array_values( array_filter( $ids, fn( int $id ): bool => ! isset( $this->originals[ $id ] ) ) );

        global $wpdb;
        // The type comes with the row — WPML files a post as post_<type> — so no question about
        // types goes before this one; whether a type is translated TODAY is a setting, not a query.
        $modes = [];
        foreach ( array_chunk( $missing, 1000 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's icl_translations table has no batch API; $placeholders is a run of %d built with array_fill() from count( $chunk ), matched by $chunk.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT t.element_id AS id, t.element_type AS type, MIN( o.element_id ) AS original
                       FROM {$wpdb->prefix}icl_translations t
                       INNER JOIN {$wpdb->prefix}icl_translations o
                         ON o.trid = t.trid AND o.element_type = t.element_type
                       INNER JOIN {$wpdb->posts} p ON p.ID = o.element_id
                      WHERE t.element_id IN ( $placeholders )
                        AND t.element_type LIKE %s
                        AND NULLIF( o.source_language_code, '' ) IS NULL
                      GROUP BY t.element_id, t.element_type",
                    array_merge( $chunk, [ $wpdb->esc_like( 'post_' ) . '%' ] )
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            foreach ( (array) $rows as $row ) {
                $type = substr( (string) $row->type, 5 );
                // A type switched to "none" keeps WPML's old rows: reading them would name another,
                // independent record as the original. Read by the type's setting as it is now.
                $modes[ $type ] ??= $this->translation_mode( 'post', $type );
                if ( in_array( $modes[ $type ], [ 'translate', 'fallback' ], true ) ) {
                    $this->originals[ (int) $row->id ] = (int) $row->original;
                }
            }
        }
        $out = [];
        foreach ( $ids as $id ) {
            $out[ $id ] = $this->originals[ $id ] ??= $id;
        }
        return $out;
    }

    public function original_of( int $post_id, string $type ): int {
        if ( $post_id <= 0 ) {
            return $post_id;
        }

        // ONE query for the whole group, with the predicate is_original() settled on: the ROW
        // decides, and the original is the row translated FROM nothing. Joining the group to
        // itself on `trid` answers "which member is the original" without first asking WPML for
        // the trid and then asking about each member in turn — that shape cost a query per
        // language per call, which is what put this question out of reach of anything running on
        // a per-request hook.
        //
        // It also answers where walking the group cannot. A listing of translations drops a post
        // in the trash, so a version whose original was trashed used to resolve to itself and
        // pass as its own original; the translation ROW outlives the trashing, and this finds it.
        // Verified against WPML, and the two operations differ: TRASHING through WordPress leaves
        // `source_language_code` alone — nothing is promoted, the original stays the original with
        // a status of trash — while DELETING it for good rewrites a surviving version's row to say
        // it was translated from nothing, making it the new original. Terms behave the same way.
        // Reading the row therefore answers correctly either way; a walk over the visible group
        // does not, which is the case below.
        //
        // TRASH STAYS IN, A GONE POST DOES NOT, and the two are a different question. This one is
        // identity — which id in this group is the source — and a trashed page is still that id.
        // But WPML also writes rows before a post exists (translation jobs) and leaves them behind
        // a hard delete, and an id nothing can open is no answer at all: a caller would hand it to
        // get_post() and be told its own page is not a page. Hence the join without a status
        // filter — the exact line {@see versionFacts} draws for the other question, which asks
        // which versions EXIST and so must drop the trash as well.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML's icl_translations table: the group's original must be found even in the trash, which WPML's own lookup drops (see above).
        $original = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT o.element_id
                   FROM {$wpdb->prefix}icl_translations o
                   INNER JOIN {$wpdb->posts} p ON p.ID = o.element_id
                   INNER JOIN {$wpdb->prefix}icl_translations t
                     ON t.trid = o.trid AND t.element_type = o.element_type
                  WHERE t.element_type = %s
                    AND t.element_id = %d
                    AND NULLIF( o.source_language_code, '' ) IS NULL
                  ORDER BY o.element_id
                  LIMIT 1",
                'post_' . $type,
                $post_id
            )
        );

        // ORDER BY, because LIMIT 1 alone is not a choice — it is whatever the storage engine
        // hands back first. A group is supposed to have ONE row translated from nothing, but
        // WPML ships a repair routine for groups that have two, and this is read by identity
        // comparisons (the archive-list block, SitePageGuard, NotFoundPage) that must agree with
        // each other. A stable wrong answer beats two different right-looking ones.
        //
        // No row at all: a type WPML knows nothing about, or a record made before its type became
        // translatable. It is then the only version there is.
        return $original === null ? $post_id : (int) $original;
    }

    public function is_original( int $post_id, string $type ): bool {
        if ( $post_id <= 0 ) {
            return true;
        }

        // THE ROW DECIDES, not the filter. `wpml_original_element_id` is served from WPML's own
        // per-request cache, and that cache goes stale the moment anything in this request
        // creates a translation: measured here, a template created AFTER translate-template ran
        // came back with a neighbouring template's id, so is_original() answered false for a
        // record whose row says source_language_code IS NULL. Everything downstream then refused
        // it — find_assignable() returned null and every assign answered "Template not found"
        // about a template that had just been created. The same id read correctly in a fresh
        // request, which is what made it look like a test artefact rather than a bug.
        //
        // The filter also answers null for a record WPML knows nothing about (an untranslated
        // post type, or one created before the type was made translatable) AND for a translation
        // whose original is in the trash — outside wp-admin its lookup drops trashed posts, and
        // such a translation would pass every guard that keeps a language version from being
        // saved or deleted as a template of its own. One query answers all three cases: WPML
        // records the language a row was translated FROM, and the original was translated from
        // nothing
        // (NULLIF(source_language_code, '') IS NULL is WPML's own test), whatever the status
        // of anything else in the group.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML's icl_translations table: WPML's own lookup drops trashed originals (see above).
        $source = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT NULLIF( source_language_code, '' ) FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND element_id = %d",
                'post_' . $type,
                $post_id
            )
        );
        return $source === null;
    }

    public function set_language( int $post_id, string $lang, string $type ): void {
        if ( $post_id <= 0 || $lang === '' ) {
            return;
        }

        do_action(
            'wpml_set_element_language_details',
            [
                'element_id'    => $post_id,
                'element_type'  => 'post_' . $type,
                // null starts a NEW translation group, which is what "its own original" means.
                'trid'          => null,
                'language_code' => $lang,
            ]
        );
    }

    public function link_translation( int $post_id, int $original_id, string $lang, string $type ): void {
        if ( $post_id <= 0 || $original_id <= 0 || $lang === '' ) {
            return;
        }

        $element = 'post_' . $type;

        // An original with no language row — content from before the plugin was active, or
        // before its type was made translatable — has no group to join. WPML reads such
        // content as the default language without ever writing that down, so the trid stays
        // null, and a translation linked to null starts a group of its own: a copy the
        // original knows nothing about, that no reader is ever handed, while the original
        // goes on offering "add a version" — and gets another orphan each time. Write the
        // default language down first, which is the answer WPML already gives for it.
        if ( ! apply_filters( 'wpml_element_trid', null, $original_id, $element ) ) {
            $this->set_language( $original_id, $this->default_language(), $type );
        }

        $source = apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $original_id, 'element_type' => $element ] );

        do_action(
            'wpml_set_element_language_details',
            [
                'element_id'           => $post_id,
                'element_type'         => $element,
                // The original's group. Passing null would start a NEW one, leaving the two
                // records unrelated — a translation nobody can reach from its source.
                'trid'                 => apply_filters( 'wpml_element_trid', null, $original_id, $element ),
                'language_code'        => $lang,
                'source_language_code' => is_string( $source ) ? $source : null,
            ]
        );
    }

    public function translation_screen_url( int $post_id ): string {
        // WPML's Translation Dashboard, filtered to this record's post type — the screen where a
        // translation it owns is actually started. `WPML_TM_FOLDER` is theirs, so the link is
        // only offered where Translation Management is really installed; guessing the path would
        // send someone to a 404 on the sites that have no such screen.
        if ( ! defined( 'WPML_TM_FOLDER' ) ) {
            return '';
        }

        return add_query_arg(
            [
                'page' => WPML_TM_FOLDER . '/menu/main.php',
                'sm'   => 'dashboard',
                'type' => (string) get_post_type( $post_id ),
            ],
            admin_url( 'admin.php' )
        );
    }

    /**
     * `translated_name` is the language's name in the ADMIN's language ("Польська"), `native_name`
     * its own ("Polski"). The first wins: whoever reads this control is working in their own admin
     * language. The code in caps is the last resort, so an unfamiliar shape still yields something
     * selectable rather than a blank row.
     *
     * @param mixed $language One entry of the `wpml_active_languages` list.
     */
    private function label_of( $language, string $code ): string {
        if ( is_array( $language ) ) {
            foreach ( [ 'translated_name', 'native_name', 'display_name' ] as $key ) {
                if ( ! empty( $language[ $key ] ) && is_string( $language[ $key ] ) ) {
                    return $language[ $key ];
                }
            }
        }

        return strtoupper( $code );
    }

    /**
     * String Translation keeps an "admin text" as a string of context `admin_texts_<option>`
     * named `[<option>]<key>`, and translates the option on read ONLY for keys listed in its
     * `_icl_admin_option_names` registry — a key it has never seen (a post type created after
     * WPML scanned the option) is stored but never swapped in. So: list the key, register the
     * string, store the translation. The compiled .mo it serves from is rebuilt by
     * icl_add_string_translation itself; a direct table write would leave a stale file.
     */
    public function translate_option( string $option, string $key, string $original, string $lang, string $value ): bool {
        if ( ! function_exists( 'icl_register_string' ) || ! function_exists( 'icl_add_string_translation' ) ) {
            return false;
        }

        $names = get_option( '_icl_admin_option_names', [] );
        $names = is_array( $names ) ? $names : [];
        if ( ! isset( $names[ $option ][ $key ] ) ) {
            $names[ $option ][ $key ] = 0;
            update_option( '_icl_admin_option_names', $names, 'no' );
        }

        $string_id = (int) icl_register_string( 'admin_texts_' . $option, '[' . $option . ']' . $key, $original, true );
        if ( $string_id <= 0 ) {
            return false;
        }
        $translation = icl_add_string_translation( $string_id, $lang, $value, defined( 'ICL_TM_COMPLETE' ) ? ICL_TM_COMPLETE : 10 );

        return is_numeric( $translation ) && (int) $translation > 0;
    }

    public function forget_languages(): void {
        $this->languages = null;
        $this->originals = [];
    }

    public function term_language( int $term_id, string $taxonomy ): string {
        $ttid = self::term_taxonomy_id( $term_id, $taxonomy );
        if ( $ttid <= 0 ) {
            return '';
        }

        $lang = apply_filters(
            'wpml_element_language_code',
            null,
            array( 'element_id' => $ttid, 'element_type' => 'tax_' . $taxonomy )
        );

        return is_string( $lang ) ? $lang : '';
    }

    /**
     * Memoised per request, and safe to be: a term's place in its translation group is decided when
     * the group is made and does not move while one page is being built. Without it this ran four
     * times on a single term archive — the context is asked for by the asset loader, by the block
     * renderer and by the template resolver — each time with a query of its own.
     *
     * @var array<string,int>
     */
    private array $original_terms = [];

    public function original_term_id( int $term_id, string $taxonomy ): int {
        $key = $taxonomy . ':' . $term_id;
        if ( isset( $this->original_terms[ $key ] ) ) {
            return $this->original_terms[ $key ];
        }

        return $this->original_terms[ $key ] = $this->read_original_term_id( $term_id, $taxonomy );
    }

    private function read_original_term_id( int $term_id, string $taxonomy ): int {
        $ttid = self::term_taxonomy_id( $term_id, $taxonomy );
        if ( $ttid <= 0 ) {
            return $term_id;
        }

        $type = 'tax_' . $taxonomy;

        $trid = apply_filters( 'wpml_element_trid', null, $ttid, $type );
        if ( ! is_numeric( $trid ) ) {
            return $term_id;
        }

        // WPML's own reader for a translation group. It answers with a row per language, each
        // carrying the term_id directly and a flag saying which one the group was made from — so
        // the question "which is the original" is one field rather than a join, and the answer
        // comes through WPML's cache rather than around it.
        //
        // Its ids are NOT language-adjusted, which is why this is safe where get_term() is not:
        // the rows describe the group, not the request.
        foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) as $row ) {
            if ( ! empty( $row->original ) && ! empty( $row->term_id ) ) {
                return (int) $row->term_id;
            }
        }

        return $term_id;
    }

    public function translate_term( int $term_id, string $taxonomy, string $lang, array $fields ): int {
        if ( $term_id <= 0 || $taxonomy === '' || $lang === '' || ! taxonomy_exists( $taxonomy ) ) {
            return 0;
        }

        $ttid = self::term_taxonomy_id( $term_id, $taxonomy );
        if ( $ttid <= 0 ) {
            return 0;
        }

        // The group the original already belongs to. Everything below hangs off this: a translation
        // that lands outside it is not a translation, it is a second term that happens to look
        // similar, and nothing will ever map between them.
        $trid = apply_filters( 'wpml_element_trid', null, $ttid, 'tax_' . $taxonomy );
        $trid = is_numeric( $trid ) ? (int) $trid : 0;
        if ( $trid <= 0 ) {
            return 0;
        }

        $source   = $this->term_language( $term_id, $taxonomy );
        $existing = $this->version_in( $term_id, $taxonomy, $lang );

        $args = array();
        if ( isset( $fields['slug'] ) && $fields['slug'] !== '' ) {
            $args['slug'] = (string) $fields['slug'];
        }
        if ( isset( $fields['description'] ) ) {
            $args['description'] = (string) $fields['description'];
        }
        if ( isset( $fields['parent'] ) && (int) $fields['parent'] > 0 ) {
            $args['parent'] = (int) $fields['parent'];
        }

        // A translation has to be created with a name — WPML has no "same as the original" — so
        // when the caller sends none, the source term's own is used.
        //
        // Resolved into its OWN variable and checked: `->name ?? ''` does not guard the property
        // access, so a term that is gone would log "Attempt to read property on null" first. Not
        // `$source` — that already holds the source LANGUAGE, read above and sent to WPML below.
        //
        // WP_Term::get_instance, NOT get_term(): get_term() is language-adjusted, and from a request
        // running in another language it hands back that language's version and its name.
        $source_term = \WP_Term::get_instance( $term_id, $taxonomy );
        $name       = isset( $fields['name'] ) && $fields['name'] !== ''
            ? (string) $fields['name']
            : ( $source_term instanceof \WP_Term ? (string) $source_term->name : '' );

        // Written IN the target language. WPML puts a new term in whatever language the request is
        // running as ({@see WPML_Term_Actions::save_term_actions()}, which on a REST request has no
        // form field to read and falls back to the current one), so switching first is what stops
        // the translation being born in the wrong language and having to be moved.
        $result = $this->in_language(
            $lang,
            static function () use ( $existing, $term_id, $taxonomy, $name, $args ) {
                if ( $existing > 0 && $existing !== $term_id ) {
                    return wp_update_term( $existing, $taxonomy, array( 'name' => $name ) + $args );
                }

                return wp_insert_term( $name, $taxonomy, $args );
            }
        );

        if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['term_taxonomy_id'] ) ) {
            return 0;
        }

        // Join it to the original's group. The insert above gave the term a language and a trid of
        // its OWN; this is what makes it the same term in another language instead.
        do_action(
            'wpml_set_element_language_details',
            array(
                'element_id'           => (int) $result['term_taxonomy_id'],
                'element_type'         => 'tax_' . $taxonomy,
                'trid'                 => $trid,
                'language_code'        => $lang,
                // A language code or nothing. WPML stores whatever arrives, and wpdb escapes a
                // non-scalar to '' — which WPML then reads as "this version is an original".
                'source_language_code' => is_string( $source ) && $source !== '' ? $source : null,
            )
        );

        // WPML serves this core option per language for a translated taxonomy, so a new term in a
        // language invalidates the copy built without it.
        delete_option( $taxonomy . '_children' );

        global $wpml_term_translations;
        if ( is_object( $wpml_term_translations ) && method_exists( $wpml_term_translations, 'invalidate_cache' ) ) {
            $wpml_term_translations->invalidate_cache();
        }

        return (int) $result['term_id'];
    }

    /**
     * WPML files terms under their `term_taxonomy_id`, not their `term_id` — the one place the term
     * path and the post path disagree about what an id is. The two are equal in most rows of a
     * freshly built table, which is exactly why mixing them up survives testing.
     */
    private static function term_taxonomy_id( int $term_id, string $taxonomy ): int {
        global $wpdb;

        // Straight from the table, NOT through get_term(). The translation plugin adjusts get_term()
        // to the current language, so asking it for an English term from a Polish request hands back
        // the Polish counterpart — and with it the wrong term_taxonomy_id, the wrong language and,
        // where the two are not already in one group, the wrong trid entirely. Measured: reading the
        // language of the English "Fiction" answered "pl".
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read past the translation plugin's get_term() language adjustment (see above).
        $ttid = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
                $term_id,
                $taxonomy
            )
        );

        return $ttid === null ? 0 : (int) $ttid;
    }

    /**
     * WPML's own answer, asked fresh.
     *
     * NOT cached beyond the request. The two setters below change it, and a listing that ran
     * before one of them must not keep answering the old way for the rest of the call — which is
     * the whole failure this method exists to end. Within one request the answer is stable, and
     * `is_translated_*` is a settings lookup rather than a query, so the memo is politeness to
     * a listing that asks per row, nothing more.
     *
     * @var array<string,bool|null>
     */
    private array $translatable_memo = array();

    public function is_post_type_translatable( string $post_type ): ?bool {
        return $this->asks_plugin( 'post:' . $post_type, 'is_translated_post_type', $post_type );
    }

    /**
     * WPML memoises every term link it builds
     * ({@see \WPML_Tax_Permalink_Filters::cached_filter_tax_permalink}, on `term_link` at priority
     * 1) under the `icl_tax_permalink_filter` group, keyed by term and taxonomy. Its own cache
     * clearing does not reach it and neither does core's: measured after wp_update_term(), the
     * term read back with the NEW slug while get_term_link() went on answering with the old one —
     * through clean_term_cache() and a wp_cache_delete() of the term both.
     *
     * The whole group goes, not the one key: the key carries a third part (whether the link is
     * being built for the language switcher) that is WPML's business, and guessing it wrong would
     * leave the stale copy exactly where it hurts. The group is a per-request memo of addresses
     * that are cheap to rebuild.
     */
    public function forget_term_link( int $term_id, string $taxonomy ): void {
        if ( ! class_exists( '\WPML_WP_Cache' ) ) {
            return;
        }

        ( new \WPML_WP_Cache( 'icl_tax_permalink_filter' ) )->flush_group_cache();
    }

    /**
     * One of SitePress's two `is_translated_*` methods, memoised, or null when it cannot answer.
     *
     * Null covers a WPML that is present but older than the method, and the moment before it has
     * booted. A caller that turned that into `false` would report "this is not translated" about a
     * site it simply had not asked yet.
     */
    private function asks_plugin( string $key, string $method, string $name ): ?bool {
        if ( array_key_exists( $key, $this->translatable_memo ) ) {
            return $this->translatable_memo[ $key ];
        }

        global $sitepress;

        $answer = ( $name !== '' && is_object( $sitepress ) && method_exists( $sitepress, $method ) )
            ? (bool) $sitepress->{$method}( $name )
            : null;

        return $this->translatable_memo[ $key ] = $answer;
    }

    public function translation_mode( string $kind, string $name ): ?string {
        global $sitepress;
        if ( $name === '' || ! $sitepress instanceof \SitePress ) {
            return null;
        }
        $is_post = $kind === 'post';
        // WPML's own "is it translated" first: it also counts a type declared in a wpml-config.xml
        // (locked on its screen) that has no row in the settings array.
        $translated = $is_post ? $sitepress->is_translated_post_type( $name ) : $sitepress->is_translated_taxonomy( $name );
        if ( ! $translated ) {
            return 'none';
        }
        $sync = $sitepress->get_setting( $is_post ? 'custom_posts_sync_option' : 'taxonomies_sync_option', array() );
        $row  = is_array( $sync ) && isset( $sync[ $name ] ) ? (int) $sync[ $name ] : self::MODE_TRANSLATE;
        return $row === self::MODE_FALLBACK ? 'fallback' : 'translate';
    }

    public function option_translations( string $option, string $key, string $original ): array {
        if ( ! function_exists( 'icl_get_string_id' ) || ! function_exists( 'icl_get_string_translations_by_id' ) ) {
            return [];
        }
        $string_id = (int) icl_get_string_id( $original, 'admin_texts_' . $option, '[' . $option . ']' . $key );
        if ( $string_id <= 0 ) {
            return [];
        }
        $out = [];
        foreach ( (array) icl_get_string_translations_by_id( $string_id ) as $code => $row ) {
            $value = is_array( $row ) ? (string) ( $row['value'] ?? '' ) : '';
            if ( $value !== '' ) {
                $out[ (string) $code ] = $value;
            }
        }
        return $out;
    }

    public function supports_strings(): bool {
        return $this->languages() !== []
            && function_exists( 'icl_register_string' )
            && function_exists( 'icl_add_string_translation' );
    }

    public function without_language( string $post_type ): array {
        global $wpdb;
        if ( $this->is_post_type_translatable( $post_type ) !== true ) {
            return [];
        }
        // Straight SQL: every WP_Query on this type is exactly what WPML filters these out of.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- records without a WPML language row; any WP_Query on the type is filtered by WPML (see above).
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID AND t.element_type = %s
                 WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') AND t.translation_id IS NULL
                 ORDER BY p.ID LIMIT 50",
                'post_' . $post_type,
                $post_type
            ),
            ARRAY_A
        );
        return array_map( static fn( array $r ): array => [ 'id' => (int) $r['ID'], 'title' => (string) $r['post_title'] ], (array) $rows );
    }

}
