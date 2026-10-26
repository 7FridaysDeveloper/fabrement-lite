<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SeoPage;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * SEO an agent audits and fixes on a one-language site with Yoast: list-seo telling where each
 * page's title and description come from, set-seo / get-seo on records, terms and post-type
 * archives (the record that describes the archive, or Yoast's site-wide values when there is no
 * record), and what a crawler then reads — the <head>, the graph, the sitemaps. Then Yoast taken
 * away: the tools say so and write nothing.
 */
final class SeoTest extends SiteTestCase {

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function head( string $url ): SeoPage {
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'], $url );
        return SeoPage::of( $page['body'] );
    }

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    private function page( string $title, array $seo = [] ): int {
        $id = $this->record( 'page', $title );
        if ( $seo !== [] ) {
            $this->mcp( 'set-seo', [ 'contentId' => $id ] + $seo );
        }
        return $id;
    }

    /** @return array<int,array> list-seo rows by id. */
    private function rows( array $input ): array {
        return array_column( $this->mcp( 'list-seo', $input + [ 'limit' => 200 ] )['items'], null, 'id' );
    }

    /** @return int[] the ids list-seo gives for these filters, among $among. */
    private function found( array $filter, array $among ): array {
        return array_values( array_intersect( array_keys( $this->rows( [ 'postType' => 'page', 'filter' => $filter ] ) ), $among ) );
    }

    /** The Yoast meta of a record, read straight. */
    private function yoast( int $id, string $key ): array {
        return $this->site()->meta( $id, '_yoast_wpseo_' . $key );
    }

    private function remove_site_code( string $name ): void {
        $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/' . $name . '.php' );
    }

    // ── list-seo: the audit ──────────────────────────────────────────────────────────────────

    public function test_an_audit_of_pages_finds_what_needs_work_and_follows_each_fix(): void {
        $own       = $this->page( 'Own', [ 'title' => 'Own title', 'metaDesc' => 'Own description' ] );
        $bare      = $this->page( 'Bare & plain' );
        $hidden    = $this->page( 'Hidden', [ 'noindex' => true ] );
        $elsewhere = $this->page( 'Elsewhere', [ 'metaDesc' => 'x', 'canonical' => 'https://example.com/other/' ] );
        $self      = $this->page( 'Self', [ 'metaDesc' => 'x' ] );
        $this->mcp( 'set-seo', [ 'contentId' => $self, 'canonical' => $this->url( $self ) ] );
        $all = [ $own, $bare, $hidden, $elsewhere, $self ];

        // What the crawler reads is what the agent wrote.
        $page = $this->head( $this->url( $own ) );
        $this->assertSame( 'Own title', $page->title() );
        $this->assertSame( 'Own description', $page->description() );
        $this->assertTrue( $this->head( $this->url( $hidden ) )->noindex() );
        $this->assertSame( 'https://example.com/other/', $this->head( $this->url( $elsewhere ) )->canonical() );
        $this->assertSame( $this->url( $self ), $this->head( $this->url( $self ) )->canonical() );
        $this->assertSame( [ 'Own title' ], $this->yoast( $own, 'title' ), 'in Yoast\'s own meta' );

        $rows = $this->rows( [ 'postType' => 'page' ] );
        $this->assertSame( [ 'title' => 'own', 'metaDesc' => 'own', 'noindex' => false, 'canonical' => '' ], $rows[ $own ]['seo'] );
        $this->assertSame( 'template', $rows[ $bare ]['seo']['title'], 'Yoast\'s page template names it' );
        $this->assertSame( 'none', $rows[ $bare ]['seo']['metaDesc'] );
        $this->assertSame( 'Bare & plain', $rows[ $bare ]['title'], 'as a reader sees it' );
        $this->assertSame( $this->url( $own ), $rows[ $own ]['url'] );

        $this->assertSame( [ $bare ], $this->found( [ 'noMetaDesc' ], $all ), 'a noindex page is not a missing description' );
        $this->assertEqualsCanonicalizing( [ $bare, $elsewhere, $self ], $this->found( [ 'noCustomTitle' ], $all ) );
        $this->assertSame( [ $hidden ], $this->found( [ 'noindex' ], $all ) );
        $this->assertSame( [ $elsewhere ], $this->found( [ 'canonicalElsewhere' ], $all ), 'a canonical to itself is not elsewhere' );
        $this->assertSame( [ $bare ], $this->found( [ 'noMetaDesc', 'noCustomTitle' ], $all ), 'filters are all-must-hold' );

        // The fixes: each takes the page off its list, and the crawler reads it.
        $this->mcp( 'set-seo', [ 'contentId' => $bare, 'metaDesc' => 'Now described' ] );
        $this->assertSame( [], $this->found( [ 'noMetaDesc' ], $all ) );
        $this->assertSame( 'Now described', $this->head( $this->url( $bare ) )->description() );
        $this->mcp( 'set-seo', [ 'contentId' => $hidden, 'noindex' => false ] );
        $this->assertSame( [], $this->found( [ 'noindex' ], $all ) );
        $this->assertFalse( $this->head( $this->url( $hidden ) )->noindex() );
        $this->mcp( 'set-seo', [ 'contentId' => $elsewhere, 'canonical' => null ] );
        $this->assertSame( [], $this->found( [ 'canonicalElsewhere' ], $all ) );
        $this->assertSame( $this->url( $elsewhere ), $this->head( $this->url( $elsewhere ) )->canonical(), 'back to its own address' );

        // A description template counts as a description, for the pages that have none.
        $plain = $this->page( 'Plain two' );
        $this->assertSame( 'none', $this->rows( [ 'postType' => 'page' ] )[ $plain ]['seo']['metaDesc'] );
        $this->mcp( 'set-seo-defaults', [ 'postType' => 'page', 'metaDescTemplate' => 'Read %%title%% here' ] );
        $this->assertSame( 'template', $this->rows( [ 'postType' => 'page' ] )[ $plain ]['seo']['metaDesc'] );
        $this->assertSame( 'Read Plain two here', $this->head( $this->url( $plain ) )->description() );
        $this->assertSame( 'own', $this->rows( [ 'postType' => 'page' ] )[ $bare ]['seo']['metaDesc'], 'its own one still wins' );
        $this->mcp( 'set-seo-defaults', [ 'postType' => 'page', 'metaDescTemplate' => null ] );
        $this->assertSame( 'none', $this->rows( [ 'postType' => 'page' ] )[ $plain ]['seo']['metaDesc'] );
    }

    public function test_limit_and_offset_page_through_the_total(): void {
        foreach ( [ 'A', 'B', 'C' ] as $t ) {
            $this->page( 'QA page ' . $t );
        }
        $all  = $this->mcp( 'list-seo', [ 'postType' => 'page', 'limit' => 200 ] );
        $page = $this->mcp( 'list-seo', [ 'postType' => 'page', 'limit' => 1, 'offset' => 1 ] );
        $this->assertGreaterThanOrEqual( 3, $all['total'] );
        $this->assertSame( $all['total'], $page['total'] );
        $this->assertSame( [ $all['items'][1]['id'] ], array_column( $page['items'], 'id' ) );
    }

    public function test_one_language_says_nothing_about_languages(): void {
        $this->page( 'Plain' );
        $tool   = array_column( $this->client()->tools(), null, 'name' )['fabrement-list-seo'];
        $schema = $tool['inputSchema'];
        $this->assertArrayNotHasKey( 'language', $schema['properties'] );
        $this->assertArrayNotHasKey( 'missingIn', $schema['properties'] );
        $this->assertNotContains( 'untranslated', $schema['properties']['filter']['items']['enum'] );
        $this->assertStringNotContainsString( 'language', $tool['description'] );

        foreach ( $this->rows( [ 'postType' => 'page' ] ) as $row ) {
            $this->assertSame( [], array_intersect( array_keys( $row ), [ 'language', 'originalId', 'missingLanguages', 'translation' ] ) );
        }
    }

    public function test_a_types_noindex_setting_counts_until_the_record_says_otherwise(): void {
        $this->post_type( 'qa_lnote', [ 'singular' => 'Note', 'hasArchive' => false ] );
        $note = $this->record( 'qa_lnote', 'A note' );
        $this->assertFalse( $this->rows( [ 'postType' => 'qa_lnote' ] )[ $note ]['seo']['noindex'] );

        $this->mcp( 'set-seo-defaults', [ 'postType' => 'qa_lnote', 'noindex' => true ] );
        $this->assertTrue( (bool) ( $this->site()->option( 'wpseo_titles' )['noindex-qa_lnote'] ?? false ), 'Yoast\'s type setting' );
        $this->assertTrue( $this->rows( [ 'postType' => 'qa_lnote' ] )[ $note ]['seo']['noindex'] );
        $this->assertTrue( $this->head( $this->url( $note ) )->noindex() );

        $this->mcp( 'set-seo', [ 'contentId' => $note, 'noindex' => false ] );
        $this->assertSame( [ '2' ], $this->yoast( $note, 'meta-robots-noindex' ), 'Yoast\'s "index, explicitly"' );
        $this->assertFalse( $this->rows( [ 'postType' => 'qa_lnote' ] )[ $note ]['seo']['noindex'], 'the record\'s own setting wins' );
        $this->assertFalse( $this->head( $this->url( $note ) )->noindex() );
    }

    public function test_an_archive_takes_the_site_wide_values(): void {
        // A type another plugin registers: it has an archive and no Fabrement record.
        $this->site_code( 'qa-foreign-type', "add_action( 'init', static function (): void { register_post_type( 'qa_sforeign', [ 'public' => true, 'has_archive' => true, 'label' => 'Foreign' ] ); } );" );
        $this->site()->wp( 'rewrite', 'flush' );
        $id = (int) trim( $this->site()->wp( 'post', 'create', '--post_type=qa_sforeign', '--post_status=publish', '--post_title=A foreign thing', '--porcelain' ) );
        $this->assertGreaterThan( 0, $id );

        $out = $this->mcp( 'set-seo', [ 'archive' => 'qa_sforeign', 'title' => 'Foreign things', 'noindex' => true ] );
        $this->assertSame( [ 'title', 'noindex' ], $out['written'] );
        $this->assertStringContainsString( 'site-wide values', implode( ' ', $out['warnings'] ) );
        $titles = $this->site()->option( 'wpseo_titles' );
        $this->assertSame( 'Foreign things', $titles['title-ptarchive-qa_sforeign'] ?? null, 'Yoast\'s one value for the archive' );
        $this->assertTrue( (bool) ( $titles['noindex-ptarchive-qa_sforeign'] ?? false ) );

        $read = $this->mcp( 'get-seo', [ 'archive' => 'qa_sforeign' ] );
        $this->assertSame( 'Foreign things', $read['archive']['stored']['title'] ?? null );
        $this->assertStringNotContainsString( 'language', $read['archive']['note'] . implode( ' ', $out['warnings'] ), 'one language here: nothing about languages' );

        $page = $this->head( $read['archive']['url'] );
        $this->assertStringStartsWith( 'Foreign things', $page->title() );
        $this->assertTrue( $page->noindex() );

        $before = $this->site()->option( 'wpseo_titles' );
        $this->assertStringContainsString( 'cannot be set on an archive', $this->mcp_error( 'set-seo', [ 'archive' => 'qa_sforeign', 'canonical' => 'https://example.com/' ] ) );
        $this->assertSame( $before, $this->site()->option( 'wpseo_titles' ), 'nothing written' );

        $this->assertStringContainsString( 'there is no type setting for it to follow', $this->mcp_error( 'set-seo', [ 'archive' => 'qa_sforeign', 'title' => 'Other things', 'noindex' => null ] ) );
        $this->assertSame( $before, $this->site()->option( 'wpseo_titles' ), 'null noindex: nothing written, not even the title' );
    }

    public function test_set_seo_defaults_names_only_what_it_takes(): void {
        $before = $this->site()->option( 'wpseo_titles' );
        $error  = $this->mcp_error( 'set-seo-defaults', [ 'site' => true ] );
        $this->assertStringStartsWith( 'No site-scope field given', $error );
        foreach ( [ 'companyLogo,', 'defaultOgImage,', 'breadcrumbsHome' ] as $not_taken ) {
            $this->assertStringNotContainsString( $not_taken, $error . ',', 'a field the tool refuses is not offered: ' . $not_taken );
        }
        $this->assertStringContainsString( 'companyLogoId', $error );
        $this->assertSame( $before, $this->site()->option( 'wpseo_titles' ) );
    }

    public function test_a_switch_sent_as_a_string_is_refused_and_nothing_is_written(): void {
        $page   = $this->record( 'page', 'String switch' );
        $titles = $this->site()->option( 'wpseo_titles' );

        $this->assertStringContainsString( '`noindex` must be true or false', $this->mcp_error( 'set-seo', [ 'contentId' => $page, 'noindex' => 'false' ] ) );
        $this->assertStringContainsString( '`cornerstone` must be true or false', $this->mcp_error( 'set-seo', [ 'contentId' => $page, 'cornerstone' => '1' ] ) );
        $this->assertSame( [], $this->yoast( $page, 'meta-robots-noindex' ) );

        $this->assertStringContainsString( '`site` must be true or false', $this->mcp_error( 'set-seo-defaults', [ 'site' => 'false', 'separator' => 'sc-dash' ] ) );
        $this->assertStringContainsString( '`noindex` must be true or false', $this->mcp_error( 'set-seo-defaults', [ 'postType' => 'post', 'noindex' => '0' ] ) );
        $this->assertSame( $titles, $this->site()->option( 'wpseo_titles' ) );

        $this->mcp( 'set-seo', [ 'contentId' => $page, 'noindex' => false, 'cornerstone' => false ] );
        $this->assertStringContainsString( 'input[cornerstone] is not of type boolean', $this->mcp_error( 'set-seo', [ 'contentId' => $page, 'cornerstone' => null ] ) );
        $this->assertStringContainsString( 'input[breadcrumbsEnabled] is not of type boolean', $this->mcp_error( 'set-seo-defaults', [ 'site' => true, 'breadcrumbsEnabled' => null ] ) );
        $this->assertStringContainsString( 'input[noindex] is not of type boolean', $this->mcp_error( 'set-seo-defaults', [ 'postType' => 'post', 'noindex' => null ] ) );
        $this->mcp( 'set-seo-defaults', [ 'site' => true, 'breadcrumbsEnabled' => false ] );
        $this->assertFalse( (bool) $this->site()->option( 'wpseo_titles' )['breadcrumbs-enable'] );
        $this->mcp( 'set-seo-defaults', [ 'site' => true, 'breadcrumbsEnabled' => true ] );
        $this->assertTrue( (bool) $this->site()->option( 'wpseo_titles' )['breadcrumbs-enable'] );
    }

    public function test_the_separator_is_one_of_yoasts_codes(): void {
        $titles = $this->site()->option( 'wpseo_titles' );
        $error  = $this->mcp_error( 'set-seo-defaults', [ 'site' => true, 'separator' => 'sc-smtilde' ] );
        $this->assertStringContainsString( 'sc-tilde', $error );
        $this->assertSame( $titles, $this->site()->option( 'wpseo_titles' ) );

        $this->mcp( 'set-seo-defaults', [ 'site' => true, 'separator' => 'sc-tilde' ] );
        $this->assertSame( 'sc-tilde', $this->site()->option( 'wpseo_titles' )['separator'] );
    }

    public function test_a_value_yoast_would_not_keep_is_refused_and_the_old_one_stays(): void {
        $page = $this->record( 'page', 'Kept values' );
        $this->mcp( 'set-seo', [ 'contentId' => $page, 'title' => 'Old title', 'canonical' => 'https://example.org/old' ] );
        foreach ( [
            [ 'title' => 'Books <b>new</b>' ],
            [ 'title' => '100%Ad off' ],
            [ 'metaDesc' => '<em></em>' ],
            [ 'canonical' => 'http://' ],
        ] as $sent ) {
            $error = $this->mcp_error( 'set-seo', [ 'contentId' => $page ] + $sent );
            $this->assertStringContainsString( 'Nothing was written', $error, (string) json_encode( $sent ) );
        }
        $this->assertSame( [ 'Old title' ], $this->yoast( $page, 'title' ) );
        $this->assertSame( [ 'https://example.org/old' ], $this->yoast( $page, 'canonical' ) );
        $this->assertStringContainsString( 'title: Yoast would store "Books new"', $this->mcp_error( 'set-seo', [ 'contentId' => $page, 'title' => 'Books <b>new</b>' ] ) );

        $term = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Kept terms' ] )['termId'];
        $this->assertStringContainsString( 'Nothing was written', $this->mcp_error( 'set-seo', [ 'termId' => $term, 'title' => 'A <i>term</i>' ] ) );

        $titles = $this->site()->option( 'wpseo_titles' );
        $this->assertStringContainsString( 'titleTemplate: Yoast would store', $this->mcp_error( 'set-seo-defaults', [ 'postType' => 'post', 'titleTemplate' => '%%title%% <b>x</b>' ] ) );
        $this->assertSame( $titles, $this->site()->option( 'wpseo_titles' ) );

        $this->mcp( 'set-seo', [ 'contentId' => $page, 'title' => '%%title%% %%sep%% 50 % off' ] );
        $this->assertSame( [ '%%title%% %%sep%% 50 % off' ], $this->yoast( $page, 'title' ), 'variables and a plain percent are kept' );
    }

    public function test_article_types_are_the_ones_yoast_accepts_on_this_site(): void {
        $post = $this->record( 'post', 'Article kinds' );
        $this->site_code( 'qa-article-types', "add_filter( 'wpseo_schema_article_types', static function ( \$types ) { unset( \$types['TechArticle'] ); \$types['Recipe'] = ''; return \$types; } );" );
        $this->assertStringContainsString( 'input[schemaArticleType]', $this->mcp_error( 'set-seo', [ 'contentId' => $post, 'schemaArticleType' => 'TechArticle' ] ) );
        $this->assertSame( [ 'Recipe' ], $this->yoast( $this->mcp( 'set-seo', [ 'contentId' => $post, 'schemaArticleType' => 'Recipe' ] )['contentId'], 'schema_article_type' ) );
        $this->remove_site_code( 'qa-article-types' );
    }

    public function test_taxonomy_defaults_reach_its_term_pages_and_a_terms_own_value_wins(): void {
        $topic = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Defaults topic' ] )['termId'];
        $other = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Own topic' ] )['termId'];
        foreach ( [ $topic, $other ] as $term ) {
            $post = $this->record( 'post', 'Filed under ' . $term );
            $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $term ], 'mode' => 'replace' ] );
        }
        $urls = array_column( $this->mcp( 'list-terms', [ 'taxonomy' => 'category' ] )['terms'], 'url', 'termId' );

        $out = $this->mcp( 'set-seo-defaults', [ 'taxonomy' => 'category', 'titleTemplate' => 'Topic %%term_title%%', 'metaDescTemplate' => 'All about %%term_title%%', 'noindex' => true ] );
        $this->assertSame( 'category', $out['slug'] );
        $this->mcp( 'set-seo', [ 'termId' => $other, 'title' => 'Own term title', 'noindex' => false ] );

        $page = $this->head( $urls[ $topic ] );
        $this->assertSame( 'Topic Defaults topic', $page->title() );
        $this->assertSame( 'All about Defaults topic', $page->description() );
        $this->assertTrue( $page->noindex() );

        $own = $this->head( $urls[ $other ] );
        $this->assertSame( 'Own term title', $own->title(), 'the term says otherwise' );
        $this->assertFalse( $own->noindex() );
        $this->assertSame( 'All about Own topic', $own->description(), 'what the term does not say comes from the taxonomy' );

        $this->mcp( 'set-seo-defaults', [ 'taxonomy' => 'category', 'titleTemplate' => null, 'metaDescTemplate' => null, 'noindex' => false ] );
        $this->assertFalse( $this->head( $urls[ $topic ] )->noindex() );
    }

    public function test_a_type_or_taxonomy_is_named_exactly(): void {
        $titles = $this->site()->option( 'wpseo_titles' );
        $this->assertStringContainsString( 'Post type "Post" is not registered', $this->mcp_error( 'set-seo-defaults', [ 'postType' => 'Post', 'titleTemplate' => 'X %%title%%' ] ) );
        $this->assertStringContainsString( 'Taxonomy "Category" is not registered', $this->mcp_error( 'set-seo-defaults', [ 'taxonomy' => 'Category', 'titleTemplate' => 'X %%term_title%%' ] ) );
        $this->assertStringContainsString( 'There is no post type "Post"', $this->mcp_error( 'set-seo', [ 'archive' => 'Post', 'title' => 'X' ] ) );
        $this->assertStringContainsString( 'Post type "Post" is not registered', $this->mcp_error( 'get-seo', [ 'postType' => 'Post' ] ) );
        $this->assertSame( $titles, $this->site()->option( 'wpseo_titles' ) );
        $this->assertSame( 'post', $this->mcp( 'set-seo-defaults', [ 'postType' => 'post', 'titleTemplate' => '%%title%% %%sep%% %%sitename%%' ] )['slug'] );
    }

    public function test_a_field_of_another_scope_is_refused_and_nothing_is_written(): void {
        $titles = $this->site()->option( 'wpseo_titles' );
        $error  = $this->mcp_error( 'set-seo-defaults', [ 'postType' => 'post', 'titleTemplate' => 'Changed %%title%%', 'companyName' => 'X' ] );
        $this->assertStringStartsWith( 'companyName is not a postType-scope field; nothing was written.', $error );
        $this->assertStringContainsString( 'titleTemplate', $error, 'names what the scope takes' );
        $this->assertSame( $titles, $this->site()->option( 'wpseo_titles' ) );
    }

    public function test_the_archive_is_named_by_a_type_that_has_one(): void {
        $this->post_type( 'qa_snoarch', [ 'singular' => 'Thing', 'hasArchive' => false ] );
        $this->post_type( 'qa_sarch', [ 'singular' => 'Shelf', 'rewrite' => 'qa-sarch' ] );
        $record = $this->record( 'qa_sarch', 'A shelf' );

        $this->assertStringContainsString( 'no post type "qa_nope"', $this->mcp_error( 'set-seo', [ 'archive' => 'qa_nope', 'title' => 'x' ] ) );
        $this->assertStringContainsString( 'registered without an archive', $this->mcp_error( 'set-seo', [ 'archive' => 'qa_snoarch', 'title' => 'x' ] ) );
        $this->assertStringContainsString( 'registered without an archive', $this->mcp_error( 'get-seo', [ 'archive' => 'qa_snoarch' ] ) );
        $this->assertStringStartsWith( 'Give exactly one of', $this->mcp_error( 'set-seo', [ 'archive' => 'qa_sarch', 'contentId' => $record, 'title' => 'x' ] ) );
        $this->assertSame( [], $this->yoast( $record, 'title' ), 'nothing written' );
    }

    // ── no SEO plugin ────────────────────────────────────────────────────────────────────────

    /** Last in this class: Yoast is switched off for good (the next class restores the snapshot). */
    public function test_without_an_seo_plugin_the_tools_say_so_and_store_nothing(): void {
        $page = $this->record( 'page', 'No plugin' );
        $this->post_type( 'qa_snoseo', [ 'singular' => 'Item', 'rewrite' => 'qa-snoseo' ] );
        $this->site()->wp( 'plugin', 'deactivate', 'wordpress-seo' );

        $this->assertStringContainsString( 'Yoast SEO is not active', $this->mcp_error( 'set-seo', [ 'contentId' => $page, 'title' => 'x' ] ) );
        $this->assertStringContainsString( 'Yoast SEO is not active', $this->mcp_error( 'set-seo', [ 'archive' => 'qa_snoseo', 'title' => 'x' ] ) );
        $this->assertStringContainsString( 'Yoast SEO is not active', $this->mcp_error( 'set-seo-defaults', [ 'site' => true, 'separator' => '-' ] ) );
        $this->assertStringContainsString( 'Yoast SEO is not active', $this->mcp_error( 'list-seo', [] ) );
        $yoast = $this->mcp( 'get-seo', [] )['yoast'];
        $this->assertFalse( $yoast['active'] );
        $this->assertTrue( $yoast['installed'], 'switched off, still on disk: install-plugin only activates it' );
        $this->assertSame( [], $this->yoast( $page, 'title' ) );

        // No plugin to list page types: the schema takes any string instead of an empty enum.
        $schema = array_column( $this->client()->tools(), null, 'name' )['fabrement-set-seo']['inputSchema'];
        $this->assertArrayNotHasKey( 'enum', $schema['properties']['schemaPageType'] );
        $this->assertArrayNotHasKey( 'enum', $schema['properties']['robotsAdvanced']['items'] );
    }
}
