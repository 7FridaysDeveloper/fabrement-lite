<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SeoPage;
use Fabrement\Tests\Site\Support\SiteTestCase;

/** Nullable fields reach real Yoast pages through MCP writes, schema tokens and SEO variables. */
final class SeoNullableFieldsTest extends SiteTestCase {

    public function test_language_context_without_wpml_preserves_the_callers_state(): void {
        $result = $this->site_runs( <<<'PHP'
$query = $GLOBALS['wp_query'];
$before = determine_locale();
$value = \Fabrement\Modules\Translation\Translation::in_context('fr', static fn() => ['value' => 42, 'locale' => determine_locale()]);
try {
    \Fabrement\Modules\Translation\Translation::in_context('pl', static function() { throw new RuntimeException('expected'); });
} catch (RuntimeException $e) {
    $message = $e->getMessage();
}
return [ 'before' => $before, 'after' => determine_locale(), 'value' => $value, 'exception' => $message, 'same_query' => $query === $GLOBALS['wp_query'] ];
PHP
        );
        $this->assertSame( $result['before'], $result['after'] );
        $this->assertSame( [ 'value' => 42, 'locale' => $result['before'] ], $result['value'] );
        $this->assertSame( 'expected', $result['exception'] );
        $this->assertTrue( $result['same_query'] );
    }

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    private function page( string $url ): SeoPage {
        $response = $this->get( $url );
        $this->assertSame( 200, $response['status'], $url );
        return SeoPage::of( $response['body'] );
    }

    private function event( string $url ): array {
        $node = $this->page( $url )->node_of_type( 'Event' );
        $this->assertNotNull( $node, 'The crawler receives the configured Event node.' );
        return $node;
    }

    public function test_defaults_clears_and_false_survive_from_storage_to_post_and_options_schema(): void {
        $this->post_type( 'qa_seonull' );
        $fields = [
            self::field( 'nf_flag', 'true_false', [ 'default' => true ] ),
            self::field( 'nf_price', 'number', [ 'default' => 7 ] ),
            self::field( 'nf_note', 'text' ),
            self::field( 'nf_details', 'group', [ 'sub_fields' => [ self::field( 'flag', 'true_false', [ 'default' => true ] ) ] ] ),
        ];
        $this->fields( 'qa_seonull', $fields );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-seo-null', 'expectedRevision' => 0, 'page' => [ 'title' => 'SEO nullable' ], 'fields' => $fields ] );
        $id = $this->record( 'qa_seonull', 'Nullable event' );
        $this->mcp( 'set-schema', [ 'postType' => 'qa_seonull', 'graph' => [ [
            '@type' => 'Event', '@id' => '{{url}}#event', 'name' => '{{title}}',
            'isAccessibleForFree' => '{{field:nf_flag}}',
            'offers' => [ '@type' => 'Offer', 'price' => '{{field:nf_price}}' ],
            'location' => [ '@type' => 'Place', 'name' => 'Venue', 'isAccessibleForFree' => '{{field:nf_details.flag}}' ],
            'organizer' => [ '@type' => 'Organization', 'name' => 'Organizer',
                'isAccessibleForFree' => '{{option:qa-seo-null.nf_flag}}',
                'additionalProperty' => [ '@type' => 'PropertyValue', 'name' => 'Nested switch', 'value' => '{{option:qa-seo-null.nf_details.flag}}' ],
            ],
        ] ] ] );
        $url = $this->url( $id );
        $default = $this->event( $url );
        $this->assertSame( 'true', $default['isAccessibleForFree'] );
        $this->assertSame( '7', $default['offers']['price'] );
        $this->assertSame( 'true', $default['location']['isAccessibleForFree'] );
        $this->assertSame( 'true', $default['organizer']['isAccessibleForFree'] );
        $this->assertSame( 'true', $default['organizer']['additionalProperty']['value'] );
        $this->assertFalse( $this->site()->has_meta( $id, 'fab_nf_flag' ), 'Rendering a default does not store it.' );
        $this->assertArrayNotHasKey( 'fab_nf_flag', (array) $this->site()->option( 'fabrement_options_qa-seo-null' ) );

        $empty = [ 'nf_flag' => null, 'nf_price' => null, 'nf_details' => [ 'flag' => null ] ];
        foreach ( [ [ 'postId' => $id ], [ 'options' => 'qa-seo-null' ] ] as $target ) {
            $this->mcp( 'set-field-values', $target + [ 'values' => $empty ] );
            $this->mcp( 'set-field-values', $target + [ 'values' => [ 'nf_note' => 'An unrelated edit' ] ] );
            $values = $this->mcp( 'get-field-values', $target )['values'];
            $this->assertNull( $values['nf_flag'] );
            $this->assertNull( $values['nf_price'] );
            $this->assertNull( $values['nf_details'] );
        }
        $cleared = $this->event( $url );
        $this->assertArrayNotHasKey( 'isAccessibleForFree', $cleared );
        $this->assertArrayNotHasKey( 'price', $cleared['offers'] ?? [] );
        $this->assertArrayNotHasKey( 'isAccessibleForFree', $cleared['location'] );
        $this->assertArrayNotHasKey( 'isAccessibleForFree', $cleared['organizer'] );
        $this->assertArrayNotHasKey( 'value', $cleared['organizer']['additionalProperty'] );
        $this->assertTrue( $this->site()->has_meta( $id, 'fab_nf_flag' ), 'Explicit empty is present in storage.' );

        foreach ( [ false, true ] as $flag ) {
            foreach ( [ [ 'postId' => $id ], [ 'options' => 'qa-seo-null' ] ] as $target ) {
                $this->mcp( 'set-field-values', $target + [ 'values' => [ 'nf_flag' => $flag, 'nf_price' => 0, 'nf_details' => [ 'flag' => $flag ] ] ] );
            }
            $node = $this->event( $url );
            $word = $flag ? 'true' : 'false';
            $this->assertSame( $word, $node['isAccessibleForFree'] );
            $this->assertSame( '0', $node['offers']['price'] );
            $this->assertSame( $word, $node['location']['isAccessibleForFree'] );
            $this->assertSame( $word, $node['organizer']['isAccessibleForFree'] );
            $this->assertSame( $word, $node['organizer']['additionalProperty']['value'] );
        }
    }

    public function test_choice_labels_and_zero_reach_the_title_description_and_graph_then_clear(): void {
        $this->post_type( 'qa_seolabel' );
        $choice = [ 'choices' => [ [ 'value' => 'basic', 'label' => 'Basic & co' ], [ 'value' => '0', 'label' => 'Zero & co' ] ], 'default' => 'basic' ];
        $this->fields( 'qa_seolabel', [
            self::field( 'nl_level', 'select', $choice ),
            self::field( 'nl_price', 'number', [ 'default' => 7 ] ),
            self::field( 'nl_details', 'group', [ 'sub_fields' => [ self::field( 'level', 'select', $choice ) ] ] ),
        ] );
        $id = $this->record( 'qa_seolabel', 'Label event' );
        $this->mcp( 'set-seo', [ 'contentId' => $id, 'title' => 'SEO %%fab_nl_level%%', 'metaDesc' => 'Nested %%fab_nl_details_level%%; cost %%fab_nl_price%%.' ] );
        $this->mcp( 'set-schema', [ 'postType' => 'qa_seolabel', 'graph' => [ [
            '@type' => 'Event', '@id' => '{{url}}#event', 'name' => '{{title}}',
            'audience' => [ '@type' => 'Audience', 'audienceType' => '{{field:nl_level.label}}' ],
            'location' => [ '@type' => 'Place', 'name' => '{{field:nl_details.level.label}}' ],
        ] ] ] );
        $url = $this->url( $id );
        $page = $this->page( $url );
        $this->assertSame( 'SEO Basic & co', $page->title() );
        $this->assertSame( 'Nested Basic & co; cost 7.', $page->description() );

        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => [ 'nl_level' => '0', 'nl_price' => 0, 'nl_details' => [ 'level' => '0' ] ] ] );
        $page = $this->page( $url );
        $this->assertSame( 'SEO Zero & co', $page->title() );
        $this->assertSame( 'Nested Zero & co; cost 0.', $page->description() );
        $node = $page->node_of_type( 'Event' );
        $this->assertSame( 'Zero & co', $node['audience']['audienceType'] );
        $this->assertSame( 'Zero & co', $node['location']['name'] );

        $this->site_runs( "fabrement()->fields($id)->clear('nl_level')->clear('nl_price')->clear('nl_details')->save();" );
        $page = $this->page( $url );
        $this->assertSame( 'SEO', $page->title() );
        $this->assertStringNotContainsString( 'Basic', $page->description() );
        $this->assertStringNotContainsString( 'Zero', $page->description() );
        $this->assertStringNotContainsString( 'cost 7', $page->description() );
        $node = $page->node_of_type( 'Event' );
        $this->assertArrayNotHasKey( 'audienceType', $node['audience'] ?? [] );
        $this->assertArrayNotHasKey( 'name', $node['location'] ?? [] );
    }

    public function test_nested_media_enters_social_previews_and_sitemaps_and_disappears_after_clear(): void {
        $this->post_type( 'qa_seomedia' );
        $this->fields( 'qa_seomedia', [
            self::field( 'nm_cover', 'image' ),
            self::field( 'nm_gallery', 'gallery' ),
            self::field( 'nm_group', 'group', [ 'sub_fields' => [ self::field( 'photo', 'image' ) ] ] ),
            self::field( 'nm_rows', 'repeater', [ 'sub_fields' => [ self::field( 'photo', 'image' ) ] ] ),
        ] );
        $one = $this->image( 'qa-nullable-one.png' );
        $two = $this->image( 'qa-nullable-two.png' );
        $urls = $this->site_runs( "return [wp_get_attachment_url($one), wp_get_attachment_url($two)];" );
        $id = $this->record( 'qa_seomedia', 'Nullable pictures' );
        $url = $this->url( $id );
        $this->assertSame( [], $this->page( $url )->og_images() );
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => [
            'nm_cover' => null, 'nm_gallery' => [ $one, $two ], 'nm_group' => [ 'photo' => $two ], 'nm_rows' => [ [ 'photo' => null ], [ 'photo' => $one ] ],
        ] ] );
        $this->assertSame( [ $urls[0] ], $this->page( $url )->og_images() );
        $sitemap = $this->get( '/qa_seomedia-sitemap.xml' );
        $this->assertSame( 200, $sitemap['status'] );
        $this->assertSame( $urls, SeoPage::sitemap_images( $sitemap['body'] )[ $url ] ?? null );

        $this->site_runs( "fabrement()->fields($id)->clear('nm_gallery')->clear('nm_group')->clear('nm_rows')->save();" );
        $this->assertSame( [], $this->page( $url )->og_images() );
        $sitemap = $this->get( '/qa_seomedia-sitemap.xml' );
        $this->assertSame( 200, $sitemap['status'] );
        $this->assertSame( [], SeoPage::sitemap_images( $sitemap['body'] )[ $url ] ?? null );
        $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_nm_gallery' ), 'The empty list has an explicit SQL NULL row.' );
        $this->assertNull( $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values']['nm_gallery'] );
    }

    public function test_a_picked_post_contributes_its_url_title_and_nullable_fields_to_the_page_schema(): void {
        $this->post_type( 'qa_seoperson' );
        $this->fields( 'qa_seoperson', [ self::field( 'np_role', 'text', [ 'default' => 'Speaker' ] ), self::field( 'np_flag', 'true_false' ) ] );
        $this->post_type( 'qa_seoref' );
        $this->fields( 'qa_seoref', [ self::field( 'nr_person', 'post_object', [ 'postTypes' => [ 'qa_seoperson' ] ] ) ] );
        $person = $this->record( 'qa_seoperson', 'Ada & team' );
        $event = $this->record( 'qa_seoref', 'A linked speaker' );
        $this->mcp( 'set-field-values', [ 'postId' => $event, 'values' => [ 'nr_person' => $person ] ] );
        $this->mcp( 'set-schema', [ 'postType' => 'qa_seoref', 'graph' => [ [
            '@type' => 'Event', '@id' => '{{url}}#event', 'name' => '{{title}}',
            'performer' => [ '@type' => 'Person', 'name' => '{{field:nr_person.title}}', 'url' => '{{field:nr_person.url}}',
                'jobTitle' => '{{field:nr_person.fields.np_role}}', 'knowsAbout' => '{{field:nr_person.fields.np_flag}}' ],
        ] ] ] );
        $url = $this->url( $event );
        $person_url = $this->url( $person );
        $this->assertSame( 200, $this->get( $person_url )['status'] );
        $node = $this->event( $url )['performer'];
        $this->assertSame( 'Ada & team', $node['name'] );
        $this->assertSame( $person_url, $node['url'] );
        $this->assertSame( 'Speaker', $node['jobTitle'] );
        $this->assertArrayNotHasKey( 'knowsAbout', $node );

        $this->mcp( 'set-field-values', [ 'postId' => $person, 'values' => [ 'np_role' => null, 'np_flag' => false ] ] );
        $node = $this->event( $url )['performer'];
        $this->assertArrayNotHasKey( 'jobTitle', $node );
        $this->assertSame( 'false', $node['knowsAbout'] );
        $this->mcp( 'set-content-status', [ 'contentId' => $person, 'status' => 'draft' ] );
        $node = $this->event( $url )['performer'] ?? [];
        foreach ( [ 'name', 'url', 'jobTitle', 'knowsAbout' ] as $key ) {
            $this->assertArrayNotHasKey( $key, $node, 'An unpublished picked record is absent from the public graph.' );
        }
        $this->mcp( 'set-content-status', [ 'contentId' => $person, 'status' => 'publish' ] );
        $this->mcp( 'set-field-values', [ 'postId' => $event, 'values' => [ 'nr_person' => null ] ] );
        $this->assertArrayNotHasKey( 'name', $this->event( $url )['performer'] ?? [] );
        $this->mcp( 'set-field-values', [ 'postId' => $event, 'values' => [ 'nr_person' => $person ] ] );
        $this->assertSame( $person_url, $this->event( $url )['performer']['url'] );
    }

    public function test_typed_nested_references_keep_private_records_out_of_pages_and_mcp(): void {
        $this->post_type( 'qa_seotarget' );
        $person = $this->record( 'qa_seotarget', 'Visible & co' );
        $draft = $this->record( 'qa_seotarget', 'Draft name', 'draft' );
        $private = $this->record( 'qa_seotarget', 'Private name', 'draft' );
        $this->site()->wp( 'post', 'update', (string) $private, '--post_status=private' );
        $file = $this->image( 'qa-seo-download.png' );
        $file_url = $this->site_runs( "return wp_get_attachment_url($file);" );
        $this->post_type( 'qa_seotyped' );
        $this->fields( 'qa_seotyped', [ self::field( 'nt_refs', 'group', [ 'sub_fields' => [
            self::field( 'person', 'post_object', [ 'postTypes' => [ 'qa_seotarget' ] ] ),
            self::field( 'people', 'relationship', [ 'postTypes' => [ 'qa_seotarget' ] ] ),
            self::field( 'link', 'link' ), self::field( 'file', 'file' ),
        ] ] ) ] );
        $id = $this->record( 'qa_seotyped', 'Typed references' );
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => [ 'nt_refs' => [
            'person' => $person, 'people' => [ $person, $draft, $private ],
            'link' => [ 'postId' => $person, 'useRecordTitle' => true ], 'file' => $file,
        ] ] ] );
        $this->mcp( 'set-seo', [ 'contentId' => $id, 'title' => 'People %%fab_nt_refs_people%%', 'metaDesc' => 'Visit %%fab_nt_refs_link%%' ] );
        $this->mcp( 'set-schema', [ 'postType' => 'qa_seotyped', 'graph' => [ [
            '@type' => 'Event', '@id' => '{{url}}#typed', 'name' => '{{title}}',
            'performer' => [ '@type' => 'Person', 'name' => '{{field:nt_refs.person.title}}', 'url' => '{{field:nt_refs.person.url}}' ],
            'sameAs' => '{{field:nt_refs.link}}', 'alternateName' => '{{field:nt_refs.link.label}}',
            'subjectOf' => [ '@type' => 'CreativeWork', 'url' => '{{field:nt_refs.file}}' ],
        ] ] ] );
        $url = $this->url( $id );
        $page = $this->page( $url );
        $node = $page->node_of_type( 'Event' );
        $this->assertSame( 'People Visible & co', $page->title() );
        $this->assertSame( 'Visit Visible & co', $page->description() );
        $this->assertSame( 'Visible & co', $node['performer']['name'] );
        $this->assertSame( $this->url( $person ), $node['sameAs'] );
        $this->assertSame( 'Visible & co', $node['alternateName'] );
        $this->assertSame( $file_url, $node['subjectOf']['url'] );

        // Private visibility is set by WordPress itself; the MCP status tool does not expose it.
        $this->site()->wp( 'post', 'update', (string) $person, '--post_status=private' );
        $page = $this->page( $url );
        $node = $page->node_of_type( 'Event' );
        $this->assertSame( 'People', $page->title() );
        $this->assertSame( 'Visit', $page->description() );
        $this->assertArrayNotHasKey( 'name', $node['performer'] );
        $this->assertArrayNotHasKey( 'url', $node['performer'] );
        $this->assertArrayNotHasKey( 'sameAs', $node );
        $this->assertArrayNotHasKey( 'alternateName', $node );
        $seo = $this->mcp( 'get-seo', [ 'contentId' => $id ] )['content'];
        $this->assertSame( $page->title(), $seo['effective']['title'], 'An administrator also sees public-only SEO references.' );
        $events = array_values( array_filter( $seo['schema']['graph'], static fn( array $n ): bool => ( $n['@type'] ?? '' ) === 'Event' ) );
        $this->assertSame( [ $node ], $events );

        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => [ 'nt_refs' => [
            'person' => null, 'people' => null, 'file' => null,
            'link' => [ 'url' => 'https://example.net/docs', 'label' => 'External & co' ],
        ] ] ] );
        $node = $this->event( $url );
        $this->assertSame( 'https://example.net/docs', $node['sameAs'] );
        $this->assertSame( 'External & co', $node['alternateName'] );
        $this->assertArrayNotHasKey( 'url', $node['subjectOf'] );
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => [ 'nt_refs' => null ] ] );
        $node = $this->event( $url );
        $this->assertArrayNotHasKey( 'sameAs', $node );
        $this->assertArrayNotHasKey( 'alternateName', $node );
    }
}
