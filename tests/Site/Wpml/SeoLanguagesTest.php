<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * SEO across languages with Yoast SEO and Yoast SEO Multilingual, as an agent sets it over MCP and
 * a crawler reads each language's page: what a language version gets and keeps, its own title and
 * description, per-language defaults through String Translation, the main category and term SEO in
 * the right language, what get-seo and list-seo report as still to translate, and our schema node
 * pointing at Yoast's nodes of the same language.
 */
final class SeoLanguagesTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private function wpml(): WpmlSite {
        return new WpmlSite( $this->site() );
    }

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    /** The page's <head> as a crawler gets it. */
    private function head( int $id ): string {
        $url  = $this->url( $id );
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'], $url );
        return html_entity_decode( (string) strstr( $page['body'], '</head>', true ) );
    }

    private static function title( string $head ): string {
        return preg_match( '#<title>(.*?)</title>#s', $head, $m ) ? trim( $m[1] ) : '';
    }

    private function version( int $id, string $lang, string $slug, string $title = '' ): int {
        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => $lang, 'slug' => $slug ] )['contentId'];
        if ( $title !== '' ) {
            $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $fr, 'title' => $title ] );
        }
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        return $fr;
    }

    private function french_term( int $term ): int {
        return (int) $this->site_runs( "return \\Fabrement\\Modules\\Translation\\Translation::bridge()->version_in($term, 'category', 'fr');" );
    }

    /** @dataProvider field_writers */
    public function test_a_version_gets_yoasts_fields_as_wpml_config_says_and_fabrements_by_policy( string $writer ): void {
        $this->fields( 'page', [ self::field( 'price', 'number', [ 'format' => 'decimal' ] ) ] );
        $page = $this->record( 'page', 'About us' );
        if ( $writer === 'php' ) {
            $this->assertTrue( $this->site_runs( "return fabrement()->fields($page)->set('price', 12.5)->save();" ) );
        } else {
            $this->mcp( 'set-field-values', [ 'postId' => $page, 'values' => [ 'price' => 12.5 ] ] );
        }
        $this->mcp( 'set-seo', [ 'contentId' => $page, 'title' => 'About — EN', 'metaDesc' => 'English description', 'canonical' => 'https://example.com/en-only/', 'noindex' => true, 'schemaPageType' => 'AboutPage' ] );

        $fr = $this->version( $page, 'fr', 'a-propos-' . $writer, 'À propos' );

        $this->assertSame( [ '1' ], $this->site()->meta( $fr, '_yoast_wpseo_meta-robots-noindex' ), 'copy — once' );
        $this->assertSame( [ 'AboutPage' ], $this->site()->meta( $fr, '_yoast_wpseo_schema_page_type' ), 'copy — once' );
        $this->assertSame( [], $this->site()->meta( $fr, '_yoast_wpseo_title' ), 'translate: Yoast\'s French template applies' );
        $this->assertSame( [], $this->site()->meta( $fr, '_yoast_wpseo_metadesc' ) );
        $this->assertSame( [], $this->site()->meta( $fr, '_yoast_wpseo_canonical' ), 'ignore: never the English address' );
        $this->assertSame( [ '12.5' ], $this->site()->meta( $fr, 'fab_price' ), 'a Fabrement copy field' );
        $this->assertSame( $this->site()->url( '/fr/a-propos-' . $writer . '/' ), $this->url( $fr ) );

        $head = $this->head( $fr );
        $this->assertStringContainsString( 'À propos', self::title( $head ), 'the French title from Yoast\'s template' );
        $this->assertStringNotContainsString( 'About — EN', $head );
        $this->assertMatchesRegularExpression( '/<meta name=["\']robots["\'][^>]*noindex/', $head, 'noindex copied' );
        $this->assertStringNotContainsString( 'en-only', $head, 'no English canonical on the French page' );
        $this->assertStringContainsString( 'About — EN', self::title( $this->head( $page ) ) );
    }

    public static function field_writers(): array {
        return [ 'MCP' => [ 'mcp' ], 'PHP' => [ 'php' ] ];
    }

    public function test_seo_set_on_a_version_is_the_versions_and_get_seo_lists_the_languages(): void {
        $page = $this->record( 'page', 'Our team' );
        $fr   = $this->version( $page, 'fr', 'notre-equipe' );

        $this->mcp( 'set-seo', [ 'contentId' => $fr, 'title' => 'Notre équipe', 'metaDesc' => 'Description française' ] );
        $this->assertSame( [ 'Notre équipe' ], $this->site()->meta( $fr, '_yoast_wpseo_title' ) );
        $this->assertSame( [], $this->site()->meta( $page, '_yoast_wpseo_title' ), 'the original is untouched' );

        $read = $this->mcp( 'get-seo', [ 'contentId' => $page ] )['content'];
        $this->assertSame( [ 'pl' ], array_values( $read['missingLanguages'] ) );
        $this->assertSame( 'translated', $read['translationsSeo']['fr']['title'] ?? null, 'the version has words of its own' );

        $head = $this->head( $fr );
        $this->assertSame( 'Notre équipe', self::title( $head ) );
        $this->assertMatchesRegularExpression( '/<meta name="description" content="Description française"/', $head );
        $this->assertStringNotContainsString( 'Notre équipe', $this->head( $page ) );
    }

    public function test_language_with_the_originals_id_reads_and_writes_that_version(): void {
        $page = $this->record( 'page', 'Our offices' );
        $fr   = $this->version( $page, 'fr', 'nos-bureaux' );

        $out = $this->mcp( 'set-seo', [ 'contentId' => $page, 'language' => 'fr', 'title' => 'Nos bureaux' ] );
        $this->assertSame( $fr, $out['contentId'] );
        $this->assertSame( $page, $out['from'] );
        $this->assertSame( [ 'Nos bureaux' ], $this->site()->meta( $fr, '_yoast_wpseo_title' ) );
        $this->assertSame( [], $this->site()->meta( $page, '_yoast_wpseo_title' ), 'the original is untouched' );

        $read = $this->mcp( 'get-seo', [ 'contentId' => $page, 'language' => 'fr' ] );
        $this->assertSame( $fr, $read['content']['id'] );
        $this->assertSame( $page, $read['from'] );
        $this->assertSame( 'Nos bureaux', $read['content']['stored']['title'] ?? null );

        $this->assertStringContainsString( 'is a language version', $this->mcp_error( 'get-seo', [ 'contentId' => $fr, 'language' => 'pl' ] ) );
        $this->assertStringContainsString( 'has no "pl" version yet', $this->mcp_error( 'set-seo', [ 'contentId' => $page, 'language' => 'pl', 'title' => 'X' ] ) );
        $this->assertStringContainsString( 'not one of the site languages', $this->mcp_error( 'get-seo', [ 'contentId' => $page, 'language' => 'xx' ] ) );

        $news = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Offices', 'slug' => 'offices' ] )['termId'];
        $this->mcp( 'translate-term', [ 'termId' => $news, 'language' => 'fr', 'name' => 'Bureaux', 'slug' => 'bureaux' ] );
        $fr_news = $this->french_term( $news );
        $rows   = array_column( $this->mcp( 'list-terms', [ 'taxonomy' => 'category' ] )['terms'], null, 'termId' );
        $this->assertSame( $news, $rows[ $fr_news ]['originalId'] ?? null, 'list-terms names the original of a version' );
        $this->assertSame( $news, $rows[ $news ]['originalId'] ?? null );
        $term   = $this->mcp( 'set-seo', [ 'termId' => $news, 'language' => 'fr', 'title' => 'Tous les bureaux' ] );
        $this->assertSame( $fr_news, $term['termId'] );
        $this->assertSame( $news, $term['from'] );
        $this->assertSame( 'Tous les bureaux', $this->mcp( 'get-seo', [ 'termId' => $news, 'language' => 'fr' ] )['term']['stored']['title'] ?? null );
    }

    public function test_a_type_template_never_saved_in_yoast_takes_its_translation_at_once(): void {
        $this->post_type( 'qa_stpl', [ 'singular' => 'Template thing', 'rewrite' => 'qa-stpl', 'translationMode' => 'translate' ] );
        $in_row    = 'global $wpdb; $row = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", "wpseo_titles" ) ) ); return array_key_exists( "title-qa_stpl", (array) $row ) ? "stored" : "absent";';
        $original = 'global $wpdb; return $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}icl_strings WHERE name = %s", "[wpseo_titles]title-qa_stpl" ) );';
        $this->assertSame( 'absent', $this->site_runs( $in_row ), 'Yoast shows its default for the new type without storing it' );

        $page = $this->record( 'qa_stpl', 'Thing one' );
        $fr   = $this->version( $page, 'fr', 'chose-une' );
        $this->assertSame( [ 'titleTemplate' ], $this->mcp( 'set-seo-defaults', [ 'postType' => 'qa_stpl', 'language' => 'fr', 'titleTemplate' => 'FR %%title%%' ] )['written'] );
        $this->assertSame( 'stored', $this->site_runs( $in_row ), 'the original is stored, so the translation has a key to apply to' );
        $this->assertSame( 'FR Thing one', self::title( $this->head( $fr ) ) );
        $this->assertStringStartsWith( 'Thing one', self::title( $this->head( $page ) ), 'the original keeps the default of Yoast' );

        $before = $this->site_runs( $original );
        $this->site_runs( 'do_action( "wpml_switch_language", "fr" ); \Fabrement\Modules\Seo\Seo::bridge()->write_defaults_translation( "postType", "qa_stpl", "fr", [ "titleTemplate" => "FR2 %%title%%" ] ); return true;' );
        $this->assertSame( $before, $this->site_runs( $original ), 'a French request does not register the translation as the original' );
        $this->assertSame( 'FR2 Thing one', self::title( $this->head( $fr ) ) );
    }

    public function test_every_language_of_the_not_found_page_is_kept_out_of_search(): void {
        $page = $this->record( 'page', 'Lost here' );
        $fr   = $this->version( $page, 'fr', 'perdu-ici' );
        $urls = [ $this->url( $page ), $this->url( $fr ) ];
        $this->mcp( 'update-site-settings', [ 'notFoundPage' => $page ] );

        foreach ( [ $page, $fr ] as $id ) {
            $this->assertMatchesRegularExpression( '/<meta name=["\']robots["\'] content=["\'][^"\']*noindex/', $this->head( $id ), 'opened at its own address, in every language' );
        }
        $missing = $this->get( '/fr/qa-no-such-address/' );
        $this->assertSame( 404, $missing['status'] );
        $this->assertStringNotContainsString( 'rel="canonical"', (string) strstr( $missing['body'], '</head>', true ) );

        $sitemap = $this->get( '/page-sitemap.xml' )['body'];
        foreach ( $urls as $url ) {
            $this->assertStringNotContainsString( $url, $sitemap, 'no language version is in the sitemap' );
        }
        $this->mcp( 'update-site-settings', [ 'notFoundPage' => 0 ] );
    }

    public function test_site_and_taxonomy_defaults_have_a_version_per_language_and_bad_ones_are_refused(): void {
        $page = $this->record( 'page', 'Studio home' );
        $fr   = $this->version( $page, 'fr', 'accueil-studio' );
        $this->mcp( 'set-seo-defaults', [ 'site' => true, 'websiteName' => 'Studio' ] );
        $this->assertSame( [ 'websiteName' ], $this->mcp( 'set-seo-defaults', [ 'site' => true, 'language' => 'fr', 'websiteName' => 'Atelier' ] )['written'] );
        $website = static fn( string $body ): string => (string) ( \Fabrement\Tests\Site\Support\SeoPage::of( $body )->node_of_type( 'WebSite' )['name'] ?? '' );
        $this->assertSame( 'Studio', $website( $this->get( $this->url( $page ) )['body'] ) );
        $this->assertSame( 'Atelier', $website( $this->get( $this->url( $fr ) )['body'] ), 'the French pages name the French site' );

        $topic = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Studio news', 'slug' => 'studio-news' ] )['termId'];
        $this->mcp( 'translate-term', [ 'termId' => $topic, 'language' => 'fr', 'name' => 'Nouvelles', 'slug' => 'nouvelles' ] );
        $fr_topic = $this->french_term( $topic );
        $story   = $this->record( 'post', 'Studio story' );
        $this->mcp( 'set-post-terms', [ 'contentId' => $story, 'taxonomy' => 'category', 'terms' => [ $topic ], 'mode' => 'replace' ] );
        $fr_story = $this->version( $story, 'fr', 'histoire-studio' );
        foreach ( [ [ $story, $topic ], [ $fr_story, $fr_topic ] ] as [ $post, $term ] ) {
            $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $term ], 'mode' => 'replace' ] );
        }
        $this->mcp( 'set-seo-defaults', [ 'taxonomy' => 'category', 'titleTemplate' => 'Topic %%term_title%%' ] );
        $this->mcp( 'set-seo-defaults', [ 'taxonomy' => 'category', 'language' => 'fr', 'titleTemplate' => 'Sujet %%term_title%%' ] );
        $urls = array_column( $this->mcp( 'list-terms', [ 'taxonomy' => 'category' ] )['terms'], 'url', 'termId' );
        $this->assertSame( 'Topic Studio news', self::title( (string) strstr( $this->get( $urls[ $topic ] )['body'], '</head>', true ) ) );
        $this->assertSame( 'Sujet Nouvelles', self::title( (string) strstr( $this->get( $urls[ $fr_topic ] )['body'], '</head>', true ) ) );

        $titles = $this->site()->option( 'wpseo_titles' );
        $this->assertStringContainsString( 'must be text', $this->mcp_error( 'set-seo-defaults', [ 'site' => true, 'language' => 'fr', 'websiteName' => '' ] ) );
        $this->assertStringContainsString( 'not one of the site languages', $this->mcp_error( 'set-seo-defaults', [ 'site' => true, 'language' => 'xx', 'websiteName' => 'X' ] ) );
        $this->assertStringContainsString( 'separator is one value for every language', $this->mcp_error( 'set-seo-defaults', [ 'site' => true, 'language' => 'fr', 'separator' => 'sc-pipe' ] ) );
        $empty = $this->mcp( 'set-seo-defaults', [ 'site' => true, 'language' => 'fr', 'alternateName' => 'Autre nom' ] );
        $this->assertSame( [], $empty['written'] );
        $this->assertStringContainsString( 'the original is still empty', implode( ' ', $empty['warnings'] ) );
        $this->assertSame( $titles, $this->site()->option( 'wpseo_titles' ), 'nothing written by a refusal' );
    }

    public function test_type_defaults_have_a_version_per_language_through_string_translation(): void {
        $page = $this->record( 'page', 'Workshop' );
        $fr   = $this->version( $page, 'fr', 'atelier', 'Atelier' );

        $this->mcp( 'set-seo-defaults', [ 'postType' => 'page', 'titleTemplate' => '%%title%% %%sep%% Studio' ] );
        $written = $this->mcp( 'set-seo-defaults', [ 'postType' => 'page', 'language' => 'fr', 'titleTemplate' => '%%title%% %%sep%% Maison' ] );
        $this->assertSame( [ 'titleTemplate' ], $written['written'] );

        $this->assertMatchesRegularExpression( '/^Workshop .* Studio$/u', self::title( $this->head( $page ) ), 'the original template on the English page' );
        $this->assertMatchesRegularExpression( '/^Atelier .* Maison$/u', self::title( $this->head( $fr ) ), 'the French template on the French page' );

        // A flag is one value for every language: refused per language, nothing changed.
        $this->mcp_error( 'set-seo-defaults', [ 'postType' => 'page', 'language' => 'fr', 'noindex' => true ] );
        $this->assertDoesNotMatchRegularExpression( '/<meta name="robots"[^>]*noindex/', $this->head( $fr ) );
        $this->assertDoesNotMatchRegularExpression( '/<meta name="robots"[^>]*noindex/', $this->head( $page ) );
    }

    public function test_the_main_category_and_term_seo_land_in_the_right_language(): void {
        $news = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news' ] )['termId'];
        $this->mcp( 'translate-term', [ 'termId' => $news, 'language' => 'fr', 'name' => 'Actualités', 'slug' => 'actualites' ] );
        $fr_news = $this->french_term( $news );
        $this->assertGreaterThan( 0, $fr_news );
        $this->assertNotSame( $news, $fr_news );

        $post = $this->record( 'post', 'Big news' );
        $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $news ], 'mode' => 'replace', 'primary' => $news ] );
        $fr = $this->version( $post, 'fr', 'grande-nouvelle', 'Grande nouvelle' );
        // Yoast Multilingual maps this meta on read; its stored id may still name the original.
        $this->assertSame( $fr_news, $this->site_runs( "return (int) get_post_meta($fr, '_yoast_wpseo_primary_category', true);" ), 'WordPress reads the French category through Yoast Multilingual' );
        $this->assertSame( $fr_news, $this->mcp( 'get-seo', [ 'contentId' => $fr ] )['content']['primaryTerms']['category'], 'MCP uses the same public read' );

        // The term's SEO, written on the French term the agent named (the request itself is in English).
        $this->mcp( 'set-seo', [ 'termId' => $fr_news, 'title' => 'Toutes les actualités' ] );
        $stored = (array) $this->site()->option( 'wpseo_taxonomy_meta' );
        $this->assertSame( 'Toutes les actualités', $stored['category'][ $fr_news ]['wpseo_title'] ?? null );
        $this->assertArrayNotHasKey( 'wpseo_title', (array) ( $stored['category'][ $news ] ?? [] ), 'not the English one' );
        $this->assertSame( 'Toutes les actualités', $this->mcp( 'get-seo', [ 'termId' => $fr_news ] )['term']['stored']['title'] ?? null );

        $archive = $this->get( '/fr/category/actualites/' );
        $this->assertSame( 200, $archive['status'] );
        $this->assertSame( 'Toutes les actualités', self::title( html_entity_decode( $archive['body'] ) ), 'the French category page' );
        $this->assertStringNotContainsString( 'Toutes les actualités', self::title( html_entity_decode( $this->get( '/category/news/' )['body'] ) ) );
    }

    public function test_terms_report_their_versions_until_translated_and_a_field_set_back_is_cleared(): void {
        $guides = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Guides', 'slug' => 'guides' ] )['termId'];
        $this->mcp( 'set-seo', [ 'termId' => $guides, 'metaDesc' => 'All the guides' ] );
        $this->mcp( 'translate-term', [ 'termId' => $guides, 'language' => 'fr', 'name' => 'Guides FR', 'slug' => 'guides-fr' ] );
        $fr_guides = $this->french_term( $guides );

        $untranslated = fn(): array => array_column( $this->mcp( 'list-seo', [ 'kind' => 'term', 'taxonomy' => 'category', 'language' => 'fr', 'filter' => [ 'untranslated' ], 'limit' => 200 ] )['items'], 'id' );
        $term         = $this->mcp( 'get-seo', [ 'termId' => $guides ] )['term'];
        $this->assertSame( 'missing', $term['translationsSeo']['fr']['metaDesc'] ?? null );
        $this->assertContains( 'pl', $term['missingLanguages'] );
        $this->assertContains( $fr_guides, $untranslated() );

        $this->mcp( 'set-seo', [ 'termId' => $fr_guides, 'metaDesc' => 'Tous les guides' ] );
        $this->assertSame( 'translated', $this->mcp( 'get-seo', [ 'termId' => $guides ] )['term']['translationsSeo']['fr']['metaDesc'] ?? null );
        $this->assertNotContains( $fr_guides, $untranslated() );

        // Fields set back to their default are cleared, the field left alone stays (WP SEO Multilingual
        // makes Yoast keep a second copy of its term option that re-reads missing fields).
        $this->mcp( 'set-seo', [ 'termId' => $guides, 'title' => 'All guides', 'noindex' => false, 'canonical' => $this->site()->url( '/elsewhere/' ), 'breadcrumbTitle' => 'Guides' ] );
        $this->mcp( 'set-seo', [ 'termId' => $guides, 'noindex' => null, 'canonical' => null, 'breadcrumbTitle' => null ] );
        $row = (array) ( ( (array) $this->site()->option( 'wpseo_taxonomy_meta' ) )['category'][ $guides ] ?? [] );
        $this->assertSame( 'All guides', $row['wpseo_title'] ?? null, 'the field the call left alone stays' );
        $this->assertNotContains( $row['wpseo_noindex'] ?? 'default', [ 'index', 'noindex' ], 'back to the default of the taxonomy' );
        $this->assertSame( '', (string) ( $row['wpseo_canonical'] ?? '' ) );
        $this->assertSame( '', (string) ( $row['wpseo_bctitle'] ?? '' ) );
    }

    public function test_a_versions_texts_are_reported_until_translated(): void {
        $page = $this->record( 'page', 'Services' );
        $this->mcp( 'set-seo', [ 'contentId' => $page, 'title' => 'Our services', 'metaDesc' => 'What we do' ] );
        $fr = $this->version( $page, 'fr', 'prestations', 'Prestations' );

        $status       = fn(): array => (array) $this->mcp( 'get-seo', [ 'contentId' => $page ] )['content']['translationsSeo']['fr'];
        $untranslated = fn(): array => array_column( $this->mcp( 'list-seo', [ 'language' => 'fr', 'filter' => [ 'untranslated' ], 'postType' => 'page', 'limit' => 200 ] )['items'], 'id' );
        $this->assertSame( [ 'id' => $fr, 'title' => 'missing', 'metaDesc' => 'missing', 'noindex' => false ], $status(), 'a new version has none of its own' );
        $this->assertContains( $fr, $untranslated() );

        // What a translation editor leaves behind when nobody translates the field.
        $this->mcp( 'set-seo', [ 'contentId' => $fr, 'title' => 'Our services', 'metaDesc' => 'Ce que nous faisons' ] );
        $this->assertSame( [ 'sameAsOriginal', 'translated' ], [ $status()['title'], $status()['metaDesc'] ] );
        $this->assertContains( $fr, $untranslated() );

        $this->mcp( 'set-seo', [ 'contentId' => $fr, 'title' => 'Nos prestations' ] );
        $this->assertSame( [ 'translated', 'translated' ], [ $status()['title'], $status()['metaDesc'] ] );
        $this->assertNotContains( $fr, $untranslated() );
        $this->assertSame( 'Nos prestations', self::title( $this->head( $fr ) ) );

        $rows = array_column( $this->mcp( 'list-seo', [ 'language' => 'fr', 'postType' => 'page', 'limit' => 200 ] )['items'], null, 'id' );
        $this->assertSame( $page, $rows[ $fr ]['originalId'] );
        $this->assertSame( 'fr', $rows[ $fr ]['language'] );
        $missing_in = fn( string $lang ): array => array_column( $this->mcp( 'list-seo', [ 'missingIn' => $lang, 'postType' => 'page', 'limit' => 200 ] )['items'], 'id' );
        $this->assertContains( $page, $missing_in( 'pl' ), 'no Polish version yet' );
        $this->assertNotContains( $page, $missing_in( 'fr' ) );
        $this->assertTrue( $this->mcp( 'get-seo', [ 'contentId' => $fr ] )['content']['translationsSeo']['en']['original'] ?? false, 'seen from the version, the original is marked, not judged' );

        // A page following the type's template needs no translation.
        $plain   = $this->record( 'page', 'Plain about' );
        $this->version( $plain, 'fr', 'simple' );
        $plain_fr = (array) $this->mcp( 'get-seo', [ 'contentId' => $plain ] )['content']['translationsSeo']['fr'];
        $this->assertSame( [ 'template', 'template' ], [ $plain_fr['title'], $plain_fr['metaDesc'] ] );
    }

    public function test_get_seo_and_list_seo_name_the_same_original_even_in_the_trash(): void {
        $page = $this->record( 'page', 'Team' );
        $this->mcp( 'set-seo', [ 'contentId' => $page, 'metaDesc' => 'Who we are' ] );
        $fr = $this->version( $page, 'fr', 'equipe' );
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'trash' ] );

        $content = $this->mcp( 'get-seo', [ 'contentId' => $fr ] )['content'];
        $rows    = array_column( $this->mcp( 'list-seo', [ 'language' => 'fr', 'postType' => 'page', 'limit' => 200 ] )['items'], null, 'id' );
        $this->assertSame( $page, $content['originalId'], 'a trashed original is still the original of its group' );
        $this->assertSame( $page, $rows[ $fr ]['originalId'] ?? null );
        $this->assertSame( 200, $this->get( $this->url( $fr ) )['status'], 'the French page is still served' );
    }

    public function test_our_node_on_the_french_page_points_at_yoasts_nodes_of_that_page(): void {
        $page = $this->record( 'page', 'Contact' );
        $fr   = $this->version( $page, 'fr', 'contact-fr' );
        $this->mcp( 'set-schema', [ 'contentId' => $fr, 'graph' => [ [ '@type' => 'Thing', '@id' => '{{url}}#qa', 'name' => 'x', 'mainEntityOfPage' => [ '@id' => '{{url}}' ], 'isPartOf' => [ '@id' => '{{site_url}}#website' ] ] ] ] );

        $nodes = self::json_ld( $this->get( $this->url( $fr ) )['body'] );
        $ids   = array_filter( array_column( $nodes, '@id' ) );
        $ours  = self::node( $nodes, 'Thing' );
        $this->assertNotNull( $ours, 'our node is in Yoast\'s graph on the French page' );
        $this->assertStringContainsString( '/fr/', $ours['mainEntityOfPage']['@id'] );
        $this->assertContains( $ours['mainEntityOfPage']['@id'], $ids, 'the WebPage Yoast prints for the French page' );
        $this->assertContains( $ours['isPartOf']['@id'], $ids, 'the French WebSite node' );
        $this->assertNull( self::node( self::json_ld( $this->get( $this->url( $page ) )['body'] ), 'Thing' ), 'set on the version only' );
    }

    public function test_a_pages_image_comes_from_the_global_blocks_version_in_its_language(): void {
        $png   = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $en_img = $this->mcp( 'upload-media', [ 'base64' => $png, 'filename' => 'qa-hero-en.png', 'altText' => 'Hero' ] );
        $fr_img = $this->mcp( 'upload-media', [ 'base64' => $png, 'filename' => 'qa-hero-fr.png', 'altText' => 'Héros' ] );
        $image = static fn( array $img ): string => '<!-- wp:image {"id":' . (int) $img['id'] . '} --><figure class="wp-block-image"><img src="' . $img['url'] . '" alt="" class="wp-image-' . (int) $img['id'] . '"/></figure><!-- /wp:image -->';

        $pattern = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'QA hero', 'kind' => 'section', 'blocks' => [ [ 'markup' => $image( $en_img ) ] ] ] )['patternId'];
        $version = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $pattern, 'language' => 'fr' ] )['patternId'];
        $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $version, 'blocks' => [ [ 'markup' => $image( $fr_img ) ] ] ] );

        $page = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'Hero page', 'blocks' => [ [ 'markup' => '<!-- wp:block {"ref":' . $pattern . '} /-->' ] ] ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'publish' ] );
        $fr = $this->version( $page, 'fr', 'page-heros' );
        $this->assertStringContainsString( '"ref":' . $pattern, (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $fr ] ), 'the version places the ORIGINAL pattern id' );

        $og_image = static fn( string $head ): string => preg_match( '#<meta property="og:image" content="([^"]+)"#', $head, $m ) ? $m[1] : '';
        $this->assertStringContainsString( 'qa-hero-fr', $og_image( $this->head( $fr ) ), 'the French page shows the French version of the block' );
        $this->assertStringContainsString( 'qa-hero-en', $og_image( $this->head( $page ) ) );
        $this->assertStringContainsString( 'qa-hero-fr', $this->get( $this->url( $fr ) )['body'], 'and the visitor sees the French image' );
    }

    public function test_an_archive_has_one_seo_for_every_language(): void {
        // A type another plugin registers: its archive SEO is one site-wide value.
        $this->site_code( 'qa-foreign-type', "add_action( 'init', static function (): void { register_post_type( 'qa_wforeign', [ 'public' => true, 'has_archive' => true, 'label' => 'Foreign' ] ); } );" );
        $before = $this->site()->option( 'wpseo_titles' );
        $this->assertStringContainsString( 'ONE SEO value for every language', $this->mcp_error( 'set-seo', [ 'archive' => 'qa_wforeign', 'language' => 'fr', 'title' => 'x' ] ) );
        $this->assertSame( $before, $this->site()->option( 'wpseo_titles' ), 'nothing written' );
    }
}
