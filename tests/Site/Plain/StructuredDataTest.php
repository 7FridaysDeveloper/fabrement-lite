<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The whole way an agent gives a record structured data — a post type, a published record, its
 * graph — and the page a crawler fetches.
 */
final class StructuredDataTest extends SiteTestCase {

    public function test_an_agent_gives_an_event_page_its_event_node(): void {
        $this->post_type( 'qa_event', [ 'supports' => [ 'title', 'editor', 'thumbnail' ] ] );
        $record = $this->record( 'qa_event', 'Negotiation workshop' );

        foreach ( [ '{{field:price}}', '{{option:qa-site.phone}}' ] as $token ) {
            $this->assertStringContainsString( 'unknown token', $this->mcp_error( 'set-schema', [ 'contentId' => $record, 'graph' => [ [ '@type' => 'Event', 'name' => $token ] ] ] ), $token . ' is refused' );
        }
        $this->mcp( 'set-schema', [ 'contentId' => $record, 'graph' => [ [
            '@type' => 'Event',
            '@id'   => '{{url}}#event',
            'name'  => '{{title}}',
        ] ] ] );

        $url  = $this->mcp( 'get-content', [ 'contentId' => $record ] )['url'];
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'], $url );
        $event = self::node( self::json_ld( $page['body'] ), 'Event' );
        $this->assertNotNull( $event, 'the page carries the Event node' );
        $this->assertSame( 'Negotiation workshop', $event['name'] );
    }
}
