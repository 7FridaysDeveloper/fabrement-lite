<?php

namespace Fabrement\Tests\Wpml;

use Fabrement\Modules\Fields\Schema\FieldKind;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Query\Filter;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * The filter on the real WPML: what a record field holds in each language, and how the filter and
 * is() / chosen() read an address written in another language.
 */
final class FilterLanguagesTest extends WpmlTestCase {

    public function set_up(): void {
        parent::set_up();
        \Fabrement\Modules\Query\Facets::forget();
        // A field save commits its own transaction, so what an earlier test saved outlives its rollback.
        foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ] ) as $left ) {
            wp_delete_post( (int) $left, true );
        }
        $saved = $this->tool( 'save-fields', [ 'type' => 'post', 'slug' => 'post', 'expectedRevision' => 0, 'fields' => [
            [ 'name' => 'host', 'label' => 'Host', 'description' => 'About host', 'type' => 'post_object' ],
            [ 'name' => 'speakers', 'label' => 'Speakers', 'description' => 'About speakers', 'type' => 'relationship' ],
            [ 'name' => 'guests', 'label' => 'Guests', 'description' => 'About guests', 'type' => 'relationship', 'translation' => 'copy_once' ],
        ] ] );
        $this->assertIsArray( $saved, $saved instanceof WP_Error ? $saved->get_error_message() : '' );
        FieldKind::forget();
    }

    public function tear_down(): void {
        if ( $owner = Owner::of( 'post', 'post' ) ) {
            SchemaRepository::delete( $owner );
        }
        SchemaRepository::flush();
        FieldKind::forget();
        $_GET = [];
        parent::tear_down();
    }

    /** @return int the French version of $id */
    private function french( int $id ): int {
        $fr = $this->tool( 'translate-content', [ 'contentId' => $id, 'language' => 'fr' ] );
        $this->assertIsArray( $fr, $fr instanceof WP_Error ? $fr->get_error_message() : '' );
        return (int) $fr['contentId'];
    }

    /** @return array{0:int,1:int,2:int,3:int} hall, French hall, event, French event — host = the French hall */
    private function hall_and_event(): array {
        $hall    = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Hall' ] );
        $hall_fr = $this->french( $hall );
        wp_update_post( [ 'ID' => $hall_fr, 'post_status' => 'publish' ] );
        $event = self::factory()->post->create( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Event' ] );
        $ok    = $this->tool( 'set-field-values', [ 'postId' => $event, 'values' => [ 'host' => $hall_fr ] ] );
        $this->assertIsArray( $ok, $ok instanceof WP_Error ? $ok->get_error_message() : '' );
        $event_fr = $this->french( $event );
        wp_update_post( [ 'ID' => $event_fr, 'post_status' => 'publish' ] );
        return [ $hall, $hall_fr, $event, $event_fr ];
    }

    public function test_many_ids_are_one_query_and_ids_asked_before_are_none(): void {
        [ $hall, $hall_fr ] = $this->hall_and_event();
        $media    = self::factory()->attachment->create( [ 'post_mime_type' => 'image/png' ] );
        $plain    = self::factory()->post->create( [ 'post_type' => 'post', 'post_status' => 'publish' ] );
        $bridge   = Translation::bridge();
        $bridge->forget_languages();

        global $wpdb;
        $before = $wpdb->num_queries;
        $map    = $bridge->originals_of_any( [ $hall_fr, $hall, $media, $plain, 0, -3, $hall_fr ] );
        $this->assertSame( 1, $wpdb->num_queries - $before, 'pages, media and posts in one query' );
        $this->assertSame( [ $hall_fr => $hall, $hall => $hall, $media => $media, $plain => $plain ], $map );

        $before = $wpdb->num_queries;
        $this->assertSame( [ $hall_fr => $hall ], $bridge->originals_of_any( [ $hall_fr ] ) );
        $this->assertSame( 0, $wpdb->num_queries - $before, 'asked before in this request: no query' );
    }

    /** @return array{0:array<string,int>,1:array<string,int>} people by name in English and in French */
    private function people(): array {
        $en = [];
        $fr = [];
        foreach ( [ 'Ann', 'Bob', 'Cid' ] as $name ) {
            $en[ $name ] = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $name ] );
            $fr[ $name ] = $this->french( $en[ $name ] );
            wp_update_post( [ 'ID' => $fr[ $name ], 'post_status' => 'publish', 'post_title' => $name . ' (fr)' ] );
        }
        return [ $en, $fr ];
    }

    /** @return list<string> the rows of a key, in order */
    private static function rows( int $post, string $key ): array {
        return array_map( 'strval', get_post_meta( $post, $key, false ) );
    }

    public function test_a_list_is_copied_to_a_translation_row_by_row_and_follows_its_original(): void {
        [ $en, $fr ] = $this->people();
        $event = self::factory()->post->create( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Event' ] );
        // Picked as French versions and in a chosen order: stored as the originals, in that order.
        $ok = $this->tool( 'set-field-values', [ 'postId' => $event, 'values' => [ 'speakers' => [ $fr['Cid'], $en['Ann'] ], 'guests' => [ $en['Bob'] ] ] ] );
        $this->assertIsArray( $ok, $ok instanceof WP_Error ? $ok->get_error_message() : '' );
        $this->assertSame( [ (string) $en['Cid'], (string) $en['Ann'] ], self::rows( $event, 'fab_speakers' ) );

        $event_fr = $this->french( $event );
        $this->assertSame( [ (string) $en['Cid'], (string) $en['Ann'] ], self::rows( $event_fr, 'fab_speakers' ), 'the translation holds the same rows, in order' );
        $this->assertSame( [ $fr['Cid'], $fr['Ann'] ], Translation::bridge()->in_language( 'fr', static fn() => fabrement_get_field( 'speakers', $event_fr ) ), 'read as the French versions' );
        $this->assertSame( [ $en['Cid'], $en['Ann'] ], fabrement_get_field( 'speakers', $event ) );

        // copy: the original's change reaches the translation, reordered and shrunk.
        $this->assertIsArray( $this->tool( 'set-field-values', [ 'postId' => $event, 'values' => [ 'speakers' => [ $en['Ann'], $en['Bob'] ] ] ] ) );
        $this->assertSame( [ (string) $en['Ann'], (string) $en['Bob'] ], self::rows( $event_fr, 'fab_speakers' ) );

        // copy_once: the translation keeps its own list.
        $own = $this->tool( 'set-field-values', [ 'postId' => $event_fr, 'values' => [ 'guests' => [ $fr['Ann'], $fr['Cid'] ] ] ] );
        $this->assertIsArray( $own, $own instanceof WP_Error ? $own->get_error_message() : '' );
        $this->assertIsArray( $this->tool( 'set-field-values', [ 'postId' => $event, 'values' => [ 'guests' => [] ] ] ) );
        $this->assertSame( [ (string) $en['Ann'], (string) $en['Cid'] ], self::rows( $event_fr, 'fab_guests' ), 'copy_once stays the own list of the translation' );
        $this->assertSame( [ '' ], self::rows( $event, 'fab_guests' ), 'an empty list is one empty row' );

        // An empty list copied is the empty list of the translation too.
        $this->assertIsArray( $this->tool( 'set-field-values', [ 'postId' => $event, 'values' => [ 'speakers' => [] ] ] ) );
        $this->assertSame( [ '' ], self::rows( $event_fr, 'fab_speakers' ) );
    }

    public function test_a_wpml_duplicate_holds_the_list_rows(): void {
        global $sitepress;
        [ $en ] = $this->people();
        $event = self::factory()->post->create( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Event' ] );
        $this->assertIsArray( $this->tool( 'set-field-values', [ 'postId' => $event, 'values' => [ 'speakers' => [ $en['Bob'], $en['Ann'] ] ] ] ) );
        $dup = (int) $sitepress->make_duplicate( $event, 'fr' );
        $this->assertGreaterThan( 0, $dup );
        $this->assertSame( [ (string) $en['Bob'], (string) $en['Ann'] ], self::rows( $dup, 'fab_speakers' ) );
        $this->assertIsArray( $this->tool( 'set-field-values', [ 'postId' => $event, 'values' => [ 'speakers' => [ $en['Cid'] ] ] ] ) );
        $this->assertSame( [ (string) $en['Cid'] ], self::rows( $dup, 'fab_speakers' ), 'the duplicate follows' );
    }

    public function test_content_preview_uses_the_records_language_in_its_frontend_address(): void {
        $page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $fr   = $this->french( $page );
        $url = Translation::bridge()->in_language( 'en', static fn() => \Fabrement\Modules\ContentPreview\ContentPreview::url( $fr ) );
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
        $token = $query[ \Fabrement\Modules\ContentPreview\ContentPreview::TOKEN_ARG ];
        try {
            $this->assertSame( Translation::bridge()->permalink( $fr, 'fr' ), remove_query_arg( \Fabrement\Modules\ContentPreview\ContentPreview::TOKEN_ARG, $url ), 'French content keeps its French address when requested from English.' );
            $_GET = $query;
            $this->assertSame( $fr, \Fabrement\Modules\ContentPreview\ContentPreview::token_id() );
        } finally {
            delete_transient( \Fabrement\Framework\Keys::content_preview( $token ) );
        }
    }
}
