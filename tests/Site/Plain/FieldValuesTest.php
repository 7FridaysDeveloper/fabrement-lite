<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Field values as an agent writes them on a real site: save-fields, set-field-values,
 * get-field-values and list-post-type-fields over the MCP endpoint, records and media made through
 * the tools too; what each call left is read from the database.
 */
final class FieldValuesTest extends SiteTestCase {

    private const TYPE = 'qa_record';

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->post_type( self::TYPE );
            $this->fields( self::TYPE, [
                self::field( 'title', 'text' ),
                self::field( 'price', 'number', [ 'format' => 'decimal', 'default' => 100 ] ),
                self::field( 'stage', 'select', [ 'choices' => [ [ 'value' => 'old', 'label' => 'Old' ], [ 'value' => 'new', 'label' => 'New' ] ] ] ),
                self::field( 'ref', 'post_object' ),
                self::field( 'details', 'group', [ 'sub_fields' => [ self::field( 'city', 'text' ), self::field( 'ref', 'post_object' ) ] ] ),
                self::field( 'client', 'text', [ 'required' => true ] ),
                self::field( 'steps', 'repeater', [ 'sub_fields' => [ self::field( 'text', 'text' ) ] ] ),
                self::field( 'page_ref', 'post_object', [ 'postTypes' => [ 'page' ] ] ),
                self::field( 'photos', 'gallery' ),
                self::field( 'starts', 'date_picker' ),
                self::field( 'opens', 'date_time_picker' ),
            ] );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    private function set( int $id, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
    }

    protected function record( string $post_type, string $title, string $status = 'publish' ): int {
        $id = parent::record( $post_type, $title, $status );
        if ( $post_type === self::TYPE ) {
            $this->set( $id, [ 'client' => 'Fixture client' ] );
        }
        return $id;
    }

    /** All meta rows of a record, ids included: a refused or empty call leaves them exactly so. */
    private function meta_rows( int $id ): array {
        return $this->site()->rows( 'SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? ORDER BY meta_id', [ $id ] );
    }

    private function refused( int $id, array $values ): string {
        $before = $this->meta_rows( $id );
        $error  = $this->mcp_error( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
        $this->assertSame( $before, $this->meta_rows( $id ), 'a refused call writes nothing' );
        return $error;
    }

    private function values( int $id ): array {
        return $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values'];
    }

    public function test_a_value_equal_to_the_default_is_stored_when_the_agent_sets_it(): void {
        $id = $this->record( self::TYPE, 'Default' );
        $this->assertTrue( $this->set( $id, [ 'price' => 100 ] )['stored']['price'] );
        $this->assertSame( [ '100' ], $this->site()->meta( $id, 'fab_price' ) );
    }

    public function test_a_partial_write_requires_a_valid_final_required_field(): void {
        $id = $this->record( self::TYPE, 'Partial' );
        $this->site()->php( "delete_post_meta($id, 'fab_client');" );
        $this->assertStringContainsString( 'client', strtolower( $this->refused( $id, [ 'title' => 'Only a title' ] ) ) );
        $this->set( $id, [ 'client' => 'Filled' ] );
        $this->set( $id, [ 'title' => 'Only a title' ] );
        $this->assertSame( 'Only a title', $this->values( $id )['title'] );
    }

    public function test_one_sub_field_changes_next_to_a_trashed_record(): void {
        $target = $this->record( 'page', 'Target in a group' );
        $id     = $this->record( self::TYPE, 'Group' );
        $this->set( $id, [ 'details' => [ 'city' => 'Kyiv', 'ref' => $target ] ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $target, 'status' => 'trash' ] );
        $this->assertSame( 'Lviv', $this->set( $id, [ 'details' => [ 'city' => 'Lviv' ] ] )['values']['details']['city'] );
        $this->assertSame( [ (string) $target ], $this->site()->meta( $id, 'fab_details_ref' ), 'the trashed pick is kept' );
    }

    public function test_a_trashed_pick_does_not_block_saving_the_fields_beside_it(): void {
        $target = $this->record( 'page', 'Target' );
        $id     = $this->record( self::TYPE, 'Beside' );
        $this->set( $id, [ 'ref' => $target ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $target, 'status' => 'trash' ] );
        $this->assertSame( 'Hello again', $this->set( $id, [ 'title' => 'Hello again' ] )['values']['title'] );
        $this->assertSame( [ (string) $target ], $this->site()->meta( $id, 'fab_ref' ) );
    }

    public function test_picking_a_record_already_in_the_trash_is_refused(): void {
        $gone = $this->record( 'page', 'Gone', 'trash' );
        $this->refused( $this->record( self::TYPE, 'Picker' ), [ 'ref' => $gone ] );
    }

    public function test_a_choice_that_is_not_offered_is_refused_and_a_removed_one_does_not_block(): void {
        $id = $this->record( self::TYPE, 'Choice' );
        $this->set( $id, [ 'stage' => 'old' ] );
        $fields = array_values( $this->definitions( self::TYPE ) );
        foreach ( $fields as $i => $field ) {
            if ( $field['name'] === 'stage' ) {
                $fields[ $i ]['choices'] = [ [ 'value' => 'new', 'label' => 'New' ] ];
            }
        }
        $this->fields( self::TYPE, $fields );
        $this->refused( $id, [ 'stage' => 'other' ] );
        $this->set( $id, [ 'title' => 'The removed choice it holds does not block this' ] );
        $this->assertSame( [ 'old' ], $this->site()->meta( $id, 'fab_stage' ) );
    }

    public function test_rows_may_be_removed_toward_a_lowered_limit_but_not_added_over_it(): void {
        $id = $this->record( self::TYPE, 'Rows' );
        $this->set( $id, [ 'steps' => [ [ 'text' => 'A' ], [ 'text' => 'B' ], [ 'text' => 'C' ] ] ] );
        $fields = array_values( $this->definitions( self::TYPE ) );
        foreach ( $fields as $i => $field ) {
            if ( $field['name'] === 'steps' ) {
                $fields[ $i ]['max'] = 1;
            }
        }
        $this->fields( self::TYPE, $fields );
        $less = $this->set( $id, [ 'steps' => array_slice( $this->values( $id )['steps'], 0, 2 ) ] );
        $this->assertCount( 2, $less['values']['steps'] );
        $this->refused( $id, [ 'steps' => [ $less['values']['steps'][0], [ 'text' => 'New' ] ] ] );
    }

    public function test_a_picker_limited_to_types_refuses_another_type(): void {
        $id   = $this->record( self::TYPE, 'Typed' );
        $page = $this->record( 'page', 'Picked page' );
        $this->assertStringContainsString( 'type page', $this->refused( $id, [ 'page_ref' => $this->record( self::TYPE, 'Other type' ) ] ) );
        $this->assertSame( $page, $this->set( $id, [ 'page_ref' => $page ] )['values']['page_ref'] );
    }

    public function test_a_gallery_is_an_ordered_list_of_images_at_most_fifty(): void {
        $this->assertSame( 'copy', $this->definitions( self::TYPE )['photos']['translation'], 'copied to translations by default' );
        $id     = $this->record( self::TYPE, 'Gallery' );
        $images = [ $this->image( 'one.png' ), $this->image( 'two.png' ), $this->image( 'three.png' ) ];

        $this->assertSame( [ $images[2], $images[0] ], $this->set( $id, [ 'photos' => [ $images[2], $images[0] ] ] )['values']['photos'] );
        $this->assertSame( [ (string) $images[2], (string) $images[0] ], $this->site()->meta( $id, 'fab_photos' ), 'a meta row per image, in order' );
        $this->assertSame( '[' . $images[2] . ',' . $images[0] . ']', $this->site()->php( 'echo wp_json_encode( fabrement_get_field( "photos", ' . $id . ' ) );' ), 'page code reads the list' );

        $this->assertStringContainsString( 'listed twice', $this->refused( $id, [ 'photos' => [ $images[1], $images[1] ] ] ), 'the same image twice: a gallery holds each once, as ACF\'s does' );
        $this->refused( $id, [ 'photos' => [ $this->record( 'page', 'Not an image' ) ] ] );
        $this->assertStringContainsString( 'at most 50', $this->refused( $id, [ 'photos' => range( 1, 51 ) ] ) );
    }

    public function test_a_relationship_cannot_allow_more_than_fifty(): void {
        $current = $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => self::TYPE ] );
        $error   = $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => self::TYPE, 'expectedRevision' => $current['revision'],
            'fields' => array_merge( $current['fields'], [ self::field( 'many', 'relationship', [ 'max' => 60 ] ) ] ) ] );
        $this->assertStringContainsString( 'Invalid max for a relationship field', $error );
        $this->assertSame( $current['revision'], $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => self::TYPE ] )['revision'], 'nothing saved' );
    }

    public function test_keys_follow_from_the_names(): void {
        $definitions = $this->definitions( self::TYPE );
        $this->assertSame( 'fab_title', $definitions['title']['key'] );
        $this->assertSame( 'city', array_column( $definitions['details']['sub_fields'], 'key', 'name' )['city'] );
        $id = $this->record( self::TYPE, 'Keys' );
        $this->set( $id, [ 'details' => [ 'city' => 'Lviv' ] ] );
        $this->assertSame( [ 'Lviv' ], $this->site()->meta( $id, 'fab_details_city' ) );
    }

    public function test_a_number_compares_by_value_so_250_0_after_250_writes_nothing(): void {
        $id = $this->record( self::TYPE, 'Number' );
        $this->set( $id, [ 'price' => 250 ] );
        $before = $this->meta_rows( $id );
        $this->set( $id, [ 'price' => 250.0 ] );
        $this->assertSame( $before, $this->meta_rows( $id ) );
    }

    public function test_clearing_a_field_the_record_never_held_stores_empty_instead_of_default(): void {
        $id = $this->record( self::TYPE, 'Never held' );
        $this->assertSame( 100, $this->values( $id )['price'] );
        $saved = $this->set( $id, [ 'price' => null ] );
        $this->assertTrue( $saved['stored']['price'] );
        $this->assertNull( $saved['values']['price'] );
        $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_price' ) );
        $this->assertNull( $this->values( $id )['price'], 'a fresh read does not restore the default' );
    }

    public function test_list_post_type_fields_lists_each_declared_field_once_with_how_to_read_it(): void {
        $this->site_code( 'qa-registered-meta', "add_action( 'init', static function (): void { register_post_meta( '" . self::TYPE . "', 'qa_registered', [ 'type' => 'integer', 'single' => true, 'description' => 'Registered probe' ] ); } );" );
        $id = $this->record( self::TYPE, 'Listed' );
        $this->set( $id, [ 'details' => [ 'city' => 'Lviv' ] ] );
        $this->site()->php( 'update_post_meta( ' . $id . ', "qa_undeclared_note", "plugin value" );' );

        $listed = $this->mcp( 'list-post-type-fields', [ 'postType' => self::TYPE ] );
        $by_key  = array_column( $listed['fields'], null, 'key' );
        $this->assertArrayHasKey( 'fab_details', $by_key );
        $this->assertArrayHasKey( 'qa_registered', $by_key );
        $this->assertArrayNotHasKey( 'fab_details_city', $by_key, 'no storage rows' );
        $this->assertArrayNotHasKey( 'qa_undeclared_note', $by_key, 'no undeclared meta' );
        $this->assertSame( 'fabrement', $by_key['fab_details']['source'] );
        $this->assertSame( "fabrement()->fields( \$post_id )->get_group( 'details' )", $by_key['fab_details']['readSnippet'] );
        $this->assertSame( "fabrement()->fields( \$post_id )->get_date( 'starts' )", $by_key['fab_starts']['readSnippet'], 'a reader that exists' );
        $this->assertSame( "fabrement()->fields( \$post_id )->get_date_time( 'opens' )", $by_key['fab_opens']['readSnippet'] );
        $this->assertSame( [ 'city', 'ref' ], array_column( $by_key['fab_details']['subFields'], 'name' ) );
        $this->assertSame( 'registered', $by_key['qa_registered']['source'] );
        $this->assertSame( "get_post_meta( \$post_id, 'qa_registered', true )", $by_key['qa_registered']['readSnippet'] );
        $definitions = $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => self::TYPE ] );
        $this->assertSame( $definitions['revision'], $listed['fabrementFields']['revision'] );
    }

    /** Who may edit a record is asked where a person comes in: the MCP tools and the fields box's route. */
    public function test_someone_who_may_not_edit_the_record_is_refused_by_mcp_and_by_the_form_route(): void {
        $id = $this->record( self::TYPE, 'Locked' );
        $this->set( $id, [ 'title' => 'Kept' ] );
        // A site rule (a membership plugin, a locked record) takes editing this one record away.
        $this->site_code( 'qa-locked-record', "add_filter( 'map_meta_cap', static fn( \$caps, \$cap, \$user, \$args ) => \$cap === 'edit_post' && (int) ( \$args[0] ?? 0 ) === $id ? [ 'do_not_allow' ] : \$caps, 10, 4 );" );

        $this->assertSame( 'You cannot edit this record.', $this->mcp_error( 'set-field-values', [ 'postId' => $id, 'values' => [ 'title' => 'Changed' ] ] ) );
        $this->assertSame( 'You cannot edit this record.', $this->mcp_error( 'get-field-values', [ 'postId' => $id ] ) );
        $put = Http::request( $this->site(), 'PUT', $this->site()->url( '/wp-json/fabrement/v1/fields/post/' . $id ), [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ),
            'Content-Type'  => 'application/json',
        ], (string) json_encode( [ 'values' => [ 'fab_title' => 'Changed' ], 'intent' => [ 'fab_title' => true ], 'intentVersion' => 1 ] ) );
        $this->assertContains( $put['status'], [ 401, 403 ] );
        $this->assertSame( [ 'Kept' ], $this->site()->meta( $id, 'fab_title' ) );
    }
}
