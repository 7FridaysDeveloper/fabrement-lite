<?php

namespace Fabrement\Tests\Cases\Wpml;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Schema\FieldKind;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Tests\CommittedCleanup;
use Fabrement\Tests\McpCalls;
use Fabrement\Tests\PageHead;
use Fabrement\Tests\Wpml\WpmlTestCase;

/**
 * The whole way an agent gives a multilingual site structured data, through its MCP server: a type
 * translated per language, its fields, a record and its French version — whose page carries its
 * own words and follows the values the original copies, while the English page keeps its own.
 */
final class StructuredDataFlowTest extends WpmlTestCase {

    use CommittedCleanup;
    use McpCalls;
    use PageHead;

    private const TYPE    = 'qa_flow_event';
    private const OPTIONS = 'qa-flow-site';

    private string $permalinks = '';

    public function set_up(): void {
        parent::set_up();
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Structured data is the output of the SEO plugin: Yoast SEO is not loaded in this run.' );
        }
        $this->forget_files();   // a run that crashed must not decide this one
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
        $this->forget_files();
        delete_option( Keys::options_page( self::OPTIONS ) );
        $this->set_permalink_structure( $this->permalinks );
        parent::tear_down();
    }

    private function forget_files(): void {
        foreach ( [ 'post.' . self::TYPE, 'options.' . self::OPTIONS ] as $name ) {
            $file = SchemaRepository::dir() . '/' . $name . '.json';
            if ( is_file( $file ) ) {
                unlink( $file );
            }
        }
        SchemaRepository::flush();
        FieldKind::forget();
    }

    private function f( string $name, string $type, array $extra = [] ): array {
        return $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
    }

    public function test_the_french_version_of_an_event_has_its_own_words_and_follows_the_copied_values(): void {
        register_post_type( self::TYPE, [ 'public' => true, 'label' => 'Event', 'supports' => [ 'title', 'editor' ], 'show_in_rest' => true ] );
        global $sitepress;
        $sync = (array) $sitepress->get_setting( 'custom_posts_sync_option', [] );
        $sync[ self::TYPE ] = 1;
        $sitepress->set_setting( 'custom_posts_sync_option', $sync, true );
        $this->assertTrue( Translation::bridge()->is_post_type_translatable( self::TYPE ) );
        $this->mcp( 'save-fields', [ 'type' => 'post', 'slug' => self::TYPE, 'expectedRevision' => 0, 'fields' => [
            $this->f( 'price', 'number', [ 'format' => 'decimal' ] ),
            $this->f( 'starts', 'date_time_picker' ),
            $this->f( 'city', 'text' ),
        ] ] );
        $this->mcp( 'save-options-page', [ 'slug' => self::OPTIONS, 'expectedRevision' => 0, 'page' => [ 'title' => 'Site' ], 'fields' => [ $this->f( 'phone', 'text', [ 'translation' => 'copy' ] ) ] ] );
        $this->mcp( 'set-field-values', [ 'options' => self::OPTIONS, 'values' => [ 'phone' => '+380 44 111 22 33' ] ] );

        $record = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => self::TYPE, 'title' => 'Negotiation workshop' ] )['contentId'];
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'price' => 59.9, 'starts' => '2026-07-15T18:00', 'city' => 'Lviv' ] ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $record, 'status' => 'publish' ] );
        $this->mcp( 'set-schema', [ 'postType' => self::TYPE, 'graph' => [ [
            '@type'     => 'Event',
            'name'      => '{{title}}',
            'startDate' => '{{field:starts}}',
            'location'  => [ '@type' => 'Place', 'name' => '{{field:city}}', 'telephone' => '{{option:' . self::OPTIONS . '.phone}}' ],
            'offers'    => [ '@type' => 'Offer', 'price' => '{{field:price}}' ],
        ] ] ] );

        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $record, 'language' => 'fr' ] )['contentId'];
        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $fr, 'title' => 'Atelier de négociation' ] );   // renamed, nothing added
        $this->mcp( 'set-field-values', [ 'postId' => $fr, 'values' => [ 'city' => 'Lviv (Lwów)' ] ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        // The original's price changes after the version was made: a copied value follows it.
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'price' => 64.5 ] ] );

        // The French address is read by WPML itself (PageHead), as a real request would be.
        $french_url = (string) Translation::bridge()->permalink( $fr, 'fr' );
        $this->assertStringContainsString( '/fr/', $french_url );
        $event = $this->node_at( $french_url, 'Event' );
        $this->assertNotNull( $event, 'the French page carries the Event node' );
        $this->assertSame( 'Atelier de négociation', $event['name'] );
        $this->assertSame( 'Lviv (Lwów)', $event['location']['name'], 'its own words' );
        $this->assertSame( '64.5', $event['offers']['price'], 'the copied price follows the original\'s change' );
        $this->assertSame( '2026-07-15T18:00:00+03:00', $event['startDate'] );
        $this->assertSame( '+380 44 111 22 33', $event['location']['telephone'] );

        // Not get_permalink(): with French on, WPML gives the French version's address for it.
        $english = $this->node_at( (string) Translation::bridge()->permalink( $record, 'en' ), 'Event' );
        $this->assertNotNull( $english );
        $this->assertSame( 'Negotiation workshop', $english['name'], 'the English page keeps its own words' );
        $this->assertSame( 'Lviv', $english['location']['name'] );
        $this->assertSame( '64.5', $english['offers']['price'] );
    }
}
