<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * A post type's field definitions through their life, as an agent goes through it over MCP:
 * defined (every type, nested in groups and repeaters, with tabs and widths), filled, changed
 * (labels, choices, layout, required, defaults, bounds), refused where a field's identity would
 * change (type, number format, key), retired and restored with their values and rows, renamed
 * the only way there is (a new field), and every rule save-fields holds a definition to.
 *
 * At every step: the MCP answer, get-fields, the field file on disk, get-field-values, the meta
 * rows, the fields box's route, and what the site's own code reads with fabrement()->fields().
 * A refusal leaves get-fields, the file and the meta exactly as they were.
 *
 * The test methods run in order; the first eight walk one type (qa_dl_life) through its life.
 */
final class FieldDefinitionLifecycleTest extends SiteTestCase {

    private const LIFE = 'qa_dl_life';


    /** What the lifecycle steps share: the record, its image and page, the rows' ids. */
    private static array $life = [];

    public static function tearDownAfterClass(): void {
        self::$life = [];
        parent::tearDownAfterClass();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private static function group( string $name, array $children, array $extra = [] ): array {
        return self::field( $name, 'group', [ 'sub_fields' => $children ] + $extra );
    }

    private static function repeater( string $name, array $children, array $extra = [] ): array {
        return self::field( $name, 'repeater', [ 'sub_fields' => $children ] + $extra );
    }

    private static function choices( array $values ): array {
        return array_map( static fn( string $v ): array => [ 'value' => $v, 'label' => ucfirst( str_replace( '_', ' ', $v ) ) ], $values );
    }

    /** get-fields as the agent reads it. */
    private function read( string $type ): array {
        return $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => $type ] );
    }

    /** save-fields with the revision get-fields gives now; $args: fields and/or layout. */
    private function save( string $type, array $args ): array {
        return $this->mcp( 'save-fields', [ 'type' => 'post', 'slug' => $type, 'expectedRevision' => (int) $this->read( $type )['revision'] ] + $args );
    }

    /** The field file exactly as it is on the site's disk; null when there is none. */
    private function file( string $type ): ?string {
        $out = $this->site()->exec( 'sh', '-c', 'f=/var/www/html/wp-content/uploads/fabrement-lite/fields/post.' . $type . '.json; [ -f "$f" ] && cat "$f" || echo "@@none"' );
        return $out === '@@none' ? null : $out;
    }

    /** A save-fields the site refuses: get-fields and the file are exactly as before. The message, entities decoded. */
    private function refused_save( string $type, array $args, ?int $revision = null ): string {
        $before = $this->read( $type );
        $file   = $this->file( $type );
        $error  = $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => $type, 'expectedRevision' => $revision ?? (int) $before['revision'] ] + $args );
        $this->assertSame( $before, $this->read( $type ), 'nothing saved: ' . $error );
        $this->assertSame( $file, $this->file( $type ), 'the field file is untouched: ' . $error );
        return html_entity_decode( $error, ENT_QUOTES );
    }

    /** The active fields as get-fields lists them, retired ones left out at every level: what the agent sends back. */
    private function active( string $type ): array {
        $strip = static function ( array $fields ) use ( &$strip ): array {
            $out = [];
            foreach ( $fields as $field ) {
                if ( ! empty( $field['retired'] ) ) {
                    continue;
                }
                if ( isset( $field['sub_fields'] ) ) {
                    $field['sub_fields'] = $strip( $field['sub_fields'] );
                }
                $out[] = $field;
            }
            return $out;
        };
        return $strip( $this->read( $type )['fields'] );
    }

    /** Retired fields left out at every level of a field list. */
    private static function without_retired_fields( array $fields ): array {
        $out = [];
        foreach ( $fields as $field ) {
            if ( ! empty( $field['retired'] ) ) {
                continue;
            }
            if ( isset( $field['sub_fields'] ) ) {
                $field['sub_fields'] = self::without_retired_fields( $field['sub_fields'] );
            }
            $out[] = $field;
        }
        return $out;
    }

    /** $fields with the field at $path (names, "venue.city") changed by $change; null from $change drops it. */
    private static function edit( array $fields, string $path, callable $change ): array {
        $names = explode( '.', $path );
        $first = array_shift( $names );
        $out   = [];
        foreach ( $fields as $field ) {
            if ( $field['name'] === $first ) {
                if ( $names ) {
                    $field['sub_fields'] = self::edit( $field['sub_fields'], implode( '.', $names ), $change );
                } else {
                    $field = $change( $field );
                    if ( $field === null ) {
                        continue;
                    }
                }
            }
            $out[] = $field;
        }
        return $out;
    }

    /** By name, at one level. */
    private static function by_name( array $fields ): array {
        return array_column( $fields, null, 'name' );
    }

    /** Arrays with their keys in one order, lists left as they are: two definitions compare by content. */
    private static function canon( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        $value = array_map( [ self::class, 'canon' ], $value );
        if ( array_values( $value ) !== $value ) {
            ksort( $value );
        }
        return $value;
    }

    /**
     * A definition as save-fields documents its normalized form: the key from the name (fab_<name>
     * at the top, the name below), the default policy (translate for words, copy for the rest),
     * required false, a number's format decimal.
     */
    private static function normalized( array $sent, bool $top = true ): array {
        $words = [ 'text', 'textarea', 'wysiwyg', 'link' ];
        $out   = $sent + [
            'key'         => $top ? 'fab_' . $sent['name'] : $sent['name'],
            'translation' => in_array( $sent['type'], $words, true ) ? 'translate' : 'copy',
            'required'    => false,
        ];
        if ( $sent['type'] === 'number' ) {
            $out += [ 'format' => 'decimal' ];
        }
        if ( isset( $sent['sub_fields'] ) ) {
            $out['sub_fields'] = array_map( static fn( array $c ): array => self::normalized( $c, false ), $sent['sub_fields'] );
        }
        return $out;
    }

    private function set( int $id, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
    }

    private function values( int $id ): array {
        return $this->mcp( 'get-field-values', [ 'postId' => $id ] );
    }

    /** All meta rows of a record, ids included: what a definition change must leave exactly so. */
    private function meta_rows( int $id ): array {
        return $this->site()->rows( 'SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE ? ORDER BY meta_id', [ $id, 'fab\\_%' ] );
    }

    private function refused_values( int $id, array $values ): string {
        $before = $this->meta_rows( $id );
        $error  = $this->mcp_error( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
        $this->assertSame( $before, $this->meta_rows( $id ), 'a refused call writes nothing' );
        return html_entity_decode( $error, ENT_QUOTES );
    }

    /** What the Fabrement fields box loads for a record (its REST route, as the editor calls it). */
    private function box( int $id ): array {
        $answer = Http::request( $this->site(), 'GET', $this->site()->url( '/wp-json/fabrement/v1/fields/post/' . $id ), [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ),
        ] );
        return [ 'status' => $answer['status'] ] + (array) json_decode( $answer['body'], true );
    }

    /** Names of the fields the box shows, nested ones as "group.child". */
    private static function box_names( array $fields, string $prefix = '' ): array {
        $names = [];
        foreach ( $fields as $field ) {
            $names[] = $prefix . $field['name'];
            if ( isset( $field['sub_fields'] ) ) {
                $names = array_merge( $names, self::box_names( $field['sub_fields'], $prefix . $field['name'] . '.' ) );
            }
        }
        return $names;
    }

    /** debug.log, read and emptied: for reads whose point is the line they log. */
    private function take_debug_log(): string {
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        return $log;
    }

    // ── the life of one type's fields ────────────────────────────────────────────────────────

    private static function life_fields(): array {
        return [
            self::field( 'headline', 'text' ),
            self::field( 'guests', 'number', [ 'format' => 'integer', 'min' => 0, 'max' => 500, 'default' => 10 ] ),
            self::field( 'price', 'number', [ 'format' => 'decimal', 'min' => 0 ] ),
            self::field( 'stage', 'select', [ 'choices' => self::choices( [ 'draft', 'live', 'closed' ] ) ] ),
            self::field( 'featured', 'true_false', [ 'default' => false ] ),
            self::field( 'cover', 'image' ),
            self::field( 'cta', 'link' ),
            self::field( 'host', 'post_object', [ 'postTypes' => [ 'page' ] ] ),
            self::group( 'venue', [ self::field( 'city', 'text' ), self::field( 'floor', 'number', [ 'format' => 'integer' ] ) ] ),
            self::repeater( 'agenda', [ self::field( 'time', 'text' ), self::field( 'topic', 'text' ), self::field( 'note', 'text' ) ], [ 'max' => 10 ] ),
        ];
    }

    private static function life_layout(): array {
        return [ 'tabs' => [
            [ 'label' => 'Main', 'items' => [ [ 'field' => 'headline', 'width' => '1' ], [ 'field' => 'guests', 'width' => '1/3' ], [ 'field' => 'price', 'width' => '1/3' ],
                [ 'field' => 'stage', 'width' => '1/3' ], [ 'field' => 'featured', 'width' => '1/4' ] ] ],
            [ 'label' => 'Media', 'items' => [ [ 'field' => 'cover', 'width' => '1/2' ], [ 'field' => 'cta', 'width' => '1/2' ], [ 'field' => 'host' ] ] ],
            [ 'label' => 'Details', 'items' => [ [ 'field' => 'venue' ], [ 'field' => 'agenda', 'display' => 'table' ] ] ],
        ] ];
    }

    /** The layout as stored: every item points at the field's key. */
    private static function stored_layout( array $layout ): array {
        foreach ( $layout['tabs'] as $t => $tab ) {
            foreach ( $tab['items'] as $i => $item ) {
                $layout['tabs'][ $t ]['items'][ $i ]['field'] = 'fab_' . $item['field'];
            }
        }
        return $layout;
    }

    public function test_1_an_agent_defines_fields_of_every_kind_with_tabs_and_gets_back_what_it_saved(): void {
        $this->post_type( self::LIFE );
        $this->assertSame( 0, $this->read( self::LIFE )['revision'], 'no fields yet' );
        $this->assertNull( $this->file( self::LIFE ) );

        $saved = $this->mcp( 'save-fields', [ 'type' => 'post', 'slug' => self::LIFE, 'expectedRevision' => 0, 'fields' => self::life_fields(), 'layout' => self::life_layout() ] );
        $this->assertTrue( $saved['saved'] );
        $this->assertSame( 1, $saved['revision'] );
        $expected = array_map( static fn( array $f ): array => self::normalized( $f ), self::life_fields() );
        $this->assertSame( self::canon( $expected ), self::canon( $saved['fields'] ), 'the answer is the normalized definition' );
        $this->assertSame( self::stored_layout( self::life_layout() ), $saved['layout'], 'layout items point at keys' );

        $read = $this->read( self::LIFE );
        $this->assertSame( 'post.' . self::LIFE, $read['owner'] );
        $this->assertSame( 'uploads/fabrement-lite/fields/post.' . self::LIFE . '.json', $read['file'] );
        $this->assertTrue( $read['exists'] );
        $this->assertSame( 1, $read['revision'] );
        $this->assertSame( $saved['fields'], $read['fields'], 'get-fields gives exactly what was saved' );
        $this->assertSame( $saved['layout'], $read['layout'] );
        $this->assertArrayNotHasKey( 'problem', $read );

        $file = json_decode( (string) $this->file( self::LIFE ), true );
        $this->assertIsArray( $file, 'the field file exists and is JSON' );
        $this->assertSame( [ 1, 'post', self::LIFE, 1 ], [ $file['schemaVersion'], $file['type'], $file['slug'], $file['revision'] ] );
        $this->assertSame( self::canon( $read['fields'] ), self::canon( $file['fields'] ), 'the file holds the definition' );
        $this->assertSame( self::canon( $read['layout'] ), self::canon( $file['layout'] ) );

        // The type now lists its fields where templates look for them.
        $listed = array_column( $this->mcp( 'list-post-type-fields', [ 'postType' => self::LIFE ] )['fields'], null, 'key' );
        foreach ( self::life_fields() as $field ) {
            $this->assertSame( 'fabrement', $listed[ 'fab_' . $field['name'] ]['source'] ?? null, $field['name'] );
        }

        // A record before anything is filled: defaults where there are some, empty elsewhere, nothing stored.
        $id = $this->record( self::LIFE, 'QA lifecycle event' );
        self::$life['id'] = $id;
        $values = $this->values( $id );
        $this->assertSame( 10, $values['values']['guests'], 'the default' );
        $this->assertFalse( $values['values']['featured'] );
        $this->assertNull( $values['values']['headline'] );
        $this->assertNull( $values['values']['agenda'] );
        $this->assertSame( array_fill_keys( array_column( self::life_fields(), 'name' ), false ), $values['stored'] );
        $this->assertSame( [], $this->meta_rows( $id ), 'a default is not written' );
        $this->assertSame( [ 10, null, false ], $this->site_runs( "\$f = fabrement()->fields( $id ); return [ \$f->get_number( 'guests' ), \$f->get_text( 'headline' ), \$f->get_true_false( 'featured' ) ];" ) );

        $box = $this->box( $id );
        $this->assertSame( 200, $box['status'] );
        $this->assertSame( [ 'headline', 'guests', 'price', 'stage', 'featured', 'cover', 'cta', 'host', 'venue', 'venue.city', 'venue.floor', 'agenda', 'agenda.time', 'agenda.topic', 'agenda.note' ], self::box_names( $box['fields'] ) );
        $this->assertSame( [ 'Main', 'Media', 'Details' ], array_column( $box['layout']['tabs'], 'label' ) );
        $this->assertSame( 'table', $box['layout']['tabs'][2]['items'][1]['display'] );
    }

    public function test_2_a_record_is_filled_and_every_reader_gives_the_values_back_typed(): void {
        $id    = self::$life['id'];
        $image = $this->image( 'qa-dl-cover.png' );
        $page  = $this->record( 'page', 'QA lifecycle host' );
        self::$life += [ 'image' => $image, 'page' => $page ];

        $answer = $this->set( $id, [
            'headline' => 'Opening night', 'guests' => 120, 'price' => 49.9, 'stage' => 'live', 'featured' => true,
            'cover'    => $image, 'cta' => [ 'url' => 'https://example.com/tickets', 'label' => 'Tickets' ], 'host' => $page,
            'venue'    => [ 'city' => 'Lviv', 'floor' => 2 ],
            'agenda'   => [ [ 'time' => '18:00', 'topic' => 'Doors', 'note' => 'n1' ], [ 'time' => '19:00', 'topic' => 'Talk', 'note' => 'n2' ] ],
        ] );
        $this->assertTrue( $answer['saved'] );
        $values = $this->values( $id );
        $this->assertSame( $answer['values'], $values['values'], 'the answer is what get-field-values reads' );
        $v = $values['values'];
        $this->assertSame( 'Opening night', $v['headline'] );
        $this->assertSame( 120, $v['guests'] );
        $this->assertSame( 49.9, $v['price'] );
        $this->assertSame( 'live', $v['stage'] );
        $this->assertTrue( $v['featured'] );
        $this->assertSame( $image, $v['cover'] );
        $this->assertSame( [ 'https://example.com/tickets', 'Tickets' ], [ $v['cta']['url'], $v['cta']['label'] ] );
        $this->assertSame( $page, $v['host'] );
        $this->assertSame( [ 'city' => 'Lviv', 'floor' => 2 ], $v['venue'] );
        $this->assertSame( [ 'Doors', 'Talk' ], array_column( $v['agenda'], 'topic' ) );
        foreach ( $v['agenda'] as $row ) {
            $this->assertMatchesRegularExpression( '/^row_[a-f0-9]{12}$/', $row['_row'] );
        }
        self::$life['rows'] = array_column( $v['agenda'], '_row' );
        $this->assertSame( array_fill_keys( array_column( self::life_fields(), 'name' ), true ), $values['stored'] );

        $meta = $this->site();
        $this->assertSame( [ 'Opening night' ], $meta->meta( $id, 'fab_headline' ) );
        $this->assertSame( [ '120' ], $meta->meta( $id, 'fab_guests' ) );
        $this->assertSame( [ '49.9' ], $meta->meta( $id, 'fab_price' ) );
        $this->assertSame( [ 'live' ], $meta->meta( $id, 'fab_stage' ) );
        $this->assertSame( [ '1' ], $meta->meta( $id, 'fab_featured' ) );
        $this->assertSame( [ (string) $image ], $meta->meta( $id, 'fab_cover' ) );
        $this->assertSame( [ (string) $page ], $meta->meta( $id, 'fab_host' ) );
        $this->assertTrue( $meta->has_meta( $id, 'fab_cta' ) );
        $this->assertSame( [ '1' ], $meta->meta( $id, 'fab_venue' ), 'the group marker' );
        $this->assertSame( [ 'Lviv' ], $meta->meta( $id, 'fab_venue_city' ) );
        $this->assertSame( [ '2' ], $meta->meta( $id, 'fab_venue_floor' ) );
        $this->assertSame( [ '2' ], $meta->meta( $id, 'fab_agenda' ), 'the row count' );
        $this->assertSame( [ 'Doors' ], $meta->meta( $id, 'fab_agenda_0_topic' ) );
        $this->assertSame( [ 'n2' ], $meta->meta( $id, 'fab_agenda_1_note' ) );
        $this->assertSame( [ self::$life['rows'][0] ], $meta->meta( $id, 'fab_agenda_0__rid' ), 'the row identity is stored in the row' );

        $this->assertSame( [
            'headline' => 'Opening night', 'guests' => 120, 'price' => 49.9, 'stage' => 'live', 'stageLabel' => 'Live', 'featured' => true,
            'cover'    => $image, 'cta' => 'https://example.com/tickets', 'ctaText' => 'Tickets', 'host' => $page, 'city' => 'Lviv', 'floor' => 2,
            'topics'   => [ 'Doors', 'Talk' ], 'rowIds' => self::$life['rows'],
        ], $this->site_runs( "\$f = fabrement()->fields( $id ); \$venue = \$f->get_group( 'venue' ); return [
            'headline' => \$f->get_text( 'headline' ), 'guests' => \$f->get_number( 'guests' ), 'price' => \$f->get_number( 'price' ),
            'stage' => \$f->get_select( 'stage' ), 'stageLabel' => \$f->get_select( 'stage', label: true ), 'featured' => \$f->get_true_false( 'featured' ),
            'cover' => \$f->get_image( 'cover' )->id(), 'cta' => \$f->get_link( 'cta' )->url(), 'ctaText' => \$f->get_link( 'cta' )->text(),
            'host' => \$f->get_post_object( 'host' )->id(), 'city' => \$venue->get_text( 'city' ), 'floor' => \$venue->get_number( 'floor' ),
            'topics' => array_map( static fn( \$row ) => \$row->get_text( 'topic' ), \$f->get_rows( 'agenda' ) ),
            'rowIds' => array_map( static fn( \$row ) => \$row->id(), \$f->get_rows( 'agenda' ) ),
        ];" ) );

        // A whole number in a decimal field reads as a decimal; the stored text is as written.
        $this->set( $id, [ 'price' => 50 ] );
        $this->assertSame( [ '50' ], $meta->meta( $id, 'fab_price' ) );
        $number = $this->site_runs( "\$n = fabrement()->fields( $id )->get_number( 'price' ); return [ gettype( \$n ), \$n ];" );
        $this->assertSame( 'double', $number[0], 'a float in site code' );
        $this->assertEquals( 50, $number[1] );
        $this->set( $id, [ 'price' => 49.9 ] );

        $box = $this->box( $id );
        $this->assertSame( 'Opening night', $box['values']['fab_headline'] );
    }

    public function test_3_labels_choices_layout_required_and_bounds_change_and_no_value_moves(): void {
        $id     = self::$life['id'];
        $before = $this->meta_rows( $id );
        $values = $this->values( $id );

        $fields = $this->active( self::LIFE );
        $fields = self::edit( $fields, 'headline', static fn( array $f ): array => [ 'label' => 'Event headline', 'description' => 'Shown on top', 'required' => true ] + $f );
        $fields = self::edit( $fields, 'stage', static fn( array $f ): array => [ 'choices' => self::choices( [ 'draft', 'live', 'sold_out' ] ) ] + $f );
        $fields = self::edit( $fields, 'guests', static fn( array $f ): array => [ 'max' => 1000, 'default' => 20 ] + $f );
        $fields = self::edit( $fields, 'venue.city', static fn( array $f ): array => [ 'label' => 'Town' ] + $f );
        $fields = self::edit( $fields, 'agenda', static fn( array $f ): array => [ 'label' => 'Programme', 'max' => 20 ] + $f );
        $layout = [ 'tabs' => [
            [ 'label' => 'Programme', 'items' => [ [ 'field' => 'agenda', 'display' => 'row' ] ] ],
            [ 'label' => 'Essentials', 'items' => [ [ 'field' => 'headline', 'width' => '2/3' ], [ 'field' => 'stage', 'width' => '1/3' ] ] ],
        ] ];
        $saved = $this->save( self::LIFE, [ 'fields' => $fields, 'layout' => $layout ] );
        $this->assertSame( 2, $saved['revision'] );

        $read = self::by_name( $this->read( self::LIFE )['fields'] );
        $this->assertSame( [ 'Event headline', 'Shown on top', true ], [ $read['headline']['label'], $read['headline']['description'], $read['headline']['required'] ] );
        $this->assertSame( [ 'draft', 'live', 'sold_out' ], array_column( $read['stage']['choices'], 'value' ) );
        $this->assertSame( [ 1000, 20 ], [ $read['guests']['max'], $read['guests']['default'] ] );
        $this->assertSame( 'Town', self::by_name( $read['venue']['sub_fields'] )['city']['label'] );
        $this->assertSame( [ 'Programme', 20 ], [ $read['agenda']['label'], $read['agenda']['max'] ] );
        $this->assertSame( 'translate', $read['headline']['translation'], 'a policy not sent stays as it was' );
        $this->assertSame( [ 'Programme', 'Essentials' ], array_column( $this->read( self::LIFE )['layout']['tabs'], 'label' ) );
        $this->assertSame( json_decode( (string) $this->file( self::LIFE ), true )['fields'], $this->read( self::LIFE )['fields'], 'the file says the same' );

        $this->assertSame( $before, $this->meta_rows( $id ), 'a definition change writes no value' );
        $this->assertSame( $values['values'], $this->values( $id )['values'], 'and reads the same values' );
        $this->assertSame( [ 'Live', 120 ], $this->site_runs( "\$f = fabrement()->fields( $id ); return [ \$f->get_select( 'stage', label: true ), \$f->get_number( 'guests' ) ];" ) );

        $box = $this->box( $id );
        $this->assertSame( [ 'Programme', 'Essentials' ], array_column( $box['layout']['tabs'], 'label' ) );
        $this->assertSame( 'Event headline', self::by_name( $box['fields'] )['headline']['label'] );
        $this->assertTrue( self::by_name( $box['fields'] )['headline']['required'] );

        // The choice the record holds is removed: the value stays stored, reads as unselected, and is back with the choice.
        $other = $this->record( self::LIFE, 'QA lifecycle other' );
        $this->set( $other, [ 'headline' => 'Other', 'stage' => 'live' ] );
        $this->save( self::LIFE, [ 'fields' => self::edit( $this->active( self::LIFE ), 'stage', static fn( array $f ): array => [ 'choices' => self::choices( [ 'draft', 'sold_out' ] ) ] + $f ) ] );
        $this->assertSame( [ 'live' ], $this->site()->meta( $id, 'fab_stage' ) );
        $this->assertNull( $this->values( $id )['values']['stage'], 'a choice no longer offered reads as unselected' );
        $this->assertNull( $this->site_runs( "return fabrement()->fields( $id )->get_select( 'stage' );" ) );
        $this->assertSame( '', $this->box( $id )['values']['fab_stage'], 'the box shows it unselected' );
        // Sent back as read beside a change (as the box sends it): the unavailable value is saved as unselected.
        $this->set( $other, [ 'stage' => 'live', 'headline' => 'Other again' ] );
        $this->assertSame( [ null ], $this->site()->meta( $other, 'fab_stage' ) );
        $this->assertStringContainsString( 'stage', $this->refused_values( $other, [ 'stage' => 'live' ] ), 'and it can no longer be picked' );
        $this->save( self::LIFE, [ 'fields' => self::edit( $this->active( self::LIFE ), 'stage', static fn( array $f ): array => [ 'choices' => self::choices( [ 'draft', 'live', 'sold_out' ] ) ] + $f ) ] );
        $this->assertSame( 'live', $this->values( $id )['values']['stage'], 'offered again: the stored value reads again' );
        $this->assertSame( $before, $this->meta_rows( $id ) );

        // A bound tightened below what a record holds: the stored value stays and does not block other saves.
        $this->save( self::LIFE, [ 'fields' => self::edit( $this->active( self::LIFE ), 'guests', static fn( array $f ): array => [ 'max' => 100 ] + $f ) ] );
        $this->assertSame( 120, $this->values( $id )['values']['guests'] );
        $this->set( $id, [ 'headline' => 'Opening night' ] );
        $this->assertSame( [ '120' ], $this->site()->meta( $id, 'fab_guests' ) );
        $this->assertStringContainsString( 'guests', $this->refused_values( $id, [ 'guests' => 150 ] ), 'a new value is held to the new bound' );
        $this->save( self::LIFE, [ 'fields' => self::edit( $this->active( self::LIFE ), 'guests', static fn( array $f ): array => [ 'max' => 1000 ] + $f ) ] );

        // Layout alone: the fields stay as they are.
        $fields_now = $this->read( self::LIFE )['fields'];
        $this->save( self::LIFE, [ 'layout' => self::life_layout() ] );
        $this->assertSame( $fields_now, $this->read( self::LIFE )['fields'] );
        $this->assertSame( self::stored_layout( self::life_layout() ), $this->read( self::LIFE )['layout'] );
    }

    public function test_4_a_fields_type_or_number_format_never_changes_and_the_refusal_writes_nothing(): void {
        $id     = self::$life['id'];
        $before = $this->meta_rows( $id );
        $cases  = [
            'top-level type'      => [ 'headline', 'textarea', 'fields[0].type' ],
            'group child type'    => [ 'venue.floor', 'text', 'fields[8].sub_fields[1]' ],
            'repeater child type' => [ 'agenda.time', 'date_picker', 'fields[9].sub_fields[0]' ],
        ];
        foreach ( $cases as $case => [ $path, $type, $at ] ) {
            $fields = self::edit( $this->active( self::LIFE ), $path, static function ( array $f ) use ( $type ): array {
                unset( $f['format'], $f['min'], $f['max'], $f['default'] );
                return [ 'type' => $type ] + $f;
            } );
            $error = $this->refused_save( self::LIFE, [ 'fields' => $fields ] );
            $this->assertStringStartsWith( $at, $error, $case . ': names the field to fix' );
            $this->assertMatchesRegularExpression( '/never changes|cannot change/', $error, $case );
        }
        $error = $this->refused_save( self::LIFE, [ 'fields' => self::edit( $this->active( self::LIFE ), 'price', static fn( array $f ): array => [ 'format' => 'integer' ] + $f ) ] );
        $this->assertStringStartsWith( 'fields[2].format', $error );
        $this->assertStringContainsString( 'keeps its format (decimal)', $error );
        $error = $this->refused_save( self::LIFE, [ 'fields' => self::edit( $this->active( self::LIFE ), 'venue.floor', static fn( array $f ): array => [ 'format' => 'decimal' ] + $f ) ] );
        $this->assertStringContainsString( 'keeps its format (integer)', $error, 'a group child\'s format too' );
        // Leaving a number's format out keeps the stored one.
        $this->save( self::LIFE, [ 'fields' => self::edit( $this->active( self::LIFE ), 'venue.floor', static function ( array $f ): array {
            unset( $f['format'] );
            return $f;
        } ) ] );
        $this->assertSame( 'integer', self::by_name( self::by_name( $this->read( self::LIFE )['fields'] )['venue']['sub_fields'] )['floor']['format'] );
        $this->assertSame( $before, $this->meta_rows( $id ), 'no value moved' );
    }

    public function test_5_a_field_left_out_is_retired_its_values_kept_and_hidden_everywhere(): void {
        $id     = self::$life['id'];
        $before = $this->meta_rows( $id );
        $fields = self::edit( $this->active( self::LIFE ), 'price', static fn(): ?array => null );
        $fields = self::edit( $fields, 'agenda.note', static fn(): ?array => null );
        $saved  = $this->save( self::LIFE, [ 'fields' => $fields ] );

        $read = self::by_name( $saved['fields'] );
        $this->assertTrue( $read['price']['retired'] ?? false, 'the left-out field is retired, not deleted' );
        $this->assertSame( 'number', $read['price']['type'] );
        $this->assertTrue( self::by_name( $read['agenda']['sub_fields'] )['note']['retired'] ?? false, 'the left-out child too' );
        $this->assertSame( $saved['fields'], $this->read( self::LIFE )['fields'] );
        $this->assertTrue( self::by_name( json_decode( (string) $this->file( self::LIFE ), true )['fields'] )['price']['retired'] );
        $this->assertNotContains( 'fab_price', array_column( array_merge( ...array_column( $this->read( self::LIFE )['layout']['tabs'], 'items' ) ), 'field' ), 'the layout no longer places it' );

        $this->assertSame( $before, $this->meta_rows( $id ), 'its values stay in the database' );
        $values = $this->values( $id );
        $this->assertArrayNotHasKey( 'price', $values['values'] );
        $this->assertArrayNotHasKey( 'price', $values['stored'] );
        foreach ( $values['values']['agenda'] as $row ) {
            $this->assertArrayNotHasKey( 'note', $row );
        }
        $box = $this->box( $id );
        $this->assertNotContains( 'price', self::box_names( $box['fields'] ), 'the fields box no longer shows it' );
        $this->assertNotContains( 'agenda.note', self::box_names( $box['fields'] ) );
        $this->assertArrayNotHasKey( 'fab_price', $box['values'] );

        // The site's code asking for it: nothing, and the log says why.
        $this->assertSame( [ null, null, 'Talk' ], $this->site_runs( "\$f = fabrement()->fields( $id ); \$rows = \$f->get_rows( 'agenda' ); return [ \$f->get_number( 'price' ), \$rows[0]->get_text( 'note' ), \$rows[1]->get_text( 'topic' ) ];" ) );
        $log = $this->take_debug_log();
        $this->assertStringContainsString( 'price: Unknown field.', $log );
        $this->assertStringContainsString( 'note: Unknown field.', $log );

        $this->assertStringContainsString( 'price', $this->refused_values( $id, [ 'price' => 10 ] ), 'a retired field takes no value' );
        $rows = $this->values( $id )['values']['agenda'];
        $this->refused_values( $id, [ 'agenda' => [ $rows[0] + [ 'note' => 'x' ], $rows[1] ] ] );

        // Its rows move: the retired child's values go with their row.
        $this->set( $id, [ 'agenda' => [ $rows[1], $rows[0] ] ] );
        $this->assertSame( [ 'n2' ], $this->site()->meta( $id, 'fab_agenda_0_note' ), 'the retired value moved with its row' );
        $this->assertSame( [ 'n1' ], $this->site()->meta( $id, 'fab_agenda_1_note' ) );
        $this->assertSame( [ self::$life['rows'][1] ], $this->site()->meta( $id, 'fab_agenda_0__rid' ) );

        // A retired name keeps its type: it cannot come back as something else.
        $error = $this->refused_save( self::LIFE, [ 'fields' => array_merge( $this->active( self::LIFE ), [ self::field( 'price', 'text' ) ] ) ] );
        $this->assertStringContainsString( 'retired number field', $error );
    }

    public function test_6_sent_again_a_retired_field_is_back_with_its_values_and_rows(): void {
        $id    = self::$life['id'];
        $saved = $this->save( self::LIFE, [ 'fields' => self::edit( self::edit( $this->active( self::LIFE ), 'agenda', static function ( array $f ): array {
            $f['sub_fields'][] = self::field( 'note', 'text' );
            return $f;
        } ), 'venue', static fn( array $f ): array => $f ) ] );
        $this->assertTrue( self::by_name( $saved['fields'] )['price']['retired'] ?? false, 'a field not sent stays retired' );
        $this->assertArrayNotHasKey( 'retired', self::by_name( self::by_name( $saved['fields'] )['agenda']['sub_fields'] )['note'], 'the child sent again is active' );

        $fields = $this->active( self::LIFE );
        array_splice( $fields, 2, 0, [ self::field( 'price', 'number', [ 'format' => 'decimal', 'min' => 0 ] ) ] );
        $saved = $this->save( self::LIFE, [ 'fields' => $fields ] );
        $this->assertArrayNotHasKey( 'retired', self::by_name( $saved['fields'] )['price'] );
        $this->assertSame( 'fab_price', self::by_name( $saved['fields'] )['price']['key'] );

        $values = $this->values( $id );
        $this->assertSame( 49.9, $values['values']['price'], 'the old value reads again' );
        $this->assertTrue( $values['stored']['price'] );
        $this->assertSame( [ self::$life['rows'][1], self::$life['rows'][0] ], array_column( $values['values']['agenda'], '_row' ), 'the rows kept their identity' );
        $this->assertSame( [ 'n2', 'n1' ], array_column( $values['values']['agenda'], 'note' ), 'each note with its row' );
        $this->assertSame( [ 49.9, 'n2' ], $this->site_runs( "\$f = fabrement()->fields( $id ); return [ \$f->get_number( 'price' ), \$f->get_rows( 'agenda' )[0]->get_text( 'note' ) ];" ) );
        $this->assertContains( 'price', self::box_names( $this->box( $id )['fields'] ) );
        $this->assertContains( 'agenda.note', self::box_names( $this->box( $id )['fields'] ) );
        $this->set( $id, [ 'price' => 55.5 ] );
        $this->assertSame( [ '55.5' ], $this->site()->meta( $id, 'fab_price' ), 'and takes values again' );
    }

    public function test_7_a_new_name_is_a_new_empty_field_and_invalid_definitions_are_refused(): void {
        $id = self::$life['id'];
        // "Rename" headline: the agent sends a new name. The old one is retired with its value.
        $saved = $this->save( self::LIFE, [ 'fields' => self::edit( $this->active( self::LIFE ), 'headline', static fn( array $f ): array => [ 'name' => 'title_line' ] + array_diff_key( $f, [ 'key' => 1 ] ) ) ] );
        $by_name = self::by_name( $saved['fields'] );
        $this->assertTrue( $by_name['headline']['retired'] ?? false );
        $this->assertSame( 'fab_title_line', $by_name['title_line']['key'] );
        $values = $this->values( $id );
        $this->assertNull( $values['values']['title_line'], 'the new name holds nothing' );
        $this->assertFalse( $values['stored']['title_line'] );
        $this->assertSame( [ 'Opening night' ], $this->site()->meta( $id, 'fab_headline' ), 'the old value stays under the old key' );
        $this->assertSame( [], $this->site()->meta( $id, 'fab_title_line' ) );
        // The new field is required (it kept headline's settings): the record must fill it before another save.
        $this->assertStringContainsString( 'title_line', $this->refused_values( $id, [ 'price' => 56 ] ) );
        $this->set( $id, [ 'title_line' => 'Opening night' ] );

        // Bring headline back: back with its value; the two are two fields.
        $restored = $this->save( self::LIFE, [ 'fields' => array_merge( [ self::field( 'headline', 'text' ) ], $this->active( self::LIFE ) ) ] );
        $this->assertArrayNotHasKey( 'retired', self::by_name( $restored['fields'] )['headline'] );
        $this->assertSame( 'Opening night', $this->values( $id )['values']['headline'] );

        $active = $this->active( self::LIFE );
        $refusals = [
            'a key that is not the name\'s'      => [ self::edit( $active, 'headline', static fn( array $f ): array => [ 'key' => 'fab_other' ] + $f ), 'fields[0].key', 'key follows from the name' ],
            'a child key with the prefix'        => [ self::edit( $active, 'venue.city', static fn( array $f ): array => [ 'key' => 'fab_city' ] + $f ), 'fields[', 'key follows from the name' ],
            'two top-level fields with one name' => [ array_merge( $active, [ self::field( 'headline', 'text' ) ] ), 'fields[' . count( $active ) . '].name', 'already called "headline"' ],
            'two children with one name'         => [ self::edit( $active, 'venue', static function ( array $f ): array {
                $f['sub_fields'][] = self::field( 'city', 'text' );
                return $f;
            } ), 'fields[', 'already called "city"' ],
            'an unknown type'                    => [ array_merge( $active, [ self::field( 'colour', 'color' ) ] ), 'fields[' . count( $active ) . '].type', 'Unsupported field type' ],
            '"retired" sent'                     => [ self::edit( $active, 'headline', static fn( array $f ): array => [ 'retired' => true ] + $f ), 'fields[0].retired', 'leave a retired field out' ],
        ];
        foreach ( $refusals as $case => [ $fields, $at, $said ] ) {
            $error = $this->refused_save( self::LIFE, [ 'fields' => $fields ] );
            $this->assertStringStartsWith( $at, $error, $case );
            $this->assertStringContainsString( $said, $error, $case );
        }
    }

    public function test_8_a_refused_save_in_the_middle_leaves_the_previous_definition_whole(): void {
        $id       = self::$life['id'];
        $before   = $this->read( self::LIFE );
        $file     = $this->file( self::LIFE );
        $meta     = $this->meta_rows( $id );
        // Many good changes and one mistake at the very end: none of them is kept.
        $fields   = self::edit( $this->active( self::LIFE ), 'headline', static fn( array $f ): array => [ 'label' => 'Changed in a refused save' ] + $f );
        $fields   = self::edit( $fields, 'price', static fn(): ?array => null );
        $fields[] = self::field( 'fresh', 'text' );
        $fields[] = self::field( 'broken', 'number', [ 'format' => 'integer', 'default' => 1.5 ] );
        $error    = $this->refused_save( self::LIFE, [ 'fields' => $fields, 'layout' => [ 'tabs' => [ [ 'label' => 'Only', 'items' => [ [ 'field' => 'fresh' ] ] ] ] ] ] );
        $this->assertStringStartsWith( 'fields[' . ( count( $fields ) - 1 ) . '].default', $error );
        // A good definition with a bad layout: refused whole as well.
        $this->refused_save( self::LIFE, [ 'fields' => array_slice( $fields, 0, -1 ), 'layout' => [ 'tabs' => [ [ 'label' => 'Only', 'items' => [ [ 'field' => 'nothing' ] ] ] ] ] ] );
        // A stale revision: refused, and it says the revision to read.
        $stale = $this->refused_save( self::LIFE, [ 'fields' => $this->active( self::LIFE ) ], (int) $before['revision'] - 1 );
        $this->assertStringContainsString( 'now revision ' . $before['revision'], $stale );

        $this->assertSame( $before, $this->read( self::LIFE ) );
        $this->assertSame( $file, $this->file( self::LIFE ) );
        $this->assertSame( $meta, $this->meta_rows( $id ) );
        $this->assertArrayHasKey( 'price', $this->values( $id )['values'], 'still active' );
        $this->assertSame( 55.5, $this->site_runs( "return fabrement()->fields( $id )->get_number( 'price' );" ) );
        // The same revision works for the next good save.
        $this->assertSame( $before['revision'] + 1, $this->save( self::LIFE, [ 'fields' => $this->active( self::LIFE ) ] )['revision'] );
    }

    // ── every field type, at the top, in a group and in a repeater ───────────────────────────

    private function every_type(): array {
        return [
            self::field( 't_text', 'text', [ 'default' => 'Hello' ] ),
            self::field( 't_area', 'textarea' ),
            self::field( 't_rich', 'wysiwyg' ),
            self::field( 't_int', 'number', [ 'format' => 'integer', 'min' => -5, 'max' => 5, 'default' => 3 ] ),
            self::field( 't_dec', 'number', [ 'format' => 'decimal', 'min' => 0.5, 'max' => 99.5 ] ),
            self::field( 't_flag', 'true_false', [ 'default' => true ] ),
            self::field( 't_pick', 'select', [ 'choices' => self::choices( [ 'a', 'b' ] ), 'default' => 'b' ] ),
            self::field( 't_day', 'date_picker', [ 'default' => '2026-01-31' ] ),
            self::field( 't_when', 'date_time_picker' ),
            self::field( 't_img', 'image' ),
            self::field( 't_gal', 'gallery', [ 'min' => 1, 'max' => 3 ] ),
            self::field( 't_file', 'file' ),
            self::field( 't_link', 'link' ),
            self::field( 't_post', 'post_object', [ 'postTypes' => [ 'page' ] ] ),
            self::field( 't_rel', 'relationship', [ 'postTypes' => [ 'page' ], 'min' => 0, 'max' => 2 ] ),
        ];
    }

    private function every_value( int $image, int $file, array $pages ): array {
        return [
            't_text' => 'Words', 't_area' => "Two\nlines", 't_rich' => '<p>Rich <strong>text</strong></p>', 't_int' => -2, 't_dec' => 12.25,
            't_flag' => false, 't_pick' => 'a', 't_day' => '2026-10-09', 't_when' => '2026-10-09T18:30',
            't_img'  => $image, 't_gal' => [ $image ], 't_file' => $file, 't_link' => [ 'url' => 'https://example.com/a', 'label' => 'A' ],
            't_post' => $pages[0], 't_rel' => [ $pages[1], $pages[0] ],
        ];
    }

    // ── defaults and required ────────────────────────────────────────────────────────────────

    public function test_a_default_is_read_until_a_value_is_stored_and_changing_it_changes_only_unfilled_records(): void {
        $this->post_type( 'qa_dl_def' );
        $this->save( 'qa_dl_def', [ 'fields' => [ self::field( 'seats', 'number', [ 'format' => 'integer', 'default' => 5 ] ), self::field( 'note', 'text' ) ] ] );
        $unfilled = $this->record( 'qa_dl_def', 'QA unfilled' );
        $filled   = $this->record( 'qa_dl_def', 'QA filled' );
        $cleared  = $this->record( 'qa_dl_def', 'QA cleared' );
        $this->set( $filled, [ 'seats' => 5 ] );
        $this->set( $cleared, [ 'seats' => null ] );
        $this->set( $unfilled, [ 'note' => 'only a note' ] );
        $this->assertSame( [], $this->site()->meta( $unfilled, 'fab_seats' ), 'a default is never written' );
        $this->assertSame( [ '5' ], $this->site()->meta( $filled, 'fab_seats' ), 'a value equal to the default, set explicitly, is stored' );
        $this->assertSame( [ null ], $this->site()->meta( $cleared, 'fab_seats' ) );

        $this->save( 'qa_dl_def', [ 'fields' => [ self::field( 'seats', 'number', [ 'format' => 'integer', 'default' => 8 ] ), self::field( 'note', 'text' ) ] ] );
        $this->assertSame( [ 8, 5, null ], [ $this->values( $unfilled )['values']['seats'], $this->values( $filled )['values']['seats'], $this->values( $cleared )['values']['seats'] ] );
        $this->assertSame( [ 8, 5, null ], $this->site_runs( "return [ fabrement()->fields( $unfilled )->get_number( 'seats' ), fabrement()->fields( $filled )->get_number( 'seats' ), fabrement()->fields( $cleared )->get_number( 'seats' ) ];" ) );

        // The default dropped: an unfilled record reads empty.
        $this->save( 'qa_dl_def', [ 'fields' => [ self::field( 'seats', 'number', [ 'format' => 'integer' ] ), self::field( 'note', 'text' ) ] ] );
        $this->assertArrayNotHasKey( 'default', self::by_name( $this->read( 'qa_dl_def' )['fields'] )['seats'] );
        $this->assertNull( $this->values( $unfilled )['values']['seats'] );
        $this->assertSame( 5, $this->values( $filled )['values']['seats'] );
    }

    public function test_a_required_field_holds_every_save_of_a_record_until_it_is_filled(): void {
        $this->post_type( 'qa_dl_req' );
        $this->save( 'qa_dl_req', [ 'fields' => [ self::field( 'client', 'text', [ 'required' => true ] ), self::field( 'note', 'text' ) ] ] );
        $id = $this->record( 'qa_dl_req', 'QA required' );
        $this->assertStringContainsString( 'client', $this->refused_values( $id, [ 'note' => 'x' ] ) );
        $this->set( $id, [ 'client' => 'ACME', 'note' => 'x' ] );
        $this->assertStringContainsString( 'client', $this->refused_values( $id, [ 'client' => '' ] ), 'nor emptied' );

        // A field made required later: a record that lacks it saves nothing else until it is filled.
        $this->save( 'qa_dl_req', [ 'fields' => [ self::field( 'client', 'text', [ 'required' => true ] ), self::field( 'note', 'text', [ 'required' => true ] ), self::field( 'extra', 'text' ) ] ] );
        $other = $this->record( 'qa_dl_req', 'QA required later' );
        $this->assertStringContainsString( 'note', $this->refused_values( $other, [ 'client' => 'Beta' ] ) );
        $this->set( $other, [ 'client' => 'Beta', 'note' => 'y' ] );
        $this->set( $id, [ 'extra' => 'fine' ] );
        // No longer required: the record saves without it.
        $this->save( 'qa_dl_req', [ 'fields' => [ self::field( 'client', 'text' ), self::field( 'note', 'text' ), self::field( 'extra', 'text' ) ] ] );
        $this->set( $id, [ 'client' => null ] );
        $this->assertNull( $this->values( $id )['values']['client'] );
    }

    // ── every rule of a definition ───────────────────────────────────────────────────────────

    public function test_names_follow_the_rules_of_a_meta_key_and_every_refusal_names_the_field(): void {
        $this->post_type( 'qa_dl_names' );
        $base = [ self::field( 'base', 'text' ) ];
        $this->save( 'qa_dl_names', [ 'fields' => $base ] );
        foreach ( [
            'Title', '1st', 'step_2', 'step__two', 'trailing_', '_leading', 'fab_price', 'with-dash', 'with space', 'ünï', '', 'abcdefghijklmnopqrstuvwxy', 'a_1',
        ] as $name ) {
            $error = $this->refused_save( 'qa_dl_names', [ 'fields' => array_merge( $base, [ self::field( 'x', 'text', [ 'name' => $name ] ) ] ) ] );
            $this->assertStringStartsWith( 'fields[1].name:', $error, '"' . $name . '"' );
        }
        $error = $this->refused_save( 'qa_dl_names', [ 'fields' => array_merge( $base, [ [ 'name' => 7 ] + self::field( 'x', 'text' ) ] ) ] );
        $this->assertStringStartsWith( 'fields[1].name:', $error, 'a number' );
        $error = $this->refused_save( 'qa_dl_names', [ 'fields' => [ self::group( 'outer', [ self::field( 'Bad', 'text' ) ] ) ] ] );
        $this->assertStringStartsWith( 'fields[0].sub_fields[0].name:', $error, 'a child\'s name too' );

        // At the edges: 24 characters, digits inside a part.
        $saved = $this->save( 'qa_dl_names', [ 'fields' => array_merge( $base, [ self::field( 'abcdefghijklmnopqrstuvwx', 'text' ), self::field( 'step2', 'text' ), self::field( 'a1_b2_c3', 'text' ) ] ) ] );
        $this->assertSame( [ 'base', 'abcdefghijklmnopqrstuvwx', 'step2', 'a1_b2_c3' ], array_column( $saved['fields'], 'name' ) );
        $id = $this->record( 'qa_dl_names', 'QA names' );
        $this->set( $id, [ 'abcdefghijklmnopqrstuvwx' => 'long', 'a1_b2_c3' => 'mixed' ] );
        $this->assertSame( [ 'long' ], $this->site()->meta( $id, 'fab_abcdefghijklmnopqrstuvwx' ) );
        $this->assertSame( 'mixed', $this->site_runs( "return fabrement()->fields( $id )->get_text( 'a1_b2_c3' );" ) );
    }

    public function test_a_name_next_to_a_group_may_not_reach_into_its_keys_retired_groups_too(): void {
        $this->post_type( 'qa_dl_clash' );
        $error = $this->refused_save( 'qa_dl_clash', [ 'fields' => [ self::group( 'place', [ self::field( 'city', 'text' ) ] ), self::field( 'place_city', 'text' ) ] ] );
        $this->assertStringContainsString( 'would be stored inside the group "place"', $error );
        $this->assertNull( $this->file( 'qa_dl_clash' ), 'no file for a refused first save' );
        $this->refused_save( 'qa_dl_clash', [ 'fields' => [ self::field( 'place_note', 'text' ), self::group( 'place', [ self::field( 'zip', 'text' ) ] ) ] ] );
        $error = $this->refused_save( 'qa_dl_clash', [ 'fields' => [ self::repeater( 'list', [ self::group( 'cell', [ self::field( 'a', 'text' ) ] ), self::field( 'cell_b', 'text' ) ] ) ] ] );
        $this->assertStringStartsWith( 'fields[0].sub_fields', $error, 'inside a repeater\'s rows as well' );

        // A repeater does not reach into its siblings: its row keys carry a digit-only part.
        $this->save( 'qa_dl_clash', [ 'fields' => [ self::repeater( 'items', [ self::field( 'title', 'text' ) ] ), self::field( 'items_title', 'text' ), self::group( 'spot', [ self::field( 'x', 'text' ) ] ) ] ] );
        // A retired group still holds its keys.
        $this->save( 'qa_dl_clash', [ 'fields' => [ self::repeater( 'items', [ self::field( 'title', 'text' ) ] ), self::field( 'items_title', 'text' ) ] ] );
        $this->assertTrue( self::by_name( $this->read( 'qa_dl_clash' )['fields'] )['spot']['retired'] ?? false );
        $this->assertStringContainsString( 'a retired one counts too', $this->refused_save( 'qa_dl_clash', [ 'fields' => [ self::repeater( 'items', [ self::field( 'title', 'text' ) ] ), self::field( 'items_title', 'text' ), self::field( 'spot_x', 'text' ) ] ] ) );
    }

    public function test_labels_descriptions_and_properties_are_held_to_their_shape(): void {
        $this->post_type( 'qa_dl_props' );
        $base  = [ self::field( 'base', 'text' ) ];
        $this->save( 'qa_dl_props', [ 'fields' => $base ] );
        $refuse = function ( array $field, string $at, string $said = '' ) use ( $base ): void {
            $error = $this->refused_save( 'qa_dl_props', [ 'fields' => array_merge( $base, [ $field ] ) ] );
            $this->assertStringStartsWith( $at, $error, json_encode( $field ) );
            if ( $said !== '' ) {
                $this->assertStringContainsString( $said, $error );
            }
        };
        $text = static fn( array $over ): array => array_merge( self::field( 'probe', 'text' ), $over );

        // label and description: required text, at most 200 / 2000 bytes (Latin texts: one byte a letter).
        $refuse( $text( [ 'label' => '' ] ), 'fields[1].label' );
        $refuse( $text( [ 'label' => '   ' ] ), 'fields[1].label' );
        $refuse( $text( [ 'label' => str_repeat( 'a', 201 ) ] ), 'fields[1].label' );
        $refuse( $text( [ 'label' => 5 ] ), 'fields[1].label' );
        // label, description, name and type are required by the tool's input schema: refused before the validator.
        $this->assertStringContainsString( 'label is a required property of input[fields][1]', $this->refused_save( 'qa_dl_props', [ 'fields' => array_merge( $base, [ array_diff_key( $text( [] ), [ 'label' => 1 ] ) ] ) ] ) );
        $this->assertStringContainsString( 'description is a required property of input[fields][1]', $this->refused_save( 'qa_dl_props', [ 'fields' => array_merge( $base, [ array_diff_key( $text( [] ), [ 'description' => 1 ] ) ] ) ] ) );
        $refuse( $text( [ 'description' => str_repeat( 'a', 2001 ) ] ), 'fields[1].description' );
        $this->assertStringContainsString( 'type is a required property of input[fields][1]', $this->refused_save( 'qa_dl_props', [ 'fields' => array_merge( $base, [ array_diff_key( $text( [] ), [ 'type' => 1 ] ) ] ) ] ) );
        $refuse( $text( [ 'placeholder' => 'x' ] ), 'fields[1].placeholder', 'Unknown property' );
        $this->assertStringContainsString( 'input[fields][1] is not of type object', $this->refused_save( 'qa_dl_props', [ 'fields' => array_merge( $base, [ 'not a field' ] ) ] ) );

        // translation, required
        $refuse( $text( [ 'translation' => 'auto' ] ), 'fields[1].translation' );
        $refuse( self::field( 'probe', 'number', [ 'translation' => 'translate' ] ), 'fields[1].translation', 'no words to translate' );
        $refuse( self::group( 'probe', [ self::field( 'a', 'text' ) ], [ 'translation' => 'translate' ] ), 'fields[1].translation', 'no words of its own' );
        $refuse( $text( [ 'required' => 'yes' ] ), 'fields[1].required' );
        $refuse( self::field( 'probe', 'true_false', [ 'required' => true ] ), 'fields[1].required', 'can be empty' );
        $refuse( self::group( 'probe', [ self::field( 'a', 'text' ) ], [ 'required' => true ] ), 'fields[1].required' );
        $refuse( self::repeater( 'probe', [ self::field( 'a', 'text' ) ], [ 'required' => true ] ), 'fields[1].required' );

        // format, min/max
        $refuse( $text( [ 'format' => 'integer' ] ), 'fields[1].format', 'Only number fields' );
        $refuse( self::field( 'probe', 'number', [ 'format' => 'float' ] ), 'fields[1].format' );
        $refuse( $text( [ 'min' => 1 ] ), 'fields[1].min' );
        $refuse( self::field( 'probe', 'number', [ 'min' => 'one' ] ), 'fields[1].min' );
        $refuse( self::field( 'probe', 'number', [ 'min' => 5, 'max' => 1 ] ), 'fields[1]', 'min is greater than max' );
        $refuse( self::field( 'probe', 'number', [ 'max' => 9007199254740992 ] ), 'fields[1].max' );
        $refuse( self::repeater( 'probe', [ self::field( 'a', 'text' ) ], [ 'min' => -1 ] ), 'fields[1].min' );
        $refuse( self::repeater( 'probe', [ self::field( 'a', 'text' ) ], [ 'max' => 501 ] ), 'fields[1].max' );
        $refuse( self::repeater( 'probe', [ self::field( 'a', 'text' ) ], [ 'min' => 3, 'max' => 2 ] ), 'fields[1]', 'min is greater than max' );
        $refuse( self::field( 'probe', 'gallery', [ 'max' => 51 ] ), 'fields[1].max' );
        $refuse( self::field( 'probe', 'relationship', [ 'max' => 1.5 ] ), 'fields[1].max' );
        $refuse( self::field( 'probe', 'image', [ 'max' => 1 ] ), 'fields[1].max' );

        // choices
        $refuse( self::field( 'probe', 'select' ), 'fields[1].choices' );
        $refuse( self::field( 'probe', 'select', [ 'choices' => [] ] ), 'fields[1].choices' );
        $refuse( self::field( 'probe', 'select', [ 'choices' => self::choices( array_map( static fn( int $i ): string => 'c' . $i, range( 1, 201 ) ) ) ] ), 'fields[1].choices' );
        $refuse( self::field( 'probe', 'select', [ 'choices' => self::choices( [ 'a', 'a' ] ) ] ), 'fields[1].choices[1].value', 'Duplicate' );
        $refuse( self::field( 'probe', 'select', [ 'choices' => [ [ 'value' => 'a' ] ] ] ), 'fields[1].choices[0].label' );
        $refuse( self::field( 'probe', 'select', [ 'choices' => [ [ 'value' => 'a', 'label' => 'A', 'color' => 'red' ] ] ] ), 'fields[1].choices[0]' );
        $refuse( self::field( 'probe', 'select', [ 'choices' => [ [ 'value' => '', 'label' => 'A' ] ] ] ), 'fields[1].choices[0].value' );
        $refuse( $text( [ 'choices' => self::choices( [ 'a' ] ) ] ), 'fields[1].choices', 'Only select fields' );

        // postTypes
        $refuse( $text( [ 'postTypes' => [ 'page' ] ] ), 'fields[1].postTypes' );
        $refuse( self::field( 'probe', 'image', [ 'postTypes' => [ 'page' ] ] ), 'fields[1].postTypes' );
        $refuse( self::field( 'probe', 'post_object', [ 'postTypes' => [] ] ), 'fields[1].postTypes' );
        $refuse( self::field( 'probe', 'post_object', [ 'postTypes' => [ 'page', 'page' ] ] ), 'fields[1].postTypes' );
        $refuse( self::field( 'probe', 'post_object', [ 'postTypes' => [ 'qa_no_such_type' ] ] ), 'fields[1].postTypes[0]', 'Not a public post type' );
        $refuse( self::field( 'probe', 'relationship', [ 'postTypes' => [ 'attachment' ] ] ), 'fields[1].postTypes[0]' );

        // sub_fields
        $refuse( self::field( 'probe', 'group' ), 'fields[1].sub_fields' );
        $refuse( self::group( 'probe', [] ), 'fields[1].sub_fields' );
        $refuse( self::repeater( 'probe', [] ), 'fields[1].sub_fields' );
        $refuse( $text( [ 'sub_fields' => [ self::field( 'a', 'text' ) ] ] ), 'fields[1].sub_fields', 'Only group and repeater' );
        $refuse( self::group( 'probe', [ self::field( 'a', 'text', [ 'translation' => 'nope' ] ) ] ), 'fields[1].sub_fields[0].translation' );

        // default
        $refuse( self::field( 'probe', 'number', [ 'format' => 'integer', 'default' => 1.5 ] ), 'fields[1].default' );
        $refuse( self::field( 'probe', 'number', [ 'max' => 3, 'default' => 4 ] ), 'fields[1].default' );
        $refuse( self::field( 'probe', 'number', [ 'default' => '4' ] ), 'fields[1].default' );
        $refuse( self::field( 'probe', 'select', [ 'choices' => self::choices( [ 'a' ] ), 'default' => 'b' ] ), 'fields[1].default' );
        $refuse( self::field( 'probe', 'date_picker', [ 'default' => '2026-13-01' ] ), 'fields[1].default' );
        $refuse( self::field( 'probe', 'date_time_picker', [ 'default' => '2026-01-01 10:00' ] ), 'fields[1].default' );
        $refuse( self::field( 'probe', 'true_false', [ 'default' => 'yes' ] ), 'fields[1].default' );
        $refuse( $text( [ 'default' => str_repeat( 'a', 10001 ) ] ), 'fields[1].default' );
        $refuse( self::field( 'probe', 'image', [ 'default' => 1 ] ), 'fields[1].default', 'takes no default' );
        $refuse( self::field( 'probe', 'post_object', [ 'default' => 1 ] ), 'fields[1].default' );
        $refuse( self::group( 'probe', [ self::field( 'a', 'text' ) ], [ 'default' => [] ] ), 'fields[1].default' );

        // At the edges, accepted: a 200-byte label, a 2000-byte description, 200 choices, a bound equal on both ends.
        $edge = $this->save( 'qa_dl_props', [ 'fields' => array_merge( $base, [
            $text( [ 'label' => str_repeat( 'a', 200 ), 'description' => str_repeat( 'd', 2000 ) ] ),
            self::field( 'many', 'select', [ 'choices' => self::choices( array_map( static fn( int $i ): string => 'c' . $i, range( 1, 200 ) ) ) ] ),
            self::field( 'exact', 'number', [ 'min' => 2, 'max' => 2, 'default' => 2 ] ),
            self::field( 'blank_day', 'date_picker', [ 'default' => '' ] ),
        ] ) ] );
        $this->assertCount( 200, self::by_name( $edge['fields'] )['many']['choices'] );
        $this->assertSame( 'copy', self::by_name( $edge['fields'] )['many']['translation'] );
    }

    public function test_a_list_of_fields_is_held_to_its_size_and_depth(): void {
        $this->post_type( 'qa_dl_size' );
        $this->assertStringContainsString( 'input[fields] is not of type array', $this->refused_save( 'qa_dl_size', [ 'fields' => [ 'name' => 'a' ] ] ), 'not a list' );
        $many  = array_map( static fn( int $i ): array => self::field( 'f' . self::letters( $i ), 'text' ), range( 1, 201 ) );
        $error = $this->refused_save( 'qa_dl_size', [ 'fields' => $many ] );
        $this->assertStringStartsWith( 'fields[200]', $error );
        $this->assertStringContainsString( 'At most 200 fields', $error );
        // Sub-fields count toward the 200.
        $this->assertStringContainsString( 'At most 200', $this->refused_save( 'qa_dl_size', [ 'fields' => [ self::group( 'big', array_slice( $many, 0, 200 ) ) ] ] ) );
        $saved = $this->save( 'qa_dl_size', [ 'fields' => array_slice( $many, 0, 200 ) ] );
        $this->assertCount( 200, $saved['fields'] );

        // Nine levels nest; a tenth does not.
        $nest = static function ( int $levels ): array {
            $field = self::field( 'leaf', 'text' );
            for ( $i = $levels - 1; $i >= 1; $i-- ) {
                $field = self::group( 'g' . self::letters( $i ), [ $field ] );
            }
            return $field;
        };
        $error = $this->refused_save( 'qa_dl_size', [ 'fields' => array_merge( array_slice( $many, 0, 10 ), [ $nest( 10 ) ] ) ] );
        $this->assertStringContainsString( 'at most 8 levels deep', $error );
        $deep = $this->save( 'qa_dl_size', [ 'fields' => array_merge( array_slice( $many, 0, 10 ), [ $nest( 9 ) ] ) ] );
        $id   = $this->record( 'qa_dl_size', 'QA deep' );
        $path = 'fab_' . implode( '_', array_map( static fn( int $i ): string => 'g' . self::letters( $i ), range( 1, 8 ) ) ) . '_leaf';
        $value = [ 'leaf' => 'bottom' ];
        for ( $i = 8; $i >= 2; $i-- ) {
            $value = [ 'g' . self::letters( $i ) => $value ];
        }
        $this->set( $id, [ 'g' . self::letters( 1 ) => $value ] );
        $this->assertSame( [ 'bottom' ], $this->site()->meta( $id, $path ) );
        $this->assertSame( 'g' . self::letters( 1 ), $deep['fields'][10]['name'] );

        // Repeaters in groups in repeaters: each row path is its own key.
        $this->save( 'qa_dl_size', [ 'fields' => [ self::repeater( 'days', [ self::group( 'slot', [ self::repeater( 'talks', [ self::field( 'who', 'text' ) ] ) ] ) ] ) ] ] );
        $this->set( $id, [ 'days' => [ [ 'slot' => [ 'talks' => [ [ 'who' => 'Ann' ], [ 'who' => 'Bob' ] ] ] ] ] ] );
        $this->assertSame( [ 'Bob' ], $this->site()->meta( $id, 'fab_days_0_slot_talks_1_who' ) );
        $this->assertSame( [ 'Ann', 'Bob' ], $this->site_runs( "return array_map( static fn( \$r ) => \$r->get_text( 'who' ), fabrement()->fields( $id )->get_rows( 'days' )[0]->get_group( 'slot' )->get_rows( 'talks' ) );" ) );
    }

    /** a, b, … z, ba, bb …: digit-free name parts. */
    private static function letters( int $n ): string {
        $out = '';
        do {
            $out = chr( 97 + $n % 26 ) . $out;
            $n   = intdiv( $n, 26 );
        } while ( $n > 0 );
        return $out;
    }

    public function test_the_layout_points_only_at_active_top_level_fields(): void {
        $this->post_type( 'qa_dl_layout' );
        $fields = [ self::field( 'a', 'text' ), self::field( 'b', 'number' ), self::repeater( 'r', [ self::field( 'c', 'text' ) ] ), self::field( 'gone', 'text' ) ];
        $this->save( 'qa_dl_layout', [ 'fields' => $fields ] );
        $this->save( 'qa_dl_layout', [ 'fields' => array_slice( $fields, 0, 3 ) ] );
        $tab = static fn( array $items, string $label = 'Tab' ): array => [ 'tabs' => [ [ 'label' => $label, 'items' => $items ] ] ];
        foreach ( [
            'an unknown field'      => [ $tab( [ [ 'field' => 'nope' ] ] ), 'layout.tabs[0].items[0].field' ],
            'a retired field'       => [ $tab( [ [ 'field' => 'gone' ] ] ), 'layout.tabs[0].items[0].field' ],
            'a sub-field'           => [ $tab( [ [ 'field' => 'c' ] ] ), 'layout.tabs[0].items[0].field' ],
            'placed twice'          => [ $tab( [ [ 'field' => 'a' ], [ 'field' => 'fab_a' ] ] ), 'layout.tabs[0].items[1].field' ],
            'a width not offered'   => [ $tab( [ [ 'field' => 'a', 'width' => '1/5' ] ] ), 'layout.tabs[0].items[0].width' ],
            'display on a text'     => [ $tab( [ [ 'field' => 'a', 'display' => 'table' ] ] ), 'layout.tabs[0].items[0].display' ],
            'a display not offered' => [ $tab( [ [ 'field' => 'r', 'display' => 'grid' ] ] ), 'layout.tabs[0].items[0].display' ],
            'an item property'      => [ $tab( [ [ 'field' => 'a', 'hidden' => true ] ] ), 'layout.tabs[0].items[0]' ],
            'an empty tab label'    => [ $tab( [ [ 'field' => 'a' ] ], ' ' ), 'layout.tabs[0].label' ],
            'a long tab label'      => [ $tab( [ [ 'field' => 'a' ] ], str_repeat( 'x', 101 ) ), 'layout.tabs[0].label' ],
            'a tab property'        => [ [ 'tabs' => [ [ 'label' => 'T', 'items' => [], 'icon' => 'x' ] ] ], 'layout.tabs[0]' ],
            '21 tabs'               => [ [ 'tabs' => array_fill( 0, 21, [ 'label' => 'T', 'items' => [] ] ) ], 'layout.tabs' ],
            'a layout property'     => [ [ 'tabs' => [], 'sections' => [] ], 'layout' ],
            'items not a list'      => [ [ 'tabs' => [ [ 'label' => 'T', 'items' => 'a' ] ] ], 'layout.tabs[0].items' ],
        ] as $case => [ $layout, $at ] ) {
            $this->assertStringStartsWith( $at, $this->refused_save( 'qa_dl_layout', [ 'layout' => $layout ] ), $case );
        }
        $this->assertStringStartsWith( 'fields', $this->refused_save( 'qa_dl_layout', [] ), 'neither fields nor layout' );

        // By name or by key; fields not placed are shown after the tabs.
        $saved = $this->save( 'qa_dl_layout', [ 'layout' => $tab( [ [ 'field' => 'fab_r', 'display' => 'block', 'width' => '1' ], [ 'field' => 'a', 'width' => '1/2' ] ] ) ] );
        $this->assertSame( [ [ 'field' => 'fab_r', 'width' => '1', 'display' => 'block' ], [ 'field' => 'fab_a', 'width' => '1/2' ] ], $saved['layout']['tabs'][0]['items'] );
        $id  = $this->record( 'qa_dl_layout', 'QA layout' );
        $box = $this->box( $id );
        $this->assertSame( [ 'a', 'b', 'r', 'r.c' ], self::box_names( $box['fields'] ), 'b is in the box though no tab places it' );

        // A field retired while placed: the stored layout drops it.
        $this->save( 'qa_dl_layout', [ 'fields' => [ self::field( 'b', 'number' ), self::repeater( 'r', [ self::field( 'c', 'text' ) ] ) ] ] );
        $this->assertSame( [ [ 'field' => 'fab_r', 'width' => '1', 'display' => 'block' ] ], $this->read( 'qa_dl_layout' )['layout']['tabs'][0]['items'] );
        $this->assertSame( [ 'b', 'r', 'r.c' ], self::box_names( $this->box( $id )['fields'] ) );
    }

    public function test_the_owner_and_the_revision_are_checked_first(): void {
        foreach ( [ 'qa_no_such_type', 'attachment', 'wp_block' ] as $slug ) {
            $this->assertStringContainsString( 'No editable post', html_entity_decode( $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => $slug, 'expectedRevision' => 0, 'fields' => [ self::field( 'a', 'text' ) ] ] ), ENT_QUOTES ), $slug );
            $this->assertNull( $this->file( $slug ) );
        }
        $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => 'Bad Slug', 'expectedRevision' => 0, 'fields' => [ self::field( 'a', 'text' ) ] ] );
        $this->mcp_error( 'save-fields', [ 'type' => 'options', 'slug' => 'qa-dl', 'expectedRevision' => 0, 'fields' => [ self::field( 'a', 'text' ) ] ] );
        $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => 'post', 'fields' => [ self::field( 'a', 'text' ) ] ] );
        $this->assertNull( $this->file( 'post' ), 'without expectedRevision nothing is written' );
        $this->assertStringContainsString( 'now revision 0', html_entity_decode( $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => 'post', 'expectedRevision' => 3, 'fields' => [ self::field( 'a', 'text' ) ] ] ), ENT_QUOTES ) );
        $this->assertNull( $this->file( 'post' ) );
        $this->assertSame( [ 0, false ], [ $this->read( 'qa_no_such_type' )['revision'], $this->read( 'qa_no_such_type' )['exists'] ], 'get-fields of a type that is not there' );
    }

    // ── saving again, two types, a type deleted, a file edited by hand ──────────────────────

    public function test_the_same_definition_saved_again_changes_no_value_and_no_field(): void {
        $this->post_type( 'qa_dl_same' );
        $first = $this->save( 'qa_dl_same', [ 'fields' => self::life_fields(), 'layout' => self::life_layout() ] );
        $id    = $this->record( 'qa_dl_same', 'QA same' );
        $this->set( $id, [ 'headline' => 'Same', 'venue' => [ 'city' => 'Kyiv', 'floor' => 1 ], 'agenda' => [ [ 'time' => '9', 'topic' => 'T', 'note' => 'N' ] ] ] );
        $meta  = $this->meta_rows( $id );
        $again = $this->save( 'qa_dl_same', [ 'fields' => $this->active( 'qa_dl_same' ), 'layout' => self::life_layout() ] );
        $this->assertSame( $first['fields'], $again['fields'], 'the same definition' );
        $this->assertSame( $first['layout'], $again['layout'] );
        $this->assertSame( $first['revision'] + 1, $again['revision'], 'each save is a revision' );
        $this->assertSame( $meta, $this->meta_rows( $id ), 'no value written or moved' );
        // Sent back exactly as get-fields gave them, keys and all.
        $third = $this->save( 'qa_dl_same', [ 'fields' => $this->read( 'qa_dl_same' )['fields'] ] );
        $this->assertSame( $first['fields'], $third['fields'] );
        $this->assertSame( $meta, $this->meta_rows( $id ) );
    }

    public function test_two_types_keep_their_own_files_revisions_and_values(): void {
        $this->post_type( 'qa_dl_one' );
        $this->post_type( 'qa_dl_two' );
        $this->save( 'qa_dl_one', [ 'fields' => [ self::field( 'shared_note', 'text', [ 'label' => 'Note of one' ] ), self::field( 'only_one', 'number' ) ] ] );
        $this->save( 'qa_dl_two', [ 'fields' => [ self::field( 'shared_note', 'text', [ 'label' => 'Note of two', 'required' => true ] ) ] ] );
        $two  = $this->read( 'qa_dl_two' );
        $file = $this->file( 'qa_dl_two' );
        $this->save( 'qa_dl_one', [ 'fields' => [ self::field( 'shared_note', 'text', [ 'label' => 'Renamed on one' ] ) ] ] );
        $this->assertSame( $two, $this->read( 'qa_dl_two' ), 'the other type\'s fields are untouched' );
        $this->assertSame( $file, $this->file( 'qa_dl_two' ) );
        $this->assertSame( 2, $this->read( 'qa_dl_one' )['revision'] );
        $this->assertSame( 'Note of two', self::by_name( $two['fields'] )['shared_note']['label'], 'the same name, its own label and rules' );

        $a = $this->record( 'qa_dl_one', 'QA one' );
        $b = $this->record( 'qa_dl_two', 'QA two' );
        $this->set( $a, [ 'shared_note' => 'from one' ] );
        $this->set( $b, [ 'shared_note' => 'from two' ] );
        $this->assertSame( [ 'from one', 'from two' ], $this->site_runs( "return [ fabrement()->fields( $a )->get_text( 'shared_note' ), fabrement()->fields( $b )->get_text( 'shared_note' ) ];" ) );
        $this->assertSame( 2, (int) $this->site()->value( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = 'fab_shared_note' AND post_id IN ($a, $b)" ), 'one key on both types' );
        // A field only the other type has is not this record's.
        $this->assertStringContainsString( 'only_one', $this->refused_values( $b, [ 'only_one' => 1 ] ) );
        $this->assertStringContainsString( 'type never changes (text)', $this->refused_save( 'qa_dl_two', [ 'fields' => [ self::field( 'shared_note', 'textarea' ) ] ] ), 'its own field first' );
        $this->post_type( 'qa_dl_three' );
        $this->assertStringContainsString( 'already a text field on another post type', $this->refused_save( 'qa_dl_three', [ 'fields' => [ self::field( 'shared_note', 'textarea' ) ] ] ) );
        // A retired field of one type still holds its key's type for the other.
        $this->assertStringContainsString( 'number', $this->refused_save( 'qa_dl_two', [ 'fields' => [ self::field( 'shared_note', 'text' ), self::field( 'only_one', 'text' ) ] ] ) );
    }

    public function test_a_field_file_edited_by_hand_is_read_as_it_is_and_a_broken_one_switches_the_fields_off(): void {
        $this->post_type( 'qa_dl_hand' );
        $this->save( 'qa_dl_hand', [ 'fields' => [ self::field( 'memo', 'text' ), self::field( 'count', 'number', [ 'format' => 'integer' ] ) ] ] );
        $id = $this->record( 'qa_dl_hand', 'QA hand' );
        $this->set( $id, [ 'memo' => 'kept', 'count' => 3 ] );
        $path = '/var/www/html/wp-content/uploads/fabrement-lite/fields/post.qa_dl_hand.json';
        $good = (string) $this->file( 'qa_dl_hand' );
        $meta = $this->meta_rows( $id );

        // A label changed by hand: read as it is, and the next save builds on it.
        $doc = json_decode( $good, true );
        $doc['fields'][0]['label'] = 'Edited by hand';
        $this->site()->write( $path, (string) json_encode( $doc, JSON_PRETTY_PRINT ) );
        $this->assertSame( 'Edited by hand', self::by_name( $this->read( 'qa_dl_hand' )['fields'] )['memo']['label'] );
        $this->assertSame( 'Edited by hand', self::by_name( $this->box( $id )['fields'] )['memo']['label'] );
        $this->save( 'qa_dl_hand', [ 'layout' => [ 'tabs' => [ [ 'label' => 'All', 'items' => [ [ 'field' => 'memo' ] ] ] ] ] ] );
        $this->assertSame( 'Edited by hand', self::by_name( $this->read( 'qa_dl_hand' )['fields'] )['memo']['label'], 'kept by the next save' );
        $good = (string) $this->file( 'qa_dl_hand' );

        foreach ( [
            'not JSON'                 => '{ "schemaVersion": 1, ',
            'another type\'s file'     => str_replace( '"slug": "qa_dl_hand"', '"slug": "qa_dl_other"', json_encode( json_decode( $good, true ), JSON_PRETTY_PRINT ) ),
            'a name twice on a level'  => (string) json_encode( array_merge( json_decode( $good, true ), [ 'fields' => [ json_decode( $good, true )['fields'][0], json_decode( $good, true )['fields'][0] ] ] ) ),
            'a key the name does not give' => (string) json_encode( array_merge( json_decode( $good, true ), [ 'fields' => [ [ 'key' => 'fab_other' ] + json_decode( $good, true )['fields'][0] ] ] ) ),
            'a layout of the wrong shape' => (string) json_encode( array_merge( json_decode( $good, true ), [ 'layout' => [ 'tabs' => [ [ 'items' => 'x' ] ] ] ] ) ),
        ] as $case => $content ) {
            $this->site()->write( $path, $content );
            $read = $this->read( 'qa_dl_hand' );
            $this->assertArrayHasKey( 'problem', $read, $case . ': get-fields reports the problem' );
            $this->assertStringContainsString( 'post.qa_dl_hand.json', $read['problem'], $case );
            $this->assertSame( [ [], 0 ], [ $read['fields'], $read['revision'] ], $case . ': no fields while it is broken' );
            $error = html_entity_decode( $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => 'qa_dl_hand', 'expectedRevision' => 0, 'fields' => [ self::field( 'memo', 'text' ) ] ] ), ENT_QUOTES );
            $this->assertStringContainsString( 'cannot be changed through MCP', $error, $case . ': not recreated over the user\'s file' );
            $this->assertSame( rtrim( $content ), $this->file( 'qa_dl_hand' ), $case . ': the file is left as the user wrote it' );
            $this->assertStringContainsString( 'no Fabrement fields', $this->mcp_error( 'get-field-values', [ 'postId' => $id ] ), $case );
            $this->assertSame( 404, $this->box( $id )['status'], $case . ': the box has nothing to show' );
            $this->assertSame( $meta, $this->meta_rows( $id ), $case . ': the values are left alone' );
            $this->assertNull( $this->site_runs( "return fabrement()->fields( $id )->get_text( 'memo' );" ), $case );
            $this->take_debug_log();
        }

        // Fixed by the user: everything is back.
        $this->site()->write( $path, $good );
        $this->assertArrayNotHasKey( 'problem', $this->read( 'qa_dl_hand' ) );
        $this->assertSame( [ 'memo' => 'kept', 'count' => 3 ], $this->values( $id )['values'] );
        $this->assertSame( 3, $this->site_runs( "return fabrement()->fields( $id )->get_number( 'count' );" ) );
    }

    // ── an options page through the same life ────────────────────────────────────────────────

    private function page( array $args ): array {
        $read = $this->client()->call( 'get-options-page', [ 'slug' => 'qa-dl-site' ] );
        $revision = $read['error'] ? 0 : (int) ( $read['data']['revision'] ?? 0 );
        return $this->mcp( 'save-options-page', [ 'slug' => 'qa-dl-site', 'expectedRevision' => $revision ] + $args );
    }

    private function page_fields(): array {
        return self::without_retired_fields( $this->mcp( 'get-options-page', [ 'slug' => 'qa-dl-site' ] )['fields'] );
    }

    private function option_row(): array {
        return (array) $this->site()->option( 'fabrement_options_qa-dl-site' );
    }

    public function test_an_options_page_is_defined_changed_retired_and_restored_with_its_values(): void {
        // save-fields is for post types; an options page's fields go through save-options-page.
        $this->mcp_error( 'save-fields', [ 'type' => 'options', 'slug' => 'qa-dl-site', 'expectedRevision' => 0, 'fields' => [ self::field( 'phone', 'text' ) ] ] );

        $fields = [
            self::field( 'phone', 'text' ),
            self::field( 'founded', 'number', [ 'format' => 'integer' ] ),
            self::field( 'mode', 'select', [ 'choices' => self::choices( [ 'calm', 'busy', 'closed' ] ) ] ),
            self::group( 'address', [ self::field( 'street', 'text' ), self::field( 'zip', 'text' ) ] ),
            self::repeater( 'hours', [ self::field( 'day', 'text' ), self::field( 'open', 'text' ) ] ),
        ];
        $layout = [ 'tabs' => [ [ 'label' => 'Contacts', 'items' => [ [ 'field' => 'phone', 'width' => '1/2' ], [ 'field' => 'founded', 'width' => '1/2' ] ] ], [ 'label' => 'Hours', 'items' => [ [ 'field' => 'hours', 'display' => 'table' ] ] ] ] ];
        $saved  = $this->page( [ 'page' => [ 'title' => 'QA site' ], 'fields' => $fields, 'layout' => $layout ] );
        $this->assertSame( self::canon( array_map( static fn( array $f ): array => self::normalized( $f ), $fields ) ), self::canon( $saved['fields'] ) );
        $read = $this->mcp( 'get-options-page', [ 'slug' => 'qa-dl-site' ] );
        $this->assertSame( $saved['fields'], $read['fields'] );
        $this->assertSame( [ 'Contacts', 'Hours' ], array_column( $read['layout']['tabs'], 'label' ) );
        $this->assertSame( '1', $this->site()->php( 'echo (int) is_file( wp_upload_dir()["basedir"] . "/fabrement-lite/fields/options.qa-dl-site.json" );' ) );

        $this->mcp( 'set-field-values', [ 'options' => 'qa-dl-site', 'values' => [ 'phone' => '+380 1', 'founded' => 1999, 'mode' => 'busy', 'address' => [ 'street' => 'Main 1', 'zip' => '79000' ], 'hours' => [ [ 'day' => 'Mon', 'open' => '9-18' ], [ 'day' => 'Tue', 'open' => '9-17' ] ] ] ] );
        $row = $this->option_row();
        $this->assertSame( [ '+380 1', '1999', 'busy', 'Main 1', '2', 'Tue' ], [ $row['fab_phone'], $row['fab_founded'], $row['fab_mode'], $row['fab_address_street'], $row['fab_hours'], $row['fab_hours_1_day'] ] );
        $this->assertSame( [ '+380 1', 1999, 'Busy', 'Main 1', [ 'Mon', 'Tue' ] ], $this->site_runs( "\$o = fabrement()->options( 'qa-dl-site' ); return [ \$o->get_text( 'phone' ), \$o->get_number( 'founded' ), \$o->get_select( 'mode', label: true ), \$o->get_group( 'address' )->get_text( 'street' ), array_map( static fn( \$r ) => \$r->get_text( 'day' ), \$o->get_rows( 'hours' ) ) ];" ) );
        $rows = array_column( $this->mcp( 'get-field-values', [ 'options' => 'qa-dl-site' ] )['values']['hours'], '_row' );

        // Changed: labels, choices, layout — the row is untouched.
        $fields = self::edit( $this->page_fields(), 'phone', static fn( array $f ): array => [ 'label' => 'Main phone' ] + $f );
        $fields = self::edit( $fields, 'mode', static fn( array $f ): array => [ 'choices' => self::choices( [ 'calm', 'busy', 'holiday' ] ) ] + $f );
        $fields = self::edit( $fields, 'address.zip', static fn( array $f ): array => [ 'label' => 'Postcode' ] + $f );
        $this->page( [ 'fields' => $fields, 'layout' => [ 'tabs' => [ [ 'label' => 'All', 'items' => [ [ 'field' => 'hours' ], [ 'field' => 'phone' ] ] ] ] ] ] );
        $read = $this->mcp( 'get-options-page', [ 'slug' => 'qa-dl-site' ] );
        $this->assertSame( 'Main phone', self::by_name( $read['fields'] )['phone']['label'] );
        $this->assertSame( [ 'calm', 'busy', 'holiday' ], array_column( self::by_name( $read['fields'] )['mode']['choices'], 'value' ) );
        $this->assertSame( 'QA site', $read['page']['title'], 'page settings left out stay' );
        $this->assertSame( $row, $this->option_row(), 'no value written' );
        $this->assertStringContainsString( 'founded', html_entity_decode( $this->mcp_error( 'save-options-page', [ 'slug' => 'qa-dl-site', 'expectedRevision' => $read['revision'], 'fields' => self::edit( $fields, 'founded', static fn( array $f ): array => [ 'format' => 'decimal' ] + $f ) ] ), ENT_QUOTES ) . json_encode( $fields[1] ) );
        $this->assertSame( $read, $this->mcp( 'get-options-page', [ 'slug' => 'qa-dl-site' ] ), 'a format change is refused, nothing saved' );

        // Retired: founded and the repeater's "open" — values stay in the row, hidden from every reader.
        $fields = self::edit( $this->page_fields(), 'founded', static fn(): ?array => null );
        $fields = self::edit( $fields, 'hours.open', static fn(): ?array => null );
        $saved  = $this->page( [ 'fields' => $fields ] );
        $this->assertTrue( self::by_name( $saved['fields'] )['founded']['retired'] ?? false );
        $this->assertTrue( self::by_name( self::by_name( $saved['fields'] )['hours']['sub_fields'] )['open']['retired'] ?? false );
        $this->assertSame( $row, $this->option_row(), 'the values stay in the row' );
        $values = $this->mcp( 'get-field-values', [ 'options' => 'qa-dl-site' ] )['values'];
        $this->assertArrayNotHasKey( 'founded', $values );
        $this->assertArrayNotHasKey( 'open', $values['hours'][0] );
        $this->assertNull( $this->site_runs( "return fabrement()->options( 'qa-dl-site' )->get_number( 'founded' );" ) );
        $this->assertStringContainsString( 'founded: Unknown field.', $this->take_debug_log() );
        $this->assertStringContainsString( 'founded', $this->mcp_error( 'set-field-values', [ 'options' => 'qa-dl-site', 'values' => [ 'founded' => 2000 ] ] ) );
        $this->assertSame( $row, $this->option_row() );
        $hours = $values['hours'];
        $this->mcp( 'set-field-values', [ 'options' => 'qa-dl-site', 'values' => [ 'hours' => [ $hours[1], $hours[0] ] ] ] );
        $this->assertSame( [ '9-17', '9-18' ], [ $this->option_row()['fab_hours_0_open'], $this->option_row()['fab_hours_1_open'] ], 'a retired child moves with its row' );

        // Restored: the old values read again, the rows keep their identity.
        $fields = $this->page_fields();
        array_splice( $fields, 1, 0, [ self::field( 'founded', 'number', [ 'format' => 'integer' ] ) ] );
        $fields = self::edit( $fields, 'hours', static function ( array $f ): array {
            $f['sub_fields'][] = self::field( 'open', 'text' );
            return $f;
        } );
        $saved = $this->page( [ 'fields' => $fields ] );
        $this->assertArrayNotHasKey( 'retired', self::by_name( $saved['fields'] )['founded'] );
        $values = $this->mcp( 'get-field-values', [ 'options' => 'qa-dl-site' ] )['values'];
        $this->assertSame( 1999, $values['founded'] );
        $this->assertSame( [ $rows[1], $rows[0] ], array_column( $values['hours'], '_row' ) );
        $this->assertSame( [ '9-17', '9-18' ], array_column( $values['hours'], 'open' ) );
        $this->assertSame( [ 1999, '9-17' ], $this->site_runs( "\$o = fabrement()->options( 'qa-dl-site' ); return [ \$o->get_number( 'founded' ), \$o->get_rows( 'hours' )[0]->get_text( 'open' ) ];" ) );
    }
}
