<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * The translation tools as an agent uses them on the WPML site: a record's language version
 * (translate-content), a term's (translate-term), a global block's (translate-synced-pattern) —
 * what each makes, where it is linked in WPML, what the visitor gets at its address, and what is
 * refused without making anything.
 */
final class TranslationToolsTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private function wpml(): WpmlSite {
        return new WpmlSite( $this->site() );
    }

    private function translate( int $id, string $lang, array $extra = [] ): array {
        return $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => $lang ] + $extra );
    }

    private function pages(): string {
        return (string) $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page'" );
    }

    public function test_the_version_is_a_linked_draft_copy_of_the_original_until_published(): void {
        $image = $this->image( 'qa-services.png' );
        $page  = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'Services', 'featuredImage' => $image,
            'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>We build sites.</p><!-- /wp:paragraph -->' ] ] ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'publish' ] );

        $out = $this->translate( $page, 'fr', [ 'slug' => 'prestations' ] );
        $this->assertTrue( $out['created'] );
        $fr = (int) $out['contentId'];
        $this->assertSame( 'draft', $out['status'], 'words to be changed before anyone reads them' );
        $this->assertSame( 'fr', $this->wpml()->language( $fr ) );
        $this->assertSame( $fr, $this->wpml()->version( $page, 'fr' ) );
        $this->assertSame(
            $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $page ] ),
            $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $fr ] ),
            'the original\'s content, to translate'
        );
        $thumb = (int) ( $this->site()->meta( $fr, '_thumbnail_id' )[0] ?? 0 );
        $this->assertGreaterThan( 0, $this->wpml()->version( $image, 'fr', 'attachment' ) );
        $this->assertSame( $this->wpml()->version( $image, 'fr', 'attachment' ), $thumb, 'WPML\'s French copy of the featured image' );

        $read = $this->mcp( 'get-content', [ 'contentId' => $fr ] );
        $this->assertSame( 'fr', $read['language'] );
        $this->assertSame( $page, $read['originalId'] );
        $this->assertSame( [ 'pl' ], array_values( $read['missingLanguages'] ) );

        // The translator rewrites the words and publishes: the French page at its own address.
        $this->mcp( 'save-content', [ 'mode' => 'replace', 'contentId' => $fr, 'title' => 'Prestations', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Nous créons des sites.</p><!-- /wp:paragraph -->' ] ] ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        $french = $this->get( $this->site()->url( '/fr/prestations/' ) );
        $this->assertSame( 200, $french['status'] );
        $this->assertMatchesRegularExpression( '/<html[^>]+lang="fr-FR"/', $french['body'] );
        $this->assertStringContainsString( 'Nous créons des sites.', $french['body'] );
        $this->assertStringContainsString( 'We build sites.', $this->get( (string) $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'] )['body'], 'the English page keeps its words' );
    }

    public function test_a_slug_given_is_the_versions_address_and_none_leaves_it_to_the_title(): void {
        $page = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'About', 'slug' => 'about', 'blocks' => [] ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'publish' ] );

        $fr = $this->translate( $page, 'fr', [ 'slug' => 'À propos' ] );
        $this->assertSame( 'a-propos', $fr['slug'] );
        $this->assertSame( 'a-propos', $this->site()->value( 'SELECT post_name FROM wp_posts WHERE ID = ?', [ (int) $fr['contentId'] ] ) );

        $again = $this->translate( $page, 'fr', [ 'slug' => 'autre' ] );
        $this->assertFalse( $again['created'], 'a second call returns the version there is' );
        $this->assertSame( $fr['contentId'], $again['contentId'] );
        $this->assertSame( 'a-propos', $again['slug'], 'an existing version keeps its own' );

        $pl = $this->translate( $page, 'pl' );
        $this->assertSame( '', $pl['slug'], 'WordPress derives it from the title at publish' );
        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $pl['contentId'], 'title' => 'O nas' ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $pl['contentId'], 'status' => 'publish' ] );
        $read = $this->mcp( 'get-content', [ 'contentId' => $pl['contentId'] ] );
        $this->assertSame( 'o-nas', $this->site()->value( 'SELECT post_name FROM wp_posts WHERE ID = ?', [ $pl['contentId'] ] ) );
        $this->assertSame( $this->site()->url( '/pl/o-nas/' ), $read['url'] );
        $this->assertSame( 200, $this->get( $read['url'] )['status'] );
    }

    public function test_what_cannot_be_translated_is_refused_and_nothing_is_made(): void {
        $page   = $this->record( 'page', 'Refusals' );
        $fr     = (int) $this->translate( $page, 'fr' )['contentId'];
        $before = $this->pages();

        $this->assertStringContainsString( (string) $page, $this->mcp_error( 'translate-content', [ 'contentId' => $fr, 'language' => 'pl' ] ), 'not an original: the original is named' );
        $this->mcp_error( 'translate-content', [ 'contentId' => $page, 'language' => 'en' ] );   // its own language
        $this->mcp_error( 'translate-content', [ 'contentId' => $page, 'language' => 'de' ] );   // not a language of the site
        $this->assertSame( $before, $this->pages(), 'no page made' );
        $this->assertSame( [ 'en', 'fr' ], array_keys( $this->wpml()->group( $page ) ) );

        $this->post_type( 'qa_shared', [ 'translationMode' => 'none', 'supports' => [ 'title', 'editor' ] ] );
        $shared = $this->record( 'qa_shared', 'Shared record' );
        $this->mcp_error( 'translate-content', [ 'contentId' => $shared, 'language' => 'fr' ] );
        $this->assertSame( '1', $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'qa_shared'" ) );
    }

    public function test_the_versions_terms_are_their_versions_in_its_language(): void {
        $news  = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news' ] )['termId'];
        $other = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Other', 'slug' => 'other' ] )['termId'];
        $this->mcp( 'translate-term', [ 'termId' => $news, 'language' => 'fr', 'name' => 'Actualités', 'slug' => 'actualites' ] );
        $fr_news = (int) $this->site_runs( "return (int) apply_filters( 'wpml_object_id', $news, 'category', false, 'fr' );" );
        $this->assertGreaterThan( 0, $fr_news );
        $this->assertNotSame( $news, $fr_news );
        $this->assertSame( 'actualites', $this->site()->value( 'SELECT slug FROM wp_terms WHERE term_id = ?', [ $fr_news ] ), 'the term version has its own address' );

        $post = $this->record( 'post', 'Terms post' );
        $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $news, $other ], 'mode' => 'replace' ] );
        $fr = (int) $this->translate( $post, 'fr', [ 'slug' => 'article-termes' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );

        $terms = $this->site_runs( "do_action( 'wpml_switch_language', 'fr' ); return array_map( 'intval', wp_get_object_terms( $fr, 'category', [ 'fields' => 'ids' ] ) );" );
        $this->assertContains( $fr_news, $terms, 'the translated category' );
        $this->assertNotContains( $news, $terms, 'not the English one' );

        // The French category page lists the French post, at the term's own address.
        $archive = $this->get( '/fr/category/actualites/' );
        $this->assertSame( 200, $archive['status'] );
        $this->assertMatchesRegularExpression( '/<html[^>]+lang="fr-FR"/', $archive['body'] );
        $this->assertStringContainsString( 'article-termes', $archive['body'] );
    }

    public function test_translate_synced_pattern_makes_a_published_linked_version(): void {
        $pattern = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Clients', 'kind' => 'section', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Our clients</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];
        $out     = $this->mcp( 'translate-synced-pattern', [ 'patternId' => $pattern, 'language' => 'fr' ] );
        $fr      = (int) $out['patternId'];
        $this->assertSame( $fr, $this->wpml()->version( $pattern, 'fr', 'wp_block' ) );
        $this->assertSame( 'publish', $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $fr ] ), 'a draft version would empty that section on every French page' );

        // The version's own words reach the French page that places the pattern.
        $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $fr, 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Nos clients</p><!-- /wp:paragraph -->' ] ] ] );
        $page = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'With clients', 'blocks' => [ [ 'markup' => '<!-- wp:block {"ref":' . $pattern . '} /-->' ] ] ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'publish' ] );
        $fr_page = (int) $this->translate( $page, 'fr', [ 'slug' => 'avec-clients' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $fr_page, 'status' => 'publish' ] );
        $this->assertStringContainsString( 'Nos clients', $this->get( '/fr/avec-clients/' )['body'] );
        $this->assertStringContainsString( 'Our clients', $this->get( (string) $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'] )['body'] );
    }

    public function test_a_new_featured_image_of_the_original_reaches_its_version_and_its_share_picture(): void {
        $first  = $this->image( 'qa-cover-first.png' );
        $second = $this->image( 'qa-cover-second.png' );
        $page   = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'Cover', 'featuredImage' => $first, 'blocks' => [] ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'publish' ] );
        $fr = (int) $this->translate( $page, 'fr', [ 'slug' => 'couverture' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );

        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $page, 'featuredImage' => $second, 'blocks' => [] ] );
        $this->assertSame( $this->wpml()->version( $second, 'fr', 'attachment' ), (int) ( $this->site()->meta( $fr, '_thumbnail_id' )[0] ?? 0 ), 'the version follows with the French copy' );

        $og_image = static fn( string $html ): string => preg_match( '#<meta property="og:image" content="([^"]+)"#', $html, $m ) ? $m[1] : '';
        $this->assertStringContainsString( 'qa-cover-second', $og_image( $this->get( (string) $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'] )['body'] ) );
        $this->assertStringContainsString( 'qa-cover-second', $og_image( $this->get( '/fr/couverture/' )['body'] ), 'the French page is shared with the new picture at once' );

        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $page, 'featuredImage' => 0, 'blocks' => [] ] );
        $this->assertSame( [], $this->site()->meta( $fr, '_thumbnail_id' ), 'removed from the version too' );
    }

    public function test_a_trashed_version_stays_in_its_group(): void {
        $page = $this->record( 'page', 'Group facts' );
        $fr   = (int) $this->translate( $page, 'fr' )['contentId'];
        $this->assertSame( [ 'en' => $page, 'fr' => $fr ], $this->wpml()->group( $page ) );
        $this->assertSame( $page, $this->mcp( 'get-content', [ 'contentId' => $fr ] )['originalId'] );

        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'trash' ] );
        $this->assertSame( $fr, $this->wpml()->group( $page )['fr'] ?? null, 'the group still names it' );
        $this->assertSame( 'trash', $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $fr ] ) );
        $this->assertSame( 'publish', $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $page ] ), 'the original is untouched' );
    }
}
