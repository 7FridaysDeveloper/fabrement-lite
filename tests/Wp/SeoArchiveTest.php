<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Framework\Keys;
use Fabrement\Modules\PostType\ArchivePageStore;
use Fabrement\Modules\Seo\Bridge\NullSeoBridge;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Translation\Translation;
use WP_Error;
use WP_UnitTestCase;

/**
 * SEO through the bridge ({@see Seo::bridge()}), and the SEO of a post-type archive in the SEO tools:
 * set-seo / get-seo {archive} write and read the record that describes the archive — or, for a type
 * whose archive has no record, the site-wide values the SEO plugin keeps. set-archive-record keeps
 * the record's title and slug; set-seo-defaults no longer takes archive fields.
 */
final class SeoArchiveTest extends WP_UnitTestCase {

    use FakeLanguages;

    private const BOOK    = 'qa_sbook';
    private const FOREIGN = 'qa_sforeign';
    private const PAGED   = 'qa_spaged';

    private int $record = 0;

    /**
     * The paged listing's record, made ONCE: ArchiveContext keeps the answer for a type for the
     * whole process, as it does for a request, so a record made per test would be answered with
     * the first one.
     */
    private static int $paged = 0;

    public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ): void {
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            return;
        }
        self::register_paged();
        self::$paged = ArchivePageStore::ensure( self::PAGED, 'Talks', 'qa-talks' );
        $factory->post->create_many( 5, [ 'post_type' => self::PAGED ] );
        unregister_post_type( self::PAGED );
    }

    public static function wpTearDownAfterClass(): void {
        foreach ( get_posts( [ 'post_type' => [ self::PAGED, 'page' ], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
            if ( get_post_type( $id ) === self::PAGED || $id === self::$paged ) {
                wp_delete_post( $id, true );
            }
        }
    }

    private static function register_paged(): void {
        register_post_type( self::PAGED, [ 'public' => true, 'has_archive' => 'qa-talks', 'label' => 'Talks' ] );
    }

    public function set_up(): void {
        parent::set_up();
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Yoast SEO is not loaded in this run.' );
        }
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        Seo::set( null );
        register_post_type( self::BOOK, [ 'public' => true, 'has_archive' => 'qa-sbooks', 'label' => 'Books' ] );
        register_post_type( self::FOREIGN, [ 'public' => true, 'has_archive' => true, 'label' => 'Foreign' ] );
        // What the type had before its archive got a record: carried onto the record once.
        \WPSEO_Options::set( 'metadesc-ptarchive-' . self::BOOK, 'Every book we have' );
        $this->record = ArchivePageStore::ensure( self::BOOK, 'Books', 'qa-sbooks' );
        $this->assertGreaterThan( 0, $this->record );
        $this->assertSame( $this->record, ArchivePageStore::page_id( self::BOOK ), 'precondition: the archive has a published record' );
    }

    public function tear_down(): void {
        foreach ( [ 'title', 'metadesc', 'noindex' ] as $key ) {
            \WPSEO_Options::set( $key . '-ptarchive-' . self::BOOK, $key === 'noindex' ? false : '' );
            \WPSEO_Options::set( $key . '-ptarchive-' . self::FOREIGN, $key === 'noindex' ? false : '' );
        }
        \WPSEO_Options::clear_cache();
        $this->forget_languages();
        Translation::forget_languages();
        Seo::set( null );
        unregister_post_type( self::BOOK );
        unregister_post_type( self::FOREIGN );
        unregister_post_type( self::PAGED );
        parent::tear_down();
    }

    /** @return mixed */
    private function tool( string $name, array $input ) {
        return wp_get_ability( 'fabrement/' . $name )->execute( $input );
    }

    /**
     * The tool's own execute(), past the schema: `language` is in the schema only on a site that
     * was multilingual when the abilities were registered, and these tests switch languages later.
     *
     * @return mixed
     */
    private function direct( string $class, array $input ) {
        return ( new $class() )->execute( $input );
    }

    private function code( $result ): string {
        return $result instanceof WP_Error ? $result->get_error_code() : 'accepted';
    }

    public function test_the_archive_seo_is_the_seo_of_its_record(): void {
        $this->assertSame( 'Every book we have', Seo::bridge()->read_post( $this->record )['metaDesc'] ?? null, 'the type\'s archive description came along with the record' );

        $out = $this->tool( 'set-seo', [ 'archive' => self::BOOK, 'title' => 'All the books', 'noindex' => true, 'ogTitle' => 'Books to share' ] );
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );
        $this->assertSame( self::BOOK, $out['archive'] );
        $this->assertSame( $this->record, $out['contentId'] );
        $this->assertStringContainsString( 'drop out of search results', implode( ' ', $out['warnings'] ) );

        $stored = Seo::bridge()->read_post( $this->record );
        $this->assertSame( 'All the books', $stored['title'] );
        $this->assertTrue( $stored['noindex'] );
        $this->assertSame( 'Books to share', $stored['ogTitle'], 'every record field, not only three' );

        $read = $this->tool( 'get-seo', [ 'archive' => self::BOOK ] );
        $this->assertSame( $this->record, $read['archive']['pageId'] );
        $this->assertSame( 'All the books', ( (array) $read['archive']['stored'] )['title'] ?? null );
        $this->assertArrayHasKey( 'effective', $read['archive'] );
    }

    public function test_a_language_version_of_the_archive_takes_its_own_seo(): void {
        $this->use_languages();
        Translation::forget_languages();
        $this->in_language( $this->record, 'en' );

        $missing = $this->direct( \Fabrement\Modules\Mcp\Ability\SetSeoAbility::class, [ 'archive' => self::BOOK, 'language' => 'pl', 'title' => 'Książki' ] );
        $this->assertSame( 'fabrement_language_missing', $this->code( $missing ), 'never the original under a Polish label' );
        $this->assertArrayNotHasKey( 'title', Seo::bridge()->read_post( $this->record ) );

        $polish = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Książki' ] );
        $this->link_version( $polish, $this->record, 'pl' );
        $out = $this->direct( \Fabrement\Modules\Mcp\Ability\SetSeoAbility::class, [ 'archive' => self::BOOK, 'language' => 'pl', 'title' => 'Wszystkie książki', 'noindex' => true ] );
        $this->assertSame( $polish, $out['contentId'] ?? null );
        $this->assertSame( 'Wszystkie książki', Seo::bridge()->read_post( $polish )['title'] ?? null );
        $this->assertArrayNotHasKey( 'noindex', Seo::bridge()->read_post( $this->record ), 'noindex on the Polish archive alone' );
        $this->assertSame( $polish, $this->direct( \Fabrement\Modules\Mcp\Ability\GetSeoAbility::class, [ 'archive' => self::BOOK, 'language' => 'pl' ] )['archive']['pageId'] );
    }

    public function test_an_archive_without_a_record_takes_the_site_wide_values(): void {
        $out = $this->tool( 'set-seo', [ 'archive' => self::FOREIGN, 'title' => 'Foreign things', 'noindex' => true ] );
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );
        $this->assertSame( [ 'title', 'noindex' ], $out['written'] );
        $this->assertStringContainsString( 'no record', implode( ' ', $out['warnings'] ) );
        \WPSEO_Options::clear_cache();
        $this->assertSame( 'Foreign things', \WPSEO_Options::get( 'title-ptarchive-' . self::FOREIGN ) );
        $this->assertTrue( (bool) \WPSEO_Options::get( 'noindex-ptarchive-' . self::FOREIGN ) );

        $read = $this->tool( 'get-seo', [ 'archive' => self::FOREIGN ] );
        $this->assertSame( 0, $read['archive']['pageId'] );
        $this->assertSame( 'Foreign things', ( (array) $read['archive']['stored'] )['title'] ?? null );
        $this->assertStringNotContainsString( 'language', $read['archive']['note'] . implode( ' ', $out['warnings'] ), 'one language here: nothing about languages' );

        $this->assertSame( 'not_for_archive', $this->code( $this->tool( 'set-seo', [ 'archive' => self::FOREIGN, 'canonical' => 'https://example.com/' ] ) ) );
        $this->use_languages();
        Translation::forget_languages();
        $this->assertSame( 'archive_needs_record', $this->code( $this->direct( \Fabrement\Modules\Mcp\Ability\SetSeoAbility::class, [ 'archive' => self::FOREIGN, 'language' => 'pl', 'title' => 'x' ] ) ), 'one value for every language' );
    }

    public function test_set_seo_defaults_names_only_what_it_takes(): void {
        $none = $this->tool( 'set-seo-defaults', [ 'site' => true ] );
        $this->assertSame( 'nothing_to_set', $this->code( $none ) );
        foreach ( [ 'companyLogo,', 'defaultOgImage,', 'breadcrumbsHome' ] as $not_taken ) {
            $this->assertStringNotContainsString( $not_taken, $none->get_error_message() . ',', 'a field the tool refuses is not offered: ' . $not_taken );
        }
        $this->assertStringContainsString( 'companyLogoId', $none->get_error_message() );
    }

    public function test_the_archive_is_named_by_a_type_that_has_one(): void {
        register_post_type( 'qa_snoarch', [ 'public' => true, 'label' => 'No archive' ] );
        try {
            $this->assertSame( 'fabrement_unknown_post_type', $this->code( $this->tool( 'set-seo', [ 'archive' => 'qa_nope', 'title' => 'x' ] ) ) );
            $this->assertSame( 'fabrement_no_archive', $this->code( $this->tool( 'set-seo', [ 'archive' => 'qa_snoarch', 'title' => 'x' ] ) ) );
            $this->assertSame( 'fabrement_no_archive', $this->code( $this->tool( 'get-seo', [ 'archive' => 'qa_snoarch' ] ) ) );
            $this->assertSame( 'one_target', $this->code( $this->tool( 'set-seo', [ 'archive' => self::BOOK, 'contentId' => $this->record, 'title' => 'x' ] ) ) );
            $this->assertSame( 'unknown_language', $this->code( $this->direct( \Fabrement\Modules\Mcp\Ability\SetSeoAbility::class, [ 'contentId' => $this->record, 'language' => 'pl', 'title' => 'x' ] ) ) );
        } finally {
            unregister_post_type( 'qa_snoarch' );
        }
    }

    public function test_without_an_seo_plugin_the_tools_say_so_and_store_nothing(): void {
        Seo::set( new NullSeoBridge() );
        $page = self::factory()->post->create( [ 'post_type' => 'page' ] );

        $this->assertSame( 'yoast_inactive', $this->code( $this->tool( 'set-seo', [ 'contentId' => $page, 'title' => 'x' ] ) ) );
        $this->assertSame( 'yoast_inactive', $this->code( $this->tool( 'set-seo', [ 'archive' => self::BOOK, 'title' => 'x' ] ) ) );
        $this->assertSame( 'yoast_inactive', $this->code( $this->tool( 'set-seo-defaults', [ 'site' => true, 'websiteName' => 'QA site' ] ) ) );
        $this->assertFalse( $this->tool( 'get-seo', [] )['yoast']['active'] );
        $this->assertSame( [], Seo::bridge()->read_post( $page ) );

        // No plugin to list page types: the schema takes any string instead of an empty enum.
        $schema = wp_get_ability( 'fabrement/set-seo' ) ? ( new \Fabrement\Modules\Mcp\Ability\SetSeoAbility() )->input_schema() : [];
        $this->assertArrayNotHasKey( 'enum', $schema['properties']['schemaPageType'] );
        $this->assertArrayNotHasKey( 'enum', $schema['properties']['robotsAdvanced']['items'] );
    }

    // ── the listing's pages, as Yoast presents its posts page ─────────────────────────────────

    /** Two talks a page, pretty permalinks: /qa-talks/, /qa-talks/page/2/, /qa-talks/page/3/. */
    private function paged_listing(): string {
        // WordPress adds a type's archive rules only when it is registered under pretty permalinks.
        $this->set_permalink_structure( '/%postname%/' );
        self::register_paged();
        flush_rewrite_rules( false );
        update_option( 'posts_per_page', 2 );
        $this->assertGreaterThan( 0, self::$paged, 'precondition: the listing has a record' );
        // Yoast keeps the record's permalink in its index; a site that changes its structure gets
        // it rebuilt by Yoast's permalink watcher, the test does it here.
        Seo::bridge()->reindex_post( self::$paged );

        return (string) get_post_type_archive_link( self::PAGED );
    }

    /** What Yoast prints in the <head> at $url, and the nodes of its graph by @type. */
    private function head_at( string $url ): array {
        $this->go_to( $url );
        YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class )->clear();
        ob_start();
        do_action( 'wpseo_head' );
        $html = (string) ob_get_clean();

        $tag = static function ( string $pattern ) use ( $html ): string {
            return preg_match( $pattern, $html, $m ) ? html_entity_decode( $m[1] ) : '';
        };
        $nodes = [];
        if ( preg_match( '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m ) ) {
            foreach ( (array) ( json_decode( $m[1], true )['@graph'] ?? [] ) as $node ) {
                $types = (array) ( $node['@type'] ?? [] );
                $nodes[ in_array( 'WebPage', $types, true ) ? 'WebPage' : (string) reset( $types ) ] = $node;
            }
        }

        return [
            'canonical' => $tag( '#<link rel="canonical" href="([^"]*)"#' ),
            'prev'      => $tag( '#<link rel="prev" href="([^"]*)"#' ),
            'next'      => $tag( '#<link rel="next" href="([^"]*)"#' ),
            'og_url'    => $tag( '#<meta property="og:url" content="([^"]*)"#' ),
            'nodes'     => $nodes,
        ];
    }

    public function test_the_listing_pages_like_the_posts_page(): void {
        $first = $this->paged_listing();
        $this->assertSame( home_url( '/qa-talks/' ), $first );
        $page2 = $first . 'page/2/';

        $head = $this->head_at( $page2 );
        $this->assertSame( $page2, $head['canonical'], 'page 2 is its own page, not a copy of page 1' );
        $this->assertSame( $first, $head['prev'], 'back to the bare listing, never /page/1/' );
        $this->assertSame( $first . 'page/3/', $head['next'] );
        $this->assertSame( $first, $head['og_url'], 'what is shared is the listing' );

        $webpage = $head['nodes']['WebPage'] ?? [];
        $this->assertContains( 'CollectionPage', (array) ( $webpage['@type'] ?? [] ) );
        $this->assertSame( $first, $webpage['@id'] ?? null, 'one entity for every page of the listing' );
        $this->assertSame( $page2, $webpage['url'] ?? null );

        $crumbs = $head['nodes']['BreadcrumbList']['itemListElement'] ?? [];
        $this->assertSame( [ 'Talks' ], array_slice( array_column( $crumbs, 'name' ), -1 ), 'page 2 keeps its trail, ending on the listing' );

        $last = $this->head_at( $first . 'page/3/' );
        $this->assertSame( $page2, $last['prev'] );
        $this->assertSame( '', $last['next'], 'no page 4' );

        $top = $this->head_at( $first );
        $this->assertSame( $first, $top['canonical'] );
        $this->assertSame( '', $top['prev'] );
        $this->assertSame( $page2, $top['next'] );
    }

    public function test_the_preview_of_the_record_types_it_as_its_page_does(): void {
        $this->paged_listing();
        $graph   = \Fabrement\Modules\Seo\Schema\SchemaOutput::preview( self::$paged )['graph'];
        $webpage = current( array_filter( $graph, static fn( $n ) => is_array( $n ) && in_array( 'WebPage', (array) ( $n['@type'] ?? [] ), true ) ) );
        $this->assertContains( 'CollectionPage', (array) ( $webpage['@type'] ?? [] ), 'get-seo shows what the archive prints, outside its request' );

        $plain = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $graph = \Fabrement\Modules\Seo\Schema\SchemaOutput::preview( $plain )['graph'];
        $node  = current( array_filter( $graph, static fn( $n ) => is_array( $n ) && in_array( 'WebPage', (array) ( $n['@type'] ?? [] ), true ) ) );
        $this->assertNotContains( 'CollectionPage', (array) ( $node['@type'] ?? [] ), 'an ordinary page stays a page' );
    }

    public function test_only_the_pages_own_node_becomes_a_collection_page(): void {
        $this->paged_listing();
        update_post_meta( self::$paged, Keys::META_SCHEMA, wp_slash( (string) wp_json_encode( [
            [ '@type' => 'WebPage', '@id' => '{{url}}#section', 'name' => 'A section' ],
        ] ) ) );
        $graph = \Fabrement\Modules\Seo\Schema\SchemaOutput::preview( self::$paged )['graph'];
        $types = [];
        foreach ( $graph as $node ) {
            if ( is_array( $node ) && isset( $node['@id'] ) ) {
                $types[ (string) $node['@id'] ] = (array) ( $node['@type'] ?? [] );
            }
        }
        $page = (string) get_post_type_archive_link( self::PAGED );
        $this->assertContains( 'CollectionPage', $types[ $page ] ?? [], 'the page Yoast describes' );
        $this->assertSame( [ 'WebPage' ], $types[ $page . '#section' ] ?? null, 'a node the agent added keeps the type it was given' );
    }

    public function test_a_canonical_set_on_the_record_holds_on_every_page(): void {
        $first = $this->paged_listing();
        $this->assertIsArray( $this->tool( 'set-seo', [ 'archive' => self::PAGED, 'canonical' => 'https://example.com/talks/' ] ) );

        $this->assertSame( 'https://example.com/talks/', $this->head_at( $first )['canonical'] );
        $this->assertSame( 'https://example.com/talks/', $this->head_at( $first . 'page/2/' )['canonical'] );
    }

    public function test_rel_prev_next_follow_the_sites_switch(): void {
        $first = $this->paged_listing();
        add_filter( 'wpseo_disable_adjacent_rel_links', '__return_true' );

        $head = $this->head_at( $first . 'page/2/' );
        $this->assertSame( '', $head['prev'] );
        $this->assertSame( '', $head['next'] );
        $this->assertSame( $first . 'page/2/', $head['canonical'] );
    }

    public function test_without_pretty_permalinks_the_archive_keeps_its_own_address(): void {
        self::register_paged();
        $this->set_permalink_structure( '' );

        $link = (string) get_post_type_archive_link( self::PAGED );
        $this->assertSame( home_url( '/?post_type=' . self::PAGED ), $link, 'the record is ?page_id=N here — another page, which shows no listing' );

        $head = $this->head_at( $link );
        $this->assertTrue( is_post_type_archive( self::PAGED ) );
        $this->assertStringNotContainsString( 'page_id', $head['canonical'], 'Yoast describes the archive itself' );
    }

    // ── the sitemap ──────────────────────────────────────────────────────────────────────────

    /** @return list<string> The addresses Yoast's sitemap lists for a post type. */
    private function sitemap( string $type ): array {
        $provider = new \WPSEO_Post_Type_Sitemap_Provider();

        return array_map( static fn( array $link ): string => (string) $link['loc'], $provider->get_sitemap_links( $type, 1000, 1 ) );
    }

    public function test_the_sitemap_lists_the_archive_once_as_its_types(): void {
        $first = $this->paged_listing();

        $this->assertNotContains( $first, $this->sitemap( 'page' ), 'the record is its archive, not a page of its own' );
        $talks = $this->sitemap( self::PAGED );
        $this->assertSame( $first, $talks[0] ?? null, 'Yoast lists the archive first in its type\'s sitemap' );
        $this->assertCount( 1, array_keys( $talks, $first, true ) );
    }

    public function test_a_noindex_record_takes_its_archive_out_of_the_sitemap(): void {
        $first = $this->paged_listing();
        $this->assertIsArray( $this->tool( 'set-seo', [ 'archive' => self::PAGED, 'noindex' => true ] ) );

        $this->assertNotContains( $first, $this->sitemap( self::PAGED ) );
        $this->assertNotContains( $first, $this->sitemap( 'page' ) );
        $this->assertGreaterThan( 0, count( $this->sitemap( self::PAGED ) ), 'the talks themselves stay' );
    }

    public function test_without_pretty_permalinks_the_record_is_a_page_in_the_sitemap(): void {
        self::register_paged();
        $this->set_permalink_structure( '' );

        $this->assertContains( (string) get_permalink( self::$paged ), $this->sitemap( 'page' ) );
    }
}
