<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The whole way an agent gives a site structured data — a post type, its fields, an options page, a
 * published record, the type's graph with {{field:…}} / {{option:…}} — and the page a crawler
 * fetches. Then the type's SEO defaults: they reach a page Yoast indexed before, at once.
 */
final class StructuredDataTest extends SiteTestCase {

    public function test_an_agent_builds_an_event_type_whose_page_carries_its_event_node(): void {
        $this->site()->wp( 'option', 'update', 'timezone_string', 'Europe/Kyiv' );
        $this->post_type( 'qa_event', [ 'supports' => [ 'title', 'editor', 'thumbnail' ] ] );
        $this->fields( 'qa_event', [
            self::field( 'price', 'number', [ 'format' => 'decimal' ] ),
            self::field( 'starts', 'date_time_picker' ),
            self::field( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => 'For beginners' ] ], 'default' => 'beginner' ] ),
            self::field( 'city', 'text' ),
        ] );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-site', 'expectedRevision' => 0, 'page' => [ 'title' => 'Site' ], 'fields' => [ self::field( 'phone', 'text' ) ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-site', 'values' => [ 'phone' => '+380 44 111 22 33' ] ] );

        $record = $this->record( 'qa_event', 'Negotiation workshop' );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'price' => 59.9, 'starts' => '2026-07-15T18:00', 'city' => 'Lviv' ] ] );

        $this->assertStringContainsString( 'prise', $this->mcp_error( 'set-schema', [ 'postType' => 'qa_event', 'graph' => [ [ '@type' => 'Event', 'name' => '{{field:prise}}' ] ] ] ), 'a mistyped field is refused' );
        $schema = $this->mcp( 'set-schema', [ 'postType' => 'qa_event', 'graph' => [ [
            '@type'     => 'Event',
            '@id'       => '{{url}}#event',
            'name'      => '{{title}}',
            'startDate' => '{{field:starts}}',
            'location'  => [ '@type' => 'Place', 'name' => '{{field:city}}', 'telephone' => '{{option:qa-site.phone}}' ],
            'offers'    => [ '@type' => 'Offer', 'price' => '{{field:price}}', 'priceCurrency' => 'UAH' ],
            'audience'  => [ '@type' => 'Audience', 'audienceType' => '{{field:level.label}}' ],
        ] ] ] );
        $this->assertEmpty( $schema['warnings'] ?? [] );

        $url  = $this->mcp( 'get-content', [ 'contentId' => $record ] )['url'];
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'], $url );
        $event = self::node( self::json_ld( $page['body'] ), 'Event' );
        $this->assertNotNull( $event, 'the page carries the Event node' );
        $this->assertSame( 'Negotiation workshop', $event['name'] );
        $this->assertSame( '2026-07-15T18:00:00+03:00', $event['startDate'], 'with the site\'s offset on that date' );
        $this->assertSame( '59.9', $event['offers']['price'] );
        $this->assertSame( 'Lviv', $event['location']['name'] );
        $this->assertSame( '+380 44 111 22 33', $event['location']['telephone'], 'from the options page' );
        $this->assertSame( 'For beginners', $event['audience']['audienceType'], 'a select never set: its default, as its label' );
    }

    public function test_new_seo_defaults_reach_a_page_indexed_before_without_a_reindex(): void {
        $page = $this->record( 'page', 'About us' );
        $url  = $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'];
        $this->assertDoesNotMatchRegularExpression( '/<meta name=.robots.[^>]*noindex/', $this->get( $url )['body'], 'indexed as usual first' );

        $out = $this->mcp( 'set-seo-defaults', [ 'postType' => 'page', 'titleTemplate' => 'QA %%title%% QA', 'noindex' => true ] );
        $this->assertArrayNotHasKey( 'reindexed', $out, 'no re-index is reported: none is done' );

        $body = $this->get( $url )['body'];
        $this->assertMatchesRegularExpression( '#<title>QA About us QA#', html_entity_decode( $body ) );
        $this->assertMatchesRegularExpression( '/<meta name=.robots.[^>]*noindex/', $body );
    }
}
