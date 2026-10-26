<?php

namespace Fabrement\Modules\Translation\Contract;

/**
 * Everything this plugin needs from a translation plugin, and the only shape it knows.
 *
 * WPML is one implementation; Polylang and the rest are others, and they do NOT share an API —
 * WPML links a translation through `wpml_set_element_language_details` and a trid, Polylang
 * through `pll_set_post_language` plus `pll_save_post_translations`, and neither has an
 * equivalent of the other's configuration rebuild. A caller that reached for either directly
 * would work on one site and fail SILENTLY on the next, because an unheard filter simply hands
 * its argument back.
 *
 * So no caller reaches for either. They take this, and a site with no translation plugin gets
 * {@see \Fabrement\Modules\Translation\Bridge\NullBridge} — which answers every question with
 * "there is one language, and it is this one". That is what keeps the single-language case free
 * of `if` statements everywhere else: it is an object, not a condition.
 */
interface TranslationBridge {

    /**
     * Is a translation plugin actually running?
     *
     * ONLY for deciding what to show — a language column, a picker, a diagnostic. Never a guard
     * before the methods below: they are all safe to call, and branching on this in business
     * logic is exactly the duplication this interface exists to remove.
     */
    public function is_active(): bool;

    /**
     * The site's languages as `[ { code, label, isCurrent } ]`, in the plugin's own order.
     *
     * EMPTY for a single language — including a translation plugin that is installed but only
     * knows one. Consumers read the emptiness as "there is nothing to choose" and render no
     * language control at all, which is why a one-language site needs no special case.
     *
     * @return list<array{code:string,label:string,isCurrent:bool}>
     */
    public function languages(): array;

    /**
     * The language switcher of the current view, as the translation plugin builds it: each row is
     * this view in that language, or that language's home page when it has none (hasTranslation
     * false). $translated_only leaves out the languages the plugin would skip for this view.
     *
     * Empty on a site with one language.
     *
     * @return list<array{code:string,label:string,isCurrent:bool,url:string,hasTranslation:bool}>
     */
    public function switcher( bool $translated_only ): array;

    /** Current language code, or '' when there is no translation plugin. */
    public function current_language(): string;

    /**
     * Run `$fn` with the site switched to `$lang`, and switch back whatever happens. For work
     * whose answer the plugin adjusts to the CURRENT language — term ids on read and write,
     * for one: a term set on a Polish post from an English request is silently swapped for
     * its English sibling unless the request is Polish while it is set. Without a plugin,
     * `$fn` simply runs.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function in_language( string $lang, callable $fn );

    /**
     * Run `$fn` with WordPress switched to the locale of `$lang` (month names, number separators,
     * translations) and switch back whatever happens; only when that locale differs from the one
     * in use. Without a translation plugin, `$fn` runs in the site's locale.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function in_locale( string $lang, callable $fn );

    /**
     * The SITE's own language — where content created without a language lands.
     *
     * Not the same as {@see current_language()}, which is whatever is being viewed or requested
     * right now. A listing wants the current one; a decision about where new content belongs
     * wants this.
     */
    public function default_language(): string;

    /**
     * Use it to SHOW a record or attachment in a language: which copy a page in `$lang` shows.
     * Reads the translation plugin's cache — no query once load_versions() loaded the group.
     *
     * `$object_id`'s counterpart in `$lang`, or in the CURRENT language when `$lang` is ''.
     *
     * The two differ in what a MISSING translation means, and the difference is the point:
     *
     *  - following the reader ('') falls back to the ORIGINAL, so a page nobody has translated
     *    yet still renders a working link;
     *  - a language the editor NAMED does not fall back — they asked for that version, and
     *    quietly substituting another answers a question they did not ask. The caller gets 0
     *    and decides for itself.
     *
     * @param string $type A post type, or a taxonomy name for terms ('nav_menu').
     */
    public function version_in( int $object_id, string $type, string $lang = '' ): int;

    /**
     * `$post_id`'s permalink AS SEEN FROM `$lang`.
     *
     * Not a convenience wrapper: `get_permalink()` answers in the language being RENDERED, not
     * the language of the post handed to it, so resolving an id to one language and then reading
     * its permalink silently throws the resolution away.
     */
    public function permalink( int $post_id, string $lang = '' ): string;

    /**
     * The site's home page in `$lang` — the destination for a language a record has no version in.
     *
     * A translation of an ADDRESS, not a record, so it never switches the site: switching rebuilds
     * the plugin's locale and caches, and a language switcher would do it once per language per
     * header. `home_url()` is language-aware only while a switch is on, which is exactly why this
     * cannot simply call it.
     */
    public function home_url( string $lang ): string;

    /**
     * Use it when an address must be in a language other than the page's: `$url` with the language
     * of the site's addresses set to `$lang`, switching nothing. The translation plugin's own
     * address conversion — for a link it remembers per record whatever the language (a term link
     * of a "display as translated" taxonomy), which a language switch alone does not change.
     */
    public function address_in( string $url, string $lang ): string;

    /**
     * Query args widened to reach EVERY language.
     *
     * A translation plugin scopes ordinary queries to the language being viewed, which is right
     * for a visitor and wrong for a listing an editor or an agent reads: a page that exists only
     * in Polish is then not missing from the answer, it is invisible in it — and the usual
     * response to "does a page called X exist" being "no" is to create a second one.
     *
     * How to widen a query is the plugin's own business (and on a site with none there is
     * nothing to widen), so callers hand their args through here rather than knowing.
     *
     * For LISTINGS an editor reads. Not for anything a visitor sees — widening can mean
     * suppressing filters other plugins put on the same query.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function all_languages_args( array $args ): array;

    /**
     * The permalinks of MANY records at once, each AS SEEN FROM its own language.
     *
     * Batched for the reason {@see permalink()} exists at all: answering per language means
     * switching the plugin's language and back, which rebuilds its query filters and caches. One
     * switch per LANGUAGE is a handful; one per row is two hundred on a hundred-row listing.
     *
     * @param array<int,string> $post_ids_to_lang id => language code ('' = follow the reader).
     * @return array<int,string> id => permalink.
     */
    public function permalinks_of_many( array $post_ids_to_lang ): array;

    /**
     * Use it in service code and listings that need, for a set of ids, each one's language,
     * whether it is an original and its versions' statuses — one query for the whole set.
     * Trashed and auto-draft versions are left out.
     *
     * The language and sibling versions of MANY records at once.
     *
     * Batched because the per-record form costs a lookup each, and the caller is a listing: fifty
     * rows would mean a hundred round trips to answer a question one query can.
     *
     * @param list<int> $post_ids
     * `isOriginal` answers {@see is_original()} for the same record, so a listing that needs both
     * facts — which language is this, and is it a record in its own right — pays for one query
     * instead of a second lookup per row.
     *
     * `statuses` carries each of those versions' `post_status`, because "this language has a
     * version" and "readers see it" are different facts: a draft translation answers yes to the
     * first and no to the second, and a screen that knows only the first calls it finished.
     *
     * @return array<int,array{language:string,translations:array<string,int>,statuses:array<string,string>,isOriginal:bool}>
     *         Records the plugin knows nothing about are simply absent; a caller reads that
     *         absence the way {@see is_original()} reads it, as "original".
     */
    public function version_facts( array $post_ids, string $type ): array;

    /**
     * Use it for DISPLAY only — language switcher, hreflang, "also in" lists: what this user may
     * see (no trash; drafts, private and scheduled only for those who may read them).
     *
     * The language versions of one record the current user is shown, as `code => id`, INCLUDING the
     * original — for display (switchers, hreflang). Structure (create, delete, sync, which version a
     * page shows) reads {@see all_versions()}.
     *
     * Empty when there is no translation plugin. A language with no version is absent rather
     * than null — a caller that needs to know what is missing compares against
     * {@see languages()}, which is the list that says what "missing" even means here.
     *
     * @return array<string,int>
     */
    public function visible_versions( int $post_id, string $type ): array;

    /**
     * Use it for STRUCTURE — find the original, change every language, find a copy in the trash:
     * the whole group as `code => id`, every status, trash included. Reads the translation
     * plugin's cache — no query once load_versions() loaded the group.
     *
     * @return array<string,int>
     */
    public function all_versions( int $post_id, string $type ): array;

    /**
     * Use it before a loop over many ids: loads their translation groups in one query, so
     * version_in(), all_versions() and Translation::shown_language_of() then cost nothing.
     *
     * @param list<int> $post_ids
     */
    public function load_versions( array $post_ids ): void;

    /**
     * Are this record's translations written BY HAND, in the WordPress editor?
     *
     * A translation plugin can own the translation of a page instead: its editor holds the text,
     * and it rewrites the translated post from the original whenever a job completes. Content
     * written into such a translation from outside survives until the next run and then vanishes,
     * with nothing to say why — so a caller must ask before writing one.
     *
     * True where there is no translation plugin: nothing is going to overwrite anything.
     */
    public function writes_translations_manually( int $post_id ): bool;

    /**
     * Notify the translation plugin after content of an original was committed outside a
     * WordPress post save. It decides which translations need updating, including manual ones.
     * Does not save the post or mark a translation complete; inert without a translation plugin.
     */
    public function original_changed( int $post_id ): void;

    /** Register the provider's field lifecycle, translation-job and settings hooks once. */
    public function register_field_hooks(): void;

    /**
     * Original requested by the provider's new-translation editor URL for this auto-draft.
     * Returns 0 for invalid, inaccessible or already occupied language versions; writes nothing.
     */
    public function pending_original( int $post_id ): int;

    /** Original a duplicate follows until translated independently; 0 for an ordinary post. */
    public function duplicate_of( int $post_id ): int;

    /**
     * Is this record the ORIGINAL, rather than one language's version of another?
     *
     * A translation is a real post, so anything listing "the site's templates" would otherwise
     * show it as a second one — with the same name, assignable to pages and choosable as a
     * default, which is exactly the per-language configuration a template must not have.
     *
     * True without a translation plugin: every record is its own original.
     */
    public function is_original( int $post_id, string $type ): bool;

    /**
     * The ORIGINAL of this record's translation group, or the id itself when it has none.
     *
     * The same question {@see isOriginal} answers one record at a time, asked about the whole
     * group at once. Walking the group and testing each member costs a query per member, which is
     * too much for anything on a per-request hook — and it drops a group whose original is in the
     * trash, because the listing this would walk does not include trashed posts while the
     * translation rows still do.
     *
     * The id itself without a translation plugin: every record is its own original.
     */
    public function original_of( int $post_id, string $type ): int;

    /**
     * {@see original_of()} for many records at once, as `id => original id` — one query per
     * thousand ids, for a listing that folds every language version into its group.
     *
     * Records the plugin has no language for are absent: they stand alone, their own original.
     * Empty without a translation plugin.
     *
     * @param list<int> $post_ids
     * @return array<int,int>
     */
    public function originals_of_many( array $post_ids, string $type ): array;

    /**
     * The ORIGINAL of each record — of any post type, media included — as `id => original id`,
     * for every positive id asked: the id itself when it has no original to give (not translated,
     * or its type is not translated today). One query for all of them, and none for an id asked
     * before in this request — a listing that resolved a hundred ids answers the next one free.
     *
     * Every id answers itself without a translation plugin.
     *
     * @param list<int> $post_ids
     * @return array<int,int>
     */
    public function originals_of_any( array $post_ids ): array;

    /**
     * Files `$post_id` as its OWN original, in `$lang` — nobody's translation.
     *
     * A Polish landing page that exists only in Polish is not a translation of anything. Without
     * being told, a translation plugin files a new record under the site's default language and
     * it then shows up in the wrong place.
     */
    public function set_language( int $post_id, string $lang, string $type ): void;

    /**
     * Records `$post_id` as `$original_id`'s version in `$lang`.
     *
     * The post must already exist — creating it is the caller's business, since only the caller
     * knows what a copy of its own content means. This links the two.
     */
    public function link_translation( int $post_id, int $original_id, string $lang, string $type ): void;

    /**
     * Where a person goes to translate a record the PLUGIN owns, or '' when there is nowhere.
     *
     * Refusing to write is only half an answer: the other half is where the work actually
     * happens. Built here because only the bridge knows which plugin is running and what its
     * screen is called — a caller guessing the URL would be right on one site and wrong on the
     * next, which is worse than saying nothing.
     */
    public function translation_screen_url( int $post_id ): string;

    /**
     * The mode the plugin applies today — "none" | "translate" | "fallback" — for a post type
     * ($kind "post") or taxonomy ($kind "tax"), or null when it cannot answer.
     */
    public function translation_mode( string $kind, string $name ): ?string;

    /**
     * Does the translation plugin translate this post type's RECORDS right now?
     *
     * THE ONLY ANSWER TO THAT QUESTION. It used to be read off our own definition file, which
     * stored what we last declared — a second copy that went stale the moment anyone touched the
     * plugin's own screen, and the two then disagreed in both directions: a listing reporting
     * `true` for a type translation had been switched off for, and a refusal saying a type had
     * "no language on it" while its records plainly had one.
     *
     * Null, not false, when there is nothing to ask: a site with one language, or a plugin that
     * cannot answer. The caller reports "the question does not exist here" rather than "no".
     */
    public function is_post_type_translatable( string $post_type ): ?bool;

    /**
     * Drop any cached ARCHIVE URL the translation plugin holds for a term.
     *
     * get_term_link() is filtered, and the answer memoised per term — so after a slug rename the
     * term itself reads correctly while its link still spells the old slug, for the rest of the
     * request. A writer that reports the new address, or a rename note telling the caller which
     * URL to relink to, hands back the one that just started 404ing.
     *
     * A no-op where nothing filters term links.
     */
    public function forget_term_link( int $term_id, string $taxonomy ): void;

    /**
     * Translate ONE key of a site option — an "admin text" — into a language.
     *
     * A site option (a Yoast title template, a plugin's label) is stored once, in the original
     * language; a translation plugin keeps the other languages beside it and swaps them in when
     * the option is read on a page in that language. This registers the key as translatable when
     * it is not yet, and stores the translation. `$original` is the value the option holds now —
     * the translation is of THAT text.
     *
     * @return bool false when the plugin cannot do this (no string-translation component) or did
     *              not store the translation.
     */
    public function translate_option( string $option, string $key, string $original, string $lang, string $value ): bool;

    /**
     * The stored translations of ONE key of a site option: code → text. Empty when none, or when
     * the plugin has no string translation at all.
     *
     * @return array<string,string>
     */
    public function option_translations( string $option, string $key, string $original ): array;

    /**
     * The language a term belongs to, or '' when it belongs to none — which is what every term of
     * an untranslated taxonomy looks like.
     */
    public function term_language( int $term_id, string $taxonomy ): string;

    /**
     * The term its translation group calls the ORIGINAL — the one every other version was made
     * from — or the term itself when it has no group.
     *
     * NOT the site default language's version, which is a different thing that merely looks the
     * same most of the time: the original is fixed when the group is made, and the default language
     * moves whenever somebody changes it. Anything keyed on "the original term" and read back later
     * has to use this one, or its keys stop matching the day the site's language changes.
     */
    public function original_term_id( int $term_id, string $taxonomy ): int;

    /**
     * Give a term a version in one language, joined to the original so the two are one term in two
     * languages rather than two unrelated terms.
     *
     * Returns the new (or updated) term id, or 0 when the plugin refused. `$fields` takes `name`,
     * `slug`, `description` and `parent` — parent as the term id IN THE TARGET LANGUAGE, because
     * nothing translates a parent on the caller's behalf.
     *
     * @param array{name?: string, slug?: string, description?: string, parent?: int} $fields
     */
    public function translate_term( int $term_id, string $taxonomy, string $lang, array $fields ): int;

    /**
     * Drop the per-request memo of the language list.
     *
     * Reading the languages replaces the global `$wp_query` for the duration of the filter, so the
     * answer is normally taken once and kept. A caller that changes the site's languages in the
     * same request has to say so — the memo cannot know.
     */
    public function forget_languages(): void;

    /** Whether the plugin's string translation is present (WPML String Translation). */
    public function supports_strings(): bool;

    /**
     * Records of a TRANSLATABLE type that have no language at all — made before the type became
     * translatable (a form created before WPML Multilingual for CF7 was activated). The plugin hides
     * them from every query, its own screens included, so they exist and cannot be found.
     *
     * @return list<array{id:int,title:string}>
     */
    public function without_language( string $post_type ): array;

}
