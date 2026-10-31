<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SeoPage;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Structured data an agent writes with set-schema, as a piece of Yoast's graph: the page's own
 * values as Yoast has them, what a template may never read (protected meta, the body, anything
 * behind a password), and Yoast's own nodes —
 * which ours point at but never replace or extend. Every graph is checked where it lands: in the
 * preview the tool answers with and in the JSON-LD a crawler fetches. Then Yoast switched off.
 */
final class SchemaTest extends SiteTestCase {

    private const COURSE = 'qa_course';

    private static bool $ready = false;

    /** @var array{course:int} */
    private static array $ids = [];

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->post_type( self::COURSE, [ 'singular' => 'Course', 'supports' => [ 'title', 'editor', 'thumbnail' ] ] );
            self::$ids   = [ 'course' => $this->record( self::COURSE, 'UX basics' ) ];
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        self::$ids   = [];
        parent::tearDownAfterClass();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    private function head( string $url ): SeoPage {
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'], $url );
        return SeoPage::of( $page['body'] );
    }

    /** The graph stored on a record, read straight ('' = none). */
    private function stored( int $id ): string {
        return (string) ( $this->site()->meta( $id, '_fabrement_schema' )[0] ?? '' );
    }

    /** A post another plugin gave a SKU: an excerpt, its description in Yoast, a category. */
    private function chair( string $title = 'Chair', bool $locked = false ): int {
        $id = $this->record( 'post', $title );
        $this->mcp( 'save-content', [ 'mode' => 'replace', 'contentId' => $id, 'excerpt' => 'A good chair', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Secret body</p><!-- /wp:paragraph -->' ] ] ] );
        $this->mcp( 'set-seo', [ 'contentId' => $id, 'metaDesc' => 'A good chair' ] );
        $this->site()->wp( 'post', 'meta', 'update', (string) $id, 'sku', 'CH-1' );
        $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Furniture' ] );
        $this->mcp( 'set-post-terms', [ 'contentId' => $id, 'taxonomy' => 'category', 'terms' => [ 'Furniture' ], 'mode' => 'replace' ] );
        if ( $locked ) {
            $this->site()->wp( 'post', 'update', (string) $id, '--post_password=letmein' );
        }
        return $id;
    }

    private static function product_template(): array {
        return [ [ '@type' => 'Product', '@id' => '{{url}}#product', 'name' => '{{title}}', 'description' => '{{excerpt}}', 'sku' => '{{meta:sku}}', 'category' => '{{term:category}}' ] ];
    }

    private function remove_site_code( string $name ): void {
        $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/' . $name . '.php' );
    }

    public function test_a_mistyped_token_is_refused_rather_than_printed(): void {
        $course = self::$ids['course'];
        $this->mcp( 'set-schema', [ 'contentId' => $course, 'graph' => [ [ '@type' => 'Course', 'name' => '{{title}}' ] ] ] );
        $before = $this->stored( $course );
        foreach ( [ '{{Title}}' => '{{Title}}', 'Course {{meta :price}}' => '{{meta :price}}', '{{ title }' => '{{ title }', 'Price }}' => '}}' ] as $value => $fragment ) {
            $error = $this->mcp_error( 'set-schema', [ 'contentId' => $course, 'graph' => [ [ '@type' => 'Course', 'name' => $value ] ] ] );
            $this->assertStringContainsString( '"' . $fragment . '" is not a token', $error, $value );
        }
        $this->assertSame( $before, $this->stored( $course ), 'the graph it had is kept' );
        $this->mcp( 'set-schema', [ 'contentId' => $course, 'graph' => [ [ '@type' => 'Course', 'name' => '{{ title }} — {{meta:price}}' ] ] ] );
    }

    // ── what a template may read ─────────────────────────────────────────────────────────────

    public function test_a_template_reads_the_page_values_another_plugins_meta_and_terms(): void {
        $id  = $this->chair();
        $url = $this->url( $id );
        $out = $this->mcp( 'set-schema', [ 'contentId' => $id, 'graph' => self::product_template() ] );
        $this->assertSame( 1, $out['pieces'] );

        $page    = $this->head( $url );
        $product = $page->node_by_id( $url . '#product' );
        $this->assertSame( 'Chair', $product['name'] );
        $this->assertSame( 'A good chair', $product['description'] );
        $this->assertSame( 'CH-1', $product['sku'], 'another plugin\'s field' );
        $this->assertSame( 'Furniture', $product['category'] );

        $this->mcp( 'set-schema', [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', '@id' => '{{url}}#thing', 'url' => '{{url}}', 'isPartOf' => [ '@id' => '{{site_url}}#website' ] ] ] ] );
        $page    = $this->head( $url );
        $thing   = $page->node_by_id( $url . '#thing' );
        $webpage = $page->node_of_type( 'WebPage' );
        $this->assertSame( $webpage['@id'], $thing['url'], 'Yoast\'s WebPage id' );
        $this->assertNotNull( $page->node_by_id( $thing['isPartOf']['@id'] ), 'Yoast\'s WebSite node' );
        $this->assertContains( 'WebSite', (array) $page->node_by_id( $thing['isPartOf']['@id'] )['@type'] );

    }

    public function test_what_a_template_may_never_read_is_refused_and_nothing_is_saved(): void {
        $id = $this->chair( 'Refused chair' );
        $this->taxonomy( 'qa_private_tax', [ 'singular' => 'Secret', 'postTypes' => [ 'post' ], 'public' => false ] );

        foreach ( [ '_edit_last', '_wp_page_template', '_yoast_wpseo_title' ] as $key ) {
            $this->assertStringContainsString( 'reads a protected key', $this->mcp_error( 'set-schema', [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', 'name' => '{{meta:' . $key . '}}' ] ] ] ), $key );
        }
        $this->assertStringContainsString( 'unknown token {{content_text}}', $this->mcp_error( 'set-schema', [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Article', 'articleBody' => '{{content_text}}' ] ] ] ), 'the page body is not a token' );
        foreach ( [ 'site_name', 'author_name', 'author_url', 'post_id', 'post_type', 'featured_image_id', 'field:price', 'option:site.phone' ] as $gone ) {
            $this->assertStringContainsString( 'unknown token', $this->mcp_error( 'set-schema', [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', 'name' => '{{' . $gone . '}}' ] ] ] ), $gone );
        }
        $this->assertStringContainsString( 'is not a public taxonomy', $this->mcp_error( 'set-schema', [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', 'name' => '{{term:qa_private_tax}}' ] ] ] ) );
        $this->assertSame( '', $this->stored( $id ), 'nothing saved on the record' );
    }

    public function test_a_protected_key_stored_before_the_check_still_prints_nothing(): void {
        $id  = $this->chair( 'Old chair' );
        $url = $this->url( $id );
        // A graph saved by an older version, past today's validation.
        $this->site()->wp( 'post', 'meta', 'update', (string) $id, '_fabrement_schema', (string) json_encode( [ [ '@type' => 'Thing', '@id' => '{{url}}#old', 'name' => 'Kept', 'identifier' => '{{meta:_edit_last}}' ] ], JSON_UNESCAPED_SLASHES ) );

        $node = $this->head( $url )->node_by_id( $url . '#old' );
        $this->assertSame( 'Kept', $node['name'] );
        $this->assertArrayNotHasKey( 'identifier', $node );
    }

    public function test_a_password_protected_record_gives_away_nothing_behind_the_password(): void {
        $id  = $this->chair( 'Locked chair', true );
        $url = $this->url( $id );
        $this->mcp( 'set-schema', [ 'contentId' => $id, 'graph' => self::product_template() ] );

        $page = $this->get( $url )['body'];
        $node = SeoPage::of( $page )->node_by_id( $url . '#product' );
        $this->assertSame( 'Locked chair', $node['name'], 'the title and address are public on a protected page anyway' );
        foreach ( [ 'description', 'sku', 'category' ] as $gone ) {
            $this->assertArrayNotHasKey( $gone, $node, $gone );
        }
        $this->assertStringNotContainsString( 'Secret body', (string) json_encode( SeoPage::of( $page )->graph() ) );
        $this->assertStringNotContainsString( 'protected post', (string) json_encode( $node ), 'no "There is no excerpt…" sentence' );
    }

    // ── our nodes in Yoast's graph ───────────────────────────────────────────────────────────

    public function test_our_nodes_are_a_piece_of_yoasts_graph_beside_its_own_and_its_filters_reach_them(): void {
        $page = $this->record( 'page', 'Chair page' );
        $url  = $this->url( $page );
        $this->mcp( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Product', '@id' => '{{url}}#product', 'name' => '{{title}}', 'mainEntityOfPage' => [ '@id' => '{{url}}' ] ] ] ] );

        $head    = $this->head( $url );
        $product = $head->node_by_id( $url . '#product' );
        $this->assertSame( 'Chair page', $product['name'] ?? null );
        $this->assertSame( [ '@id' => $url ], $product['mainEntityOfPage'] ?? null );
        $webpage = $head->node_by_id( $url );
        $this->assertNotNull( $webpage, 'the WebPage of Yoast is still there' );
        $this->assertArrayHasKey( 'breadcrumb', $webpage );

        // The Schema API filters of Yoast reach our nodes as they reach its own.
        $this->site_code( 'qa-schema-off', "add_filter( 'wpseo_schema_needs_fabrement', '__return_false' );" );
        $this->assertNull( $this->head( $url )->node_by_id( $url . '#product' ), 'wpseo_schema_needs_fabrement turns them off' );
        $this->remove_site_code( 'qa-schema-off' );
        $this->site_code( 'qa-schema-touch', "add_filter( 'wpseo_schema_fabrement', static fn( \$node ) => is_array( \$node ) ? \$node + [ 'disambiguatingDescription' => 'seen by the site' ] : \$node );" );
        $touched = $this->head( $url )->node_by_id( $url . '#product' );
        $this->remove_site_code( 'qa-schema-touch' );
        $this->assertSame( 'seen by the site', $touched['disambiguatingDescription'] ?? null, 'wpseo_schema_fabrement sees each node' );

        $this->assertNull( $this->head( '/?s=chair' )->node_by_id( $url . '#product' ), 'a search page describes no record' );
    }

    public function test_the_id_of_a_yoast_node_is_refused(): void {
        $page = $this->record( 'page', 'Ids' );
        $url  = $this->url( $page );
        $home = $this->site()->url( '/' );
        $taken = [ '{{url}}', '{{ url }}', '{{url}}#breadcrumb', '{{url}}#primaryimage', '{{url}}#article', '{{site_url}}#website', '{{site_url}}#organization', '{{site_url}}#/schema/person/1', $url, $url . '#breadcrumb', $home . '#organization' ];
        foreach ( $taken as $id ) {
            $this->assertStringContainsString( 'is the id of a node Yoast SEO already prints', $this->mcp_error( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Product', '@id' => $id, 'name' => 'x' ] ] ] ), $id );
        }
        $this->assertSame( '', $this->stored( $page ), 'nothing saved' );

        // On a static front page the page URL is the site URL: {{url}}#organization is Yoast's.
        $front = $this->record( 'page', 'Home' );
        $this->reading( $front );
        foreach ( [ '{{url}}#organization', '{{site_url}}#breadcrumb' ] as $id ) {
            $this->assertStringContainsString( 'is the id of a node Yoast SEO already prints', $this->mcp_error( 'set-schema', [ 'contentId' => $front, 'graph' => [ [ '@type' => 'Thing', '@id' => $id, 'name' => 'x' ] ] ] ), 'front page: ' . $id );
        }
        $this->reading( 0 );

        foreach ( [ '{{url}}#product', '{{url}}#/product/1', $url . '#product', '{{site_url}}#store' ] as $id ) {
            $this->assertSame( 1, $this->mcp( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Product', '@id' => $id, 'name' => 'x' ] ] ] )['pieces'], $id );
        }
    }

    public function test_a_yoast_node_inside_ours_is_a_reference_only(): void {
        $page  = $this->record( 'page', 'Event page' );
        $url   = $this->url( $page );
        $cases = [
            'organizer' => [ '@id' => '{{site_url}}#organization', 'name' => 'Another name', 'telephone' => '+1' ],
            'isPartOf'  => [ '@id' => '{{url}}', '@type' => 'WebPage' ],
            'performer' => [ [ '@type' => 'Person', 'name' => 'A' ], [ '@id' => '{{site_url}}#organization', 'logo' => 'x.png' ] ],
        ];
        foreach ( $cases as $property => $value ) {
            $this->assertStringContainsString( 'names a node Yoast SEO prints', $this->mcp_error( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Event', '@id' => '{{url}}#event', 'name' => 'x', $property => $value ] ] ] ), $property );
        }
        $this->assertStringContainsString( 'graph[0].performer[1]', $this->mcp_error( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Event', '@id' => '{{url}}#event', 'name' => 'x', 'performer' => $cases['performer'] ] ] ] ) );
        $this->assertSame( '', $this->stored( $page ) );

        $this->mcp( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Event', '@id' => '{{url}}#event', 'name' => 'x', 'organizer' => [ '@id' => '{{site_url}}#organization' ], 'location' => [ '@type' => 'Place', '@id' => '{{url}}#place', 'name' => 'Hall' ] ] ] ] );
        $event = $this->head( $url )->node_by_id( $url . '#event' );
        $this->assertSame( [ '@id' => $this->site()->url( '/' ) . '#organization' ], $event['organizer'], 'a reference alone is fine' );
        $this->assertSame( 'Hall', $event['location']['name'], 'and a nested node of our own' );
    }

    public function test_graphs_stored_by_an_older_version_never_change_yoasts_nodes(): void {
        $page = $this->record( 'page', 'Stored' );
        $url  = $this->url( $page );
        $home = $this->site()->url( '/' );
        $write = fn( array $graph ) => $this->site()->wp( 'post', 'meta', 'update', (string) $page, '_fabrement_schema', (string) json_encode( $graph, JSON_UNESCAPED_SLASHES ) );

        $write( [ [ '@type' => 'Event', '@id' => '{{url}}#event', 'name' => 'Talk', 'organizer' => [ '@id' => '{{site_url}}#organization', 'name' => 'Swapped' ] ] ] );
        $head = $this->head( $url );
        $this->assertSame( [ '@id' => $home . '#organization' ], $head->node_by_id( $url . '#event' )['organizer'], 'printed as a reference' );
        $this->assertStringNotContainsString( 'Swapped', (string) json_encode( $head->graph() ) );

        $write( [ [ '@type' => 'Product', '@id' => '{{url}}', 'name' => 'Swapped' ], [ '@type' => 'Thing', '@id' => '{{url}}#thing', 'name' => 'Kept' ] ] );
        $head = $this->head( $url );
        $this->assertSame( 1, $head->count_id( $url ), 'one node with the page id — ours is not printed beside it' );
        $this->assertContains( 'WebPage', (array) $head->node_by_id( $url )['@type'], 'the WebPage of Yoast, not ours in its place' );
        $this->assertStringNotContainsString( 'Swapped', (string) json_encode( $head->graph() ) );
        $this->assertSame( 'Kept', $head->node_by_id( $url . '#thing' )['name'] ?? null );

        // Under a custom canonical Yoast names #breadcrumb after the canonical, not the permalink.
        $canonical = $this->site()->url( '/elsewhere/' );
        $this->mcp( 'set-seo', [ 'contentId' => $page, 'canonical' => $canonical ] );
        $write( [ [ '@type' => 'BreadcrumbList', '@id' => $canonical . '#breadcrumb', 'name' => 'Swapped' ] ] );
        $head = $this->head( $url );
        $this->assertSame( 1, $head->count_id( $canonical . '#breadcrumb' ) );
        $this->assertStringNotContainsString( 'Swapped', (string) json_encode( $head->graph() ) );
    }

    public function test_the_posts_page_takes_its_own_nodes(): void {
        $posts = $this->record( 'page', 'Blog' );
        $plain = $this->record( 'page', 'About' );
        $this->reading( $plain, $posts );
        try {
            $this->mcp( 'set-schema', [ 'contentId' => $posts, 'graph' => [ [ '@type' => 'Blog', '@id' => '{{url}}#blog', 'name' => 'Own' ] ] ] );

            $url = $this->url( $posts );
            $this->assertSame( 'Own', $this->head( $url )->node_by_id( $url . '#blog' )['name'] ?? null );
        } finally {
            $this->reading( 0 );
        }
    }

    public function test_a_graph_warns_what_will_be_thin(): void {
        // A course without the meta the graph reads: it warns it comes out empty there.
        $out = $this->mcp( 'set-schema', [ 'contentId' => self::$ids['course'], 'graph' => [ [ '@type' => 'Event', 'name' => '{{title}}', 'url' => '{{meta:qa_site}}' ] ] ] );
        $all = implode( "\n", $out['warnings'] ?? [] );
        $this->assertStringContainsString( 'Event has no location', $all );
        $this->assertStringContainsString( 'graph[0].url resolved empty on record ' . self::$ids['course'], $all );
    }

    /** Last in this class: Yoast is switched off for good (the next class restores the snapshot). */
    public function test_without_an_seo_plugin_nothing_is_written(): void {
        $id = $this->record( 'post', 'No plugin chair' );
        $this->site()->wp( 'plugin', 'deactivate', 'wordpress-seo' );
        $this->assertStringContainsString( 'Yoast SEO is not active', $this->mcp_error( 'set-schema', [ 'contentId' => $id, 'graph' => self::product_template() ] ) );
        $this->assertSame( '', $this->stored( $id ) );
    }

    /** Settings → Reading as the administrator sets it: the home page and the posts page (0 = latest posts). */
    private function reading( int $front, int $posts = 0 ): void {
        $this->site_runs( "update_option( 'show_on_front', $front > 0 ? 'page' : 'posts' ); update_option( 'page_on_front', $front ); update_option( 'page_for_posts', $posts ); return true;" );
    }
}
