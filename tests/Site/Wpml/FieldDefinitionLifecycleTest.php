<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * Field definitions through their life on a three-language WPML site, from the side of the
 * translations: each field's policy (translate / copy / copy_once / ignore) as defined and read
 * back, a translated record following its original after the definition changes, fields retired
 * while translations hold values and brought back, and a field's policy changed with values in
 * both languages.
 *
 * At every step: get-fields, get-field-values of each version, the meta rows of each version and
 * what the site's code reads with fabrement()->fields().
 */
final class FieldDefinitionLifecycleTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private const TYPE = 'qa_dlw_talk';

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->post_type( self::TYPE, [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
            $this->fields( self::TYPE, self::talk_fields() );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    /** The talk's fields, with $over[name] => [property => value] laid over them (sub-fields as "info.city"). */
    private static function talk_fields( array $over = [], array $without = [] ): array {
        $fields = [
            self::field( 'words', 'text' ),                                             // translate (default for words)
            self::field( 'price', 'number', [ 'format' => 'decimal' ] ),                // copy (default for the rest)
            self::field( 'seats', 'number', [ 'format' => 'integer', 'translation' => 'copy_once' ] ),
            self::field( 'memo', 'text', [ 'translation' => 'ignore' ] ),
            self::field( 'stage', 'select', [ 'choices' => [ [ 'value' => 'open', 'label' => 'Open' ], [ 'value' => 'full', 'label' => 'Full' ] ] ] ),
            self::field( 'info', 'group', [ 'sub_fields' => [ self::field( 'city', 'text', [ 'translation' => 'copy' ] ), self::field( 'intro', 'text' ) ] ] ),
            self::field( 'steps', 'repeater', [ 'sub_fields' => [ self::field( 'title', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ] ] ),
        ];
        $out = [];
        foreach ( $fields as $field ) {
            if ( in_array( $field['name'], $without, true ) ) {
                continue;
            }
            $field = ( $over[ $field['name'] ] ?? [] ) + $field;
            if ( isset( $field['sub_fields'] ) ) {
                $children = [];
                foreach ( $field['sub_fields'] as $child ) {
                    $path = $field['name'] . '.' . $child['name'];
                    if ( ! in_array( $path, $without, true ) ) {
                        $children[] = ( $over[ $path ] ?? [] ) + $child;
                    }
                }
                $field['sub_fields'] = $children;
            }
            $out[] = $field;
        }
        return $out;
    }

    private function wpml(): WpmlSite {
        return new WpmlSite( $this->site() );
    }

    private function set( int $id, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
    }

    private function values( int $id ): array {
        return $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values'];
    }

    private function meta_rows( int $id ): array {
        return $this->site()->rows( 'SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE ? ORDER BY meta_id', [ $id, 'fab\\_%' ] );
    }

    private function read(): array {
        return $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => self::TYPE ] );
    }

    private function policies(): array {
        $out = [];
        foreach ( $this->read()['fields'] as $field ) {
            $out[ $field['name'] ] = $field['translation'];
            foreach ( $field['sub_fields'] ?? [] as $child ) {
                $out[ $field['name'] . '.' . $child['name'] ] = $child['translation'];
            }
        }
        return $out;
    }

    private function translated( int $id, string $lang, string $slug ): int {
        $out = $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => $lang, 'slug' => $slug ] );
        $this->assertTrue( $out['created'] );
        $this->mcp( 'set-content-status', [ 'contentId' => (int) $out['contentId'], 'status' => 'publish' ] );
        return (int) $out['contentId'];
    }

    /** A talk filled in English with its French version made from it. @return array{0:int,1:int} */
    private function talk( string $slug ): array {
        $en = $this->record( self::TYPE, 'QA ' . $slug );
        $this->set( $en, [
            'words' => 'Hello', 'price' => 10.5, 'seats' => 40, 'memo' => 'EN memo', 'stage' => 'open',
            'info'  => [ 'city' => 'Lviv', 'intro' => 'Welcome' ],
            'steps' => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ] ],
        ] );
        $fr   = $this->translated( $en, 'fr', $slug . '-fr' );
        $rows = $this->values( $fr )['steps'];
        $this->set( $fr, [ 'words' => 'Bonjour', 'memo' => 'FR memo', 'info' => [ 'city' => 'Lviv', 'intro' => 'Bienvenue' ],
            'steps' => [ [ '_row' => $rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ], [ '_row' => $rows[1]['_row'], 'title' => 'Deux', 'code' => 'b' ] ] ] );
        return [ $en, $fr ];
    }

    /** What the site's code reads of a version. */
    private function reads( int $id ): array {
        return $this->site_runs( "\$f = fabrement()->fields( $id ); return [
            'words' => \$f->get_text( 'words' ), 'price' => \$f->get_number( 'price' ), 'seats' => \$f->get_number( 'seats' ), 'memo' => \$f->get_text( 'memo' ),
            'city' => \$f->get_group( 'info' )->get_text( 'city' ), 'intro' => \$f->get_group( 'info' )->get_text( 'intro' ),
            'titles' => array_map( static fn( \$r ) => \$r->get_text( 'title' ), \$f->get_rows( 'steps' ) ),
        ];" );
    }

    private function take_debug_log(): string {
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        return $log;
    }

    // ── policies as defined ──────────────────────────────────────────────────────────────────

    public function test_each_field_has_the_policy_it_was_given_or_its_types_default_and_words_only_may_be_translated(): void {
        $this->assertSame( [
            'words' => 'translate', 'price' => 'copy', 'seats' => 'copy_once', 'memo' => 'ignore', 'stage' => 'copy',
            'info'  => 'copy', 'info.city' => 'copy', 'info.intro' => 'translate', 'steps' => 'copy', 'steps.title' => 'translate', 'steps.code' => 'copy',
        ], $this->policies() );

        $before = $this->read();
        foreach ( [
            'price'      => [ [ 'price' => [ 'translation' => 'translate' ] ], 'fields[1].translation', 'no words to translate' ],
            'stage'      => [ [ 'stage' => [ 'translation' => 'translate' ] ], 'fields[4].translation', 'no words to translate' ],
            'info'       => [ [ 'info' => [ 'translation' => 'translate' ] ], 'fields[5].translation', 'no words of its own' ],
            'steps'      => [ [ 'steps' => [ 'translation' => 'translate' ] ], 'fields[6].translation', 'no words of its own' ],
            'a bad name' => [ [ 'memo' => [ 'translation' => 'own' ] ], 'fields[3].translation', 'translate, copy, copy_once or ignore' ],
        ] as $case => [ $over, $at, $said ] ) {
            $error = html_entity_decode( $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => self::TYPE, 'expectedRevision' => $before['revision'], 'fields' => self::talk_fields( $over ) ] ), ENT_QUOTES );
            $this->assertStringStartsWith( $at, $error, $case );
            $this->assertStringContainsString( $said, $error, $case );
        }
        $this->assertSame( $before, $this->read(), 'nothing saved' );

        // A policy left out keeps the stored one, not the type's default.
        $this->fields( self::TYPE, self::talk_fields( [ 'seats' => [ 'translation' => 'copy' ] ] ), true );
        $this->fields( self::TYPE, array_map( static function ( array $f ): array {
            unset( $f['translation'] );
            return $f;
        }, self::talk_fields() ) );
        $this->assertSame( 'copy', $this->policies()['seats'] );
        $this->assertSame( 'ignore', $this->policies()['memo'] );
        $this->fields( self::TYPE, self::talk_fields() );
        $this->assertSame( 'copy_once', $this->policies()['seats'] );
    }

    // ── a translated record follows its original after the definition changes ─────────────

    public function test_a_translation_follows_by_policy_and_a_label_or_choice_change_moves_no_value(): void {
        [ $en, $fr ] = $this->talk( 'qa-dlw-follow' );
        $values = $this->values( $fr );
        $this->assertSame( [ 'Bonjour', 10.5, 40, 'FR memo', 'open', 'Lviv', 'Bienvenue' ], [ $values['words'], $values['price'], $values['seats'], $values['memo'], $values['stage'], $values['info']['city'], $values['info']['intro'] ] );
        $this->assertSame( [ 'Un', 'Deux' ], array_column( $values['steps'], 'title' ) );
        $this->assertSame( [ '10.5' ], $this->site()->meta( $fr, 'fab_price' ) );

        // Labels, a choice and a description change: no row of either version moves.
        $en_rows = $this->meta_rows( $en );
        $fr_rows = $this->meta_rows( $fr );
        $this->fields( self::TYPE, self::talk_fields( [
            'words'     => [ 'label' => 'Words on top' ],
            'stage'     => [ 'choices' => [ [ 'value' => 'open', 'label' => 'Open now' ], [ 'value' => 'full', 'label' => 'Full' ], [ 'value' => 'later', 'label' => 'Later' ] ] ],
            'info.city' => [ 'description' => 'Where it is' ],
        ] ) );
        $this->assertSame( $en_rows, $this->meta_rows( $en ) );
        $this->assertSame( $fr_rows, $this->meta_rows( $fr ) );

        // The original changes every field: copy fields follow, the rest stay the version's own.
        $this->set( $en, [ 'words' => 'Hi', 'price' => 12, 'seats' => 50, 'memo' => 'EN memo 2', 'stage' => 'later', 'info' => [ 'city' => 'Kyiv', 'intro' => 'Hello there' ],
            'steps' => array_map( static fn( array $r ): array => [ 'code' => strtoupper( $r['code'] ) ] + $r, $this->values( $en )['steps'] ) ] );
        $values = $this->values( $fr );
        $this->assertSame( [ 'Bonjour', 12, 40, 'FR memo', 'later', 'Kyiv', 'Bienvenue' ], [ $values['words'], $values['price'], $values['seats'], $values['memo'], $values['stage'], $values['info']['city'], $values['info']['intro'] ] );
        $this->assertSame( [ 'A', 'B' ], array_column( $values['steps'], 'code' ), 'a copied sub-field follows in every row' );
        $this->assertSame( [ 'Un', 'Deux' ], array_column( $values['steps'], 'title' ) );
        $this->assertSame( [ '12' ], $this->site()->meta( $fr, 'fab_price' ) );
        $this->assertSame( [ 'later' ], $this->site()->meta( $fr, 'fab_stage' ), 'the new choice reaches the version' );
        $this->assertEquals( [ 'words' => 'Bonjour', 'price' => 12.0, 'seats' => 40, 'memo' => 'FR memo', 'city' => 'Kyiv', 'intro' => 'Bienvenue', 'titles' => [ 'Un', 'Deux' ] ], $this->reads( $fr ) );

        // A sub-field added to the rows later is seeded into the version from the original.
        $this->fields( self::TYPE, self::talk_fields( [ 'steps' => [ 'sub_fields' => [ self::field( 'title', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ), self::field( 'room', 'text', [ 'translation' => 'copy' ] ) ] ] ] ) );
        $rows = $this->values( $en )['steps'];
        $this->set( $en, [ 'steps' => [ [ 'room' => 'R1' ] + $rows[0], [ 'room' => 'R2' ] + $rows[1] ] ] );
        $this->assertSame( [ 'R1', 'R2' ], array_column( $this->values( $fr )['steps'], 'room' ) );
        $this->fields( self::TYPE, self::talk_fields() );
    }

    // ── retired and restored with values in every language ───────────────────────────────

    public function test_a_retired_fields_values_stay_in_every_language_and_come_back_with_it(): void {
        [ $en, $fr ] = $this->talk( 'qa-dlw-retire' );
        $fr_before = $this->meta_rows( $fr );

        $this->fields( self::TYPE, self::talk_fields( [], [ 'price', 'words', 'info.intro', 'steps.code' ] ) );
        $policies = $this->policies();
        $this->assertArrayHasKey( 'price', $policies, 'kept as retired' );
        $read = array_column( $this->read()['fields'], null, 'name' );
        $this->assertTrue( $read['price']['retired'] ?? false );
        $this->assertTrue( $read['words']['retired'] ?? false );
        $this->assertSame( 'copy', $read['price']['translation'], 'a retired field keeps its policy' );
        $this->assertSame( $fr_before, $this->meta_rows( $fr ), 'the version\'s rows stay' );
        $values = $this->values( $fr );
        $this->assertArrayNotHasKey( 'price', $values );
        $this->assertArrayNotHasKey( 'words', $values );
        $this->assertArrayNotHasKey( 'intro', $values['info'] );
        $this->assertArrayNotHasKey( 'code', $values['steps'][0] );
        $this->assertSame( [ null, null ], $this->site_runs( "\$f = fabrement()->fields( $fr ); return [ \$f->get_text( 'words' ), \$f->get_number( 'price' ) ];" ) );
        $this->assertStringContainsString( 'words: Unknown field.', $this->take_debug_log() );
        $this->assertStringContainsString( 'price', $this->mcp_error( 'set-field-values', [ 'postId' => $fr, 'values' => [ 'price' => 1 ] ] ), 'not on the version either' );

        // The original saves while they are retired: the sync leaves the version's retired values alone.
        $this->set( $en, [ 'seats' => 41, 'info' => [ 'city' => 'Odesa' ] ] );
        $this->assertSame( [ 'Bonjour' ], $this->site()->meta( $fr, 'fab_words' ) );
        $this->assertSame( [ '10.5' ], $this->site()->meta( $fr, 'fab_price' ) );
        $this->assertSame( [ 'Bienvenue' ], $this->site()->meta( $fr, 'fab_info_intro' ) );
        $this->assertSame( [ 'a' ], $this->site()->meta( $fr, 'fab_steps_0_code' ) );
        $this->assertSame( 'Odesa', $this->values( $fr )['info']['city'], 'what is active still follows' );

        // Rows reordered on the original: the version's rows follow by identity, retired values with them.
        $rows = $this->values( $en )['steps'];
        $this->set( $en, [ 'steps' => [ $rows[1], $rows[0] ] ] );
        $this->assertSame( [ 'Deux', 'Un' ], array_column( $this->values( $fr )['steps'], 'title' ) );
        $this->assertSame( [ 'b' ], $this->site()->meta( $fr, 'fab_steps_0_code' ), 'the retired code moved with its row' );

        // Brought back: each language reads what it held.
        $this->fields( self::TYPE, self::talk_fields() );
        $values = $this->values( $fr );
        $this->assertSame( [ 'Bonjour', 10.5, 'Bienvenue' ], [ $values['words'], $values['price'], $values['info']['intro'] ] );
        $this->assertSame( [ 'b', 'a' ], array_column( $values['steps'], 'code' ) );
        $this->assertSame( 'Hello', $this->values( $en )['words'] );
        $this->assertSame( array_column( $this->values( $en )['steps'], '_row' ), array_column( $values['steps'], '_row' ), 'the rows kept their identity' );
        $this->assertSame( 'Bonjour', $this->reads( $fr )['words'] );
        // And the copied value follows the original again.
        $this->set( $en, [ 'price' => 11 ] );
        $this->assertSame( [ '11' ], $this->site()->meta( $fr, 'fab_price' ) );
    }

    // ── a field's policy changed ─────────────────────────────────────────────────────────────

    public function test_a_policy_changed_keeps_every_languages_values_and_the_next_sync_follows_the_new_policy(): void {
        [ $en, $fr ] = $this->talk( 'qa-dlw-policy' );

        // copy → copy_once: the version keeps the copy it has, then has its own.
        $this->fields( self::TYPE, self::talk_fields( [ 'price' => [ 'translation' => 'copy_once' ] ] ) );
        $this->assertSame( 'copy_once', $this->policies()['price'] );
        $this->assertSame( [ '10.5' ], $this->site()->meta( $fr, 'fab_price' ), 'nothing lost on the change' );
        $answer = $this->set( $fr, [ 'price' => 9.5 ] );
        $this->assertArrayNotHasKey( 'routed', $answer, 'its own now: not routed to the original' );
        $this->assertSame( 10.5, $this->values( $en )['price'] );
        $this->set( $en, [ 'price' => 13 ] );
        $this->assertSame( 9.5, $this->values( $fr )['price'], 'the original no longer reaches it' );

        // Sent without a policy, a field keeps the one it has: copy_once stays.
        $this->fields( self::TYPE, self::talk_fields() );
        $this->assertSame( 'copy_once', $this->policies()['price'] );

        // copy_once → copy: both values are kept until the original saves; then the version follows.
        $this->fields( self::TYPE, self::talk_fields( [ 'price' => [ 'translation' => 'copy' ] ] ), true );
        $this->assertSame( 'copy', $this->policies()['price'] );
        $this->assertSame( [ '9.5' ], $this->site()->meta( $fr, 'fab_price' ), 'the policy change itself writes nothing' );
        $this->assertSame( [ '13' ], $this->site()->meta( $en, 'fab_price' ) );
        $this->set( $en, [ 'seats' => 45 ] );
        $this->assertSame( [ '13' ], $this->site()->meta( $fr, 'fab_price' ), 'the next save of the original brings the copy into step' );
        $this->assertEquals( 13, $this->reads( $fr )['price'] );

        // ignore → copy_once: the version's own memo stays.
        $this->fields( self::TYPE, self::talk_fields( [ 'memo' => [ 'translation' => 'copy_once' ] ] ) );
        $this->set( $en, [ 'memo' => 'EN memo changed' ] );
        $this->assertSame( 'FR memo', $this->values( $fr )['memo'] );

        // A sub-field from copy to translate: the version's city becomes its own words.
        $this->fields( self::TYPE, self::talk_fields( [ 'memo' => [ 'translation' => 'copy_once' ], 'info.city' => [ 'translation' => 'translate' ] ] ) );
        $this->set( $fr, [ 'info' => [ 'city' => 'Lviv (FR)', 'intro' => 'Bienvenue' ] ] );
        $this->assertSame( 'Lviv', $this->values( $en )['info']['city'], 'not routed to the original' );
        $this->set( $en, [ 'info' => [ 'city' => 'Lwow', 'intro' => 'Welcome' ] ] );
        $this->assertSame( 'Lviv (FR)', $this->values( $fr )['info']['city'] );

        // translate → copy for words: the version's words stay until the original saves, then they are the original's.
        // …which is why it is made only when told to: the refusal says what will be lost, and writes nothing.
        $copied  = self::with_keys( self::without_retired( self::talk_fields( [ 'words' => [ 'translation' => 'copy' ] ] ) ), (array) $this->read()['fields'] );
        $refusal = $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => self::TYPE, 'expectedRevision' => (int) $this->read()['revision'], 'fields' => $copied ] );
        $this->assertStringContainsString( '"words" (translate → copy)', $refusal );
        $this->assertStringContainsString( 'send the same call with force: true', $refusal );
        $this->assertSame( 'translate', $this->policies()['words'] );
        $this->fields( self::TYPE, self::talk_fields( [ 'words' => [ 'translation' => 'copy' ] ] ), true );
        $this->assertSame( [ 'Bonjour' ], $this->site()->meta( $fr, 'fab_words' ), 'the change itself writes nothing' );
        $this->set( $en, [ 'seats' => 46 ] );
        $this->assertSame( [ 'Hello' ], $this->site()->meta( $fr, 'fab_words' ), 'a copy field is the original\'s value' );
        $this->fields( self::TYPE, self::talk_fields( [ 'words' => [ 'translation' => 'translate' ], 'memo' => [ 'translation' => 'ignore' ], 'info.city' => [ 'translation' => 'copy' ] ] ), true );
        $this->assertSame( [ 'translate', 'ignore', 'copy' ], [ $this->policies()['words'], $this->policies()['memo'], $this->policies()['info.city'] ] );
    }
}
