<?php

namespace Fabrement\Tests\Cases\Wpml;

use Fabrement\Modules\Fields\Schema\FieldKind;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Tests\CommittedCleanup;
use Fabrement\Tests\McpCalls;
use Fabrement\Tests\Wpml\WpmlTestCase;

/**
 * Field values in translations, as an agent writes them through the site's MCP server on the real
 * WPML: record ids stored as the original, what a translator cleared, sub-fields added later, copy
 * values gone stale, and a type WPML does not translate.
 */
final class FieldValuesTest extends WpmlTestCase {

    use CommittedCleanup;
    use McpCalls;

    private const OTHER = 'qa_fvt_partner';

    public function set_up(): void {
        parent::set_up();
        $this->start_cleanup( [], get_current_user_id() );
        register_post_type( self::OTHER, [ 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'label' => 'Partners' ] );
        $this->fields( [
            $this->field( 'abstract', 'text' ),
            $this->field( 'venue_note', 'text' ),
            $this->field( 'day', 'date_picker', [ 'translation' => 'copy_once' ] ),
            $this->field( 'ref', 'post_object' ),
            $this->field( 'partner', 'post_object', [ 'postTypes' => [ self::OTHER ] ] ),
            $this->field( 'steps', 'repeater', [ 'sub_fields' => [ $this->field( 'heading', 'text' ), $this->field( 'code', 'text', [ 'translation' => 'copy' ] ) ] ] ),
            $this->field( 'details', 'group', [ 'sub_fields' => [ $this->field( 'city', 'text' ), $this->field( 'zip', 'text', [ 'translation' => 'copy' ] ) ] ] ),
        ] );
    }

    public function tear_down(): void {
        $owner = Owner::of( 'post', 'page' );
        if ( $owner ) {
            SchemaRepository::delete( $owner );
        }
        unregister_post_type( self::OTHER );
        SchemaRepository::flush();
        FieldKind::forget();
        $this->end_cleanup();
        parent::tear_down();
    }

    private function field( string $name, string $type, array $extra = [] ): array {
        return $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
    }

    /** save-fields with the keys get-fields gave. */
    private function fields( array $fields ): void {
        $current = $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => 'page' ] );
        $by_name  = array_column( (array) ( $current['fields'] ?? [] ), null, 'name' );
        foreach ( $fields as $i => $field ) {
            if ( isset( $by_name[ $field['name'] ] ) ) {
                $fields[ $i ]['key'] = $by_name[ $field['name'] ]['key'];
                $sub_keys             = array_column( $by_name[ $field['name'] ]['sub_fields'] ?? [], 'key', 'name' );
                foreach ( $field['sub_fields'] ?? [] as $j => $sub ) {
                    if ( isset( $sub_keys[ $sub['name'] ] ) ) {
                        $fields[ $i ]['sub_fields'][ $j ]['key'] = $sub_keys[ $sub['name'] ];
                    }
                }
            }
        }
        $this->mcp( 'save-fields', [ 'type' => 'post', 'slug' => 'page', 'expectedRevision' => (int) ( $current['revision'] ?? 0 ), 'fields' => $fields ] );
        FieldKind::forget();
    }

    private function page( string $title, array $values = [] ): int {
        $id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title ] );
        if ( $values ) {
            $this->set( $id, $values );
        }
        return $id;
    }

    private function translate( int $id, string $lang = 'fr' ): int {
        return (int) $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => $lang ] )['contentId'];
    }

    private function set( int $id, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
    }

    private function values( int $id ): array {
        return $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values'];
    }

    public function test_a_picked_version_of_a_translated_type_is_stored_as_its_original(): void {
        $target = $this->page( 'Target' );
        $fr     = $this->translate( $target );
        $id     = $this->page( 'Record' );
        $this->set( $id, [ 'ref' => $fr ] );
        $this->assertSame( (string) $target, get_post_meta( $id, 'fab_ref', true ), 'one id for every language: the original' );
    }

    public function test_a_type_wpml_does_not_translate_stores_exactly_the_record_picked(): void {
        $this->assertSame( 'none', Translation::bridge()->translation_mode( 'post', self::OTHER ) );
        $a = self::factory()->post->create( [ 'post_type' => self::OTHER, 'post_status' => 'publish', 'post_title' => 'Partner A' ] );
        $b = self::factory()->post->create( [ 'post_type' => self::OTHER, 'post_status' => 'publish', 'post_title' => 'Partner B' ] );
        // As after the type was switched to "not translatable": WPML keeps the old pair.
        global $wpdb;
        $icl = $wpdb->prefix . 'icl_translations';
        $wpdb->delete( $icl, [ 'element_type' => 'post_' . self::OTHER, 'element_id' => $a ] );
        $wpdb->delete( $icl, [ 'element_type' => 'post_' . self::OTHER, 'element_id' => $b ] );
        $trid = (int) $wpdb->get_var( "SELECT MAX(trid) FROM {$icl}" ) + 1;
        $wpdb->insert( $icl, [ 'element_type' => 'post_' . self::OTHER, 'element_id' => $a, 'trid' => $trid, 'language_code' => 'en', 'source_language_code' => null ] );
        $wpdb->insert( $icl, [ 'element_type' => 'post_' . self::OTHER, 'element_id' => $b, 'trid' => $trid, 'language_code' => 'fr', 'source_language_code' => 'en' ] );
        wp_cache_flush();
        $this->assertSame( $a, (int) Translation::bridge()->original_of( $b, self::OTHER ), 'WPML still holds the old pair' );

        $id = $this->page( 'Record' );
        $this->set( $id, [ 'partner' => $b ] );
        $this->assertSame( (string) $b, get_post_meta( $id, 'fab_partner', true ) );
    }

    public function test_translate_content_refuses_a_type_wpml_does_not_translate_and_creates_nothing(): void {
        $partner = self::factory()->post->create( [ 'post_type' => self::OTHER, 'post_status' => 'publish', 'post_title' => 'Partner' ] );
        $before  = (array) wp_count_posts( self::OTHER );
        $this->mcp_error( 'translate-content', [ 'contentId' => $partner, 'language' => 'fr' ] );
        $this->assertEquals( $before, (array) wp_count_posts( self::OTHER ) );
    }

    public function test_what_a_translator_cleared_stays_cleared_and_what_it_never_held_follows(): void {
        $en = $this->page( 'Talk', [ 'abstract' => 'EN abstract', 'day' => '2026-10-01' ] );
        $fr = $this->translate( $en );
        $this->set( $fr, [ 'abstract' => null, 'day' => null ] );
        $this->assertSame( '', get_post_meta( $fr, 'fab_abstract', true ) );
        $this->assertTrue( metadata_exists( 'post', $fr, 'fab_abstract' ), 'cleared in a translation = stored empty, not a missing row' );

        $this->set( $en, [ 'abstract' => 'EN abstract v2', 'day' => '2026-11-01', 'venue_note' => 'EN venue' ] );
        $values = $this->values( $fr );
        $this->assertNull( $values['abstract'], 'the original does not seed a cleared field again' );
        $this->assertNull( $values['day'] );
        $this->assertSame( 'EN venue', $values['venue_note'], 'a field the translation never held follows the original' );

        $this->set( $en, [ 'venue_note' => null ] );
        $this->assertTrue( metadata_exists( 'post', $en, 'fab_venue_note' ), 'on the original, clearing stores an explicit empty value' );
        $this->assertSame( '', get_post_meta( $en, 'fab_venue_note', true ) );
        $this->assertNull( $this->values( $en )['venue_note'] );
    }

    public function test_a_sub_field_added_later_is_seeded_into_the_translations_rows_and_a_cleared_one_stays(): void {
        $en  = $this->page( 'Course', [ 'steps' => [ [ 'heading' => 'H1', 'code' => 'c1' ] ] ] );
        $fr  = $this->translate( $en );
        $row = $this->values( $en )['steps'][0]['_row'];
        $this->assertSame( $row, $this->values( $fr )['steps'][0]['_row'], 'the translation follows the row' );

        $this->fields( [
            $this->field( 'abstract', 'text' ),
            $this->field( 'venue_note', 'text' ),
            $this->field( 'day', 'date_picker', [ 'translation' => 'copy_once' ] ),
            $this->field( 'ref', 'post_object' ),
            $this->field( 'partner', 'post_object', [ 'postTypes' => [ self::OTHER ] ] ),
            $this->field( 'steps', 'repeater', [ 'sub_fields' => [ $this->field( 'heading', 'text' ), $this->field( 'code', 'text', [ 'translation' => 'copy' ] ), $this->field( 'note', 'text' ) ] ] ),
            $this->field( 'details', 'group', [ 'sub_fields' => [ $this->field( 'city', 'text' ), $this->field( 'zip', 'text', [ 'translation' => 'copy' ] ) ] ] ),
        ] );
        $this->set( $en, [ 'steps' => [ [ '_row' => $row, 'heading' => 'H1', 'code' => 'c1', 'note' => 'EN note' ] ] ] );
        $this->assertSame( 'EN note', $this->values( $fr )['steps'][0]['note'], 'seeded from the original' );

        $this->set( $fr, [ 'steps' => [ [ '_row' => $row, 'heading' => 'H1 FR', 'code' => 'c1', 'note' => '' ] ] ] );
        $this->set( $en, [ 'steps' => [ [ '_row' => $row, 'heading' => 'H1', 'code' => 'c2', 'note' => 'EN note 2' ] ] ] );
        $step = $this->values( $fr )['steps'][0];
        $this->assertNull( $step['note'], 'written empty, it stays empty' );
        $this->assertSame( 'H1 FR', $step['heading'] );
        $this->assertSame( 'c2', $step['code'], 'a copied sub-field still follows' );
    }

    public function test_a_stale_copy_value_sent_back_unchanged_saves_the_words_next_to_it_and_is_healed(): void {
        $en = $this->page( 'Office', [ 'details' => [ 'city' => 'Kyiv', 'zip' => '01001' ] ] );
        $fr = $this->translate( $en );
        update_post_meta( $fr, 'fab_details_zip', '99999' );   // stale, as after a failed sync
        wp_cache_delete( $fr, 'post_meta' );

        $this->set( $fr, [ 'details' => [ 'city' => 'Kiev', 'zip' => '99999' ] ] );
        $this->assertSame( 'Kiev', get_post_meta( $fr, 'fab_details_city', true ) );
        $this->assertSame( '01001', get_post_meta( $fr, 'fab_details_zip', true ), 'healed from the original' );

        $this->mcp_error( 'set-field-values', [ 'postId' => $fr, 'values' => [ 'details' => [ 'city' => 'Kiev', 'zip' => '55555' ] ] ] );
        $this->assertSame( '01001', get_post_meta( $fr, 'fab_details_zip', true ), 'a copy value really changed is still refused' );
    }

}
