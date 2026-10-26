<?php

use Fabrement\Modules\Fields\Schema\StorageLayout;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Translation\Bridge\NullBridge;
use Fabrement\Modules\Translation\Translation;
use PHPUnit\Framework\TestCase;

final class FieldValueCodecTest extends TestCase {

    protected function setUp(): void {
        \Fabrement\Tests\WordPressState::reset();
    }

    private const ROW_A = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ROW_B = 'row_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private static function field( string $key, string $type, array $extra = [] ): array {
        return $extra + [ 'key' => $key, 'name' => substr( $key, 0, strrpos( $key, '_' ) ), 'label' => $key, 'type' => $type, 'required' => false ];
    }

    private static function milestones( bool $quote_retired = false ): array {
        return self::field( 'milestones_r4t8w1', 'repeater', [ 'sub_fields' => [
            self::field( 'title_h3n5c2', 'text' ),
            self::field( 'quote_p9d2x7', 'text', $quote_retired ? [ 'retired' => true ] : [] ),
        ] ] );
    }

    public function test_default_and_explicit_empty_are_distinct(): void {
        $number = self::field( 'n_a1b2c3', 'number', [ 'default' => 42, 'format' => 'decimal' ] );
        self::assertSame( 42, ValueCodec::empty_value( $number ) );
        self::assertNull( ValueCodec::normalize( $number, null ) );
        self::assertSame( 0, ValueCodec::normalize( $number, 0 ) );
    }

    /** @dataProvider invalid_values */
    public function test_rejects_invalid_values( string $type, $value, array $extra ): void {
        $this->expectException( InvalidArgumentException::class );
        ValueCodec::normalize( self::field( 'v_a1b2c3', $type, $extra + [ 'format' => 'decimal' ] ), $value );
    }

    public function invalid_values(): array {
        return [
            [ 'number', '1.25', [] ], [ 'number', INF, [] ], [ 'number', 1.25, [ 'format' => 'integer' ] ],
            [ 'number', 0, [ 'min' => 1 ] ], [ 'number', null, [ 'required' => true ] ],
            [ 'number', 12345678901234567890, [ 'format' => 'integer' ] ], [ 'number', 9007199254740992, [] ], [ 'number', -1e20, [] ],
            [ 'true_false', 'false', [] ], [ 'date_picker', '2025-02-29', [] ],
            [ 'date_time_picker', '2026-09-22T24:00', [] ],
            [ 'select', 'other', [ 'choices' => [ [ 'value' => 'one', 'label' => 'One' ] ] ] ],
            [ 'repeater', [ [ 'id' => 'not-an-id', 'values' => [] ] ], [ 'sub_fields' => [] ] ],
            [ 'repeater', [ [ 'id' => self::ROW_A, 'values' => [] ], [ 'id' => self::ROW_A, 'values' => [] ] ], [ 'sub_fields' => [] ] ],
            [ 'link', [ 'url' => '//evil.example' ], [] ],
            // Protocol-relative in disguise: a browser opens each of these on another site.
            [ 'link', [ 'url' => '/\\/evil.example' ], [] ],   // cleaned to "//evil.example"
            [ 'link', [ 'url' => '/\\evil.example' ], [] ],    // "\" is read as "/"
            [ 'link', [ 'url' => '\\\\evil.example' ], [] ],
            [ 'link', [ 'url' => '/\\\\evil.example' ], [] ],
            [ 'link', [ 'url' => "/\t/evil.example" ], [] ],   // whitespace inside
            [ 'link', [ 'url' => 'javascript:alert(1)' ], [] ],
            [ 'link', [ 'url' => '/\\новини/evil.example' ], [] ], // still "\" read as "/" once encoded
        ];
    }

    public function test_repeater_rows_carry_their_identity_in_meta(): void {
        $field = self::milestones();
        $value = [
            [ 'id' => self::ROW_A, 'values' => [ 'title_h3n5c2' => 'Discovery', 'quote_p9d2x7' => 'Fast' ] ],
            [ 'id' => self::ROW_B, 'values' => [ 'title_h3n5c2' => 'Launch', 'quote_p9d2x7' => '' ] ],
        ];
        $meta = [];
        ValueCodec::flatten( $field, $value, 'milestones_r4t8w1', $meta );
        self::assertSame( [
            'milestones_r4t8w1'                => '2',
            'milestones_r4t8w1_0__rid'         => self::ROW_A,
            'milestones_r4t8w1_0_title_h3n5c2' => 'Discovery',
            'milestones_r4t8w1_0_quote_p9d2x7' => 'Fast',
            'milestones_r4t8w1_1__rid'         => self::ROW_B,
            'milestones_r4t8w1_1_title_h3n5c2' => 'Launch',
            'milestones_r4t8w1_1_quote_p9d2x7' => null,
        ], $meta );
        self::assertSame( $value, ValueCodec::inflate( $field, 'milestones_r4t8w1', $meta ) );
        foreach ( array_keys( $meta ) as $key ) {
            self::assertSame( 1, preg_match( StorageLayout::owned_pattern( $field ), $key ), $key );
        }
    }

    public function test_retired_values_move_with_their_row_on_reorder(): void {
        $meta = [
            'milestones_r4t8w1'                => '2',
            'milestones_r4t8w1_0__rid'         => self::ROW_A,
            'milestones_r4t8w1_0_title_h3n5c2' => 'Discovery',
            'milestones_r4t8w1_0_quote_p9d2x7' => 'Quote A',
            'milestones_r4t8w1_1__rid'         => self::ROW_B,
            'milestones_r4t8w1_1_title_h3n5c2' => 'Launch',
            'milestones_r4t8w1_1_quote_p9d2x7' => 'Quote B',
        ];
        $field = self::milestones( true );
        $previous = ValueCodec::inflate( $field, 'milestones_r4t8w1', $meta );
        // The editor no longer sees `quote`; it swaps the rows and edits a title.
        $next = ValueCodec::normalize( $field, [
            [ 'id' => self::ROW_B, 'values' => [ 'title_h3n5c2' => 'Launch!' ] ],
            [ 'id' => self::ROW_A, 'values' => [ 'title_h3n5c2' => 'Discovery' ] ],
        ] );
        $stored = [];
        ValueCodec::flatten( $field, ValueCodec::with_retired( $field, $next, $previous ), 'milestones_r4t8w1', $stored );
        self::assertSame( self::ROW_B, $stored['milestones_r4t8w1_0__rid'] );
        self::assertSame( 'Quote B', $stored['milestones_r4t8w1_0_quote_p9d2x7'] );
        self::assertSame( 'Quote A', $stored['milestones_r4t8w1_1_quote_p9d2x7'] );

        $public = ValueCodec::public_value( $field, ValueCodec::inflate( $field, 'milestones_r4t8w1', $stored ) );
        self::assertSame( [ [ 'title' => 'Launch!' ], [ 'title' => 'Discovery' ] ], $public );
    }

    public function test_rows_without_a_stored_identity_get_a_stable_derived_one(): void {
        $meta = [ 'milestones_r4t8w1' => '2', 'milestones_r4t8w1_0_title_h3n5c2' => 'A', 'milestones_r4t8w1_1__rid' => 'garbage' ];
        $rows = ValueCodec::inflate( self::milestones(), 'milestones_r4t8w1', $meta );
        self::assertSame( StorageLayout::derived_row_id( 'milestones_r4t8w1', 0 ), $rows[0]['id'] );
        self::assertSame( $rows, ValueCodec::inflate( self::milestones(), 'milestones_r4t8w1', $meta ) );
        self::assertNotSame( $rows[0]['id'], $rows[1]['id'] );
    }

    /** A row another plugin or an import wrote: what is not an id reads as "none", never as item 1. */
    public function test_a_stored_item_that_is_not_an_id_reads_as_none(): void {
        foreach ( [ 'image', 'file', 'post_object' ] as $type ) {
            $field = self::field( 'pick_k1m2n3', $type );
            $read  = static fn( $raw ) => ValueCodec::inflate( $field, 'fab_pick', [ 'fab_pick' => $raw ] );
            self::assertSame( 42, $read( '42' ), $type );
            self::assertSame( 42, $read( 42 ), $type );
            self::assertSame( 0, $read( '' ), $type );
            self::assertSame( 0, $read( '-5' ), $type );
            self::assertSame( 0, $read( [ 'id' => 5 ] ), "$type: a keyed array is not item 1" );
            self::assertSame( 7, $read( [ '7', '8' ] ), "$type: written twice under one key, the first row — as it always read" );
            self::assertSame( 0, $read( [ [ 'id' => 5 ], '8' ] ), "$type: the first row an array is not item 1" );
            self::assertSame( 0, $read( new \stdClass() ), $type );
            self::assertSame( 0, $read( true ), "$type: true is not item 1" );
        }
    }

    public function test_a_new_row_gets_a_short_identity_and_an_older_long_one_stays_valid(): void {
        $field = self::milestones();
        $new   = ValueCodec::from_public( $field, [ [ 'title' => 'New' ] ], [] )[0]['id'];
        self::assertMatchesRegularExpression( '/^row_[a-f0-9]{12}$/D', $new );
        self::assertNotSame( $new, StorageLayout::new_row_id() );

        $long = 'row_' . str_repeat( 'c', 32 );
        foreach ( [ $new => 1, $long => 1, 'row_' . str_repeat( 'c', 16 ) => 0, 'row_' . str_repeat( 'C', 12 ) => 0, 'row_title' => 0 ] as $id => $valid ) {
            self::assertSame( $valid, preg_match( StorageLayout::ROW_ID_RE, (string) $id ), (string) $id );
        }
        // A stored row keeps the identity it has, whichever length.
        $previous = [ [ 'id' => $long, 'values' => [ 'title_h3n5c2' => 'Old' ] ] ];
        $kept     = ValueCodec::from_public( $field, [ [ '_row' => $long, 'title' => 'Old, edited' ], [ 'title' => 'Added' ] ], $previous );
        self::assertSame( $long, $kept[0]['id'] );
        self::assertMatchesRegularExpression( '/^row_[a-f0-9]{12}$/D', $kept[1]['id'] );
    }

    public function test_rows_without_any_identity_replace_the_stored_rows(): void {
        $field = self::milestones();
        $previous = [
            [ 'id' => self::ROW_A, 'values' => [ 'title_h3n5c2' => 'A', 'quote_p9d2x7' => 'qa' ] ],
            [ 'id' => self::ROW_B, 'values' => [ 'title_h3n5c2' => 'B', 'quote_p9d2x7' => 'qb' ] ],
        ];
        // No position is guessed: "B, C" could be "A removed" or "B removed and A renamed".
        $editor = ValueCodec::from_public( $field, [ [ 'title' => 'B2' ], [ 'title' => 'C' ] ], $previous );
        self::assertCount( 2, $editor );
        foreach ( $editor as $row ) {
            self::assertMatchesRegularExpression( StorageLayout::ROW_ID_RE, $row['id'] );
            self::assertNotContains( $row['id'], [ self::ROW_A, self::ROW_B ], 'Every row is new.' );
        }
        self::assertSame( '', $editor[0]['values']['quote_p9d2x7'] ?? '', 'A new row starts empty: nothing carried from the row once in its place.' );
    }

    public function test_a_row_named_by_its_identity_keeps_what_was_left_out(): void {
        $field = self::milestones();
        $previous = [
            [ 'id' => self::ROW_A, 'values' => [ 'title_h3n5c2' => 'A', 'quote_p9d2x7' => 'qa' ] ],
        ];
        $editor = ValueCodec::from_public( $field, [ [ '_row' => self::ROW_A, 'title' => 'A2' ] ], $previous );
        self::assertSame( self::ROW_A, $editor[0]['id'] );
        self::assertSame( 'qa', $editor[0]['values']['quote_p9d2x7'], 'A sub-field left out keeps its stored value.' );
    }

    public function test_rows_sent_with_their_identity_keep_it_when_inserted_or_reordered(): void {
        $field = self::milestones();
        $previous = [
            [ 'id' => self::ROW_A, 'values' => [ 'title_h3n5c2' => 'A', 'quote_p9d2x7' => 'qa' ] ],
            [ 'id' => self::ROW_B, 'values' => [ 'title_h3n5c2' => 'B', 'quote_p9d2x7' => 'qb' ] ],
        ];
        $public = ValueCodec::public_value( $field, $previous, true );
        self::assertSame( self::ROW_A, $public[0]['_row'] );
        self::assertArrayNotHasKey( '_row', ValueCodec::public_value( $field, $previous )[0], 'Templates never see identities.' );

        // A new row on top, then B, then A: identities follow the rows, not the positions.
        $editor = ValueCodec::from_public( $field, [ [ 'title' => 'New' ], $public[1], $public[0] ], $previous );
        self::assertNotContains( $editor[0]['id'], [ self::ROW_A, self::ROW_B ] );
        self::assertSame( [ self::ROW_B, self::ROW_A ], [ $editor[1]['id'], $editor[2]['id'] ] );
        self::assertSame( 'qb', $editor[1]['values']['quote_p9d2x7'] );

        foreach ( [ [ [ '_row' => 'row_' . str_repeat( 'f', 32 ) ] ], [ $public[0], $public[0] ] ] as $bad ) {
            try {
                ValueCodec::from_public( $field, $bad, $previous, 'milestones' );
                self::fail( 'Accepted a foreign or repeated _row.' );
            } catch ( InvalidArgumentException $e ) {
                self::assertStringContainsString( '._row', $e->getMessage() );
            }
        }
    }

    public function test_scalars_round_trip_through_meta(): void {
        $cases = [
            [ self::field( 'n_a1b2c3', 'number', [ 'format' => 'decimal' ] ), 0.1 + 0.2 ],
            [ self::field( 'n_a1b2c3', 'number', [ 'format' => 'integer' ] ), 42 ],
            [ self::field( 'n_a1b2c3', 'number', [ 'format' => 'decimal' ] ), null ],
            [ self::field( 'b_a1b2c3', 'true_false' ), false ],
            [ self::field( 'r_a1b2c3', 'relationship' ), [ 3, 1, 2 ] ],
            [ self::field( 'g_a1b2c3', 'group', [ 'sub_fields' => [ self::field( 'city_c8w2q4', 'text' ) ] ] ), [ 'city_c8w2q4' => 'Kyiv' ] ],
        ];
        foreach ( $cases as [ $field, $value ] ) {
            $meta = [];
            ValueCodec::flatten( $field, $value, $field['key'], $meta );
            self::assertSame( $value, ValueCodec::inflate( $field, $field['key'], $meta ), json_encode( $value ) );
        }
    }

    public function test_what_the_record_holds_is_not_rejudged_by_todays_rules(): void {
        $select = self::field( 'stage_a1b2c3', 'select', [ 'choices' => [ [ 'value' => 'new', 'label' => 'New' ] ] ] );
        $number = self::field( 'qty_d4e5f6', 'number', [ 'format' => 'integer', 'min' => 10 ] );
        $text = self::field( 'title_g7h8j9', 'text', [ 'required' => true ] );
        // Stored before the choice was removed, the minimum raised or the field made required.
        self::assertSame( 'old', ValueCodec::normalize( $select, 'old', '', [ 'old' ] ) );
        self::assertSame( 3, ValueCodec::normalize( $number, 3, '', [ 3.0 ] ) );
        self::assertSame( '', ValueCodec::normalize( $text, '', '', [ '' ] ) );
        self::assertSame( 0, ValueCodec::normalize( self::field( 'ref_k1m2n3', 'post_object' ), 0, '', [ 0 ] ) );
        // A kept record id is not looked up again (it may be in the trash by now).
        self::assertSame( 987654, ValueCodec::normalize( self::field( 'ref_k1m2n3', 'post_object' ), 987654, '', [ 987654 ] ) );
        // A change is judged in full.
        foreach ( [ [ $select, 'other', [ 'old' ] ], [ $number, 4, [ 3 ] ], [ $text, '', [ 'Hello' ] ], [ $select, 'old', null ] ] as [ $field, $value, $was ] ) {
            try {
                ValueCodec::normalize( $field, $value, '', $was );
                self::fail( 'Accepted ' . json_encode( $value ) . ' for ' . $field['key'] );
            } catch ( InvalidArgumentException $e ) {
                self::assertNotSame( '', $e->getMessage() );
            }
        }
        // The shape is always checked, kept or not.
        $this->expectException( InvalidArgumentException::class );
        ValueCodec::normalize( $number, '3', '', [ '3' ] );
    }

    public function test_kept_values_inside_groups_and_rows_follow_their_place(): void {
        $choices = [ 'choices' => [ [ 'value' => 'new', 'label' => 'New' ] ] ];
        $group = self::field( 'details_a1b2c3', 'group', [ 'sub_fields' => [ self::field( 'city_d4e5f6', 'text' ), self::field( 'stage_g7h8j9', 'select', $choices ) ] ] );
        $stored = [ 'city_d4e5f6' => 'Kyiv', 'stage_g7h8j9' => 'old' ];
        self::assertSame( [ 'city_d4e5f6' => 'Odesa', 'stage_g7h8j9' => 'old' ], ValueCodec::normalize( $group, [ 'city_d4e5f6' => 'Odesa', 'stage_g7h8j9' => 'old' ], '', [ $stored ] ) );

        $rows = self::field( 'steps_k1m2n3', 'repeater', [ 'sub_fields' => [ self::field( 'stage_g7h8j9', 'select', $choices ) ] ] );
        $before = [ [ 'id' => self::ROW_A, 'values' => [ 'stage_g7h8j9' => 'old' ] ] ];
        $moved = [ [ 'id' => self::ROW_B, 'values' => [ 'stage_g7h8j9' => 'new' ] ], $before[0] ];
        self::assertSame( $moved, ValueCodec::normalize( $rows, $moved, '', [ $before ] ) );
        // The same stale value in a NEW row is refused.
        $this->expectException( InvalidArgumentException::class );
        ValueCodec::normalize( $rows, [ $before[0], [ 'id' => self::ROW_B, 'values' => [ 'stage_g7h8j9' => 'old' ] ] ], '', [ $before ] );
    }

    public function test_an_out_of_range_count_may_only_stay_or_shrink_toward_the_limit(): void {
        $rows = self::field( 'steps_k1m2n3', 'repeater', [ 'max' => 1, 'sub_fields' => [ self::field( 't_a1b2c3', 'text' ) ] ] );
        $row = static fn( string $id ): array => [ 'id' => $id, 'values' => [ 't_a1b2c3' => 'x' ] ];
        $c = 'row_cccccccccccccccccccccccccccccccc';
        $stored = [ $row( self::ROW_A ), $row( self::ROW_B ), $row( $c ) ];
        self::assertCount( 3, ValueCodec::normalize( $rows, $stored, '', [ $stored ] ) );
        self::assertCount( 2, ValueCodec::normalize( $rows, [ $row( $c ), $row( self::ROW_A ) ], '', [ $stored ] ) );
        $new = 'row_dddddddddddddddddddddddddddddddd';
        foreach ( [ [ $row( self::ROW_A ), $row( self::ROW_B ), $row( $new ) ], [ $row( $new ), $row( self::ROW_B ) ] ] as $bad ) {
            try {
                ValueCodec::normalize( $rows, $bad, '', [ $stored ] );
                self::fail( 'A new row was added over the limit.' );
            } catch ( InvalidArgumentException $e ) {
                self::assertStringContainsString( 'at most 1', $e->getMessage() );
            }
        }
        $related = self::field( 'rel_e4f5g6', 'relationship', [ 'max' => 1 ] );
        self::assertSame( [ 7, 5 ], ValueCodec::normalize( $related, [ 7, 5 ], '', [ [ 5, 6, 7 ] ] ) );
    }

    public function test_numbers_compare_by_value(): void {
        self::assertFalse( ValueCodec::same( 'number', 9007199254740993, 9007199254740992 ) );
        self::assertTrue( ValueCodec::same( 'number', 2, 2.0 ) );
        self::assertFalse( ValueCodec::same( 'number', 2, null ) );
        self::assertFalse( ValueCodec::same( 'text', '2', 2 ) );
    }

    public function test_safe_link_addresses_are_kept(): void {
        $link = self::field( 'cta_a1b2c3', 'link' );
        foreach ( [
            '/contacts'              => '/contacts',
            '/'                      => '/',
            '#top'                   => '#top',
            '/a/b?x=1#c'             => '/a/b?x=1#c',
            '/%2F%2Fnot-a-host'      => '/%2F%2Fnot-a-host', // encoded slashes stay a path here
            'https://example.com/x'  => 'https://example.com/x',
            'mailto:hi@example.com'  => 'mailto:hi@example.com',
            ''                       => '',
            // Letters outside ASCII are percent-encoded as a browser sends them, never dropped.
            '/uk/новини/'            => '/uk/%D0%BD%D0%BE%D0%B2%D0%B8%D0%BD%D0%B8/',
            '#розділ'                => '#%D1%80%D0%BE%D0%B7%D0%B4%D1%96%D0%BB',
            '/uk/новини/?q=так'      => '/uk/%D0%BD%D0%BE%D0%B2%D0%B8%D0%BD%D0%B8/?q=%D1%82%D0%B0%D0%BA',
        ] as $url => $stored ) {
            self::assertSame( $stored, ValueCodec::normalize( $link, [ 'url' => $url ] )['url'], $url );
        }
    }

    public function test_link_language_must_be_one_of_the_sites(): void {
        $link = self::field( 'cta_a1b2c3', 'link' );
        // A single-language site: any pinned language is one it does not have.
        Translation::set( new NullBridge() );
        try {
            ValueCodec::normalize( $link, [ 'url' => '/x', 'lang' => 'de' ] );
            self::fail( 'A language the site does not have was accepted.' );
        } catch ( InvalidArgumentException $e ) {
            self::assertStringContainsString( 'one language', $e->getMessage() );
        }
        // "" follows the reader; a language already held is kept, whatever the site has now.
        self::assertSame( '', ValueCodec::normalize( $link, [ 'url' => '/x', 'lang' => '' ] )['lang'] );
        $held = [ 'url' => '/x', 'label' => '', 'postId' => 0, 'lang' => 'de', 'newTab' => false, 'useRecordTitle' => false ];
        self::assertSame( 'de', ValueCodec::normalize( $link, $held, '', [ $held ] )['lang'] );
        Translation::set( null );
    }

    public function test_min_of_a_list_is_not_enforced_max_is(): void {
        $gallery = [ 'key' => 'fab_photos', 'name' => 'photos', 'label' => 'Photos', 'type' => 'gallery', 'translation' => 'copy', 'required' => false, 'min' => 3, 'max' => 4 ];
        // One short list must not block the save: a gallery filled one image at a time, a draft.
        self::assertSame( [], ValueCodec::normalize( $gallery, [] ) );
        $repeater = [ 'key' => 'fab_steps', 'name' => 'steps', 'label' => 'Steps', 'type' => 'repeater', 'translation' => 'copy', 'required' => false, 'min' => 3, 'sub_fields' => [] ];
        $row = [ 'id' => 'row_' . str_repeat( 'a', 32 ), 'values' => [] ];
        self::assertCount( 1, ValueCodec::normalize( $repeater, [ $row ] ) );
        $this->expectException( \InvalidArgumentException::class );
        ValueCodec::normalize( [ 'max' => 1 ] + $repeater, [ $row, [ 'id' => 'row_' . str_repeat( 'b', 32 ), 'values' => [] ] ] );
    }

    public function test_a_picked_item_is_cleared_with_null_and_a_sent_zero_is_refused(): void {
        $field = self::field( 'cover_k1m2n3', 'image' );
        self::assertSame( 0, ValueCodec::from_public( $field, null, 0 ), 'null clears' );
        try {
            ValueCodec::from_public( $field, 0, 0, 'cover' );
            self::fail( 'a sent 0 is no id' );
        } catch ( \Fabrement\Modules\Fields\Values\FieldValueError $e ) {
            self::assertSame( 'cover: cover_k1m2n3: Send null to clear it; 0 is not an ID.', $e->getMessage() );
        }
        self::assertSame( 0, ValueCodec::normalize( $field, 0 ), 'the stored empty value itself stays valid' );
    }

    public function test_numbers_up_to_two_to_the_53_are_kept_exactly(): void {
        $field = [ 'key' => 'fab_n', 'name' => 'n', 'label' => 'N', 'type' => 'number', 'format' => 'integer', 'translation' => 'copy', 'required' => false ];
        self::assertSame( 9007199254740991, ValueCodec::normalize( $field, 9007199254740991 ) );
        self::assertSame( -9007199254740991, ValueCodec::normalize( $field, -9007199254740991 ) );
    }
}
