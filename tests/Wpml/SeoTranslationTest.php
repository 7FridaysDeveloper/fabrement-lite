<?php

namespace Fabrement\Tests\Wpml;

use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\PostType\ArchivePageStore;
use Fabrement\Modules\Seo\Content\ContentImages;
use Fabrement\Modules\Seo\PrimaryTerm;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * SEO across languages on the real WPML with Yoast SEO and Yoast SEO Multilingual: what a language
 * version gets and keeps, per-language defaults through String Translation, term SEO and the main
 * category in the right language, an archive's SEO per language, and images from a global block's
 * version in the page's language.
 */
final class SeoTranslationTest extends WpmlTestCase {

    public function tear_down(): void {
        if ( $owner = Owner::of( 'post', 'page' ) ) {
            SchemaRepository::delete( $owner );
        }
        SchemaRepository::flush();
        ContentImages::flush();
        \WPSEO_Options::clear_cache();
        parent::tear_down();
    }

    private function page( array $args = [] ): int {
        return self::factory()->post->create( $args + [ 'post_type' => 'page', 'post_status' => 'publish' ] );
    }

    private function translate( int $id, string $lang ): int {
        $out = $this->tool( 'translate-content', [ 'contentId' => $id, 'language' => $lang ] );
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );
        return (int) $out['contentId'];
    }

    private function code( $result ): string {
        return $result instanceof WP_Error ? $result->get_error_code() : 'accepted';
    }

    public function test_a_version_gets_yoasts_fields_as_wpml_config_says_and_fabrements_by_policy(): void {
        $saved = $this->tool( 'save-fields', [ 'type' => 'post', 'slug' => 'page', 'expectedRevision' => 0, 'fields' => [
            [ 'name' => 'price', 'label' => 'Price', 'description' => 'Price', 'type' => 'number', 'format' => 'decimal' ],
        ] ] );
        $this->assertIsArray( $saved );
        $page = $this->page( [ 'post_title' => 'About us' ] );
        fabrement()->fields( $page )->set( 'price', 12.5 )->save();
        foreach ( [
            '_yoast_wpseo_title'               => 'About — EN',
            '_yoast_wpseo_metadesc'            => 'English description',
            '_yoast_wpseo_canonical'           => 'https://example.com/en-only/',
            '_yoast_wpseo_meta-robots-noindex' => '1',
            '_yoast_wpseo_schema_page_type'    => 'AboutPage',
        ] as $key => $value ) {
            update_post_meta( $page, $key, $value );
        }

        $fr = $this->translate( $page, 'fr' );

        $this->assertSame( [ '1' ], get_post_meta( $fr, '_yoast_wpseo_meta-robots-noindex' ), 'copy — once' );
        $this->assertSame( [ 'AboutPage' ], get_post_meta( $fr, '_yoast_wpseo_schema_page_type' ), 'copy — once' );
        $this->assertSame( [], get_post_meta( $fr, '_yoast_wpseo_title' ), 'translate: Yoast\'s French template applies' );
        $this->assertSame( [], get_post_meta( $fr, '_yoast_wpseo_metadesc' ) );
        $this->assertSame( [], get_post_meta( $fr, '_yoast_wpseo_canonical' ), 'ignore: never the English address' );
        $this->assertEquals( 12.5, fabrement_get_field( 'price', $fr ), 'a Fabrement copy field' );

        wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish', 'post_name' => 'a-propos' ] );
        $this->assertSame( 'http://example.org/fr/a-propos/', Translation::bridge()->permalink( $fr, 'fr' ) );
    }

    public function test_the_main_category_goes_to_the_version_in_its_language(): void {
        $news = self::factory()->category->create( [ 'name' => 'News' ] );
        $this->tool( 'translate-term', [ 'termId' => $news, 'language' => 'fr', 'name' => 'Actualités', 'slug' => 'actualites' ] );
        $fr_news = (int) apply_filters( 'wpml_object_id', $news, 'category', false, 'fr' );
        $post    = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        $set     = $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $news ], 'mode' => 'replace', 'primary' => $news ] );
        $this->assertIsArray( $set, $set instanceof WP_Error ? $set->get_error_message() : '' );

        $fr = $this->translate( $post, 'fr' );

        $this->assertSame( (string) $fr_news, (string) get_post_meta( $fr, '_yoast_wpseo_primary_category', true ), 'Yoast SEO Multilingual maps it to the French category' );
        $this->assertSame( $fr_news, PrimaryTerm::get( $fr, 'category' ), 'and the version holds it, in its own language' );
    }

    public function test_seo_set_on_a_version_is_the_versions_and_get_seo_lists_the_languages(): void {
        $page = $this->page();
        $fr   = $this->translate( $page, 'fr' );

        $out = $this->tool( 'set-seo', [ 'contentId' => $fr, 'title' => 'À propos', 'metaDesc' => 'Description française' ] );
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );
        $this->assertSame( 'À propos', Seo::bridge()->read_post( $fr )['title'] ?? null );
        $this->assertArrayNotHasKey( 'title', Seo::bridge()->read_post( $page ), 'the original is untouched' );

        $read = $this->tool( 'get-seo', [ 'contentId' => $page ] )['content'];
        $this->assertSame( [ 'pl' ], array_values( $read['missingLanguages'] ) );
        $this->assertSame( 'translated', ( (array) $read['translationsSeo'] )['fr']['title'] ?? null, 'the version has words of its own' );
    }

    public function test_type_defaults_have_a_version_per_language_through_string_translation(): void {
        $en = $this->tool( 'set-seo-defaults', [ 'postType' => 'page', 'titleTemplate' => '%%title%% %%sep%% Studio' ] );
        $this->assertIsArray( $en, $en instanceof WP_Error ? $en->get_error_message() : '' );
        $fr = ( new \Fabrement\Modules\Mcp\Ability\SetSeoDefaultsAbility() )->execute( [ 'postType' => 'page', 'language' => 'fr', 'titleTemplate' => '%%title%% %%sep%% Atelier' ] );
        $this->assertIsArray( $fr, $fr instanceof WP_Error ? $fr->get_error_message() : '' );
        $this->assertSame( [ 'titleTemplate' ], $fr['written'] );

        $this->assertSame( '%%title%% %%sep%% Atelier', Seo::bridge()->read_defaults_translations( 'postType', 'page' )['fr']['titleTemplate'] ?? null );
        $this->assertSame( '%%title%% %%sep%% Studio', Seo::bridge()->read_defaults( 'postType', 'page' )['titleTemplate'] ?? null, 'the original stays' );
        $this->assertSame( 'shared_field', $this->code( ( new \Fabrement\Modules\Mcp\Ability\SetSeoDefaultsAbility() )->execute( [ 'postType' => 'page', 'language' => 'fr', 'noindex' => true ] ) ), 'a flag is one value for every language' );
    }

    public function test_term_seo_is_written_on_the_term_asked_for(): void {
        $news = self::factory()->category->create( [ 'name' => 'News' ] );
        $this->tool( 'translate-term', [ 'termId' => $news, 'language' => 'fr', 'name' => 'Actualités', 'slug' => 'actualites' ] );
        $fr_news = (int) apply_filters( 'wpml_object_id', $news, 'category', false, 'fr' );

        $out = $this->tool( 'set-seo', [ 'termId' => $fr_news, 'title' => 'Toutes les actualités' ] ); // the request is in English
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );

        $this->assertSame( 'Toutes les actualités', Seo::bridge()->read_term( \WP_Term::get_instance( $fr_news ) )['title'] ?? null );
        $this->assertArrayNotHasKey( 'title', Seo::bridge()->read_term( \WP_Term::get_instance( $news ) ), 'not the English one' );
        $this->assertSame( 'Toutes les actualités', ( (array) $this->tool( 'get-seo', [ 'termId' => $fr_news ] )['term']['stored'] )['title'] ?? null );
    }

    public function test_an_archive_has_seo_per_language(): void {
        register_post_type( 'qa_wbook', [ 'public' => true, 'has_archive' => 'qa-wbooks', 'label' => 'Books' ] );
        try {
            $record = ArchivePageStore::ensure( 'qa_wbook', 'Books', 'qa-wbooks' );
            $this->assertGreaterThan( 0, $record );
            $set_seo = new \Fabrement\Modules\Mcp\Ability\SetSeoAbility();

            $this->assertSame( 'fabrement_language_missing', $this->code( $set_seo->execute( [ 'archive' => 'qa_wbook', 'language' => 'fr', 'title' => 'Livres' ] ) ) );
            $fr = $this->translate( $record, 'fr' );
            wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish' ] );

            $out = $set_seo->execute( [ 'archive' => 'qa_wbook', 'language' => 'fr', 'title' => 'Tous les livres', 'noindex' => true ] );
            $this->assertSame( $fr, $out['contentId'] ?? null, $out instanceof WP_Error ? $out->get_error_message() : '' );
            $this->assertSame( 'Tous les livres', Seo::bridge()->read_post( $fr )['title'] ?? null );
            $this->assertArrayNotHasKey( 'noindex', Seo::bridge()->read_post( $record ), 'noindex on the French archive alone' );
            $this->assertSame( $fr, ( new \Fabrement\Modules\Mcp\Ability\GetSeoAbility() )->execute( [ 'archive' => 'qa_wbook', 'language' => 'fr' ] )['archive']['pageId'] );

            register_post_type( 'qa_wforeign', [ 'public' => true, 'has_archive' => true, 'label' => 'Foreign' ] );
            $this->assertSame( 'archive_needs_record', $this->code( $set_seo->execute( [ 'archive' => 'qa_wforeign', 'language' => 'fr', 'title' => 'x' ] ) ), 'without a record: one value for every language' );
        } finally {
            unregister_post_type( 'qa_wbook' );
            unregister_post_type( 'qa_wforeign' );
        }
    }

    public function test_the_french_archive_pages_at_its_own_address(): void {
        register_post_type( 'qa_wtalk', [ 'public' => true, 'has_archive' => 'qa-wtalks', 'label' => 'Talks' ] );
        try {
            $record = ArchivePageStore::ensure( 'qa_wtalk', 'Talks', 'qa-wtalks' );
            $fr     = $this->translate( $record, 'fr' );
            wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish', 'post_name' => 'exposes' ] );
            self::factory()->post->create_many( 5, [ 'post_type' => 'qa_wtalk' ] );
            update_option( 'posts_per_page', 2 );
            flush_rewrite_rules( false );

            // A reader on page 2 of the French listing: the request is in French, as WPML sets it from the URL.
            do_action( 'wpml_switch_language', 'fr' );
            $first = (string) get_post_type_archive_link( 'qa_wtalk' );
            $this->assertStringContainsString( '/fr/exposes', $first, 'at the French record\'s address' );
            $this->go_to( trailingslashit( $first ) . 'page/2/' );
            $this->assertTrue( is_post_type_archive( 'qa_wtalk' ), 'the French rules answer page 2' );
            YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class )->clear();
            ob_start();
            do_action( 'wpseo_head' );
            $head = (string) ob_get_clean();

            $this->assertStringContainsString( '<link rel="canonical" href="' . trailingslashit( $first ) . 'page/2/"', $head );
            $this->assertStringContainsString( '<link rel="prev" href="' . $first . '"', $head );
            $this->assertStringContainsString( '<link rel="next" href="' . trailingslashit( $first ) . 'page/3/"', $head );
        } finally {
            unregister_post_type( 'qa_wtalk' );
        }
    }

    public function test_the_language_version_of_an_archive_record_is_a_collection_page_too(): void {
        register_post_type( 'qa_wtalk', [ 'public' => true, 'has_archive' => 'qa-wtalks', 'label' => 'Talks' ] );
        try {
            $record = ArchivePageStore::ensure( 'qa_wtalk', 'Talks', 'qa-wtalks' );
            $fr     = $this->translate( $record, 'fr' );
            wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish', 'post_name' => 'exposes' ] );

            $types = static function ( int $id ): array {
                $graph = Translation::bridge()->in_language( (string) Translation::live_language_of( $id, 'page' ), static fn() => \Fabrement\Modules\Seo\Schema\SchemaOutput::preview( $id )['graph'] );
                foreach ( $graph as $node ) {
                    if ( is_array( $node ) && in_array( 'WebPage', (array) ( $node['@type'] ?? [] ), true ) ) {
                        return (array) $node['@type'];
                    }
                }
                return [];
            };
            $this->assertContains( 'CollectionPage', $types( $record ) );
            $this->assertContains( 'CollectionPage', $types( $fr ), 'the French record stands for the French archive' );
        } finally {
            unregister_post_type( 'qa_wtalk' );
        }
    }

    public function test_the_sitemap_lists_each_languages_archive_once(): void {
        register_post_type( 'qa_wtalk', [ 'public' => true, 'has_archive' => 'qa-wtalks', 'label' => 'Talks' ] );
        try {
            $record = ArchivePageStore::ensure( 'qa_wtalk', 'Talks', 'qa-wtalks' );
            $fr     = $this->translate( $record, 'fr' );
            wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish', 'post_name' => 'exposes' ] );
            $bridge = Translation::bridge();
            $en_url = $bridge->permalink( $record, 'en' );
            $fr_url = $bridge->permalink( $fr, 'fr' );
            $this->assertNotSame( $en_url, $fr_url );

            // What WPML's sitemap filter adds after Yoast's own link: the archive in every language —
            // Polish has no record, so its archive is the original's address, listed again.
            $wpml = [ [ 'loc' => $en_url ], [ 'loc' => $fr_url ], [ 'loc' => $en_url ] ];
            $locs = static fn( $links ): array => array_column( (array) $links, 'loc' );

            $this->assertSame( [ $en_url, $fr_url ], $locs( apply_filters( 'wpseo_sitemap_post_type_first_links', $wpml, 'qa_wtalk' ) ) );

            $this->assertIsArray( ( new \Fabrement\Modules\Mcp\Ability\SetSeoAbility() )->execute( [ 'archive' => 'qa_wtalk', 'language' => 'fr', 'noindex' => true ] ) );
            $this->assertSame( [ $en_url ], $locs( apply_filters( 'wpseo_sitemap_post_type_first_links', $wpml, 'qa_wtalk' ) ), 'noindex on the French archive takes its address out' );

            $excluded = apply_filters( 'wpseo_exclude_from_sitemap_by_post_ids', [] );
            $this->assertContains( $record, $excluded );
            $this->assertContains( $fr, $excluded, 'no version of the record is a page in the sitemap' );
        } finally {
            unregister_post_type( 'qa_wtalk' );
        }
    }

    // ── what is still to translate: get-seo and list-seo ──────────────────────────────────────

    /** @return array<int,array<string,mixed>> id → row */
    private function seo_rows( array $input ): array {
        $out = ( new \Fabrement\Modules\Mcp\Ability\ListSeoAbility() )->execute( $input + [ 'limit' => 200 ] );
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );
        return array_column( $out['items'], null, 'id' );
    }

    public function test_a_versions_texts_are_reported_until_translated(): void {
        $page = $this->page( [ 'post_title' => 'Services' ] );
        Seo::bridge()->write_post( $page, [ 'title' => 'Our services', 'metaDesc' => 'What we do' ] );
        $fr = $this->translate( $page, 'fr' );
        wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish', 'post_title' => 'Prestations' ] );

        $status = fn(): array => (array) $this->tool( 'get-seo', [ 'contentId' => $page ] )['content']['translationsSeo']->fr;
        $untranslated = fn(): array => array_keys( $this->seo_rows( [ 'language' => 'fr', 'filter' => [ 'untranslated' ], 'postType' => 'page' ] ) );

        $this->assertSame( [ 'id' => $fr, 'title' => 'missing', 'metaDesc' => 'missing', 'noindex' => false ], $status(), 'a new version has none of its own' );
        $this->assertContains( $fr, $untranslated() );

        // What a translation editor leaves behind when nobody translates the field.
        Seo::bridge()->write_post( $fr, [ 'title' => 'Our services', 'metaDesc' => 'Ce que nous faisons' ] );
        $this->assertSame( [ 'sameAsOriginal', 'translated' ], [ $status()['title'], $status()['metaDesc'] ] );
        $this->assertContains( $fr, $untranslated() );

        $this->assertIsArray( $this->tool( 'set-seo', [ 'contentId' => $fr, 'title' => 'Nos prestations' ] ) );
        $this->assertSame( [ 'translated', 'translated' ], [ $status()['title'], $status()['metaDesc'] ] );
        $this->assertNotContains( $fr, $untranslated() );

        $row = $this->seo_rows( [ 'language' => 'fr', 'postType' => 'page' ] )[ $fr ];
        $this->assertSame( $page, $row['originalId'] );
        $this->assertSame( 'fr', $row['language'] );

        $this->assertArrayHasKey( $page, $this->seo_rows( [ 'missingIn' => 'pl', 'postType' => 'page' ] ), 'no Polish version yet' );
        $this->assertArrayNotHasKey( $page, $this->seo_rows( [ 'missingIn' => 'fr', 'postType' => 'page' ] ) );
        $this->assertSame( [ 'original' => true ], array_intersect_key( (array) $this->tool( 'get-seo', [ 'contentId' => $fr ] )['content']['translationsSeo']->en, [ 'original' => 1 ] ), 'seen from the version, the original is marked, not judged' );
    }

    public function test_get_seo_and_list_seo_name_the_same_original_even_in_the_trash(): void {
        $page = $this->page( [ 'post_title' => 'Team' ] );
        Seo::bridge()->write_post( $page, [ 'metaDesc' => 'Who we are' ] );
        $fr = $this->translate( $page, 'fr' );
        wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish' ] );
        wp_trash_post( $page );

        $content = $this->tool( 'get-seo', [ 'contentId' => $fr ] )['content'];
        $row     = $this->seo_rows( [ 'language' => 'fr', 'postType' => 'page' ] )[ $fr ] ?? [];
        $this->assertSame( $page, $content['originalId'], 'a trashed original is still the original of its group' );
        $this->assertSame( $content['originalId'], $row['originalId'] ?? null );
    }

    public function test_a_page_following_the_template_needs_no_translation(): void {
        $page = $this->page( [ 'post_title' => 'About' ] );
        $fr   = $this->translate( $page, 'fr' );
        $this->assertSame( [ 'template', 'template' ], array_values( array_intersect_key( (array) $this->tool( 'get-seo', [ 'contentId' => $page ] )['content']['translationsSeo']->fr, [ 'title' => 1, 'metaDesc' => 1 ] ) ) );
        unset( $fr );
    }

    public function test_a_terms_field_set_back_to_its_default_is_cleared(): void {
        // WP SEO Multilingual makes Yoast create a second copy of its term-meta option object, whose
        // validator re-reads every field missing from the saved row from the stored one.
        $term = self::factory()->term->create( [ 'taxonomy' => 'category', 'name' => 'Guides', 'slug' => 'guides' ] );
        $this->assertIsArray( $this->tool( 'set-seo', [ 'termId' => $term, 'title' => 'All guides', 'noindex' => false, 'canonical' => home_url( '/elsewhere/' ), 'breadcrumbTitle' => 'Guides' ] ) );

        $this->assertIsArray( $this->tool( 'set-seo', [ 'termId' => $term, 'noindex' => null, 'canonical' => null, 'breadcrumbTitle' => null ] ) );

        global $wpdb;
        $stored = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wpseo_taxonomy_meta' ) ) );
        $row    = (array) ( $stored['category'][ $term ] ?? [] );
        $this->assertSame( 'All guides', $row['wpseo_title'] ?? null, 'the field the call left alone stays' );
        $this->assertNotContains( $row['wpseo_noindex'] ?? 'default', [ 'index', 'noindex' ], 'back to the default of the taxonomy' );
        $this->assertSame( '', (string) ( $row['wpseo_canonical'] ?? '' ) );
        $this->assertSame( '', (string) ( $row['wpseo_bctitle'] ?? '' ) );
    }

    public function test_terms_and_archives_report_their_versions_too(): void {
        $news = self::factory()->term->create( [ 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news' ] );
        Seo::bridge()->write_term( \WP_Term::get_instance( $news ), [ 'metaDesc' => 'All the news' ] );
        $this->tool( 'translate-term', [ 'termId' => $news, 'language' => 'fr', 'name' => 'Actualités', 'slug' => 'actualites' ] );
        $fr_news = (int) apply_filters( 'wpml_object_id', $news, 'category', false, 'fr' );
        $this->assertGreaterThan( 0, $fr_news );

        $term = $this->tool( 'get-seo', [ 'termId' => $news ] )['term'];
        $this->assertSame( 'missing', $term['translationsSeo']->fr['metaDesc'] ?? null );
        $this->assertContains( 'pl', $term['missingLanguages'] );
        $this->assertArrayHasKey( $fr_news, $this->seo_rows( [ 'kind' => 'term', 'taxonomy' => 'category', 'language' => 'fr', 'filter' => [ 'untranslated' ] ] ) );

        $this->assertIsArray( $this->tool( 'set-seo', [ 'termId' => $fr_news, 'metaDesc' => 'Toutes les nouvelles' ] ) );
        $this->assertSame( 'translated', $this->tool( 'get-seo', [ 'termId' => $news ] )['term']['translationsSeo']->fr['metaDesc'] ?? null );
        $this->assertArrayNotHasKey( $fr_news, $this->seo_rows( [ 'kind' => 'term', 'taxonomy' => 'category', 'language' => 'fr', 'filter' => [ 'untranslated' ] ] ) );

        register_post_type( 'qa_wtalk', [ 'public' => true, 'has_archive' => 'qa-wtalks', 'label' => 'Talks' ] );
        try {
            $record = ArchivePageStore::ensure( 'qa_wtalk', 'Talks', 'qa-wtalks' );
            Seo::bridge()->write_post( $record, [ 'metaDesc' => 'Every talk' ] );
            $fr = $this->translate( $record, 'fr' );
            wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish', 'post_name' => 'exposes' ] );

            $row = $this->seo_rows( [ 'kind' => 'archive', 'language' => 'fr' ] )[ $fr ] ?? null;
            $this->assertSame( 'qa_wtalk', $row['postType'] ?? null );
            $this->assertSame( 'missing', $row['translation']['metaDesc'] ?? null );
            $this->assertSame( 'missing', $this->tool( 'get-seo', [ 'archive' => 'qa_wtalk' ] )['archive']['translationsSeo']->fr['metaDesc'] ?? null );
            $this->assertContains( 'pl', $this->seo_rows( [ 'kind' => 'archive' ] )[ $record ]['missingLanguages'] ?? [] );
        } finally {
            unregister_post_type( 'qa_wtalk' );
        }
    }

    public function test_schema_tokens_on_a_version_point_at_yoasts_nodes_in_its_language(): void {
        $page = $this->page( [ 'post_title' => 'Contact' ] );
        $fr   = $this->translate( $page, 'fr' );
        wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish', 'post_name' => 'contact-fr' ] );
        $schema = $this->tool( 'set-schema', [ 'contentId' => $fr, 'graph' => [ [ '@type' => 'Thing', '@id' => '{{url}}#qa', 'name' => 'x', 'mainEntityOfPage' => [ '@id' => '{{url}}' ], 'isPartOf' => [ '@id' => '{{site_url}}#website' ] ] ] ] );
        $this->assertIsArray( $schema, $schema instanceof WP_Error ? $schema->get_error_message() : '' );

        $graph = Translation::bridge()->in_language( 'fr', static fn() => \Fabrement\Modules\Seo\Schema\SchemaOutput::preview( $fr )['graph'] );
        $ids   = array_column( array_filter( $graph, 'is_array' ), '@type', '@id' );
        $ours  = current( array_filter( $graph, static fn( $n ) => is_array( $n ) && ( $n['@type'] ?? '' ) === 'Thing' ) );
        $this->assertIsArray( $ours, 'our node is in Yoast\'s graph' );
        $this->assertStringContainsString( '/fr/', $ours['mainEntityOfPage']['@id'] );
        $this->assertArrayHasKey( $ours['mainEntityOfPage']['@id'], $ids, 'the WebPage Yoast prints for the French page' );
        $this->assertArrayHasKey( $ours['isPartOf']['@id'], $ids, 'the French WebSite node' );
    }

    public function test_the_french_page_prints_our_node_pointing_at_its_own_yoast_nodes(): void {
        $page = $this->page( [ 'post_title' => 'Contact' ] );
        $fr   = $this->translate( $page, 'fr' );
        wp_update_post( [ 'ID' => $fr, 'post_status' => 'publish', 'post_name' => 'contact-fr' ] );
        $this->tool( 'set-schema', [ 'contentId' => $fr, 'graph' => [ [ '@type' => 'Thing', '@id' => '{{url}}#qa', 'name' => 'x', 'mainEntityOfPage' => [ '@id' => '{{url}}' ], 'isPartOf' => [ '@id' => '{{site_url}}#website' ] ] ] ] );

        // A reader opening the French page: the request is in French, as WPML sets it from the URL.
        $url = Translation::bridge()->permalink( $fr, 'fr' );
        do_action( 'wpml_switch_language', 'fr' );
        $this->go_to( $url );
        YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class )->clear();
        $graph = YoastSEO()->meta->for_current_page()->schema['@graph'] ?? [];

        $this->assertSame( $fr, get_queried_object_id(), 'the French page is the one viewed' );
        $ids  = array_column( array_filter( $graph, 'is_array' ), '@type', '@id' );
        $ours = current( array_filter( $graph, static fn( $n ) => is_array( $n ) && ( $n['@type'] ?? '' ) === 'Thing' ) );
        $this->assertIsArray( $ours, 'our node is on the page' );
        $this->assertArrayHasKey( $ours['mainEntityOfPage']['@id'], $ids, 'its WebPage' );
        $this->assertArrayHasKey( $ours['isPartOf']['@id'], $ids, 'its French WebSite node: ' . wp_json_encode( array_keys( $ids ) ) );
    }

    public function test_a_pages_images_come_from_the_global_blocks_version_in_its_language(): void {
        $en_img  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
        $fr_img  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
        $block   = static fn( int $id ): string => '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => [ 'id' => $id, 'url' => (string) wp_get_attachment_url( $id ) ] ] ) . ' /-->';
        $pattern = self::factory()->post->create( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Hero', 'post_content' => $block( $en_img ) ] );
        $version = $this->tool( 'translate-synced-pattern', [ 'patternId' => $pattern, 'language' => 'fr' ] );
        $this->assertIsArray( $version, $version instanceof WP_Error ? $version->get_error_message() : '' );
        wp_update_post( [ 'ID' => (int) $version['patternId'], 'post_content' => $block( $fr_img ) ] );

        $page = $this->page( [ 'post_content' => '<!-- wp:block {"ref":' . $pattern . '} /-->' ] );
        $fr   = $this->translate( $page, 'fr' );
        $this->assertStringContainsString( '"ref":' . $pattern, (string) get_post_field( 'post_content', $fr ), 'the version places the ORIGINAL pattern id' );

        $this->assertSame( [ $en_img ], ContentImages::of_post( $page ) );
        $this->assertSame( [ $fr_img ], ContentImages::of_post( $fr ), 'the French page shows the French version of the block' );
    }
}
