<?php

namespace Fabrement\Modules\Seo\Contract;

/**
 * The SEO plugin's STORED data, in Fabrement's words — what the SEO tools read and write.
 *
 * The same split as translations ({@see \Fabrement\Modules\Translation\Contract\TranslationBridge}):
 * the tools speak one vocabulary ("title", "metaDesc", "noindex", "schemaPageType"), and a bridge
 * knows where one plugin keeps each of them. Yoast is the first ({@see \Fabrement\Modules\Seo\Bridge\YoastBridge});
 * a site with no supported SEO plugin gets {@see \Fabrement\Modules\Seo\Bridge\NullSeoBridge}, which
 * stores nothing and says so. Reached through {@see \Fabrement\Modules\Seo\Seo::bridge()}.
 *
 * The page OUTPUT side — hooks into the plugin's rendering, sitemaps, schema graph — is
 * {@see SeoIntegration}. A new plugin is one of each.
 *
 * Field values: `null` or "" clears a field on write. Reads list only fields that hold something.
 * Scopes of defaults: 'postType' and 'taxonomy' (with a slug) and 'site'.
 */
interface SeoBridge {

    /** Is the plugin running, so that what is stored reaches the page? */
    public function is_active(): bool;

    /** The plugin's version, '' when it is not running. */
    public function version(): string;

    // ── vocabulary ──────────────────────────────────────────────────────────────────────────

    /** @return list<string> The fields a term takes. */
    public function term_fields(): array;

    /** @return list<string> Page types a record or a post type can declare for its schema. */
    public function page_types(): array;

    /** @return list<string> Article types; 'None' = no article node. */
    public function article_types(): array;

    /** @return list<string> Values of the `robotsAdvanced` list. */
    public function robots_advanced(): array;

    /** @return list<string> Codes of the title separators the plugin offers. */
    public function separators(): array;

    /** Whether a text holds the plugin's variables, so its printed length is not its own. */
    public function has_variables( string $text ): bool;

    /** @return list<string> The default fields a scope takes. */
    public function default_fields( string $scope ): array;

    /** @return list<string> Of those, the ones that hold text per language. */
    public function translatable_fields( string $scope ): array;

    // ── a record ────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function read_post( int $post_id ): array;

    /**
     * @param array<string,mixed> $fields
     * @return list<string> the fields written
     */
    public function write_post( int $post_id, array $fields ): array;

    /**
     * What the plugin will actually print for the record — title, description, canonical, robots,
     * social — and, with `$with_schema`, its schema graph. Empty when it cannot tell.
     * Computed in the record's language, restoring the caller's language and locale afterwards.
     *
     * @return array<string,mixed>
     */
    public function effective_post( int $post_id, bool $with_schema = true ): array;

    /** Bring the plugin's own index up to date with the record now, so a read-back is exact. */
    public function reindex_post( int $post_id ): bool;

    /**
     * How MANY records show in search, in short, with the texts set on each — for a listing: one
     * read for all of them, not one per record. Per record: where its title and its description
     * come from ('own' — set on it; 'template' — its type's; 'none'), whether it is kept out of
     * search (its own setting, else its type's) and the canonical set on it ('' = its own
     * address). [] without a plugin.
     *
     * @param array<int,string> $post_types record id → its post type (the template it falls back to)
     * @return array<int, array{seo: array{title:string, metaDesc:string, noindex:bool, canonical:string}, texts: array{title:string, metaDesc:string}}>
     */
    public function post_listing( array $post_types ): array;

    // ── the plugin's schema graph ───────────────────────────────────────────────────────────

    /**
     * What the page-level schema tokens stand for on record `$post_id`, as the plugin itself puts
     * them in its graph: url (its WebPage id), site_url (its WebSite / Organization base), title
     * (the record's name), excerpt (its description), featured_image, date_published and
     * date_modified (ISO 8601). `$context` is the plugin's own page context when the caller has it
     * (a piece of its graph); otherwise it is built in the record's language. [] without a plugin.
     *
     * @param mixed $context
     * @return array<string,string>
     */
    public function schema_values( int $post_id, $context = null ): array;

    /** Whether `$id` names a node the plugin prints itself — for page `$page` of site `$site`. */
    public function is_own_schema_id( string $id, string $page, string $site ): bool;

    /** Whether an article type set on this post type would print anything. */
    public function article_supported( string $post_type ): bool;

    // ── a record's main term per hierarchical taxonomy (breadcrumbs, category URLs) ─────────

    /** @return list<string> The taxonomies of a type whose records can have one. */
    public function primary_taxonomies( string $post_type ): array;

    /** The taxonomy a record's breadcrumb trail runs through; '' when the trail names no term. */
    public function breadcrumb_taxonomy( string $post_type ): string;

    /**
     * Fields the plugin would not keep as sent — its own cleaning run without writing — each with
     * what it would store instead. One call per store: a record's fields, a term's, or defaults.
     *
     * @param array<string,mixed> $fields
     * @return array<string,string>
     */
    public function not_kept_post( string $post_type, array $fields ): array;

    /**
     * @param array<string,mixed> $fields
     * @return array<string,string>
     */
    public function not_kept_term( \WP_Term $term, array $fields ): array;

    /**
     * Defaults of any scope, and an archive's site-wide values.
     *
     * @param array<string,mixed> $fields
     * @return array<string,string>
     */
    public function not_kept_defaults( array $fields ): array;

    /** The stored main term id, 0 when none — whether the record still holds it is not checked. */
    public function primary_term( int $post_id, string $taxonomy ): int;

    /** Store the main term; 0 clears it. */
    public function set_primary_term( int $post_id, string $taxonomy, int $term_id ): void;

    /** A record's main terms changed: bring what the plugin derives from them up to date. */
    public function rebuild_primary_terms( int $post_id ): void;

    // ── a term ──────────────────────────────────────────────────────────────────────────────

    /** Whether this plugin version can have term SEO written from here. */
    public function terms_supported(): bool;

    /** @return array<string,mixed> */
    public function read_term( \WP_Term $term ): array;

    /**
     * @param array<string,mixed> $fields
     * @return list<string> the fields written
     */
    public function write_term( \WP_Term $term, array $fields ): array;

    /** @return array<string,mixed> */
    public function effective_term( int $term_id ): array;

    /**
     * How MANY terms' archive pages show in search, in short (as {@see post_listing()}), with the
     * texts set on each. [] without a plugin, or when this plugin version's term store cannot
     * be read.
     *
     * @param list<\WP_Term> $terms
     * @return array<int, array{seo: array{title:string, metaDesc:string, noindex:bool, canonical:string}, texts: array{title:string, metaDesc:string}}>
     */
    public function term_listing( array $terms ): array;

    // ── defaults ────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function read_defaults( string $scope, string $slug = '' ): array;

    /**
     * @param array<string,mixed> $fields
     * @return list<string> the fields written
     */
    public function write_defaults( string $scope, string $slug, array $fields ): array;

    /**
     * The node the site setting asks for (Organization or Person), the fields it still lacks before
     * the plugin prints it, and those the plugin fills from elsewhere with where from:
     * companyName ← websiteName | siteTitle | filter, companyLogoId ← siteLogo | filter.
     * Null when the node is printed from the setting alone, or none is asked for.
     *
     * @return array{node:string, missing:list<string>, borrowed:array<string,string>}|null
     */
    public function knowledge_graph(): ?array;

    /** @return array<string,array<string,string>> language → { field → text } */
    public function read_defaults_translations( string $scope, string $slug = '' ): array;

    /**
     * @param array<string,string> $fields
     * @return array{written:list<string>, emptyOriginal:list<string>}
     */
    public function write_defaults_translation( string $scope, string $slug, string $lang, array $fields ): array;

    // ── the archive of a post type ───────────────────────────────────────────────────────────

    /**
     * The archive's title / metaDesc / noindex as the plugin keeps them — one value for the whole
     * site.
     *
     * @return array<string,mixed>
     */
    public function read_archive_defaults( string $post_type ): array;

    /**
     * @param array<string,mixed> $fields title, metaDesc, noindex
     * @return list<string> the fields written
     */
    public function write_archive_defaults( string $post_type, array $fields ): array;
}
