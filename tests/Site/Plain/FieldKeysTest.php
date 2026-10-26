<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\FieldRules;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Post types share one meta table, so a field's key (fab_venue_city) means the same field on every
 * post type: an agent defining fields on several types is held to it, retired fields included,
 * while options pages keep their own rows. The SEO variables named after the keys (%%fab_…%%,
 * %%fabopt_<page>__<key>%%) are what a visitor reads in the <title>.
 *
 * Each test uses field names of its own: the rule is site-wide, and the tests share the site.
 */
final class FieldKeysTest extends SiteTestCase {

    private const TYPES = [ 'qa_ka', 'qa_kb', 'qa_kc' ];

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            foreach ( self::TYPES as $type ) {
                $this->post_type( $type );
            }
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    private static function group( string $name, array $children ): array {
        return self::field( $name, 'group', [ 'sub_fields' => $children ] );
    }

    private static function repeater( string $name, array $children ): array {
        return self::field( $name, 'repeater', [ 'sub_fields' => $children ] );
    }

    /** The fields the type has now plus $more, as an agent adds fields to a type. */
    private function adding( string $type, array $more ): array {
        $current = array_values( array_filter( $this->definitions( $type ), static fn( array $f ): bool => empty( $f['retired'] ) ) );
        return array_merge( $current, $more );
    }

    /** A save-fields the site refuses; the type's fields and its file are exactly as before. */
    private function refused_fields( string $type, array $fields ): string {
        $before = $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => $type ] );
        $file   = FieldRules::field_file_exists( $this->site(), 'post', $type );
        $error  = $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => $type, 'expectedRevision' => (int) ( $before['revision'] ?? 0 ), 'fields' => $fields ] );
        $this->assertSame( $before, $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => $type ] ), 'nothing saved' );
        $this->assertSame( $file, FieldRules::field_file_exists( $this->site(), 'post', $type ), 'the field file neither made nor removed' );
        return html_entity_decode( $error, ENT_QUOTES );
    }

    private function url( int $id ): string {
        return $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    private function title_of( int $id ): string {
        $page = $this->get( $this->url( $id ) );
        $this->assertSame( 200, $page['status'] );
        return FieldRules::title( $page['body'] );
    }

    // ── one key, one field ───────────────────────────────────────────────────────────────────

    public function test_a_group_child_and_a_field_named_like_its_key_cannot_live_on_two_types(): void {
        $this->fields( 'qa_ka', [ self::group( 'venue', [ self::field( 'city', 'text' ) ] ) ] );
        $event = $this->record( 'qa_ka', 'QA event in Lviv' );
        $this->mcp( 'set-field-values', [ 'postId' => $event, 'values' => [ 'venue' => [ 'city' => 'Lviv' ] ] ] );
        $this->assertSame( [ 'Lviv' ], $this->site()->meta( $event, 'fab_venue_city' ) );

        $error = $this->refused_fields( 'qa_kb', [ self::field( 'venue_city', 'text' ) ] );
        $this->assertStringContainsString( 'fab_venue_city', $error );
        $this->assertStringContainsString( 'venue.city', $error, 'names what already holds the key' );
        $this->assertStringContainsString( 'qa_ka', $error, 'and on which type' );
        $this->assertFalse( FieldRules::field_file_exists( $this->site(), 'post', 'qa_kb' ), 'the second type got no field file' );

        // The other way round: a plain field first, then a group whose child lands on its key.
        $this->fields( 'qa_kc', [ self::field( 'place_town', 'text' ) ] );
        $this->assertStringContainsString( 'fab_place_town', $this->refused_fields( 'qa_kb', [ self::group( 'place', [ self::field( 'town', 'text' ) ] ) ] ) );

        // The same group on the second type — even with a child of its own — is the same field.
        $talks = $this->fields( 'qa_kb', [ self::group( 'venue', [ self::field( 'city', 'text' ), self::field( 'hall', 'text' ) ] ) ] );
        $this->assertSame( [ 'city', 'hall' ], array_column( $talks['venue']['sub_fields'], 'name' ) );
        $talk = $this->record( 'qa_kb', 'QA talk in Kyiv' );
        $this->mcp( 'set-field-values', [ 'postId' => $talk, 'values' => [ 'venue' => [ 'city' => 'Kyiv', 'hall' => 'B' ] ] ] );
        $this->assertSame( [ 'Kyiv' ], $this->site()->meta( $talk, 'fab_venue_city' ), 'one key, read the same on both types' );
        $this->assertSame( 'Lviv', $this->mcp( 'get-field-values', [ 'postId' => $event ] )['values']['venue']['city'], 'the first type\'s value untouched' );

        // A third type with the child as another type of field is refused.
        $this->assertStringContainsString( 'fab_venue_city', $this->refused_fields( 'qa_kc', $this->adding( 'qa_kc', [ self::group( 'venue', [ self::field( 'city', 'number' ) ] ) ] ) ) );
        $this->assertArrayNotHasKey( 'venue', $this->definitions( 'qa_kc' ) );
    }

    public function test_a_retired_field_keeps_its_key_while_its_values_are_stored(): void {
        $this->fields( 'qa_ka', $this->adding( 'qa_ka', [ self::field( 'badge_code', 'text' ), self::field( 'fee', 'number' ) ] ) );
        $record = $this->record( 'qa_ka', 'QA badge holder' );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'badge_code' => 'A-1' ] ] );

        // The agent drops the field: it is retired, its rows stay.
        $kept = array_values( array_filter( $this->adding( 'qa_ka', [] ), static fn( array $f ): bool => $f['name'] !== 'badge_code' ) );
        $this->fields( 'qa_ka', $kept );
        $this->assertTrue( $this->definitions( 'qa_ka' )['badge_code']['retired'] ?? false, 'kept in the file as retired' );
        $this->assertArrayNotHasKey( 'badge_code', $this->mcp( 'get-field-values', [ 'postId' => $record ] )['values'], 'no longer offered' );
        $this->assertSame( [ 'A-1' ], $this->site()->meta( $record, 'fab_badge_code' ), 'its value is still in the database' );

        $error = $this->refused_fields( 'qa_kb', $this->adding( 'qa_kb', [ self::group( 'badge', [ self::field( 'code', 'text' ) ] ) ] ) );
        $this->assertStringContainsString( 'fab_badge_code', $error, 'the retired field\'s rows are still stored under the key' );
    }

    public function test_a_top_level_name_on_two_types_keeps_its_type(): void {
        $this->fields( 'qa_ka', $this->adding( 'qa_ka', [ self::field( 'price', 'number' ) ] ) );
        $this->fields( 'qa_kb', $this->adding( 'qa_kb', [ self::field( 'price', 'number' ) ] ) );
        $this->assertSame( 'number', $this->definitions( 'qa_kb' )['price']['type'], 'the same field on a second type' );

        $error = $this->refused_fields( 'qa_kc', $this->adding( 'qa_kc', [ self::field( 'price', 'text' ) ] ) );
        $this->assertStringContainsString( 'number', $error, 'says what the name already is' );
    }

    public function test_a_repeaters_children_keep_their_types_on_every_type_retired_ones_too(): void {
        $agenda = static fn( string $time, array $more = [] ): array => self::repeater( 'agenda', array_merge( [ self::field( 'time', $time ) ], $more ) );
        $this->fields( 'qa_ka', $this->adding( 'qa_ka', [ $agenda( 'text' ) ] ) );
        $this->fields( 'qa_kb', $this->adding( 'qa_kb', [ $agenda( 'text', [ self::field( 'topic', 'text' ) ] ) ] ) );
        $this->assertSame( [ 'time', 'topic' ], array_column( $this->definitions( 'qa_kb' )['agenda']['sub_fields'], 'name' ), 'a child only one type has is fine' );

        $record = $this->record( 'qa_kb', 'QA agenda' );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'agenda' => [ [ 'time' => '10:00', 'topic' => 'Opening' ] ] ] ] );
        $this->assertSame( [ '10:00' ], $this->site()->meta( $record, 'fab_agenda_0_time' ), 'the row keys both types write' );

        $error = $this->refused_fields( 'qa_kc', $this->adding( 'qa_kc', [ $agenda( 'number' ) ] ) );
        $this->assertStringContainsString( 'agenda[].time', $error );
        $this->assertStringContainsString( 'fab_agenda_{n}_time', $error );

        // A group inside a repeater is held to it too.
        $room = static fn( string $type ): array => self::repeater( 'sessions', [ self::group( 'room', [ self::field( 'floor', $type ) ] ) ] );
        $this->fields( 'qa_ka', $this->adding( 'qa_ka', [ $room( 'number' ) ] ) );
        $this->refused_fields( 'qa_kb', $this->adding( 'qa_kb', [ $room( 'text' ) ] ) );

        // A child the agent removed is retired and still holds its type.
        $this->fields( 'qa_ka', $this->adding( 'qa_ka', [ self::repeater( 'steps', [ self::field( 'label', 'text' ), self::field( 'note', 'text' ) ] ) ] ) );
        $with_steps = $this->adding( 'qa_ka', [] );
        foreach ( $with_steps as $i => $field ) {
            if ( $field['name'] === 'steps' ) {
                $with_steps[ $i ]['sub_fields'] = array_values( array_filter( $field['sub_fields'], static fn( array $c ): bool => $c['name'] === 'label' ) );
            }
        }
        $this->fields( 'qa_ka', $with_steps );
        $steps = array_column( $this->definitions( 'qa_ka' )['steps']['sub_fields'], null, 'name' );
        $this->assertTrue( $steps['note']['retired'] ?? false, 'the removed child is kept as retired' );
        $this->refused_fields( 'qa_kb', $this->adding( 'qa_kb', [ self::repeater( 'steps', [ self::field( 'note', 'number' ) ] ) ] ) );
    }

    // ── options pages ────────────────────────────────────────────────────────────────────────

    public function test_options_pages_have_their_own_rows_and_their_variables_keep_the_page_apart(): void {
        $this->fields( 'qa_ka', $this->adding( 'qa_ka', [ self::group( 'hotel', [ self::field( 'city', 'text' ) ] ) ] ) );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-keys', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA keys' ], 'fields' => [ self::field( 'hotel_city', 'text' ) ] ] );
        $this->assertSame( 'text', array_column( $this->mcp( 'get-options-page', [ 'slug' => 'qa-keys' ] )['fields'], null, 'name' )['hotel_city']['type'], 'not held to the post types\' keys' );

        // Two pages whose page + field could read alike: qa-o / x_y and qa-o_x / y.
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-o', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA o' ], 'fields' => [ self::field( 'x_y', 'text' ) ] ] );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-o_x', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA o x' ], 'fields' => [ self::field( 'y', 'text' ) ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-o', 'values' => [ 'x_y' => 'from qa-o' ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-o_x', 'values' => [ 'y' => 'from qa-o_x' ] ] );

        $page = $this->record( 'page', 'QA options in a title' );
        $this->mcp( 'set-seo', [ 'contentId' => $page, 'title' => '%%fabopt_qa-o__x_y%% | %%fabopt_qa-o_x__y%%' ] );
        $this->assertSame( 'from qa-o | from qa-o_x', $this->title_of( $page ), 'each variable reads its own page' );

        $this->mcp( 'set-field-values', [ 'options' => 'qa-o', 'values' => [ 'x_y' => 'changed on qa-o' ] ] );
        $this->assertSame( 'changed on qa-o | from qa-o_x', $this->title_of( $page ), 'the next request reads the new value' );

        // A slug with "__" would make its variables ambiguous.
        $this->mcp_error( 'save-options-page', [ 'slug' => 'qa__bad', 'expectedRevision' => 0, 'page' => [ 'title' => 'x' ], 'fields' => [ self::field( 'a', 'text' ) ] ] );
        $this->assertFalse( FieldRules::field_file_exists( $this->site(), 'options', 'qa__bad' ) );
        $this->assertNotContains( 'qa__bad', array_column( $this->mcp( 'list-options-pages' )['pages'], 'slug' ) );
    }

    // ── SEO variables named after the key ────────────────────────────────────────────────────

    public function test_a_variable_reads_the_records_own_type_and_is_empty_where_the_type_lacks_the_field(): void {
        $level = static fn( string $label ): array => self::group( 'stage', [ self::field( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => $label ] ] ] ) ] );
        $this->fields( 'qa_ka', $this->adding( 'qa_ka', [ $level( 'For beginners' ) ] ) );
        $this->fields( 'qa_kb', $this->adding( 'qa_kb', [ $level( 'Starters' ) ] ) );
        foreach ( self::TYPES as $type ) {
            $this->mcp( 'set-seo-defaults', [ 'postType' => $type, 'titleTemplate' => 'Level %%fab_stage_level%% end' ] );
        }
        $a = $this->record( 'qa_ka', 'QA level a' );
        $b = $this->record( 'qa_kb', 'QA level b' );
        $c = $this->record( 'qa_kc', 'QA level c' );
        $this->mcp( 'set-field-values', [ 'postId' => $a, 'values' => [ 'stage' => [ 'level' => 'beginner' ] ] ] );
        $this->mcp( 'set-field-values', [ 'postId' => $b, 'values' => [ 'stage' => [ 'level' => 'beginner' ] ] ] );
        // An import wrote a stray row on a type that has no such field.
        $this->site_runs( "return update_post_meta( $c, 'fab_stage_level', 'beginner' );" );

        $this->assertSame( 'Level For beginners end', $this->title_of( $a ) );
        $this->assertSame( 'Level Starters end', $this->title_of( $b ), 'its own type\'s label' );
        $this->assertStringNotContainsString( 'beginner', strtolower( $this->title_of( $c ) ), 'no such field on this type: the stray row is not read' );
        $this->assertStringNotContainsString( 'Starters', $this->title_of( $c ) );
        $this->assertNull( $this->site_runs( "return \\Fabrement\\Modules\\Seo\\Content\\FieldText::for_variable('fab_stage_level', $c);" ), 'A stray row is not an available field.' );
        $this->assertSame( [ 'qa_ka', 'qa_kb' ], $this->site_runs( "return array_keys(\\Fabrement\\Modules\\Seo\\Content\\FieldText::names()['fab_stage_level']['by']);" ), 'The variable is registered for both defining post types.' );

        // The second type renames its choice: its pages say so on the next request, the first type's do not.
        $fields = $this->adding( 'qa_kb', [] );
        foreach ( $fields as $i => $field ) {
            if ( $field['name'] === 'stage' ) {
                $fields[ $i ]['sub_fields'][0]['choices'] = [ [ 'value' => 'beginner', 'label' => 'Newcomers' ] ];
            }
        }
        $this->fields( 'qa_kb', $fields );
        $this->assertSame( 'Level Newcomers end', $this->title_of( $b ) );
        $this->assertSame( 'Level For beginners end', $this->title_of( $a ) );

        // Cleared on the record: the variable is dropped.
        $this->mcp( 'set-field-values', [ 'postId' => $a, 'values' => [ 'stage' => null ] ] );
        $this->assertSame( [ null ], $this->site()->meta( $a, 'fab_stage_level' ), 'explicit clear retains SQL NULL' );
        $this->assertStringNotContainsString( 'beginners', $this->title_of( $a ) );
    }

    public function test_a_repeaters_row_count_and_a_field_named_count_are_two_variables(): void {
        $this->fields( 'qa_ka', $this->adding( 'qa_ka', [
            self::repeater( 'phases', [ self::field( 'label', 'text' ) ] ),
            self::field( 'phases_count', 'text' ),
        ] ) );
        $record = $this->record( 'qa_ka', 'QA phases' );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'phases' => [ [ 'label' => 'One' ], [ 'label' => 'Two' ] ], 'phases_count' => 'about ten' ] ] );
        $this->assertSame( [ '2' ], $this->site()->meta( $record, 'fab_phases' ), 'the repeater\'s row count row' );
        $this->assertSame( [ 'about ten' ], $this->site()->meta( $record, 'fab_phases_count' ) );

        $this->mcp( 'set-seo', [ 'contentId' => $record, 'title' => 'Rows %%fab_phases%% / %%fab_phases_count%%' ] );
        $this->assertSame( 'Rows 2 / about ten', $this->title_of( $record ) );

        $rows = $this->mcp( 'get-field-values', [ 'postId' => $record ] )['values']['phases'];
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'phases' => [ $rows[1] ] ] ] );
        $this->assertSame( 'Rows 1 / about ten', $this->title_of( $record ), 'a row removed: the count follows, the text field does not' );
    }
}
