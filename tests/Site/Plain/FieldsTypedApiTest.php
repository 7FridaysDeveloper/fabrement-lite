<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The fields API by type, as a template and the site's code call it:
 * get_<type>() with its named options — escape, echo, label, breaks; numbers and dates raw, shown with
 * fabrement()->format() — rows and groups as objects, rows and list items changed one at a time,
 * and a method of another type: a read gives nothing and is logged, a write throws. An unknown option
 * is logged and left out; the value is still read.
 */
final class FieldsTypedApiTest extends SiteTestCase {

    private const TYPE = 'qa_typed';

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->post_type( self::TYPE );
            $this->fields( self::TYPE, [
                self::field( 'title', 'text' ),
                self::field( 'about', 'textarea' ),
                self::field( 'body', 'wysiwyg' ),
                self::field( 'price', 'number', [ 'format' => 'decimal' ] ),
                self::field( 'seats', 'number', [ 'format' => 'integer' ] ),
                self::field( 'sold', 'true_false' ),
                self::field( 'level', 'select', [ 'choices' => [ [ 'value' => 'basic', 'label' => 'Basic' ], [ 'value' => 'pro', 'label' => 'Pro & co' ] ] ] ),
                self::field( 'start', 'date_picker' ),
                self::field( 'starts', 'date_time_picker' ),
                self::field( 'cover', 'image' ),
                self::field( 'photos', 'gallery' ),
                self::field( 'program', 'file' ),
                self::field( 'cta', 'link' ),
                self::field( 'hall_page', 'post_object' ),
                self::field( 'related', 'relationship' ),
                self::field( 'venue', 'group', [ 'sub_fields' => [ self::field( 'street', 'text' ), self::field( 'city', 'text' ) ] ] ),
                self::field( 'agenda', 'repeater', [ 'sub_fields' => [
                    self::field( 'title', 'text' ),
                    self::field( 'role', 'select', [ 'choices' => [ [ 'value' => 'host', 'label' => 'Host' ], [ 'value' => 'guest', 'label' => 'Guest' ] ] ] ),
                    self::field( 'room', 'group', [ 'sub_fields' => [ self::field( 'name', 'text' ) ] ] ),
                    self::field( 'speakers', 'repeater', [ 'sub_fields' => [ self::field( 'name', 'text' ) ] ] ),
                ] ] ),
            ] );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    /** What the site's code gets back: the value, or the message of the FieldsException it threw. */
    private function attempt( string $body ) {
        return $this->site_runs( 'try { ' . $body . ' } catch ( \Fabrement\Api\FieldsException $e ) { return [ "thrown" => $e->getMessage() ]; }' );
    }

    /** The site's debug.log, emptied: a test that expects lines there reads them, so tearDown() finds none. */
    private function take_log(): string {
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        return $log;
    }

    /** Site code: $echo( fn() => … ) gives [ what the call returned, what it printed ]. */
    private const PRINTED = '$echo = static function ( callable $print ): array { ob_start(); $returned = $print(); return [ $returned, ob_get_clean() ]; };';

    /** Three agenda rows (the first with a room and two speakers) saved by the site's code. @return list<string> Their _row. */
    private function agenda( int $id ): array {
        return $this->site_runs( "\$f = fabrement()->fields( $id );
            \$a = \$f->add_row( 'agenda', [ 'title' => 'Opening', 'role' => 'host', 'room' => [ 'name' => 'Hall A' ] ] );
            \$b = \$f->add_row( 'agenda', [ 'title' => 'Talk', 'role' => 'guest' ] );
            \$c = \$f->add_row( 'agenda', [ 'title' => 'Lunch' ] );
            \$f->add_row( 'agenda.' . \$a . '.speakers', [ 'name' => 'Ann' ] );
            \$f->add_row( 'agenda.' . \$a . '.speakers', [ 'name' => 'Bob' ] );
            \$f->save();
            return [ \$a, \$b, \$c ];" );
    }

    // ── text ─────────────────────────────────────────────────────────────────────────────────

    // ── numbers, choices, dates, switches ────────────────────────────────────────────────────

    public function test_choices_are_schema_data_for_records_groups_rows_and_options(): void {
        $choices = [
            [ 'value' => '0', 'label' => 'Zero & co' ],
            [ 'value' => '02', 'label' => 'Second' ],
            [ 'value' => 'pro', 'label' => 'Professional' ],
        ];
        $rank = self::field( 'rank', 'select', [ 'choices' => $choices ] );
        $this->post_type( 'qa_choices' );
        $definitions = $this->fields( 'qa_choices', [
            $rank,
            self::field( 'title', 'text' ),
            self::field( 'details', 'group', [ 'sub_fields' => [ $rank ] ] ),
            self::field( 'agenda', 'repeater', [ 'sub_fields' => [ $rank ] ] ),
        ] );
        $id = $this->record( 'qa_choices', 'Choices without values' );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-choices', 'expectedRevision' => 0,
            'page' => [ 'title' => 'Choices' ], 'fields' => [ $rank ] ] );
        $out = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$before = \$f->choices( 'rank' );
            \$f->set_select( 'rank', 'pro' );
            \$f->set_select( 'details.rank', 'pro' );
            \$row_id = \$f->add_row( 'agenda', [ 'rank' => '02' ] );
            \$row = \$f->get_rows( 'agenda' )[0];
            return [
                \$before,
                \$f->choices( 'rank' ),
                \$f->choices( 'details.rank' ),
                \$f->get_group( 'details' )->choices( 'rank' ),
                \$f->choices( 'agenda.rank' ),
                \$f->choices( 'agenda.' . \$row_id . '.rank' ),
                \$row->choices( 'rank' ),
                fabrement()->options( 'qa-choices' )->choices( 'rank' ),
            ];" );
        $this->assertSame( array_fill( 0, 8, $choices ), $out );
        $this->assertFalse( $this->site()->has_meta( $id, 'fab_rank' ) );
        $this->assertFalse( $this->site()->has_meta( $id, 'fab_agenda' ) );
        $this->assertSame( [], $this->site_runs( 'return fabrement()->fields( 0 )->choices( "rank" );' ) );
        $this->assertSame( '', $this->take_log() );
        $this->assertSame( [ [], [], [], [], [], [] ], $this->site_runs( "\$f = fabrement()->fields( $id );
            return [ \$f->choices( 'missing' ), \$f->choices( 'title' ), \$f->choices( 7 ),
                \$f->choices( 'agenda.row_missing' ), \$f->choices( 'rank', label: true ), \$f->choices( 'rank', 'extra' ) ];" ) );
        $log = $this->take_log();
        $this->assertStringContainsString( "Fields::choices( 'missing' ):", $log );
        $this->assertStringContainsString( 'has no choices', $log );
        $this->assertStringContainsString( 'as strings; got 7.', $log );
        $this->assertStringContainsString( 'a row is not a field', $log );
        $this->assertStringContainsString( 'without extra arguments', $log );

        unset( $definitions['rank'] );
        $this->fields( 'qa_choices', array_values( $definitions ) );
        $this->assertSame( [], $this->site_runs( "return fabrement()->fields( $id )->choices( 'rank' );" ) );
        $this->assertStringContainsString( "Fields::choices( 'rank' ):", $this->take_log() );
    }

    public function test_not_empty_answers_by_the_fields_type(): void {
        $id = $this->record( self::TYPE, 'Filled' );
        $empty = $this->record( self::TYPE, 'Not filled' );
        $this->site_runs( "fabrement()->fields( $id )->set_text( 'title', 'Hi' )->set_number( 'seats', 0 )->set_true_false( 'sold', false )->set_date( 'start', '2026-11-01' )->set_group( 'venue', [ 'city' => 'Paris' ] )->save();" );
        $rows = $this->agenda( $id );
        $names = [ 'title', 'about', 'price', 'seats', 'sold', 'start', 'cover', 'photos', 'cta', 'related', 'venue', 'agenda' ];
        $out = $this->site_runs( "\$f = fabrement()->fields( $id ); \$e = fabrement()->fields( $empty ); \$names = " . var_export( $names, true ) . ";
            return [
                'filled' => array_map( fn( \$n ) => \$f->not_empty( \$n ), \$names ),
                'empty'  => array_map( fn( \$n ) => \$e->not_empty( \$n ), \$names ),
                'nested' => [ \$f->get_group( 'venue' )->not_empty( 'city' ), \$f->get_group( 'venue' )->not_empty( 'street' ), \$f->not_empty( 'agenda', '{$rows[0]}', 'room' ), \$f->not_empty( 'agenda', '{$rows[1]}', 'room' ) ],
                'typo'   => \$f->not_empty( 'titel' ),
                'list'   => [ \$f->get_group( 'venue' )->not_empty( [ 'street', 'city' ] ), \$f->get_group( 'venue' )->not_empty( [ 'street' ] ), \$e->not_empty( [ 'title', 'seats', 'venue' ] ), \$f->not_empty( [ 'about', 'seats' ] ) ],
                'badList' => [ \$f->not_empty( [ 'title', 'titl' ] ), \$f->not_empty( [] ), \$f->not_empty( [ 'title' ], 'x' ) ],
            ];" );
        $this->assertSame( [ true, false, false, true ], $out['list'], 'any of the fields, each judged by its type' );
        $this->assertSame( [ true, false, false ], $out['badList'] );
        $this->assertSame( array_combine( $names, [ true, false, false, true, false, true, false, false, false, false, true, true ] ), array_combine( $names, $out['filled'] ), '0 is a number; false is not filled; a group with one value is' );
        $this->assertSame( array_fill( 0, count( $names ), false ), $out['empty'] );
        $this->assertSame( [ true, false, true, false ], $out['nested'] );
        $this->assertFalse( $out['typo'] );
        $log = $this->take_log();
        $this->assertStringContainsString( "Fields::not_empty( 'titel' ):", $log );
        $this->assertStringContainsString( "Fields::not_empty( 'titl' ):", $log );
        $this->assertStringContainsString( 'give a list of field names, at least one.', $log );
        $this->assertStringContainsString( 'a list of fields takes no more path', $log );
    }

    // ── media and records ────────────────────────────────────────────────────────────────────

    // ── groups and rows as objects ───────────────────────────────────────────────────────────

    public function test_rows_are_looped_as_objects_with_nested_rows_and_a_group_inside(): void {
        $id = $this->record( self::TYPE, 'Rows' );
        [ $a, $b, $c ] = $this->agenda( $id );
        $this->site_runs( "fabrement()->fields( $id )->set_text( 'venue.city', 'Lviv' );" );
        $this->site_runs( "\$f = fabrement()->fields( $id ); \$f->set_text( 'venue.street', 'Rynok 1' )->save();" );
        $out = $this->site_runs( "\$f = fabrement()->fields( $id ); \$rows = [];
            foreach ( \$f->get_rows( 'agenda' ) as \$row ) {
                \$rows[] = [
                    'id'       => \$row->id(),
                    'index'    => \$row->index(),
                    'title'    => \$row->get_text( 'title' ),
                    'role'     => \$row->get_select( 'role', label: true ),
                    'label'    => \$row->label( 'role' ),
                    'room'     => \$row->get_group( 'room' )?->get_text( 'name' ),
                    'dotted'   => \$row->get_text( 'room.name' ),
                    'speakers' => array_map( static fn( \$s ): array => [ \$s->index(), \$s->get_text( 'name' ), strpos( \$s->id(), 'row_' ) === 0 ], \$row->get_rows( 'speakers' ) ),
                    'has'      => [ \$row->has( 'title' ), \$row->has( 'price' ) ],
                    'keys'     => array_keys( \$row->values( row_ids: false ) ),
                    'get'      => \$row->get( 'title' ),
                ];
            }
            \$venue = \$f->get_group( 'venue' );
            return [
                'rows'    => \$rows,
                'limit'   => array_map( static fn( \$r ): string => \$r->id(), \$f->get_rows( 'agenda', limit: 2 ) ),
                'all'     => count( \$f->get_rows( 'agenda', limit: 10 ) ),
                'venue'   => [ \$venue->get_text( 'street' ), \$venue->get_text( 'city' ), \$venue->values(), \$venue->field( 'street' )['value'] ],
                'deep'    => \$f->get_text( 'agenda', '$a', 'room', 'name' ),
                'grouped' => \$f->get_group( 'agenda', '$a', 'room' )->get_text( 'name' ),
            ];" );
        $this->assertSame( [ $a, $b, $c ], array_column( $out['rows'], 'id' ), 'id() is the row\'s _row' );
        $this->assertSame( [ 0, 1, 2 ], array_column( $out['rows'], 'index' ) );
        $this->assertSame( [ 'Opening', 'Talk', 'Lunch' ], array_column( $out['rows'], 'title' ) );
        $this->assertSame( [ 'Host', 'Guest', null ], array_column( $out['rows'], 'role' ), 'a row\'s select by its label' );
        $this->assertSame( [ 'Host', 'Guest', null ], array_column( $out['rows'], 'label' ), 'a row knows its own value' );
        $this->assertSame( [ 'Hall A', null, null ], array_column( $out['rows'], 'room' ), 'a group inside a row' );
        $this->assertSame( [ 'Hall A', null, null ], array_column( $out['rows'], 'dotted' ) );
        $this->assertSame( [ [ 0, 'Ann', true ], [ 1, 'Bob', true ] ], $out['rows'][0]['speakers'], 'rows inside a row' );
        $this->assertSame( [], $out['rows'][1]['speakers'] );
        $this->assertSame( [ true, false ], $out['rows'][0]['has'] );
        $this->assertSame( [ 'title', 'role', 'room', 'speakers' ], $out['rows'][0]['keys'] );
        $this->assertSame( 'Opening', $out['rows'][0]['get'] );
        $this->assertSame( [ $a, $b ], $out['limit'] );
        $this->assertSame( 3, $out['all'] );
        $this->assertSame( [ 'Rynok 1', null, [ 'street' => 'Rynok 1', 'city' => null ], 'Rynok 1' ], $out['venue'], 'a group\'s change is stored only by the record\'s save()' );
        $this->assertSame( 'Hall A', $out['deep'] );
        $this->assertSame( 'Hall A', $out['grouped'] );
    }

    public function test_a_change_made_through_a_row_or_a_group_is_stored_by_the_records_save(): void {
        $id = $this->record( self::TYPE, 'Row writes' );
        [ $a, $b ] = $this->agenda( $id );
        $out = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$rows = \$f->get_rows( 'agenda' );
            \$rows[1]->set_text( 'title', 'Keynote' );
            \$rows[0]->get_group( 'room' )->set_text( 'name', 'Hall B' );
            \$rows[0]->add_row( 'speakers', [ 'name' => 'Cid' ] );
            \$rows[2]->set( 'role', 'guest' );
            \$f->set_text( 'venue.city', 'Lviv' );
            \$stored  = fabrement()->fields( $id )->get_text( 'agenda', '$b', 'title' );
            \$pending = [ \$f->get_text( 'agenda', '$b', 'title' ), \$rows[1]->get_text( 'title' ) ];
            \$saved   = \$f->save();
            \$after   = fabrement()->fields( $id );
            \$first   = \$after->get_rows( 'agenda' )[0];
            return [ \$stored, \$pending, \$saved, \$after->get_text( 'agenda', '$b', 'title' ), \$first->get_text( 'room.name' ),
                array_map( static fn( \$s ): string => \$s->get_text( 'name' ), \$first->get_rows( 'speakers' ) ), \$after->get_rows( 'agenda' )[2]->get_select( 'role' ),
                \$after->get_text( 'venue.city' ), method_exists( \$first, 'save' ) ];" );
        $this->assertSame( [ 'Talk', [ 'Keynote', 'Keynote' ], true, 'Keynote', 'Hall B', [ 'Ann', 'Bob', 'Cid' ], 'guest', 'Lviv', false ], $out );
    }

    public function test_rows_are_added_updated_moved_and_deleted_one_at_a_time_at_any_depth(): void {
        $id = $this->record( self::TYPE, 'Row by row' );
        [ $a, $b, $c ] = $this->agenda( $id );
        $out = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$d = \$f->add_row( 'agenda', [ 'title' => 'Coffee' ], after: '$a' );
            \$e = \$f->add_row( 'agenda', [ 'title' => 'Doors' ], before: '$a' );
            \$f->update_row( 'agenda', '$b', [ 'title' => 'Keynote' ] );
            \$f->move_row( 'agenda', '$c', after: \$e );
            \$f->delete_row( 'agenda', \$d );
            \$row = \$f->get_rows( 'agenda' )[2];
            \$ann = \$row->get_rows( 'speakers' )[0]->id();
            \$bob = \$row->get_rows( 'speakers' )[1]->id();
            \$cid = \$row->add_row( 'speakers', [ 'name' => 'Cid' ], before: \$ann );
            \$row->update_row( 'speakers', \$bob, [ 'name' => 'Robert' ] );
            \$row->move_row( 'speakers', \$ann, before: \$cid );
            \$row->delete_row( 'speakers', \$bob );
            \$f->save();
            \$after = fabrement()->fields( $id );
            return [ \$e, array_column( \$after->get( 'agenda' ), 'title' ), array_column( \$after->get( 'agenda' ), '_row' ),
                array_column( \$after->get( 'agenda.$a.speakers' ), 'name' ), \$after->get_rows( 'agenda' )[3]->get_text( 'room.name' ) ];" );
        [ $e, $titles, $ids, $speakers, $room ] = $out;
        $this->assertSame( [ 'Doors', 'Lunch', 'Opening', 'Keynote' ], $titles );
        $this->assertSame( [ $e, $c, $a, $b ], $ids, 'every row keeps its _row wherever it moves' );
        $this->assertSame( [ 'Ann', 'Cid' ], $speakers, 'rows inside a row, changed through the row' );
        $this->assertNull( $room );

        $thrown = fn( string $code ): string => (string) ( $this->attempt( $code )['thrown'] ?? 'not thrown' );
        $this->assertStringContainsString( 'move_row() needs where to: after: or before:', $thrown( "fabrement()->fields( $id )->move_row( 'agenda', '$a' ); return 1;" ) );
        $this->assertStringContainsString( 'move_row() needs where to', $thrown( "fabrement()->fields( $id )->move_row( 'agenda', '$a', after: '$b', before: '$c' ); return 1;" ) );
        $this->assertStringContainsString( 'cannot be moved next to itself', $thrown( "fabrement()->fields( $id )->move_row( 'agenda', '$a', after: '$a' ); return 1;" ) );
        $this->assertStringContainsString( 'no row "row_00000000000000000000000000000000"', $thrown( "fabrement()->fields( $id )->add_row( 'agenda', [ 'title' => 'X' ], after: 'row_00000000000000000000000000000000' ); return 1;" ) );
        $this->assertStringContainsString( 'add_row() takes one row', $thrown( "fabrement()->fields( $id )->get_rows( 'agenda' )[0]->add_row( 'speakers', [ [ 'name' => 'A' ], [ 'name' => 'B' ] ] ); return 1;" ) );
        $this->assertStringContainsString( 'a new row has no _row yet', $thrown( "fabrement()->fields( $id )->add_row( 'agenda', [ '_row' => '$a', 'title' => 'X' ] ); return 1;" ) );
        $this->assertStringContainsString( 'was given the _row of another row', $thrown( "fabrement()->fields( $id )->update_row( 'agenda', '$a', [ '_row' => '$b' ] ); return 1;" ) );
        $this->assertStringContainsString( 'title: a text field, not a repeater — update_row() is for repeaters; use set_text()', $thrown( "fabrement()->fields( $id )->update_row( 'title', '$a', [] ); return 1;" ) );
    }

    public function test_a_gallery_takes_items_one_at_a_time(): void {
        $one   = $this->image( 'qa-typed-item-one.png' );
        $two   = $this->image( 'qa-typed-item-two.png' );
        $three = $this->image( 'qa-typed-item-three.png' );
        $id    = $this->record( self::TYPE, 'Items' );
        $out = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$f->add_item( 'photos', $one )->add_item( 'photos', $two )->add_item( 'photos', $three, before: $one );
            \$steps = [ \$f->get( 'photos' ) ];
            \$f->move_item( 'photos', $two, after: $three );
            \$steps[] = \$f->get( 'photos' );
            \$f->delete_item( 'photos', $one )->delete_item( 'photos', 999999 );
            \$steps[] = \$f->get( 'photos' );
            \$saved = \$f->save();
            return [ \$steps, \$saved, fabrement()->fields( $id )->get( 'photos' ), count( fabrement()->fields( $id )->get_gallery( 'photos' ) ) ];" );
        $this->assertSame( [ [ [ $three, $one, $two ], [ $three, $two, $one ], [ $three, $two ] ], true, [ $three, $two ], 2 ], $out );

        $thrown = fn( string $code ): string => (string) ( $this->attempt( $code )['thrown'] ?? 'not thrown' );
        $this->assertStringContainsString( 'move_item() needs where to', $thrown( "fabrement()->fields( $id )->move_item( 'photos', $two ); return 1;" ) );
        $this->assertStringContainsString( $one . ' is not in the list', $thrown( "fabrement()->fields( $id )->move_item( 'photos', $one, before: $two ); return 1;" ) );
        $this->assertStringContainsString( 'title: a text field, not a relationship or a gallery — add_item() is for lists; use set_text()', $thrown( "fabrement()->fields( $id )->add_item( 'title', $one ); return 1;" ) );
    }

    // ── a method of another type ─────────────────────────────────────────────────────────────

    public function test_a_setter_of_another_type_throws_and_writes_nothing(): void {
        $id = $this->record( self::TYPE, 'Wrong setter' );
        [ $a ] = $this->agenda( $id );
        $before = $this->site()->rows( 'SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? ORDER BY meta_id', [ $id ] );
        $thrown = fn( string $code ): string => (string) ( $this->attempt( $code )['thrown'] ?? 'not thrown' );
        $this->assertStringContainsString( 'title: a text field — use set_text()', $thrown( "fabrement()->fields( $id )->set_number( 'title', 1 ); return 1;" ) );
        $this->assertStringContainsString( 'agenda: a repeater field — use set_rows()', $thrown( "fabrement()->fields( $id )->set_text( 'agenda', 'x' ); return 1;" ) );
        $this->assertStringContainsString( 'start: a date_picker field — use set_date()', $thrown( "fabrement()->fields( $id )->set_date_time( 'start', '2026-11-01T10:00' ); return 1;" ) );
        $this->assertStringContainsString( 'agenda.' . $a . '.room.name: a text field — use set_text()', $thrown( "fabrement()->fields( $id )->get_rows( 'agenda' )[0]->get_group( 'room' )->set_number( 'name', 1 ); return 1;" ) );
        $this->assertStringContainsString( 'no value', $thrown( "fabrement()->fields( $id )->set_text( 'title' ); return 1;" ) );
        $this->assertStringContainsString( 'set_text(): unknown option "escape"', $thrown( "fabrement()->fields( $id )->set_text( 'title', 'x', escape: 'html' ); return 1;" ) );
        $this->assertStringContainsString( 'Enter a valid date', $thrown( "fabrement()->fields( $id )->set_date( 'start', '01.11.2026' ); return 1;" ) );
        $this->assertStringContainsString( 'Expected a finite number', $thrown( "fabrement()->fields( $id )->set_number( 'price', '12' ); return 1;" ) );
        $this->assertStringContainsString( 'there is no post 99999999', $thrown( "fabrement()->fields( 99999999 )->set_text( 'venue.city', 'x' ); return 1;" ) );
        $this->assertSame( $before, $this->site()->rows( 'SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? ORDER BY meta_id', [ $id ] ), 'nothing was written' );
    }
}
