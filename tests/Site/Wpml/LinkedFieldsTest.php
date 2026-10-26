<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/** Independently authored pages linked through WPML, including actual link fields and their readers. */
final class LinkedFieldsTest extends SiteTestCase {

    protected const SITE = 'wpml';

    protected function setUp(): void {
        parent::setUp();
        $this->fields( 'page', [
            self::field( 'cta', 'link' ), self::field( 'pinned', 'link' ),
            self::field( 'steps', 'repeater', [ 'sub_fields' => [
                self::field( 'title', 'text' ), self::field( 'cta', 'link' ),
                self::field( 'shared', 'link', [ 'translation' => 'copy' ] ),
                self::field( 'once', 'link', [ 'translation' => 'copy_once' ] ),
                self::field( 'own', 'link', [ 'translation' => 'ignore' ] ),
            ] ] ),
            self::field( 'details', 'group', [ 'translation' => 'copy', 'sub_fields' => [
                self::field( 'items', 'repeater', [ 'sub_fields' => [ self::field( 'cta', 'link' ) ] ] ),
            ] ] ),
            self::field( 'program', 'repeater', [ 'translation' => 'copy_once', 'sub_fields' => [ self::field( 'title', 'text' ) ] ] ),
            self::field( 'notes', 'repeater', [ 'translation' => 'ignore', 'sub_fields' => [ self::field( 'title', 'text' ) ] ] ),
        ] );
    }

    private function french( string $title ): int {
        $id = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => $title, 'blocks' => [], 'language' => 'fr', 'newOriginal' => true ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $id, 'status' => 'publish' ] );
        return $id;
    }

    private function set( int $id, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] )['values'];
    }

    private function values( int $id ): array {
        return $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values'];
    }

    private function link( int $en, int $fr ): void {
        $wpml = new WpmlSite( $this->site() );
        $wpml->link( $fr, $wpml->trid( $en ), 'fr' );
        $this->assertSame( $wpml->trid( $en ), $wpml->trid( $fr ) );
        $this->assertSame( 'en', $wpml->language( $en ) );
        $this->assertSame( 'fr', $wpml->language( $fr ) );
    }

    public function test_linking_preserves_own_links_nulls_and_independent_rows_then_follows_ids(): void {
        $en = $this->record( 'page', 'Linked fields original' );
        $fr = $this->french( 'Champs indépendants' );
        $english = [ 'url' => '/contact', 'label' => 'Contact' ];
        $french = [ 'url' => '/fr/contact', 'label' => 'Contactez-nous' ];
        $source = $this->set( $en, [
            'cta' => $english,
            'steps' => [
                [ 'title' => 'One', 'cta' => $english, 'shared' => $english, 'once' => $english ],
                [ 'title' => 'Two', 'cta' => $english, 'shared' => $english, 'once' => $english ],
            ],
            'details' => [ 'items' => [ [ 'cta' => $english ], [ 'cta' => $english ] ] ],
            'program' => [ [ 'title' => 'EN program' ] ], 'notes' => [ [ 'title' => 'EN note' ] ],
        ] );
        $own = $this->set( $fr, [
            'cta' => null,
            'steps' => [
                [ 'title' => 'Un', 'cta' => null, 'shared' => $french, 'once' => null, 'own' => $french ],
                [ 'title' => 'Deux', 'cta' => $french, 'shared' => $french, 'once' => $french ],
                [ 'title' => 'Trois', 'cta' => $french ],
            ],
            'details' => [ 'items' => [ [ 'cta' => null ] ] ],
            'program' => [ [ 'title' => 'Programme' ], [ 'title' => 'Encore' ] ],
            'notes' => [ [ 'title' => 'Note personnelle' ] ],
        ] );
        $this->assertNotSame( $source['steps'][0]['_row'], $own['steps'][0]['_row'] );
        $this->link( $en, $fr );
        $linked = $this->values( $fr );
        $this->assertSame( array_column( $source['steps'], '_row' ), array_column( $linked['steps'], '_row' ) );
        $this->assertSame( [ 'Un', 'Deux' ], array_column( $linked['steps'], 'title' ) );
        $this->assertNull( $linked['cta'] );
        $this->assertNull( $linked['steps'][0]['cta'] );
        $this->assertNull( $linked['steps'][0]['once'] );
        $this->assertSame( $source['steps'][0]['shared'], $linked['steps'][0]['shared'] );
        $this->assertSame( $own['steps'][0]['own'], $linked['steps'][0]['own'] );
        $this->assertSame( $own['steps'][1]['cta'], $linked['steps'][1]['cta'] );
        $this->assertSame( $own['steps'][1]['once'], $linked['steps'][1]['once'] );
        $this->assertSame( $own['program'], $linked['program'] );
        $this->assertSame( $own['notes'], $linked['notes'] );
        $this->assertSame( array_column( $source['details']['items'], '_row' ), array_column( $linked['details']['items'], '_row' ) );
        $this->assertNull( $linked['details']['items'][0]['cta'] );
        $this->assertSame( $source['details']['items'][1]['cta'], $linked['details']['items'][1]['cta'] );
        foreach ( [ 'fab_cta', 'fab_steps_0_cta', 'fab_steps_0_once', 'fab_details_items_0_cta' ] as $key ) {
            $this->assertSame( [ null ], $this->site()->meta( $fr, $key ), $key . ' stays explicitly cleared in SQL' );
        }
        $this->assertSame( [], $this->site()->rows( "SELECT meta_key FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'fab_steps_2_%'", [ $fr ] ), 'the surplus third row leaves no child meta' );

        $this->set( $en, [ 'cta' => [ 'url' => '/updated' ], 'steps' => array_reverse( $source['steps'] ) ] );
        $after = $this->values( $fr );
        $this->assertSame( [ 'Deux', 'Un' ], array_column( $after['steps'], 'title' ) );
        $this->assertSame( $own['steps'][1]['cta'], $after['steps'][0]['cta'] );
        $this->assertNull( $after['steps'][1]['cta'] );
        $this->assertNull( $after['cta'] );
        $this->assertSame( $own['program'], $after['program'] );
        $this->assertSame( $own['notes'], $after['notes'] );
        $this->link( $en, $fr );
        $this->assertSame( $after, $this->values( $fr ), 'repeated WPML linking does not rematch rows by their new position' );
    }

    private function rendered_links( int $id ): array {
        return $this->site_runs( "do_action( 'wpml_switch_language', 'fr' );
            \$cta = fabrement_get_field( 'cta', $id );
            \$pinned = fabrement_get_field( 'pinned', $id );
            return [ 'href' => fabrement_link_href( \$cta ), 'text' => fabrement_link_text( \$cta ),
                'pinned' => fabrement_link_href( \$pinned ), 'pinnedText' => fabrement_link_text( \$pinned ) ];" );
    }

    public function test_record_links_resolve_the_reader_or_pinned_language_after_pages_are_linked(): void {
        $destination = $this->record( 'page', 'Contact destination' );
        $destination_fr = $this->french( 'Destination française' );
        $this->link( $destination, $destination_fr );
        $en = $this->record( 'page', 'Linked reference original' );
        $fr = $this->french( 'Références françaises' );
        $this->set( $en, [ 'cta' => [ 'url' => 'https://example.com/' ] ] );
        $own = $this->set( $fr, [
            'cta' => [ 'postId' => $destination_fr, 'useRecordTitle' => true, 'label' => 'Contactez-nous' ],
            'pinned' => [ 'postId' => $destination_fr, 'lang' => 'en', 'useRecordTitle' => true ],
        ] );
        $this->assertSame( $destination, $own['cta']['postId'], 'picked French IDs are stored as their original' );
        $this->link( $en, $fr );
        $this->assertSame( $own['cta'], $this->values( $fr )['cta'] );
        $en_url = $this->mcp( 'get-content', [ 'contentId' => $destination ] )['url'];
        $fr_url = $this->mcp( 'get-content', [ 'contentId' => $destination_fr ] )['url'];
        $this->assertSame( [ 'href' => $fr_url, 'text' => 'Destination française', 'pinned' => $en_url, 'pinnedText' => 'Contact destination' ], $this->rendered_links( $fr ) );
        $this->assertSame( 200, $this->get( $fr_url )['status'] );
        $this->assertSame( 200, $this->get( $en_url )['status'] );

        $this->mcp( 'save-content', [ 'mode' => 'add', 'blocks' => [], 'contentId' => $destination_fr, 'title' => 'Nouveau contact', 'slug' => 'qa-new-contact-fr' ] );
        $renamed_url = $this->mcp( 'get-content', [ 'contentId' => $destination_fr ] )['url'];
        $this->assertNotSame( $fr_url, $renamed_url );
        $rendered = $this->rendered_links( $fr );
        $this->assertSame( $renamed_url, $rendered['href'] );
        $this->assertSame( 'Nouveau contact', $rendered['text'] );
        $this->assertSame( $en_url, $rendered['pinned'] );
        $this->assertSame( $own['cta'], $this->values( $fr )['cta'], 'renaming the target needs no rewrite of the stored link' );
        $this->assertSame( 200, $this->get( $renamed_url )['status'] );

        $this->mcp( 'set-content-status', [ 'contentId' => $destination_fr, 'status' => 'trash' ] );
        $rendered = $this->rendered_links( $fr );
        $this->assertSame( '', $rendered['href'], 'a trashed translated target is not linked publicly' );
        $this->assertSame( 'Contactez-nous', $rendered['text'] );
        $this->assertSame( $en_url, $rendered['pinned'] );
    }
}
