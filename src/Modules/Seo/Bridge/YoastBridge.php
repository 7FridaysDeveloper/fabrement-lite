<?php

namespace Fabrement\Modules\Seo\Bridge;

use Fabrement\Modules\Seo\Bridge\Yoast\Indexables;
use Fabrement\Modules\Seo\Bridge\Yoast\Options;
use Fabrement\Modules\Seo\Bridge\Yoast\PostMeta;
use Fabrement\Modules\Seo\Bridge\Yoast\TermMeta;
use Fabrement\Modules\Seo\Contract\SeoBridge;
use Fabrement\Modules\Translation\Translation;

/**
 * The one place that knows how Yoast SEO stores things — the {@see SeoBridge} for Yoast.
 *
 * Yoast keeps per-post SEO in post meta under the `_yoast_wpseo_` prefix, per-term SEO in the
 * `wpseo_taxonomy_meta` option, per-type and site-wide defaults and the organisation ("knowledge
 * graph") in `wpseo_titles`, and derives what it prints into its own index (indexables). None of
 * that is a public API; it is stable across many major versions, but it is still storage, so every
 * key name lives in this bridge and nowhere else — an ability asks for "metaDesc", not for
 * "_yoast_wpseo_metadesc".
 *
 * One class per way Yoast keeps things, in `Yoast/`: {@see PostMeta}, {@see TermMeta},
 * {@see Options}, and {@see Indexables} for its runtime. This facade is the contract the tools see;
 * it keeps the vocabulary and the questions that need no storage, and says when Yoast must be
 * running for an answer.
 *
 * Writes go through Yoast's own setters when it is loaded (`WPSEO_Meta::set_value`,
 * `WPSEO_Options::set`): they validate and, for meta, wake the indexable watcher that rebuilds the
 * indexable row the front end actually reads. Without Yoast, post meta is written raw — it is
 * picked up the moment Yoast is activated — but option defaults are REFUSED: there is no
 * `wpseo_titles` to merge into and a half-built one would be overwritten by Yoast's installer.
 */
final class YoastBridge implements SeoBridge {

    /** Yoast's own list (Schema tab of the metabox / post-type defaults). */
    private const PAGE_TYPES = [
        'WebPage', 'ItemPage', 'AboutPage', 'FAQPage', 'QAPage', 'ProfilePage', 'ContactPage',
        'MedicalWebPage', 'CollectionPage', 'CheckoutPage', 'RealEstateListing', 'SearchResultsPage',
    ];

    /** "None" means: do not add an Article node for this post / type. */
    private const ARTICLE_TYPES = [
        'None', 'Article', 'BlogPosting', 'SocialMediaPosting', 'NewsArticle', 'AdvertiserContentArticle',
        'SatiricalArticle', 'ScholarlyArticle', 'TechArticle', 'Report',
    ];

    public function is_active(): bool {
        return defined( 'WPSEO_VERSION' );
    }

    public function version(): string {
        return defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : '';
    }

    /** Yoast's main plugin file, as WordPress names it among the installed plugins. */
    public const PLUGIN_FILE = 'wordpress-seo/wp-seo.php';

    /** Whether Yoast is on disk, active or not. */
    public static function installed(): bool {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return isset( get_plugins()[ self::PLUGIN_FILE ] );
    }

    // ── vocabulary ──────────────────────────────────────────────────────────────────────────

    public function term_fields(): array {
        return TermMeta::fields();
    }

    public function page_types(): array {
        return self::PAGE_TYPES;
    }

    /** Yoast's article types as the site has them: its filter can take some away or add its own. */
    public function article_types(): array {
        if ( ! class_exists( '\Yoast\WP\SEO\Config\Schema_Types' ) ) {
            return self::ARTICLE_TYPES;
        }
        $types = apply_filters( 'wpseo_schema_article_types', \Yoast\WP\SEO\Config\Schema_Types::ARTICLE_TYPES ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast's own filter, read so the tool offers what Yoast accepts.
        return is_array( $types ) && $types !== [] ? array_values( array_map( 'strval', array_keys( $types ) ) ) : self::ARTICLE_TYPES;
    }

    public function robots_advanced(): array {
        return PostMeta::ROBOTS_ADVANCED;
    }

    public function separators(): array {
        return Options::separators();
    }

    public function has_variables( string $text ): bool {
        return strpos( $text, '%%' ) !== false;
    }

    public function default_fields( string $scope ): array {
        return Options::fields( $scope );
    }

    public function translatable_fields( string $scope ): array {
        return Options::translatable( $scope );
    }

    // ── a record ────────────────────────────────────────────────────────────────────────────

    public function read_post( int $post_id ): array {
        return PostMeta::read( $post_id );
    }

    public function write_post( int $post_id, array $fields ): array {
        return PostMeta::write( $post_id, $fields );
    }

    public function effective_post( int $post_id, bool $with_schema = true ): array {
        return $this->is_active()
            ? Translation::in_context( Translation::live_language_of( $post_id ), static fn(): array => Indexables::effective_post( $post_id, $with_schema ) )
            : [];
    }

    public function reindex_post( int $post_id ): bool {
        return $this->is_active()
            && Translation::in_context( Translation::live_language_of( $post_id ), static fn(): bool => Indexables::reindex_post( $post_id ) );
    }

    public function post_listing( array $post_types ): array {
        if ( ! $this->is_active() ) {
            return [];
        }
        $stored = PostMeta::read_many( array_keys( $post_types ), [ 'title', 'metaDesc', 'noindex', 'canonical' ] );
        $out    = [];
        foreach ( $post_types as $id => $type ) {
            $out[ (int) $id ] = self::listing( $stored[ (int) $id ] ?? [], 'postType', (string) $type );
        }
        return $out;
    }

    // ── the schema graph ────────────────────────────────────────────────────────────────────

    public function schema_values( int $post_id, $context = null ): array {
        if ( ! $this->is_active() ) {
            return [];
        }
        // During frontend generation Yoast supplies the authoritative page context. Standalone
        // callers have only an id; compute all lazy token values in that record's language.
        return is_object( $context )
            ? Indexables::schema_values( $post_id, $context )
            : Translation::in_context( Translation::live_language_of( $post_id ), static fn(): array => Indexables::schema_values( $post_id, null ) );
    }

    /**
     * The nodes Yoast prints itself (src/config/schema-ids.php): the page's WebPage (its URL), the
     * page's breadcrumb / primary image / article, the site's WebSite / Organization, a person or
     * logo (`#/schema/…`).
     */
    public function is_own_schema_id( string $id, string $page, string $site ): bool {
        if ( $id === '' ) {
            return false;
        }
        foreach ( [ '', '#breadcrumb', '#primaryimage', '#article' ] as $hash ) {
            if ( $page !== '' && $id === $page . $hash ) {
                return true;
            }
        }
        foreach ( [ '#website', '#organization' ] as $hash ) {
            if ( $site !== '' && $id === $site . $hash ) {
                return true;
            }
        }
        return $site !== '' && strpos( $id, $site . '#/schema/' ) === 0;
    }

    /**
     * Yoast outputs an Article node only for a post type that supports `author`
     * (Article_Helper::is_author_supported); an article type set on any other type does nothing.
     */
    public function article_supported( string $post_type ): bool {
        return post_type_supports( $post_type, 'author' );
    }

    // ── a record's main term ────────────────────────────────────────────────────────────────

    /** The hierarchical taxonomies of the type, through Yoast's own filter — what its metabox offers. */
    public function primary_taxonomies( string $post_type ): array {
        // Exactly what Yoast passes (Primary_Term_Helper): the hierarchical taxonomy objects keyed
        // by name, twice — callbacks written for Yoast unset keys or read ->name.
        $out = array_filter( get_object_taxonomies( $post_type, 'objects' ), static fn( $taxonomy ): bool => (bool) $taxonomy->hierarchical );
        $out = apply_filters( 'wpseo_primary_term_taxonomies', $out, $post_type, $out ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast's own filter, read so we offer what Yoast shows.

        $names = [];
        foreach ( (array) $out as $key => $taxonomy ) {
            $names[] = is_object( $taxonomy ) && isset( $taxonomy->name ) ? (string) $taxonomy->name : (string) $key;
        }
        return array_values( array_filter( $names ) );
    }

    public function not_kept_post( string $post_type, array $fields ): array {
        return $this->is_active() ? PostMeta::not_kept( $post_type, $fields ) : [];
    }

    public function not_kept_term( \WP_Term $term, array $fields ): array {
        return $this->is_active() && TermMeta::supported() ? TermMeta::not_kept( $term, $fields ) : [];
    }

    public function not_kept_defaults( array $fields ): array {
        return $this->is_active() ? Options::not_kept( $fields ) : [];
    }

    public function breadcrumb_taxonomy( string $post_type ): string {
        return $this->is_active() ? Options::breadcrumb_taxonomy( $post_type ) : '';
    }

    public function primary_term( int $post_id, string $taxonomy ): int {
        return Translation::in_context( Translation::live_language_of( $post_id ), static fn(): int => PostMeta::primary( $post_id, $taxonomy ) );
    }

    public function set_primary_term( int $post_id, string $taxonomy, int $term_id ): void {
        PostMeta::set_primary( $post_id, $taxonomy, $term_id );
    }

    public function rebuild_primary_terms( int $post_id ): void {
        if ( $this->is_active() ) {
            Indexables::rebuild_primary_terms( $post_id );
        }
    }

    // ── a term ──────────────────────────────────────────────────────────────────────────────

    public function terms_supported(): bool {
        return TermMeta::supported();
    }

    public function read_term( \WP_Term $term ): array {
        return TermMeta::supported() ? TermMeta::read( $term ) : [];
    }

    public function write_term( \WP_Term $term, array $fields ): array {
        return TermMeta::supported() ? TermMeta::write( $term, $fields ) : [];
    }

    public function effective_term( int $term_id ): array {
        $term = \WP_Term::get_instance( $term_id );
        if ( ! $this->is_active() || ! $term instanceof \WP_Term ) {
            return [];
        }
        $language = Translation::bridge()->term_language( $term_id, (string) $term->taxonomy );
        return Translation::in_context( $language, static fn(): array => Indexables::effective_term( $term_id ) );
    }

    public function term_listing( array $terms ): array {
        if ( ! $this->is_active() || ! TermMeta::supported() ) {
            return [];
        }
        $stored = TermMeta::read_many( $terms );
        $out    = [];
        foreach ( $terms as $term ) {
            $out[ (int) $term->term_id ] = self::listing( $stored[ (int) $term->term_id ] ?? [], 'taxonomy', (string) $term->taxonomy );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $stored
     * @return array{seo: array{title:string, metaDesc:string, noindex:bool, canonical:string}, texts: array{title:string, metaDesc:string}}
     */
    private static function listing( array $stored, string $scope, string $slug ): array {
        return [
            'seo'   => Options::summary( $stored, $scope, $slug ),
            'texts' => [ 'title' => (string) ( $stored['title'] ?? '' ), 'metaDesc' => (string) ( $stored['metaDesc'] ?? '' ) ],
        ];
    }

    // ── defaults ────────────────────────────────────────────────────────────────────────────

    public function read_defaults( string $scope, string $slug = '' ): array {
        return $this->is_active() ? Options::read( $scope, $slug ) : [];
    }

    public function write_defaults( string $scope, string $slug, array $fields ): array {
        return Options::write( $scope, $slug, $fields );
    }

    public function knowledge_graph(): ?array {
        return $this->is_active() ? Options::knowledge_graph() : null;
    }

    public function read_defaults_translations( string $scope, string $slug = '' ): array {
        return $this->is_active() ? Options::translations( $scope, $slug ) : [];
    }

    public function write_defaults_translation( string $scope, string $slug, string $lang, array $fields ): array {
        return Options::write_translation( $scope, $slug, $lang, $fields );
    }

    // ── the archive of a post type ───────────────────────────────────────────────────────────

    public function read_archive_defaults( string $post_type ): array {
        return $this->is_active() ? Options::read_archive( $post_type ) : [];
    }

    public function write_archive_defaults( string $post_type, array $fields ): array {
        return Options::write_archive( $post_type, $fields );
    }
}
