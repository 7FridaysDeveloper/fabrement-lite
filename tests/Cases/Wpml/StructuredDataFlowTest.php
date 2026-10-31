<?php

namespace Fabrement\Tests\Cases\Wpml;

use Fabrement\Modules\Translation\Translation;
use Fabrement\Tests\CommittedCleanup;
use Fabrement\Tests\McpCalls;
use Fabrement\Tests\PageHead;
use Fabrement\Tests\Wpml\WpmlTestCase;

/**
 * The whole way an agent gives a multilingual site structured data, through its MCP server: a type
 * translated per language, a record and its French version, each with its graph — whose page carries its own words,
 * while the English page keeps its own.
 */
final class StructuredDataFlowTest extends WpmlTestCase {

    use CommittedCleanup;
    use McpCalls;
    use PageHead;

    private const TYPE = 'qa_flow_event';

    private string $permalinks = '';

    public function set_up(): void {
        parent::set_up();
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Structured data is the output of the SEO plugin: Yoast SEO is not loaded in this run.' );
        }
        $this->start_cleanup( [ 'timezone_string' ], get_current_user_id() );
        $this->permalinks = (string) get_option( 'permalink_structure' );
        update_option( 'timezone_string', 'Europe/Kyiv' );
        $this->set_permalink_structure( '/%postname%/' );
    }

    public function tear_down(): void {
        if ( post_type_exists( self::TYPE ) ) {
            unregister_post_type( self::TYPE );
        }
        $this->end_cleanup();
        $this->set_permalink_structure( $this->permalinks );
        parent::tear_down();
    }

    public function test_the_french_version_of_an_event_has_its_own_words(): void {
        register_post_type( self::TYPE, [ 'public' => true, 'label' => 'Event', 'supports' => [ 'title', 'editor' ], 'show_in_rest' => true ] );
        flush_rewrite_rules( false ); // the type's addresses, so go_to() finds its records
        global $sitepress;
        $sync = (array) $sitepress->get_setting( 'custom_posts_sync_option', [] );
        $sync[ self::TYPE ] = 1;
        $sitepress->set_setting( 'custom_posts_sync_option', $sync, true );
        $this->assertTrue( Translation::bridge()->is_post_type_translatable( self::TYPE ) );

        $record = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => self::TYPE, 'title' => 'Negotiation workshop' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $record, 'status' => 'publish' ] );
        $graph = [ [
            '@type' => 'Event',
            'name'  => '{{title}}',
        ] ];
        $this->mcp( 'set-schema', [ 'contentId' => $record, 'graph' => $graph ] );

        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $record, 'language' => 'fr' ] )['contentId'];
        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $fr, 'title' => 'Atelier de négociation' ] );   // renamed, nothing added
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        $this->mcp( 'set-schema', [ 'contentId' => $fr, 'graph' => $graph ] );

        // The French address is read by WPML itself (PageHead), as a real request would be.
        $french_url = (string) Translation::bridge()->permalink( $fr, 'fr' );
        $this->assertStringContainsString( '/fr/', $french_url );
        $event = $this->node_at( $french_url, 'Event' );
        $this->assertNotNull( $event, 'the French page carries the Event node' );
        $this->assertSame( 'Atelier de négociation', $event['name'] );

        // Not get_permalink(): with French on, WPML gives the French version's address for it.
        $english = $this->node_at( (string) Translation::bridge()->permalink( $record, 'en' ), 'Event' );
        $this->assertNotNull( $english );
        $this->assertSame( 'Negotiation workshop', $english['name'], 'the English page keeps its own words' );
    }
}
