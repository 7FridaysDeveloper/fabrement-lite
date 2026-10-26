<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SeoPage;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Structured data an agent writes with set-schema, as a piece of Yoast's graph: {{field:…}} and
 * {{option:…}} read as the page shows them, the page's own values as Yoast has them, what a template
 * may never read (protected meta, the body, anything behind a password), and Yoast's own nodes —
 * which ours point at but never replace or extend. Every graph is checked where it lands: in the
 * preview the tool answers with and in the JSON-LD a crawler fetches. Then Yoast switched off.
 */
final class SchemaTest extends SiteTestCase {

    private const COURSE  = 'qa_course';
    private const TEACHER = 'qa_steacher';

    private static bool $ready = false;

    /** @var array{teacher:int, course:int, photo:int, photoUrl:string} */
    private static array $ids = [];

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->site()->wp( 'option', 'update', 'timezone_string', 'Europe/Kyiv' );
            $this->post_type( self::TEACHER, [ 'singular' => 'Teacher', 'supports' => [ 'title', 'editor', 'thumbnail' ] ] );
            $this->post_type( self::COURSE, [ 'singular' => 'Course', 'supports' => [ 'title', 'editor', 'thumbnail' ] ] );
            $this->fields( self::TEACHER, [ self::field( 'position', 'text' ) ] );
            $this->fields( self::COURSE, [
                self::field( 'price', 'number', [ 'format' => 'decimal' ] ),
                self::field( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => 'For beginners' ], [ 'value' => 'pro', 'label' => 'For pros' ] ], 'default' => 'beginner' ] ),
                self::field( 'starts', 'date_time_picker' ),
                self::field( 'day', 'date_picker' ),
                self::field( 'free', 'true_false' ),
                self::field( 'photo', 'image' ),
                self::field( 'site', 'link' ),
                self::field( 'notes', 'wysiwyg' ),
                self::field( 'teacher', 'post_object', [ 'postTypes' => [ self::TEACHER ] ] ),
                self::field( 'venue', 'group', [ 'sub_fields' => [ self::field( 'city', 'text' ), self::field( 'kind', 'select', [ 'choices' => [ [ 'value' => 'hall', 'label' => 'Concert hall' ] ] ] ) ] ] ),
                self::field( 'modules', 'repeater', [ 'sub_fields' => [ self::field( 'title', 'text' ) ] ] ),
            ] );
            $png   = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
            $photo = $this->mcp( 'upload-media', [ 'base64' => $png, 'filename' => 'qa-canola.png', 'altText' => 'A canola field' ] );

            $teacher = $this->record( self::TEACHER, 'Olena' );
            $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $teacher, 'blocks' => [], 'featuredImage' => (int) $photo['id'] ] );
            $this->mcp( 'set-field-values', [ 'postId' => $teacher, 'values' => [ 'position' => 'Lead designer' ] ] );

            $course = $this->record( self::COURSE, 'UX basics' );
            $this->mcp( 'set-field-values', [ 'postId' => $course, 'values' => [
                'price'   => 59.9,
                'starts'  => '2026-07-15T18:00',
                'day'     => '2026-07-01',
                'free'    => false,
                'photo'   => (int) $photo['id'],
                'site'    => [ 'url' => 'https://example.com/ux', 'label' => 'Course site' ],
                'notes'   => '<p>Bring a <strong>laptop</strong>.</p>',
                'teacher' => $teacher,
                'venue'   => [ 'city' => 'Lviv', 'kind' => 'hall' ],
            ] ] );
            self::$ids   = [ 'teacher' => $teacher, 'course' => $course, 'photo' => (int) $photo['id'], 'photoUrl' => (string) $photo['url'] ];
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

    /** Resolve one Course node on the course; the node from the tool's preview, checked against the page. */
    private function on_course( array $props ): array {
        $out  = $this->mcp( 'set-schema', [ 'contentId' => self::$ids['course'], 'graph' => [ [ '@type' => 'Course', '@id' => '{{url}}#course' ] + $props ] ] );
        $url  = $this->url( self::$ids['course'] );
        $node = null;
        foreach ( $out['preview']['graph'] as $n ) {
            if ( is_array( $n ) && ( $n['@id'] ?? '' ) === $url . '#course' ) {
                $node = $n;
            }
        }
        $this->assertNotNull( $node, 'the preview holds the node' );
        $this->assertSame( $node, $this->head( $url )->node_by_id( $url . '#course' ), 'the page prints what the preview showed' );
        unset( $node['@id'] );
        return $node;
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

    // ── {{field:…}} and {{option:…}} ─────────────────────────────────────────────────────────

    public function test_each_field_type_as_schema_text(): void {
        $node = $this->on_course( [
            'price'  => '{{field:price}}',
            'level'  => '{{field:level}}',
            'label'  => '{{field:level.label}}',
            'free'   => '{{field:free}}',
            'day'    => '{{field:day}}',
            'image'  => '{{field:photo}}',
            'alt'    => '{{field:photo.alt}}',
            'url'    => '{{field:site}}',
            'anchor' => '{{field:site.label}}',
            'notes'  => '{{field:notes}}',
            'city'   => '{{field:venue.city}}',
            'kind'   => '{{field:venue.kind.label}}',
        ] );
        $this->assertSame( [
            '@type'  => 'Course',
            'price'  => '59.9',
            'level'  => 'beginner',
            'label'  => 'For beginners',
            'free'   => 'false',
            'day'    => '2026-07-01',
            'image'  => self::$ids['photoUrl'],
            'alt'    => 'A canola field',
            'url'    => 'https://example.com/ux',
            'anchor' => 'Course site',
            'notes'  => 'Bring a laptop.',
            'city'   => 'Lviv',
            'kind'   => 'Concert hall',
        ], $node, 'a select never set: the default, as the page shows it; rich text as plain words' );

        // The value changes, the graph follows on the next view.
        $this->mcp( 'set-field-values', [ 'postId' => self::$ids['course'], 'values' => [ 'level' => 'pro', 'price' => 70 ] ] );
        $url  = $this->url( self::$ids['course'] );
        $page = $this->head( $url )->node_by_id( $url . '#course' );
        $this->assertSame( [ 'pro', 'For pros', '70' ], [ $page['level'], $page['label'], $page['price'] ] );
    }

    public function test_a_date_time_carries_the_sites_offset_on_that_date(): void {
        $summer = $this->on_course( [ 'startDate' => '{{field:starts}}', 'd' => '{{field:starts.date}}', 't' => '{{field:starts.time}}', 'u' => '{{field:starts.utc}}' ] );
        $this->assertSame( '2026-07-15T18:00:00+03:00', $summer['startDate'] );
        $this->assertSame( '2026-07-15', $summer['d'] );
        $this->assertSame( '18:00', $summer['t'] );
        $this->assertSame( '2026-07-15T15:00:00Z', $summer['u'] );

        $this->mcp( 'set-field-values', [ 'postId' => self::$ids['course'], 'values' => [ 'starts' => '2026-12-15T18:00' ] ] );
        $winter = $this->on_course( [ 'startDate' => '{{field:starts}}' ] );
        $this->assertSame( '2026-12-15T18:00:00+02:00', $winter['startDate'], 'winter time: another offset' );
        $this->mcp( 'set-field-values', [ 'postId' => self::$ids['course'], 'values' => [ 'starts' => '2026-07-15T18:00' ] ] );
    }

    public function test_a_picked_record_by_its_url_title_image_and_fields_while_it_is_public(): void {
        $teacher = self::$ids['teacher'];
        $node    = $this->on_course( [ 'instructor' => [
            '@type'    => 'Person',
            'url'      => '{{field:teacher}}',
            'name'     => '{{field:teacher.title}}',
            'image'    => '{{field:teacher.image}}',
            'jobTitle' => '{{field:teacher.fields.position}}',
        ] ] );
        $this->assertSame( [
            '@type'    => 'Person',
            'url'      => $this->url( $teacher ),
            'name'     => 'Olena',
            'image'    => self::$ids['photoUrl'],
            'jobTitle' => 'Lead designer',
        ], $node['instructor'] );

        $this->mcp( 'set-content-status', [ 'contentId' => $teacher, 'status' => 'draft' ] );
        $node = $this->on_course( [ 'instructor' => [ '@type' => 'Person', 'name' => '{{field:teacher.title}}', 'url' => '{{field:teacher}}' ] ] );
        $this->assertSame( [ '@type' => 'Person' ], $node['instructor'], 'a draft is not named in a public graph' );

        $this->mcp( 'set-content-status', [ 'contentId' => $teacher, 'status' => 'publish' ] );
        $this->site()->wp( 'post', 'update', (string) $teacher, '--post_password=x' );
        $node = $this->on_course( [ 'instructor' => [ '@type' => 'Person', 'name' => '{{field:teacher.title}}', 'jobTitle' => '{{field:teacher.fields.position}}' ] ] );
        $this->assertSame( 'Olena', $node['instructor']['name'] );
        $this->assertArrayNotHasKey( 'jobTitle', $node['instructor'], 'its fields stay behind its password' );
        $this->site()->wp( 'post', 'update', (string) $teacher, '--post_password=' );
    }

    public function test_a_mistyped_token_is_refused_rather_than_printed(): void {
        $course = self::$ids['course'];
        $this->mcp( 'set-schema', [ 'contentId' => $course, 'graph' => [ [ '@type' => 'Course', 'name' => '{{title}}' ] ] ] );
        $before = $this->stored( $course );
        foreach ( [ '{{Title}}' => '{{Title}}', 'Course {{field :price}}' => '{{field :price}}', '{{ title }' => '{{ title }', 'Price }}' => '}}' ] as $value => $fragment ) {
            $error = $this->mcp_error( 'set-schema', [ 'contentId' => $course, 'graph' => [ [ '@type' => 'Course', 'name' => $value ] ] ] );
            $this->assertStringContainsString( '"' . $fragment . '" is not a token', $error, $value );
        }
        $this->assertSame( $before, $this->stored( $course ), 'the graph it had is kept' );
        $this->mcp( 'set-schema', [ 'contentId' => $course, 'graph' => [ [ '@type' => 'Course', 'name' => '{{ title }} — {{field:price}}' ] ] ] );
    }

    public function test_unknown_paths_are_refused_with_what_exists_and_nothing_is_saved(): void {
        $course = self::$ids['course'];
        $this->mcp( 'set-schema', [ 'contentId' => $course, 'graph' => [ [ '@type' => 'Course', 'name' => '{{title}}' ] ] ] );
        $before = $this->stored( $course );
        $cases  = [
            '{{field:prise}}'                 => 'has no field "prise"',
            '{{field:venue.town}}'            => 'group "venue" has no sub-field "town"',
            '{{field:modules}}'               => '"modules" is a repeater',
            '{{field:venue}}'                 => '"venue" is a group',
            '{{field:price.label}}'           => 'not ".label"',
            '{{field:starts.year}}'           => 'not ".year"',
            '{{field:teacher.fields.salary}}' => 'has no field "salary"',
            '{{option:nope.phone}}'           => 'there is no options page "nope"',
        ];
        foreach ( $cases as $token => $message ) {
            $this->assertStringContainsString( $message, $this->mcp_error( 'set-schema', [ 'contentId' => $course, 'graph' => [ [ '@type' => 'Course', 'name' => $token ] ] ] ), $token );
        }
        $this->assertSame( $before, $this->stored( $course ), 'the graph it had is kept' );

        $error = $this->mcp_error( 'set-schema', [ 'postType' => self::COURSE, 'graph' => [ [ '@type' => 'Course', 'name' => '{{field:prise}}' ] ] ] );
        $this->assertStringContainsString( 'price', $error, 'the message lists the real fields' );
        $this->assertSame( [], $this->mcp( 'get-seo', [ 'postType' => self::COURSE ] )['postType']['schemaTemplate'] );
    }

    public function test_options_page_values_and_an_unset_image_drops_out(): void {
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-site', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA site' ], 'fields' => [ self::field( 'phone', 'text' ), self::field( 'logo', 'image' ) ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-site', 'values' => [ 'phone' => '+380 44 000 00 00' ] ] );

        $node = $this->on_course( [ 'provider' => [ '@type' => 'Organization', 'telephone' => '{{option:qa-site.phone}}', 'logo' => '{{option:qa-site.logo}}' ] ] );
        $this->assertSame( [ '@type' => 'Organization', 'telephone' => '+380 44 000 00 00' ], $node['provider'], 'an unset image drops out' );

        $this->mcp( 'set-field-values', [ 'options' => 'qa-site', 'values' => [ 'logo' => self::$ids['photo'], 'phone' => '+380 44 111 11 11' ] ] );
        $url  = $this->url( self::$ids['course'] );
        $page = $this->head( $url )->node_by_id( $url . '#course' );
        $this->assertSame( [ '@type' => 'Organization', 'telephone' => '+380 44 111 11 11', 'logo' => self::$ids['photoUrl'] ], $page['provider'], 'the options page changed: so did every page' );

        $this->assertStringContainsString( 'has no field "fax"', $this->mcp_error( 'set-schema', [ 'contentId' => self::$ids['course'], 'graph' => [ [ '@type' => 'Course', 'name' => '{{option:qa-site.fax}}' ] ] ] ) );
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
        $this->assertStringContainsString( '{{field:price}}', $this->mcp_error( 'set-schema', [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Product', 'sku' => '{{meta:fab_price}}' ] ] ] ), 'a Fabrement field is a field token' );
        foreach ( [ 'site_name', 'author_name', 'author_url', 'post_id', 'post_type', 'featured_image_id' ] as $gone ) {
            $this->assertStringContainsString( 'unknown token', $this->mcp_error( 'set-schema', [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', 'name' => '{{' . $gone . '}}' ] ] ] ), $gone );
        }
        $this->assertStringContainsString( 'is not a public taxonomy', $this->mcp_error( 'set-schema', [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', 'name' => '{{term:qa_private_tax}}' ] ] ] ) );
        $this->assertSame( '', $this->stored( $id ), 'nothing saved on the record' );

        $this->assertStringContainsString( 'reads a protected key', $this->mcp_error( 'set-schema', [ 'postType' => 'post', 'graph' => [ [ '@type' => 'Thing', 'name' => '{{meta:_edit_last}}' ] ] ] ) );
        $this->assertSame( [], $this->mcp( 'get-seo', [ 'postType' => 'post' ] )['postType']['schemaTemplate'], 'nor on the type' );
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
        foreach ( [ '{{url}}', $home . '#organization', $home . '#/schema/logo/image/' ] as $id ) {
            $this->assertStringContainsString( 'is the id of a node Yoast SEO already prints', $this->mcp_error( 'set-schema', [ 'postType' => self::COURSE, 'graph' => [ [ '@type' => 'Course', '@id' => $id, 'name' => '{{title}}' ] ] ] ), 'the type template too: ' . $id );
        }

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

    public function test_the_posts_page_takes_its_own_nodes_not_the_page_template(): void {
        $posts = $this->record( 'page', 'Blog' );
        $plain = $this->record( 'page', 'About' );
        $this->reading( $plain, $posts );
        try {
            $this->mcp( 'set-schema', [ 'postType' => 'page', 'graph' => [ [ '@type' => 'Thing', '@id' => '{{url}}#page-template', 'name' => 'Template' ] ] ] );
            $this->mcp( 'set-schema', [ 'contentId' => $posts, 'graph' => [ [ '@type' => 'Blog', '@id' => '{{url}}#blog', 'name' => 'Own' ] ] ] );

            $url  = $this->url( $posts );
            $blog = $this->head( $url );
            $this->assertSame( 'Own', $blog->node_by_id( $url . '#blog' )['name'] ?? null );
            $this->assertNull( $blog->node_by_id( $url . '#page-template' ), 'not the page template' );

            $home    = $this->head( '/' );
            $webpage = (string) ( $home->node_of_type( 'WebPage' )['@id'] ?? '' );
            $this->assertNotSame( '', $webpage, 'the WebPage id Yoast gives the front page' );
            $this->assertSame( 'Template', $home->node_by_id( $webpage . '#page-template' )['name'] ?? null, 'an ordinary page takes it, on that WebPage id' );
        } finally {
            $this->mcp( 'set-schema', [ 'postType' => 'page', 'graph' => [] ] );
            $this->reading( 0 );
        }
    }

    public function test_the_type_template_follows_the_same_rules_and_warns_what_will_be_thin(): void {
        $open   = $this->chair( 'Open chair' );
        $locked = $this->chair( 'Closed chair', true );
        $this->mcp( 'set-schema', [ 'postType' => 'post', 'graph' => self::product_template() ] );
        $this->assertSame( 'CH-1', $this->head( $this->url( $open ) )->node_by_id( $this->url( $open ) . '#product' )['sku'] ?? null );
        $this->assertArrayNotHasKey( 'sku', $this->head( $this->url( $locked ) )->node_by_id( $this->url( $locked ) . '#product' ) ?? [] );

        // A course whose link was cleared: the template warns it comes out empty there.
        $this->mcp( 'set-field-values', [ 'postId' => self::$ids['course'], 'values' => [ 'site' => null ] ] );
        $out = $this->mcp( 'set-schema', [ 'postType' => self::COURSE, 'graph' => [ [ '@type' => 'Event', 'name' => '{{title}}', 'startDate' => '{{field:starts}}', 'url' => '{{field:site}}' ] ] ] );
        $all = implode( "\n", $out['warnings'] ?? [] );
        $this->assertStringContainsString( 'Event has no location', $all );
        $this->assertStringContainsString( 'graph[0].url resolved empty on record ' . self::$ids['course'], $all );
        $this->assertStringNotContainsString( 'startDate', $all, 'present and filled: nothing to say' );
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
