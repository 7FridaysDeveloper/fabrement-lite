<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The PHP API the site's code calls — fabrement()->fields() / ->options() to read and write, the
 * template functions fabrement_get_field() and its siblings, fabrement_file() — run inside the site
 * with no one signed in, on fields an agent made.
 */
final class FieldsPhpApiTest extends SiteTestCase {

    private const TYPE = 'qa_api';

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
                self::field( 'details', 'group', [ 'sub_fields' => [ self::field( 'city', 'text' ), self::field( 'zip', 'text' ) ] ] ),
                self::field( 'body', 'wysiwyg' ),
                self::field( 'agenda', 'repeater', [ 'max' => 5, 'sub_fields' => [
                    self::field( 'time', 'text' ),
                    self::field( 'topic', 'text' ),
                    self::field( 'speakers', 'repeater', [ 'sub_fields' => [ self::field( 'name', 'text' ) ] ] ),
                    self::field( 'more', 'link' ),
                ] ] ),
                self::field( 'tags', 'relationship' ),
                self::field( 'cta', 'link' ),
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

    /** All meta rows of a record: a refused call leaves them exactly so. */
    private function meta_rows( int $id ): array {
        return $this->site()->rows( 'SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? ORDER BY meta_id', [ $id ] );
    }

    // ── writing ──────────────────────────────────────────────────────────────────────────────

    public function test_the_sites_code_writes_and_clears_with_no_user_as_with_acf(): void {
        $id = $this->record( self::TYPE, 'Cron' );
        $this->assertSame( [ 0, true, false, true ], $this->site_runs( "\$f = fabrement()->fields( $id );
            return [
                get_current_user_id(),
                \$f->set( 'title', 'From cron' )->set( 'price', 250 )->save(),
                \$f->save(),                                  // nothing left to write
                \$f->clear( 'price' )->save(),
            ];" ) );
        $this->assertSame( [ 'From cron' ], $this->site()->meta( $id, 'fab_title' ) );
        $this->assertTrue( $this->site()->has_meta( $id, 'fab_price' ), 'cleared: explicit empty row' );
        $this->assertNull( $this->site_runs( "return fabrement()->fields( $id )->get( 'price' );" ), 'cleared does not restore its default' );
    }

    /** The site's code is trusted, like ACF's update_field(): its words are kept as written. */
    public function test_words_the_sites_code_writes_are_kept_as_written(): void {
        $id    = $this->record( self::TYPE, 'Embed' );
        $embed = '<p>Kids <12 &amp; parents</p><iframe src="https://www.youtube.com/embed/qa" width="560"></iframe>';
        $this->site_runs( "fabrement()->fields( $id )->set( 'body', " . var_export( $embed, true ) . " )->set( 'details.city', 'Lviv <old town>' )->save();" );
        $this->assertSame( [ $embed ], $this->site()->meta( $id, 'fab_body' ), 'byte for byte, though no one is signed in' );
        $this->assertSame( [ 'Lviv <old town>' ], $this->site()->meta( $id, 'fab_details_city' ) );
    }

    public function test_a_sub_field_of_a_group_is_set_alone(): void {
        $id = $this->record( self::TYPE, 'Group' );
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => [ 'details' => [ 'city' => 'Lviv', 'zip' => '79000' ] ] ] );
        $this->assertSame( [ 'Kyiv', '79000', [ 'city' => 'Kyiv', 'zip' => '79000' ] ], $this->site_runs( "\$f = fabrement()->fields( $id );
            \$f->set( 'details.city', 'Kyiv' )->save();
            return [ \$f->get( 'details.city' ), \$f->get( 'details.zip' ), \$f->get( 'details' ) ];" ) );
    }

    public function test_a_value_equal_to_the_default_is_stored(): void {
        $id = $this->record( self::TYPE, 'Default' );
        $this->site_runs( "fabrement()->fields( $id )->set( 'price', 100 )->save();" );
        $this->assertSame( [ '100' ], $this->site()->meta( $id, 'fab_price' ) );
    }

    public function test_a_pick_is_judged_the_same_whoever_writes_it(): void {
        $published = $this->record( 'page', 'Published' );
        $private   = $this->record( 'page', 'Private', 'private' );
        $this->mcp( 'set-content-status', [ 'contentId' => $private, 'status' => 'publish' ] );
        $this->site()->php( "wp_update_post( [ 'ID' => $private, 'post_status' => 'private' ] );" );
        $trashed = $this->record( 'page', 'Trashed', 'trash' );
        $id      = $this->record( self::TYPE, 'Picker' );
        $this->site_runs( "fabrement()->fields( $id )->set( 'ref', $published )->save(); fabrement()->fields( $id )->set( 'ref', $private )->save();" );
        $this->assertSame( [ (string) $private ], $this->site()->meta( $id, 'fab_ref' ), 'a record the writer cannot read is still a record' );
        $refused = $this->attempt( "fabrement()->fields( $id )->set( 'ref', $trashed ); return 'set';" );
        $this->assertArrayHasKey( 'thrown', $refused, 'one in the trash is refused for anyone, at set()' );
        $this->assertSame( [ (string) $private ], $this->site()->meta( $id, 'fab_ref' ) );
    }

    /** No coercion: the value is the field's type, or it is refused where it is given. */
    public function test_a_mistake_is_thrown_where_it_is_made_and_nothing_is_written(): void {
        $id = $this->record( self::TYPE, 'Mistakes' );
        $this->site_runs( "fabrement()->fields( $id )->set( 'title', 'Kept' )->save();" );
        $before = $this->meta_rows( $id );
        $thrown = static fn( array $out ): string => (string) ( $out['thrown'] ?? 'not thrown' );

        $this->assertStringContainsString( 'Did you mean "price"?', $thrown( $this->attempt( "fabrement()->fields( $id )->set( 'pirce', 1 ); return 1;" ) ) );
        $this->assertStringContainsString( 'Expected a finite number', $thrown( $this->attempt( "fabrement()->fields( $id )->set( 'price', '120' ); return 1;" ) ) );
        $this->assertStringContainsString( 'a repeater has one value per row — name the row', $thrown( $this->attempt( "fabrement()->fields( $id )->set( 'agenda.topic', 'x' ); return 1;" ) ) );
        $this->assertStringContainsString( 'no row "row_00000000000000000000000000000000"', $thrown( $this->attempt( "fabrement()->fields( $id )->update_row( 'agenda', 'row_00000000000000000000000000000000', [ 'topic' => 'x' ] ); return 1;" ) ) );
        $this->assertSame( 1, $this->attempt( "fabrement()->fields( $id )->delete_row( 'agenda', 'row_00000000000000000000000000000000' ); return 1;" ), 'deleting a row that is not there: nothing to do, quietly' );
        $this->assertStringContainsString( 'price: a number field, not a repeater — add_row() is for repeaters; use set_number()', $thrown( $this->attempt( "fabrement()->fields( $id )->add_row( 'price', [] ); return 1;" ) ) );
        $this->assertStringContainsString( 'after: or before:', $thrown( $this->attempt( "\$f = fabrement()->fields( $id ); \$a = \$f->add_row( 'agenda', [ 'topic' => 'A' ] ); \$f->move_row( 'agenda', \$a ); return 1;" ) ) );
        $this->assertStringContainsString( 'one row', $thrown( $this->attempt( "fabrement()->fields( $id )->add_row( 'agenda', [ [ 'topic' => 'A' ] ] ); return 1;" ) ) );
        // Only what the whole record tells is left to save(): six rows where five are the most.
        $six = $thrown( $this->attempt( "\$f = fabrement()->fields( $id );
            foreach ( range( 1, 6 ) as \$i ) { \$f->add_row( 'agenda', [ 'topic' => 'T' . \$i ] ); }
            return 1;" ) );
        $this->assertStringContainsString( 'outside the allowed range', $six, 'the sixth add() is refused at once' );
        $this->assertSame( $before, $this->meta_rows( $id ), 'nothing was written by any of them' );
    }

    /**
     * A read never throws: a template must not lose its block over one misspelt name. It gives
     * nothing and the site's log says why; a write to what is not there throws.
     */
    public function test_a_read_that_cannot_be_done_gives_nothing_and_is_logged(): void {
        $id   = $this->record( self::TYPE, 'Reads' );
        $page = $this->record( 'page', 'No fields' );
        $this->assertSame( [ null, null, null, null, null, [], false, null, null ], $this->site_runs( "return [
            fabrement()->fields( $id )->get( 'pirce' ),
            fabrement()->fields( $id )->get( 'agenda.topic' ),
            fabrement()->fields()->get( 'title' ),
            fabrement()->fields( $id )->label( 'title' ),
            fabrement()->fields( $id )->field( 'nothing' ),
            fabrement()->fields( 99999999 )->values(),
            fabrement()->fields( $page )->has( 'price' ),
            fabrement()->fields( $page )->get( 'price' ),
            fabrement()->options( 'qa-none' )->get( 'phone' ),
        ];" ) );
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        foreach ( [ 'pirce: Unknown field. Did you mean "price"?', 'a repeater has one value per row', 'there is no current post here', 'has no choices to label',
            'price: Unknown field.', 'there is no options page "qa-none"' ] as $said ) {
            $this->assertStringContainsString( $said, $log );
        }
        $this->assertStringNotContainsString( '99999999', $log, 'a record that is not there: nothing, quietly' );
        $write = $this->attempt( "fabrement()->fields( 99999999 )->set( 'title', 'x' ); return 1;" );
        $this->assertStringContainsString( 'there is no post 99999999', (string) ( $write['thrown'] ?? '' ), 'a write throws' );
        $this->assertStringContainsString( 'there is no options page', (string) ( $this->attempt( "fabrement()->options( 'qa-none' )->save(); return 1;" )['thrown'] ?? '' ) );
        $this->assertStringContainsString( 'there is no options page "qa-none"', $this->site()->debug_log(), 'options() itself is a read: logged too' );
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
    }

    // ── a field by its type ──

    public function test_a_field_is_written_by_its_types_method_inside_rows_and_groups_too(): void {
        $id  = $this->record( self::TYPE, 'Typed' );
        $out = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$f->set_number( 'price', 120 );
            \$f->set_link( 'cta', [ 'url' => 'https://example.com/a', 'label' => 'Go' ] );
            \$f->set_text( 'details.city', 'Lviv' );
            \$row = \$f->add_row( 'agenda', [ 'time' => '10:00', 'topic' => 'Opening' ] );
            \$f->set_text( 'agenda', \$row, 'topic', 'Welcome' );
            \$f->add_row( 'agenda.' . \$row . '.speakers', [ 'name' => 'Ann' ] );
            \$f->save();
            \$f = fabrement()->fields( $id );
            return [ \$f->get_number( 'price' ), \$f->get_link( 'cta' )->text(), \$f->get_text( 'details', 'city' ),
                \$f->get_text( 'agenda', \$row, 'topic' ), \$f->get( 'agenda.' . \$row . '.speakers', row_ids: false ),
                \$f->clear( 'price' )->save(), \$f->get( 'price' ) ];" );
        $this->assertSame( [ 120, 'Go', 'Lviv', 'Welcome', [ [ 'name' => 'Ann' ] ], true, null ], $out );
    }

    /** A field decoded once is read again as it is: after a change, after save(), and by a fields object made later. */
    public function test_a_field_read_twice_follows_its_changes_and_its_save(): void {
        $id = $this->record( self::TYPE, 'Read twice' );
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => [ 'agenda' => [ [ 'time' => '09:00', 'topic' => 'Opening' ] ] ] ] );
        $this->assertSame( [ 'Opening', 'Opening', 'Changed', 'Changed', 'Changed' ], $this->site_runs( "
            \$f = fabrement()->fields( $id );
            \$read = static fn(): ?string => \$f->get_rows( 'agenda' )[0]->get_text( 'topic' );
            \$out = [ \$read(), \$read() ];
            \$f->set( 'agenda', [ [ 'time' => '09:00', 'topic' => 'Changed' ] ] );
            \$out[] = \$read();
            \$f->save();
            \$out[] = \$read();
            \$out[] = fabrement()->fields( $id )->get_rows( 'agenda' )[0]->get_text( 'topic' );
            return \$out;" ) );
    }

    public function test_a_relationship_takes_items_one_at_a_time_each_once(): void {
        $a  = $this->record( 'page', 'Item A' );
        $b  = $this->record( 'page', 'Item B' );
        $c  = $this->record( 'page', 'Item C' );
        $id = $this->record( self::TYPE, 'Lists' );
        $tags = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$f->add_item( 'tags', $a )->add_item( 'tags', $b )->add_item( 'tags', $c, after: $a )->add_item( 'tags', $a );   // a, c, b — a is there: the last add does nothing
            \$f->save();
            \$out = [ \$f->get( 'tags' ) ];
            \$f->delete_item( 'tags', $a )->save();                                                     // every a
            \$out[] = \$f->get( 'tags' );
            \$f->delete_item( 'tags', 999999 )->move_item( 'tags', $b, before: $c )->save();             // not there: nothing; then b first
            \$out[] = \$f->get( 'tags' );
            return \$out;" );
        $this->assertSame( [ [ $a, $c, $b ], [ $c, $b ], [ $b, $c ] ], $tags );
        $this->assertSame( [ (string) $b, (string) $c ], $this->site()->meta( $id, 'fab_tags' ), 'one meta row per item, in order' );

        $thrown = fn( string $code ): string => (string) ( $this->attempt( $code )['thrown'] ?? 'not thrown' );
        $this->assertStringContainsString( 'listed twice; this field lists each once.', $thrown( "fabrement()->fields( $id )->set_relationship( 'tags', [ $a, $a ] ); return 1;" ) );
        $this->assertStringContainsString( 'listed twice', $this->mcp_error( 'set-field-values', [ 'postId' => $id, 'values' => [ 'tags' => [ $b, $b ] ] ] ) );
        $this->assertStringContainsString( 'cannot be moved next to itself', $thrown( "fabrement()->fields( $id )->move_item( 'tags', $c, before: $c ); return 1;" ) );
    }

    public function test_a_field_has_no_unique(): void {
        $current = $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => self::TYPE ] );
        $refused = static fn( array $field ): array => [ 'type' => 'post', 'slug' => self::TYPE, 'expectedRevision' => (int) $current['revision'], 'fields' => [ $field ] ];
        $this->assertStringContainsString( 'unique', $this->mcp_error( 'save-fields', $refused( self::field( 'qa_team', 'relationship', [ 'unique' => true ] ) ) ), 'a relationship always lists each record once' );
        $this->assertStringContainsString( 'unique', $this->mcp_error( 'save-fields', $refused( self::field( 'qa_photos', 'gallery', [ 'unique' => true ] ) ) ) );
    }

    /** Bug 1: what get() reads, set() takes back — an empty link reads null, alone or in a row. */
    public function test_an_empty_link_read_is_written_back(): void {
        $id  = $this->record( self::TYPE, 'Round trip' );
        $out = $this->attempt( "\$f = fabrement()->fields( $id );
            \$f->set( 'cta', \$f->get( 'cta' ) );                       // null: no link
            \$f->add_row( 'agenda', [ 'topic' => 'A' ] );
            \$f->save();
            \$rows = \$f->get( 'agenda' );
            \$rows[0]['topic'] = 'B';
            \$f->set( 'agenda', \$rows )->save();
            return [ \$f->get( 'cta' ), \$f->get( 'agenda' )[0]['topic'] ];" );
        $this->assertSame( [ null, 'B' ], $out );
    }

    // ── repeater rows ────────────────────────────────────────────────────────────────────────

    public function test_rows_are_read_with_their_identity_and_changed_one_at_a_time(): void {
        $id  = $this->record( self::TYPE, 'Agenda' );
        $ids = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$open  = \$f->add_row( 'agenda', [ 'time' => '10:00', 'topic' => 'Opening' ] );
            \$talk  = \$f->add_row( 'agenda', [ 'time' => '11:00', 'topic' => 'Talk' ] );
            \$lunch = \$f->add_row( 'agenda', [ 'time' => '12:00', 'topic' => 'Lunch' ] );
            \$f->add_row( 'agenda.' . \$open . '.speakers', [ 'name' => 'Ann' ] );
            \$f->save();
            return [ \$open, \$talk, \$lunch ];" );
        [ $open, $talk, $lunch ] = $ids;
        $this->assertSame( [ '3' ], $this->site()->meta( $id, 'fab_agenda' ) );

        $read = $this->site_runs( "\$f = fabrement()->fields( $id ); return [ \$f->get( 'agenda' ), \$f->get( 'agenda', false ), fabrement_get_field( 'agenda', $id ) ];" );
        $this->assertSame( [ $open, $talk, $lunch ], array_column( $read[0], '_row' ), 'every row carries its _row' );
        $this->assertSame( 'Ann', $read[0][0]['speakers'][0]['name'] );
        $this->assertMatchesRegularExpression( '/^row_[a-f0-9]{12}$/', $read[0][0]['speakers'][0]['_row'], 'nested rows too' );
        $this->assertArrayNotHasKey( '_row', $read[1][0], 'row_ids: false leaves it out' );
        $this->assertArrayNotHasKey( '_row', $read[1][0]['speakers'][0] );
        $this->assertArrayNotHasKey( '_row', $read[2][0], 'the template function reads as it always did' );

        // One save for all of it: a coffee before the talk, the talk renamed, lunch moved first, a speaker added.
        $coffee = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$coffee = \$f->add_row( 'agenda', [ 'time' => '10:30', 'topic' => 'Coffee' ], before: '$talk' );
            \$f->update_row( 'agenda', '$talk', [ 'topic' => 'Keynote' ] );
            \$f->move_row( 'agenda', '$lunch', before: '$open' );
            \$f->add_row( 'agenda.$open.speakers', [ 'name' => 'Bob' ] );
            \$f->save();
            return \$coffee;" );
        $rows = $this->site_runs( "return fabrement()->fields( $id )->get( 'agenda' );" );
        $this->assertSame( [ $lunch, $open, $coffee, $talk ], array_column( $rows, '_row' ) );
        $this->assertSame( [ 'Lunch', 'Opening', 'Coffee', 'Keynote' ], array_column( $rows, 'topic' ) );
        $this->assertSame( '11:00', $rows[3]['time'], 'update() leaves what it was not given' );
        $this->assertSame( [ 'Ann', 'Bob' ], array_column( $rows[1]['speakers'], 'name' ) );

        $this->site_runs( "fabrement()->fields( $id )->delete_row( 'agenda', '$coffee' )->save();" );
        $this->assertSame( [ 'Lunch', 'Opening', 'Keynote' ], array_column( $this->site_runs( "return fabrement_get_field( 'agenda', $id );" ), 'topic' ) );
        $this->assertSame( [ '3' ], $this->site()->meta( $id, 'fab_agenda' ) );
        $this->assertSame( [], $this->site()->meta( $id, 'fab_agenda_3_topic' ), 'no ghost row left behind' );
    }

    public function test_a_whole_list_keeps_the_rows_named_by_row_and_replaces_them_when_none_is(): void {
        $id = $this->record( self::TYPE, 'Whole list' );
        $this->site_runs( "\$f = fabrement()->fields( $id );
            foreach ( [ '12:00' => 'C', '10:00' => 'A', '11:00' => 'B' ] as \$time => \$topic ) { \$f->add_row( 'agenda', [ 'time' => \$time, 'topic' => \$topic ] ); }
            \$f->save();" );
        $before = array_column( $this->site_runs( "return fabrement()->fields( $id )->get( 'agenda' );" ), '_row', 'topic' );

        // Read, sort, write back: the rows keep their identity.
        $sorted = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$rows = \$f->get( 'agenda' );
            usort( \$rows, fn( \$a, \$b ) => strcmp( \$a['time'], \$b['time'] ) );
            \$f->set( 'agenda', \$rows )->save();
            return \$f->get( 'agenda' );" );
        $this->assertSame( [ 'A', 'B', 'C' ], array_column( $sorted, 'topic' ) );
        $this->assertSame( [ $before['A'], $before['B'], $before['C'] ], array_column( $sorted, '_row' ) );

        // Rows without any _row: an import that writes the list anew. Every row is new.
        $fresh = $this->site_runs( "\$f = fabrement()->fields( $id );
            \$f->set( 'agenda', [ [ 'time' => '09:00', 'topic' => 'Doors' ], [ 'time' => '10:00', 'topic' => 'A' ] ] )->save();
            return \$f->get( 'agenda' );" );
        $this->assertSame( [ 'Doors', 'A' ], array_column( $fresh, 'topic' ) );
        $this->assertSame( [], array_intersect( array_column( $fresh, '_row' ), array_values( $before ) ), 'no row is taken for an old one by its place' );
        $this->assertSame( [ '2' ], $this->site()->meta( $id, 'fab_agenda' ) );

        // A copied row keeps the _row of its source: refused, a copy is a new row.
        $copy = $this->attempt( "\$f = fabrement()->fields( $id ); \$rows = \$f->get( 'agenda' ); \$rows[] = \$rows[0]; \$f->set( 'agenda', \$rows ); return 1;" );
        $this->assertStringContainsString( 'sent twice', (string) ( $copy['thrown'] ?? '' ) );
    }

    // ── reading ──────────────────────────────────────────────────────────────────────────────

    public function test_empty_raw_values_are_null_and_unsaved_changes_are_read_as_they_will_be_stored(): void {
        $id = $this->record( self::TYPE, 'Empty' );
        $this->assertSame( [ null, null, null, null, null, null, null, 100, true, false ], $this->site_runs( "\$f = fabrement()->fields( $id );
            \$out = [ \$f->get( 'agenda' ), \$f->get( 'tags' ), \$f->get( 'details' ), \$f->get( 'title' ), \$f->get( 'ref' ),
                \$f->get( 'cta' ), fabrement_get_field( 'cta', $id ) ];   // a link nobody set: null
            \$f->set( 'price', 100 );
            \$out[] = \$f->get( 'price' );
            \$out[] = \$f->field( 'price' )['exists'];       // to be stored
            \$out[] = fabrement()->fields( $id )->field( 'price' )['exists'];   // not yet
            return \$out;" ) );
    }

    public function test_without_an_id_the_current_post_of_the_loop_is_read_and_an_explicit_0_reads_none(): void {
        $id = $this->record( self::TYPE, 'Loop' );
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => [ 'title' => 'Hello' ] ] );
        $this->assertSame( [
            'loop' => 'Hello', 'api' => 'Hello', 'apiZero' => null, 'zero' => null, 'zeroText' => null, 'empty' => null, 'fields' => [], 'object' => null,
        ], $this->site_runs( "\$GLOBALS['post'] = get_post( $id );   // what an archive sets: the post of the loop
            return [
                'loop'     => fabrement_get_field( 'title' ),
                'api'      => fabrement()->fields()->get( 'title' ),
                'apiZero'  => fabrement()->fields( 0 )->get( 'title' ),   // no record, on purpose: not logged
                'zero'     => fabrement_get_field( 'title', 0 ),
                'zeroText' => fabrement_get_field( 'title', '0' ),
                'empty'    => fabrement_get_field( 'title', '' ),
                'fields'   => fabrement_get_fields( 0 ),
                'object'   => fabrement_get_field_object( 'title', 0 ),
            ];" ) );
    }

    public function test_a_choice_label_is_the_label_of_a_value_still_offered(): void {
        $id = $this->record( self::TYPE, 'Labels' );
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => [ 'stage' => 'new' ] ] );
        $this->assertSame( [ 'new', 'New', 'New', 'New', '', '', '', '', '', 'New', 'Old' ], $this->site_runs( "return [
            fabrement_get_field( 'stage', $id ),
            fabrement_get_choice_label( 'stage', $id ),
            fabrement_get_choice_label( 'fab_stage', $id ),
            fabrement_get_choice_label( 'stage', $id, 'new' ),
            fabrement_get_choice_label( 'stage', $id, 'gone' ),
            fabrement_get_choice_label( 'stage', $id, 0 ),
            fabrement_get_choice_label( 'title', $id ),
            fabrement_get_choice_label( 'details.city', $id, 'x' ),
            fabrement_get_choice_label( 'stage', 0 ),
            fabrement()->fields( $id )->label( 'stage' ),
            fabrement()->fields( $id )->label( 'stage', value: 'old' ),
        ];" ) );
    }

    // ── what generated code and old code may do ──────────────────────────────────────────────

    /** A block's render.php or a page's page.php renders; a write from it is refused, wherever it is made. */
    public function test_a_clear_not_saved_yet_has_no_label(): void {
        $id = $this->record( self::TYPE, 'Pending label' );
        $this->assertSame( [ 'New', null ], $this->site_runs( "\$f = fabrement()->fields( $id );
            \$f->set( 'stage', 'new' )->save();
            \$before = \$f->label( 'stage' );
            \$f->clear( 'stage' );
            return [ \$before, \$f->label( 'stage' ) ];" ) );
    }

    public function test_generated_code_only_reads(): void {
        $id = $this->record( self::TYPE, 'Generated' );
        $this->site()->write( '/var/www/html/wp-content/qa-generated.php', "<?php\nreturn static function () { fabrement()->fields( $id )->set( 'title', 'From a template' )->save(); };\n" );
        $out = $this->attempt( '( require WP_CONTENT_DIR . "/qa-generated.php" )(); return "written";' );
        $this->assertStringContainsString( 'only reads fields', (string) ( $out['thrown'] ?? '' ) );
        $this->assertFalse( $this->site()->has_meta( $id, 'fab_title' ) );
        $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/qa-generated.php' );
    }

    // ── fabrement_file() ─────────────────────────────────────────────────────────────────────

    public function test_fabrement_file_reads_an_attachment_by_its_id(): void {
        $pdf = (int) $this->mcp( 'upload-media', [ 'base64' => base64_encode( '%PDF-1.4 ' . str_repeat( 'x', 2048 ) ), 'filename' => 'qa-brochure.pdf', 'title' => 'Brochure' ] )['id'];
        $file = $this->site_runs( "return [ 'file' => fabrement_file( $pdf ), 'url' => wp_get_attachment_url( $pdf ),
            'block' => fabrement_file( [ 'id' => $pdf, 'url' => 'https://old-domain.example/old.pdf', 'filename' => 'old.pdf', 'fileSize' => 1, 'mimeType' => 'x', 'label' => 'Download the brochure' ] ) ];" );
        $this->assertSame( $pdf, $file['file']['id'] );
        $this->assertSame( 'qa-brochure.pdf', $file['file']['filename'] );
        $this->assertSame( 'PDF', $file['file']['ext'] );
        $this->assertGreaterThan( 2000, $file['file']['size'] );
        $this->assertNotSame( '', $file['file']['sizeLabel'] );
        $this->assertSame( 'application/pdf', $file['file']['mimeType'] );
        $this->assertSame( $file['url'], $file['file']['url'] );
        $this->assertSame( $file['url'], $file['block']['url'], 'a block value is read by its id, not by what it stored' );
        $this->assertSame( 'qa-brochure.pdf', $file['block']['filename'] );
        $this->assertSame( 'Download the brochure', $file['block']['label'], 'its label is kept' );
    }

    public function test_fabrement_file_without_an_attachment(): void {
        $gone = (int) $this->mcp( 'upload-media', [ 'base64' => base64_encode( '%PDF-1.4 gone' ), 'filename' => 'gone.pdf' ] )['id'];
        $this->mcp( 'delete-media', [ 'mediaId' => $gone ] );
        $this->assertSame( [
            'snapshot' => [ 0, 'guide.zip', 'ZIP', 5 ],
            'nothing'  => [ null, null, null, null ],
            'gone'     => null,
            'goneUrl'  => 'https://x.example/g.pdf',
        ], $this->site_runs( "\$s = fabrement_file( [ 'id' => 0, 'url' => 'https://cdn.example/files/guide.zip', 'fileSize' => 5 ] );
            return [
                'snapshot' => [ \$s['id'], \$s['filename'], \$s['ext'], \$s['size'] ],
                'nothing'  => [ fabrement_file( 0 ), fabrement_file( null ), fabrement_file( [] ), fabrement_file( [ 'id' => 0, 'url' => '' ] ) ],
                'gone'     => fabrement_file( $gone ),
                'goneUrl'  => fabrement_file( [ 'id' => $gone, 'url' => 'https://x.example/g.pdf' ] )['url'],
            ];" ) );
    }
}
