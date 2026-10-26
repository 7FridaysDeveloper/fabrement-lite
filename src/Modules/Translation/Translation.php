<?php

namespace Fabrement\Modules\Translation;

use Fabrement\Modules\Translation\Bridge\NullBridge;
use Fabrement\Modules\Translation\Bridge\WpmlBridge;
use Fabrement\Modules\Translation\Contract\TranslationBridge;

/**
 * Picks the translation bridge for this site, once.
 *
 * A static accessor, not a container binding: one source for one decision.
 *
 * The choice is made from what is actually loaded, not from configuration, so a plugin activated
 * or removed between requests is simply a different answer next time.
 */
final class Translation {

    private static ?TranslationBridge $bridge = null;

    /** Memo for {@see is_multilingual()} — asked dozens of times per request (tool schemas, descriptions). */
    private static ?bool $multilingual = null;

    /**
     * The bridge for this site — a real one when a translation plugin is running, otherwise
     * {@see NullBridge}, which answers every question with "there is one language".
     */
    public static function bridge(): TranslationBridge {
        if ( self::$bridge === null ) {
            $plugin_bridge = new WpmlBridge();
            self::$bridge = $plugin_bridge->is_active() ? $plugin_bridge : new NullBridge();
        }

        return self::$bridge;
    }

    /**
     * Compute a result in one language, including WordPress translations and date formatting.
     * Locale goes first: switching the language can already change determine_locale() through
     * provider filters. Both scopes restore their caller's state, including on an exception.
     * Materialize lazy results inside the callback, before this scope ends.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function in_context( string $language, callable $callback ) {
        $bridge = self::bridge();
        return $bridge->in_locale( $language, static fn() => $bridge->in_language( $language, static function () use ( $callback ) {
            // Some providers populate WP_Locale lazily. wp_date() checks its tables BEFORE
            // calling the getters, so initialize through the public API while in this language.
            $locale = $GLOBALS['wp_locale'] ?? null;
            if ( $locale instanceof \WP_Locale && ( empty( $locale->month ) || empty( $locale->weekday ) ) ) {
                $locale->get_month( 1 );
            }
            return $callback();
        } ) );
    }

    /**
     * Does this site have MORE THAN ONE language — the one question every "should the agent see
     * this?" decision asks (a tool offered, a paragraph in the manual, a field in a schema).
     *
     * Asked of the language LIST, not of is_active(): a translation plugin that knows a single
     * language is, for every decision downstream, the same site as one with no plugin at all,
     * and answering "yes" there would have an agent branch on a difference that changes nothing.
     */
    public static function is_multilingual(): bool {
        if ( self::$multilingual !== null ) {
            return self::$multilingual;
        }

        $bridge = self::bridge();
        $answer = $bridge->languages() !== [];

        // "Yes" cannot change within a request. "No" is final without a translation plugin, but
        // with one it may only mean the plugin has not answered yet — the bridge refuses to cache
        // an early empty list for that reason — so it is kept only once `init` has run.
        if ( $answer || $bridge instanceof NullBridge || did_action( 'init' ) ) {
            self::$multilingual = $answer;
        }

        return $answer;
    }

    /**
     * What an agent is told wherever string translation is missing — list-languages and every tool
     * that needs it say the SAME thing: what is out of reach, and that only the site admin can
     * change it. The add-on is paid and comes from a WPML account;
     * saying so stops an agent from trying, or from retrying the refused call.
     */
    public const STRINGS_MISSING = 'WPML String Translation (a WPML add-on) is not active, so SEO title/description '
        . 'templates cannot be translated here — pages, '
        . 'posts, templates and terms still can. Ask the site admin to install and activate it (it is paid, '
        . 'from their WPML account); do not retry.';

    /**
     * The same, for forms: what makes Contact Form 7 forms translatable is a WPML add-on, and like
     * String Translation it comes from a WPML account, not WordPress.org.
     */
    public const FORMS_MISSING = 'Contact Form 7 forms cannot be translated here: WPML Multilingual for CF7 (a WPML add-on) '
        . 'is not active. Ask the site admin to install and activate it from their WPML account; '
        . 'do not retry.';

    /**
     * Drop the memoised language list and the answer built on it — for a request that changes
     * the site's languages (WPML's setup wizard) and reads them again afterwards.
     */
    public static function forget_languages(): void {
        self::$multilingual = null;
        self::bridge()->forget_languages();
    }

    /**
     * The site's languages a translation group does NOT cover yet — the codes an agent would pass
     * to translate-content / translate-template. Reads the language list once per
     * request (it is cached in the bridge), so calling it per row of a listing is free.
     *
     * @param array<string,int> $translations code → id of the group, as the bridge reports it.
     * @return string[]
     */
    public static function missing_languages( array $translations ): array {
        $missing = [];
        foreach ( self::bridge()->languages() as $language ) {
            $code = (string) ( $language['code'] ?? '' );
            if ( $code !== '' && ! isset( $translations[ $code ] ) ) {
                $missing[] = $code;
            }
        }
        return $missing;
    }

    /**
     * Every language version of a term, as code → term id, the term itself included; a language
     * with no version is absent. Empty on a single-language site.
     *
     * @return array<string,int>
     */
    public static function term_translations( int $term_id, string $taxonomy ): array {
        $out = [];
        foreach ( self::bridge()->languages() as $language ) {
            $code = (string) ( $language['code'] ?? '' );
            $id   = $code === '' ? 0 : self::bridge()->version_in( $term_id, $taxonomy, $code );
            if ( $id > 0 ) {
                $out[ $code ] = $id;
            }
        }
        return $out;
    }

    /**
     * A term's archive address in `$language` — its own, from list-terms and the SEO tools alike:
     * get_term_link() answers in the language of the request, and from there hands back another
     * version's address. '' when the taxonomy has no term pages or the address does not resolve.
     *
     * get_term_link() answers with a WP_Error when the term does not resolve where it is asked, and
     * the multilingual form asks by id in ANOTHER language than the request runs in — the form that
     * can miss. An empty url is the honest answer; one unresolvable address must not take down a
     * whole listing.
     */
    public static function term_link( \WP_Term $term, string $language = '' ): string {
        // A taxonomy nobody can view has no term pages, though WordPress still builds an address.
        if ( ! is_taxonomy_viewable( (string) $term->taxonomy ) ) {
            return '';
        }
        $link = $language === ''
            ? get_term_link( $term )
            : self::bridge()->in_language( $language, static fn() => get_term_link( (int) $term->term_id, (string) $term->taxonomy ) );

        return is_wp_error( $link ) ? '' : (string) $link;
    }

    /**
     * The ORIGINAL of a record's translation group — the version the others were translated from —
     * or the id itself when it has no group, or no member of it is marked as the source. Site
     * settings that name one page (front, posts, not-found) store this, so a version's id and the
     * original's mean the same page.
     *
     * THE ANSWER MOVES WHEN AN ORIGINAL IS DELETED FOR GOOD. Measured, because the two operations
     * read alike and behave differently: TRASHING an original changes nothing — its row keeps
     * `source_language_code` empty and it stays the original with a status of trash — while
     * DELETING it promotes a remaining version, whose row is rewritten to say it was translated
     * from nothing. The same holds for terms. So an id stored in a setting can stop being the
     * original without that setting being touched, and this is what finds the new one.
     *
     * NOT memoised, deliberately. The bridge answers this in one indexed lookup, so there is
     * little left to save — and a per-request memo would be wrong for the one caller that changes
     * the answer mid-request: {@see ContentTranslator} inserts a copy and links it to the group
     * afterwards, so anything that asked in between would have cached "it is its own original".
     */
    public static function original_of( int $id, string $type = 'page' ): int {
        return $id > 0 ? self::bridge()->original_of( $id, $type ) : $id;
    }

    /**
     * Use it when SHOWING a record or attachment: the language it is filed under, from the
     * translation plugin's cache — no query in a loop. Any status counts, so ask it only of what
     * is being shown; service code asks live_language_of().
     */
    public static function shown_language_of( int $id, string $type ): string {
        return $id > 0 ? (string) array_search( $id, self::bridge()->all_versions( $id, $type ), true ) : '';
    }

    /** Use it when SHOWING a term: the language it is filed under, '' for none (an untranslated taxonomy). */
    public static function shown_language_of_term( int $id, string $taxonomy ): string {
        return $id > 0 ? self::bridge()->term_language( $id, $taxonomy ) : '';
    }

    /**
     * Use it in SERVICE code — translate, delete, MCP tools: the language of a live record; a
     * trashed or auto-draft one has none (`$unknown`). One query per call, so a display loop
     * asks shown_language_of() instead.
     *
     * The language of ONE record, as a code — '' on a single-language site. The `language` of
     * {@see TranslationBridge::version_facts()}'s row, for callers that need nothing else.
     *
     * @param string $type    the record's post type; '' looks it up
     * @param string $unknown what to answer when the translation plugin knows nothing of the record
     *                        (its default language, for a caller that must have one)
     */
    public static function live_language_of( int $id, string $type = '', string $unknown = '' ): string {
        if ( $id <= 0 ) {
            return $unknown;
        }
        if ( $type === '' ) {
            $type = (string) get_post_type( $id );
        }
        $row = self::bridge()->version_facts( [ $id ], $type )[ $id ] ?? null;

        return is_array( $row ) && isset( $row['language'] ) ? (string) $row['language'] : $unknown;
    }

    /**
     * Override the bridge — for tests, and for a site that has to force one.
     *
     * Pass null to forget the choice and let it be made again, which is what a test that
     * activated a plugin mid-run needs.
     */
    public static function set( ?TranslationBridge $bridge ): void {
        self::$bridge = $bridge;
    }
}
