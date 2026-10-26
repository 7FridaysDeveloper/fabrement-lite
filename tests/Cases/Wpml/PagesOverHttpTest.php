<?php

namespace Fabrement\Tests\Cases\Wpml;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Schema\FieldKind;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Tests\CommittedCleanup;
use Fabrement\Tests\McpCalls;
use Fabrement\Tests\PageHead;
use Fabrement\Tests\WebRequests;
use Fabrement\Tests\Wpml\WpmlTestCase;

/**
 * A page and its French version, built by an agent through the MCP server, fetched over real HTTP
 * from the suite's web server: WPML takes the language from the address, the theme prints the page,
 * Yoast prints our node — nothing is simulated on the way.
 */
final class PagesOverHttpTest extends WpmlTestCase {

    use CommittedCleanup;
    use McpCalls;
    use PageHead;
    use WebRequests;

    public function set_up(): void {
        parent::set_up();
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Structured data is the output of the SEO plugin: Yoast SEO is not loaded in this run.' );
        }
        $this->forget_fields();
        // What this test commits besides its posts: put back after it.
        $this->start_cleanup( [ 'timezone_string', Keys::OPTION_SCHEMA_TYPES, 'rewrite_rules' ], get_current_user_id() );
        update_option( 'timezone_string', 'Europe/Kyiv' );
    }

    public function tear_down(): void {
        $this->end_cleanup();
        $this->forget_fields();
        $this->publish();   // the clean-up, for the web server too
        parent::tear_down();
    }

    private function forget_fields(): void {
        $owner = Owner::of( 'post', 'page' );
        if ( $owner ) {
            SchemaRepository::delete( $owner );
        }
        SchemaRepository::flush();
        FieldKind::forget();
    }

    private function f( string $name, string $type, array $extra = [] ): array {
        return $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
    }

    public function test_each_language_is_served_at_its_own_address_with_its_own_words(): void {
        $this->mcp( 'save-fields', [ 'type' => 'post', 'slug' => 'page', 'expectedRevision' => 0, 'fields' => [
            $this->f( 'price', 'number', [ 'format' => 'decimal' ] ),
            $this->f( 'city', 'text' ),
        ] ] );
        $page = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'Negotiation workshop', 'blocks' => [] ] )['contentId'];
        $this->mcp( 'set-field-values', [ 'postId' => $page, 'values' => [ 'price' => 59.9, 'city' => 'Lviv' ] ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'publish' ] );
        $this->mcp( 'set-schema', [ 'postType' => 'page', 'graph' => [ [
            '@type'  => 'Event',
            'name'   => '{{title}}',
            'location' => [ '@type' => 'Place', 'name' => '{{field:city}}' ],
            'offers' => [ '@type' => 'Offer', 'price' => '{{field:price}}' ],
        ] ] ] );

        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'fr' ] )['contentId'];
        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $fr, 'title' => 'Atelier de négociation' ] );
        $this->mcp( 'set-field-values', [ 'postId' => $fr, 'values' => [ 'city' => 'Lviv (Lwów)' ] ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        $this->mcp( 'set-field-values', [ 'postId' => $page, 'values' => [ 'price' => 64.5 ] ] );   // a copied value, after the version was made
        $this->publish();

        $french_url = (string) Translation::bridge()->permalink( $fr, 'fr' );
        $this->assertStringContainsString( '/fr/', $french_url );
        $french = $this->web( $french_url );
        $this->assertSame( 200, $french['status'], $french_url );
        $this->assertMatchesRegularExpression( '/<html[^>]+lang="fr-FR"/', $french['body'], 'WPML read French from the address' );
        $event = self::node_of( self::json_ld( $french['body'] ), 'Event' );
        $this->assertNotNull( $event, 'the French page carries the Event node' );
        $this->assertSame( 'Atelier de négociation', $event['name'] );
        $this->assertSame( 'Lviv (Lwów)', $event['location']['name'] );
        $this->assertSame( '64.5', $event['offers']['price'] );

        $english = $this->web( (string) Translation::bridge()->permalink( $page, 'en' ) );
        $this->assertSame( 200, $english['status'] );
        $this->assertMatchesRegularExpression( '/<html[^>]+lang="en-US"/', $english['body'] );
        $event = self::node_of( self::json_ld( $english['body'] ), 'Event' );
        $this->assertNotNull( $event );
        $this->assertSame( 'Negotiation workshop', $event['name'] );
        $this->assertSame( 'Lviv', $event['location']['name'] );
    }
}
