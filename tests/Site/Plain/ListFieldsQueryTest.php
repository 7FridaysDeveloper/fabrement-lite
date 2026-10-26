<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\PageBundleTestCase;

/**
 * A list field (relationship, gallery) is a meta row per item, in order — so any query finds "the
 * list holds this one" with a plain = / IN — and still reads, writes and compares as one list. An
 * agent fills talks' speakers; the database keeps a row each; the site's code and the visitor's
 * archive read the list; a filter asks what a list holds and what it does not.
 */
final class ListFieldsQueryTest extends PageBundleTestCase {

    private const TYPE = 'qa_talk';

    /** @var array<string,int> name => page id */
    private static array $people = [];

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            self::$ready = true;
            $this->post_type( self::TYPE );
            $this->fields( self::TYPE, [
                self::field( 'speakers', 'relationship' ),
                self::field( 'photos', 'gallery' ),
                self::field( 'days', 'repeater', [ 'sub_fields' => [ self::field( 'title', 'text' ), self::field( 'guests', 'relationship' ) ] ] ),
            ] );
            foreach ( [ 'Ann', 'Bob', 'Cid', 'Dee' ] as $name ) {
                self::$people[ $name ] = $this->record( 'page', $name );
            }
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready  = false;
        self::$people = [];
        parent::tearDownAfterClass();
    }

    /** @param list<string> $names @return list<int> */
    private static function ids( array $names ): array {
        return array_map( static fn( string $n ): int => self::$people[ $n ], $names );
    }

    /** @param list<string> $names @return list<string> */
    private static function rows( array $names ): array {
        return array_map( 'strval', self::ids( $names ) );
    }

    private function talk( string $title, array $values ): int {
        $id = $this->record( self::TYPE, $title );
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
        return $id;
    }

    private function set( int $id, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] )['values'];
    }

    public function test_a_list_is_a_row_per_item_in_order_and_reads_back_as_the_list(): void {
        $id = $this->talk( 'Rows', [ 'speakers' => self::ids( [ 'Cid', 'Ann', 'Bob' ] ) ] );
        $this->assertSame( self::rows( [ 'Cid', 'Ann', 'Bob' ] ), $this->site()->meta( $id, 'fab_speakers' ), 'a row per item, in order' );
        $this->assertSame( self::ids( [ 'Cid', 'Ann', 'Bob' ] ), $this->site_runs( "return fabrement_get_field( 'speakers', $id );" ), 'the site\'s code reads the list' );
        $this->assertSame( self::ids( [ 'Cid', 'Ann', 'Bob' ] ), $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values']['speakers'] );

        $this->set( $id, [ 'speakers' => self::ids( [ 'Bob', 'Cid' ] ) ] );
        $this->assertSame( self::rows( [ 'Bob', 'Cid' ] ), $this->site()->meta( $id, 'fab_speakers' ), 'reordered and shrunk: the rows follow' );

        $this->set( $id, [ 'speakers' => self::ids( [ 'Dee' ] ) ] );
        $this->assertSame( self::rows( [ 'Dee' ] ), $this->site()->meta( $id, 'fab_speakers' ), 'a list of one is one row' );
        $this->assertSame( self::ids( [ 'Dee' ] ), $this->site_runs( "return fabrement_get_field( 'speakers', $id );" ) );

        $this->set( $id, [ 'speakers' => [] ] );
        $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_speakers' ), 'an empty list keeps one explicit null row' );
        $this->assertSame( [], $this->site_runs( "return fabrement_get_field( 'speakers', $id );" ) );
        $this->assertNull( $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values']['speakers'] );
    }

    public function test_saving_the_same_list_again_changes_nothing(): void {
        $id = $this->talk( 'Twin', [ 'speakers' => self::ids( [ 'Ann', 'Bob' ] ), 'photos' => [] ] );
        // The record is old (a migration's import date).
        $this->site()->db()->query( "UPDATE wp_posts SET post_modified = '2001-01-01 00:00:00', post_modified_gmt = '2001-01-01 00:00:00' WHERE ID = $id" );
        $rows = $this->site()->rows( 'SELECT meta_id, meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key = ? ORDER BY meta_id', [ $id, 'fab_speakers' ] );

        $this->set( $id, [ 'speakers' => self::ids( [ 'Ann', 'Bob' ] ), 'photos' => [] ] );
        $this->assertSame( '2001-01-01 00:00:00', $this->site()->value( 'SELECT post_modified FROM wp_posts WHERE ID = ?', [ $id ] ), 'the same list is no change' );
        $this->assertSame( $rows, $this->site()->rows( 'SELECT meta_id, meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key = ? ORDER BY meta_id', [ $id, 'fab_speakers' ] ), 'not a row rewritten' );

        $this->set( $id, [ 'speakers' => self::ids( [ 'Ann' ] ), 'photos' => [] ] );
        $this->assertNotSame( '2001-01-01 00:00:00', $this->site()->value( 'SELECT post_modified FROM wp_posts WHERE ID = ?', [ $id ] ), 'a different one is' );
    }

    public function test_a_list_in_a_repeater_row_is_rows_too(): void {
        $id = $this->talk( 'Days', [ 'days' => [ [ 'title' => 'One', 'guests' => self::ids( [ 'Bob', 'Ann' ] ) ], [ 'title' => 'Two', 'guests' => [] ] ] ] );
        $this->assertSame( self::rows( [ 'Bob', 'Ann' ] ), $this->site()->meta( $id, 'fab_days_0_guests' ) );
        $days = $this->site_runs( "return fabrement_get_field( 'days', $id );" );
        $this->assertSame( self::ids( [ 'Bob', 'Ann' ] ), $days[0]['guests'] );
        $this->assertSame( [], $days[1]['guests'] );
    }

    public function test_a_list_on_an_options_page_stays_in_its_one_option(): void {
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-lr-site', 'expectedRevision' => 0, 'page' => [ 'title' => 'Site' ], 'fields' => [ self::field( 'team', 'relationship' ) ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-lr-site', 'values' => [ 'team' => self::ids( [ 'Bob', 'Ann' ] ) ] ] );
        $this->assertSame( self::ids( [ 'Bob', 'Ann' ] ), $this->site_runs( "return fabrement_get_option( 'team', 'qa-lr-site' );" ) );
        $this->assertIsArray( $this->site()->option( 'fabrement_options_qa-lr-site' ), 'one option row' );
        $this->assertSame( 0, (int) $this->site()->value( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE 'fab_team%'" ), 'no post meta' );
    }

    public function test_an_old_serialized_row_still_reads(): void {
        $id = $this->record( self::TYPE, 'Old row' );
        // What an older import wrote: the list as one serialized row.
        $this->site()->php( "add_post_meta( $id, 'fab_speakers', [ " . implode( ', ', self::ids( [ 'Ann', 'Bob' ] ) ) . ' ] );' );
        $this->assertSame( self::ids( [ 'Ann', 'Bob' ] ), $this->site_runs( "return fabrement_get_field( 'speakers', $id );" ) );
        $this->assertSame( self::ids( [ 'Ann', 'Bob' ] ), $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values']['speakers'] );
    }

    public function test_the_filter_finds_what_a_list_holds_and_what_it_does_not(): void {
        $e1 = $this->talk( 'E1', [ 'speakers' => self::ids( [ 'Ann', 'Bob' ] ) ] );
        $e2 = $this->talk( 'E2', [ 'speakers' => self::ids( [ 'Bob', 'Cid' ] ) ] );
        $e3 = $this->talk( 'E3', [ 'speakers' => self::ids( [ 'Dee' ] ) ] );
        $e4 = $this->talk( 'E4', [ 'speakers' => [] ] );
        $list = static fn( $rule ): array => [ 'source' => 'posts', 'args' => [ 'type' => self::TYPE, 'limit' => -1, 'sort' => [ 'title' => 'ASC' ], 'search' => 'E', 'fields' => [ 'speakers' => $rule ] ] ];

        $a = $this->probe( [
            'bob'      => $list( self::$people['Bob'] ),
            'annOrDee' => $list( [ 'IN', self::ids( [ 'Ann', 'Dee' ] ) ] ),
            'notBob'   => $list( [ 'NOT IN', self::ids( [ 'Bob' ] ) ] ),
            'notDee'   => $list( [ '!=', self::$people['Dee'] ] ),
            'speaker'  => [ 'args' => [ 'type' => self::TYPE, 'limit' => -1, 'sort' => [ 'title' => 'ASC' ], 'search' => 'E', 'fields' => [ 'speakers' => '?speaker' ] ], 'ask' => [
                [ 'posts' ], [ 'is', 'speaker', [ '@post' => self::$people['Cid'] ] ], [ 'chosen', 'speaker' ], [ 'active' ], [ 'counts', 'speakers' ],
            ] ],
        ], [ 'speaker' => (string) self::$people['Cid'] ] );
        $this->assertSame( [ 'E1', 'E2' ], $a['bob'], 'holds Bob' );
        $this->assertSame( [ 'E1', 'E3' ], $a['annOrDee'], 'holds Ann or Dee' );
        $this->assertSame( [ 'E3' ], $a['notBob'], 'A filled list that does not hold Bob; empty lists do not participate.' );
        $this->assertSame( [ 'E1', 'E2' ], $a['notDee'] );
        [ $posts, $is, $chosen, $active, $counts ] = $a['speaker'];
        $this->assertSame( [ 'E2' ], $posts );
        $this->assertTrue( $is );
        $this->assertSame( [ 'post:Cid' ], $chosen );
        $this->assertSame( 'Cid', $active[0]['label'], 'a person by the title of their page' );
        $by_name = [];
        foreach ( self::counts( $counts ) as $id => $n ) {
            $by_name[ (string) array_search( (int) $id, self::$people, true ) ] = $n;
        }
        ksort( $by_name );
        $this->assertSame( [ 'Ann' => 1, 'Bob' => 2, 'Cid' => 1, 'Dee' => 1 ], $by_name, 'a count per person, not narrowed by the speaker chosen' );

        // Any query of another plugin finds it with a plain = (among these talks; earlier tests made others).
        $raw = $this->site_runs( "return array_map( 'intval', get_posts( [ 'post_type' => '" . self::TYPE . "', 'fields' => 'ids', 'post__in' => [ $e1, $e2, $e3, $e4 ], 'meta_query' => [ [ 'key' => 'fab_speakers', 'value' => " . self::$people['Ann'] . ' ] ] ] ) );' );
        $this->assertSame( [ $e1 ], $raw );

        // The visitor's archive by speaker; the list changes and the next visit follows.
        $page = self::archive_page( [ 'fields' => [ 'speakers' => '?speaker' ], 'search' => '?q' ], [ 'title' => [ 'title' => 'ASC' ] ], 'title', 0, [ 'speakers' => [ 'counts', 'speakers' ] ] );
        $this->archive( 'qa-talk-archive', [ 'archive:' . self::TYPE ], $page, self::archive_render( 'archive:' . self::TYPE, self::TYPE ), self::archive_listing() );
        $archive = $this->archive_url( 'archive:' . self::TYPE );
        $this->assertSame( [ 'E1', 'E2' ], $this->visit( $archive, [ 'speaker' => self::$people['Bob'], 'q' => 'E' ] )['state']['titles'] );
        $this->set( $e1, [ 'speakers' => self::ids( [ 'Ann' ] ) ] );
        $state = $this->visit( $archive, [ 'speaker' => self::$people['Bob'], 'q' => 'E' ] )['state'];
        $this->assertSame( [ 'E2' ], $state['titles'], 'Bob taken off E1' );
        $this->assertSame( 1, self::counts( $state['report']['speakers'] )[ (string) self::$people['Bob'] ] ?? 0 );
    }
}
