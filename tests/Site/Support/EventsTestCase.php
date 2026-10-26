<?php

namespace Fabrement\Tests\Site\Support;

/**
 * The events an agent sets up for the filter tests, through the tools: two post types sharing their
 * fields, a hierarchical topic taxonomy, six events with varied values and a course.
 *
 *   title  price  starts      doors             open  city   seats  _rank  topics
 *   A      100    2021-01-10  2021-01-10T18:00  yes   Kyiv   120    100    tech
 *   B      9      2021-03-05  2021-03-05T09:30  no    Lviv   8      9      ai
 *   C      25     —           —                 yes   Kyiv   —      25     ai, 2026
 *   D      —      2020-12-31  2020-12-31T23:00  —     Odesa  40     —      2026
 *   E      1000   2021-03-05  2021-03-05T20:15  yes   —      1000   1000   —
 *   F      9.5    —           —                 no    Lviv   9.5    9.5    —
 *   G (a qa_course): price 15
 *
 * Topics: tech ⊃ ai (its child); "2026", a slug of digits whose id is something else. _rank is
 * another plugin's meta, written by that plugin's own import (WP-CLI), never a field. Every date is
 * in the past, whenever the tests run.
 */
abstract class EventsTestCase extends PageBundleTestCase {

    protected const TYPE   = 'qa_ev';
    protected const COURSE = 'qa_course';
    protected const TOPIC  = 'qa_topic';

    /** @var array<string,int> title => id */
    protected static array $ids = [];

    /** @var array<string,int> slug => term id */
    protected static array $terms = [];

    /** @var array<string,string> slug => term archive address */
    protected static array $term_urls = [];

    protected static int $hall = 0;

    private static bool $ready = false;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$ready = false;
        self::$ids   = self::$terms = self::$term_urls = [];
        self::$hall  = 0;
    }

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            self::$ready = true;
            $this->events();
            $this->built();
        }
    }

    /** What a class adds once the events are there (its bundles). */
    protected function built(): void {
    }

    protected static function definitions_of_events(): array {
        return [
            self::field( 'price', 'number', [ 'format' => 'decimal' ] ),
            self::field( 'starts', 'date_picker' ),
            self::field( 'doors', 'date_time_picker' ),
            self::field( 'open', 'true_false' ),
            self::field( 'city', 'text' ),
            self::field( 'venue', 'group', [ 'sub_fields' => [ self::field( 'seats', 'number', [ 'format' => 'decimal' ] ) ] ] ),
            self::field( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => 'For beginners' ], [ 'value' => 'pro', 'label' => 'For pros' ] ] ] ),
            self::field( 'host', 'post_object' ),
        ];
    }

    private function events(): void {
        foreach ( [ self::TYPE, self::COURSE ] as $type ) {
            $this->post_type( $type );
            $this->fields( $type, self::definitions_of_events() );
        }
        $this->taxonomy( self::TOPIC, [ 'singular' => 'Topic', 'postTypes' => [ self::TYPE, self::COURSE ], 'hierarchical' => true ] );
        foreach ( [ [ 'Tech', 'tech', null ], [ 'AI', 'ai', 'tech' ], [ 'Year 2026', '2026', null ] ] as [ $name, $slug, $parent ] ) {
            $term = $this->mcp( 'create-term', [ 'taxonomy' => self::TOPIC, 'name' => $name, 'slug' => $slug ] + ( $parent ? [ 'parent' => $parent ] : [] ) );
            self::$terms[ $slug ]    = (int) $term['termId'];
            self::$term_urls[ $slug ] = (string) $term['url'];
        }
        self::$hall = $this->record( 'page', 'Acme Hall' );

        $rows = [
            'A' => [ 100, '2021-01-10', '2021-01-10T18:00', true, 'Kyiv', 120, '100', [ 'tech' ] ],
            'B' => [ 9, '2021-03-05', '2021-03-05T09:30', false, 'Lviv', 8, '9', [ 'ai' ] ],
            'C' => [ 25, null, null, true, 'Kyiv', null, '25', [ 'ai', '2026' ] ],
            'D' => [ null, '2020-12-31', '2020-12-31T23:00', null, 'Odesa', 40, null, [ '2026' ] ],
            'E' => [ 1000, '2021-03-05', '2021-03-05T20:15', true, null, 1000, '1000', [] ],
            'F' => [ 9.5, null, null, false, 'Lviv', 9.5, '9.5', [] ],
        ];
        $import = '';
        foreach ( $rows as $title => [ $price, $starts, $doors, $open, $city, $seats, $rank, $topics ] ) {
            $id     = $this->record( self::TYPE, $title );
            $values = array_filter( [ 'price' => $price, 'starts' => $starts, 'doors' => $doors, 'open' => $open, 'city' => $city ], static fn( $v ): bool => $v !== null );
            if ( $seats !== null ) {
                $values['venue'] = [ 'seats' => $seats ];
            }
            $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
            if ( $topics !== [] ) {
                // By id: a string of digits ("2026") is read as a term id, never as a slug.
                $this->mcp( 'set-post-terms', [ 'contentId' => $id, 'taxonomy' => self::TOPIC, 'terms' => array_map( static fn( string $slug ): int => self::$terms[ $slug ], $topics ), 'mode' => 'replace' ] );
            }
            if ( $rank !== null ) {
                $import .= "update_post_meta( $id, '_rank', '$rank' );\n";
            }
            self::$ids[ $title ] = $id;
        }
        // Another plugin's import writes its own meta; it is no field of ours.
        $this->site()->php( $import );

        self::$ids['G'] = $this->record( self::COURSE, 'G' );
        $this->mcp( 'set-field-values', [ 'postId' => self::$ids['G'], 'values' => [ 'price' => 15 ] ] );

        $this->assertSame( [ '9.5' ], $this->site()->meta( self::$ids['F'], 'fab_price' ), 'a decimal is kept as written' );
        $this->assertSame( [ '9' ], $this->site()->meta( self::$ids['B'], '_rank' ) );
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        self::$ids   = self::$terms = self::$term_urls = [];
        parent::tearDownAfterClass();
    }
}
