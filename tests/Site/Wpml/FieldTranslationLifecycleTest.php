<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\PageBundleTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * A post type's fields through the life of its records on a three-language WPML site, as an agent
 * builds and fills them over MCP and a visitor reads them in each language: the type and its
 * fields with every translation policy (translate / copy / copy_once / ignore), a record filled and
 * translated with translate-content, the translation changing its own words, the original changing
 * what it copies, repeater rows added, moved and removed, a group cleared, a field retired and
 * brought back, a choice removed, the translation deleted and made again, the original deleted.
 *
 * At every step: what the visitor's page shows in each language — the talk's page uses trusted
 * theme PHP selected by the type's default block template, which prints the fields
 * it reads with fabrement_get_fields() — what get-field-values answers for each version, and the
 * rows in the database.
 */
final class FieldTranslationLifecycleTest extends PageBundleTestCase {

    protected const SITE = 'wpml';

    private const TYPE    = 'qa_talk';
    private const PARTNER = 'qa_partner';

    private static bool $ready = false;

    /** The talk page's logic: one helper render.php calls. */
    private const PAGE = <<<'PHP'
<?php
defined( 'ABSPATH' ) || exit;

return new class extends \QaThemeQuery {

    private function qa_fields(): array {
        return fabrement_get_fields( $this->post()->ID );
    }
};
PHP;

    /** The talk page: its title and every field, in the language WPML took from the address. */
    private const RENDER = <<<'PHP'
<?php
/**
 * Fabrement: singular:qa_talk
 */
defined( 'ABSPATH' ) || exit;

$post  = $this->post();
$state = [
    'id'     => (int) $post->ID,
    'lang'   => fabrement()->language(),
    'title'  => get_the_title( $post ),
    'fields' => $this->qa_fields(),
];
?>
<article class="qa-talk">
    <h1><?php echo esc_html( get_the_title( $post ) ); ?></h1>
    <script type="application/json" id="qa-state"><?php echo wp_json_encode( $state, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
</article>
PHP;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->post_type( self::TYPE, [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
            // Partners are one shared set for every language (logos, addresses): WPML does not translate them.
            $this->post_type( self::PARTNER, [ 'translationMode' => 'none', 'supports' => [ 'title', 'editor' ] ] );
            $this->fields( self::TYPE, self::definitions_of_talk() );
            $this->single( 'qa-talk-page', self::TYPE, self::PAGE, self::RENDER );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    /** The talk's fields; $hint adds a sub-field to the steps' rows (a field added later). */
    private static function definitions_of_talk( bool $hint = false ): array {
        $steps = [ self::field( 'title', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ];
        if ( $hint ) {
            $steps[] = self::field( 'hint', 'text' );
        }
        return [
            self::field( 'tagline', 'text' ),                                          // translate: the default for words
            self::field( 'price', 'number', [ 'format' => 'decimal' ] ),               // copy: the default for a number
            self::field( 'since', 'number', [ 'format' => 'integer', 'translation' => 'copy_once' ] ),
            self::field( 'note', 'text', [ 'translation' => 'ignore' ] ),
            self::field( 'cta', 'link' ),                                              // translate: caption and typed address
            self::field( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => 'Beginner' ], [ 'value' => 'advanced', 'label' => 'Advanced' ] ] ] ),
            self::field( 'abstract', 'text' ),
            self::field( 'venue', 'text' ),
            self::field( 'day', 'date_picker', [ 'translation' => 'copy_once' ] ),
            self::field( 'ref', 'post_object' ),
            self::field( 'partner', 'post_object', [ 'postTypes' => [ self::PARTNER ] ] ),
            self::field( 'steps', 'repeater', [ 'sub_fields' => $steps ] ),
            self::field( 'details', 'group', [ 'translation' => 'copy', 'sub_fields' => [
                self::field( 'city', 'text', [ 'translation' => 'copy' ] ),
                self::field( 'intro', 'text' ),
                self::field( 'memo', 'text', [ 'translation' => 'ignore' ] ),
                self::field( 'stops', 'repeater', [ 'sub_fields' => [ self::field( 'name', 'text' ) ] ] ),
            ] ] ),
        ];
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

    /** All meta rows of a record, ids included: a refused call leaves them exactly so. */
    private function meta_rows( int $id ): array {
        return $this->site()->rows( 'SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? ORDER BY meta_id', [ $id ] );
    }

    private function refused( int $id, array $values ): string {
        $before = $this->meta_rows( $id );
        $error  = $this->mcp_error( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
        $this->assertSame( $before, $this->meta_rows( $id ), 'a refused call writes nothing' );
        return $error;
    }

    /** The version in $lang, made by the agent with translate-content and published. */
    private function translated( int $id, string $lang, string $slug ): int {
        $out = $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => $lang, 'slug' => $slug ] );
        $this->assertTrue( $out['created'] );
        $this->assertSame( 'draft', $out['status'], 'a version starts as a draft: its words are changed before anyone reads them' );
        $this->mcp( 'set-content-status', [ 'contentId' => (int) $out['contentId'], 'status' => 'publish' ] );
        return (int) $out['contentId'];
    }

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    /** What the visitor's page of a record shows: lang, title and the fields the talk page printed. */
    private function shown( int $id ): array {
        $url   = $this->url( $id );
        $state = $this->visit( $url )['state'];
        $this->assertSame( $id, $state['id'], 'the page of that version: ' . $url );
        return $state;
    }

    // ── the policies through a record's life ─────────────────────────────────────────────────

    public function test_a_talk_lives_through_its_translation_from_copy_to_deletion(): void {
        $talk = $this->record( self::TYPE, 'Negotiation talk' );
        $this->set( $talk, [
            'tagline' => 'We build sites', 'price' => 12.5, 'since' => 2001, 'note' => 'Internal',
            'cta'     => [ 'url' => 'https://example.com/contact', 'label' => 'Contact us' ],
        ] );
        $en = $this->shown( $talk );
        $this->assertSame( 'en', $en['lang'] );
        $this->assertSame( 'We build sites', $en['fields']['tagline'] );

        // The French version: a copy of the original, every policy applied once.
        $fr = $this->translated( $talk, 'fr', 'qa-talk-fr' );
        $this->assertSame( 'fr', $this->wpml()->language( $fr, self::TYPE ) );
        $this->assertSame( $this->wpml()->trid( $talk, self::TYPE ), $this->wpml()->trid( $fr, self::TYPE ), 'one translation group' );
        $values = $this->values( $fr );
        $this->assertSame( 'We build sites', $values['tagline'], 'translate: the original\'s words, to start from' );
        $this->assertEquals( 12.5, $values['price'], 'copy' );
        $this->assertSame( 2001, $values['since'], 'copy_once' );
        $this->assertNull( $values['note'], 'ignore: never copied' );
        $this->assertSame( 'Contact us', $values['cta']['label'] );
        $this->assertSame( [], $this->site()->meta( $fr, 'fab_note' ), 'no row for what is ignored' );
        $this->assertSame( [ '12.5' ], $this->site()->meta( $fr, 'fab_price' ), 'a copy value is stored on the version too' );

        // The translator writes the version's own words.
        $this->set( $fr, [ 'tagline' => 'Nous créons des sites', 'note' => 'Interne', 'cta' => [ 'url' => 'https://example.com/contact', 'label' => 'Contactez-nous' ] ] );
        $french = $this->shown( $fr );
        $this->assertSame( 'fr', $french['lang'], 'WPML read French from the address' );
        $this->assertSame( 'Nous créons des sites', $french['fields']['tagline'] );
        $this->assertSame( 'Interne', $french['fields']['note'] );
        $this->assertEquals( 12.5, $french['fields']['price'] );
        $english = $this->shown( $talk );
        $this->assertSame( 'We build sites', $english['fields']['tagline'], 'the original keeps its words' );
        $this->assertSame( 'Internal', $english['fields']['note'] );

        // The original changes everything: each policy decides what reaches the version.
        $this->set( $talk, [ 'tagline' => 'We build websites', 'price' => 15, 'since' => 2005, 'note' => 'Changed' ] );
        $values = $this->values( $fr );
        $this->assertSame( 'Nous créons des sites', $values['tagline'], 'translate: the original\'s change does not overwrite the translation' );
        $this->assertEquals( 15, $values['price'], 'copy: the original\'s change reaches the version' );
        $this->assertSame( 2001, $values['since'], 'copy_once: not followed after the first copy' );
        $this->assertSame( 'Interne', $values['note'], 'ignore: the version\'s own' );
        $this->assertSame( [ '15' ], $this->site()->meta( $fr, 'fab_price' ) );
        $this->assertEquals( 15, $this->shown( $fr )['fields']['price'], 'the French visitor sees the new price' );

        $this->set( $fr, [ 'price' => 99 ] );
        $this->assertEquals( 99, $this->values( $fr )['price'] );
        $this->assertEquals( 99, $this->values( $talk )['price'], 'A copied value is routed to the original.' );
        // copy_once is the version's own after the first copy.
        $this->set( $fr, [ 'since' => 1999 ] );
        $this->assertSame( 1999, $this->values( $fr )['since'] );
        $this->assertSame( 2005, $this->values( $talk )['since'] );

        // The translation is deleted: the original and its page stay, the French page is gone.
        $french_url = $this->url( $fr );
        $this->assertTrue( $this->mcp( 'delete-content', [ 'contentId' => $fr, 'force' => true ] )['deleted'] );
        $this->assertSame( 404, $this->get( $french_url )['status'], $french_url );
        $this->assertSame( [], $this->meta_rows( $fr ), 'its rows went with it' );
        $this->assertArrayNotHasKey( 'fr', $this->wpml()->group( $talk, self::TYPE ) );
        $this->assertSame( 'We build websites', $this->shown( $talk )['fields']['tagline'] );

        // Translated again: a fresh copy of what the original holds now.
        $again  = $this->translated( $talk, 'fr', 'qa-talk-fr-again' );
        $values = $this->values( $again );
        $this->assertSame( 'We build websites', $values['tagline'] );
        $this->assertEquals( 99, $values['price'] );
        $this->assertSame( 2005, $values['since'] );
        $this->assertNull( $values['note'] );

        // The original is deleted: its page is gone; the French version keeps what it holds.
        $english_url = $this->url( $talk );
        $this->mcp( 'delete-content', [ 'contentId' => $talk, 'force' => true ] );
        $this->assertSame( 404, $this->get( $english_url )['status'] );
        $this->assertSame( [ '99' ], $this->site()->meta( $again, 'fab_price' ), 'the version\'s rows stay' );
        $this->assertSame( 'We build websites', $this->values( $again )['tagline'] );
        $this->assertSame( 'We build websites', $this->shown( $again )['fields']['tagline'], 'and its page is still served' );
    }

    public function test_repeater_rows_follow_the_original_by_row_and_keep_their_words(): void {
        $talk = $this->record( self::TYPE, 'Workshop with steps' );
        $this->set( $talk, [ 'steps' => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ], [ 'title' => 'Three', 'code' => 'c' ] ] ] );
        $fr = $this->translated( $talk, 'fr', 'qa-steps-fr' );

        $en   = $this->values( $talk )['steps'];
        $rows = $this->values( $fr )['steps'];
        $this->assertSame( array_column( $en, '_row' ), array_column( $rows, '_row' ), 'the version holds the original\'s rows, by their id' );
        $this->set( $fr, [ 'steps' => [
            [ '_row' => $rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ],
            [ '_row' => $rows[1]['_row'], 'title' => 'Deux', 'code' => 'b' ],
            [ '_row' => $rows[2]['_row'], 'title' => 'Trois', 'code' => 'c' ],
        ] ] );
        $this->assertSame( [ 'Un', 'Deux', 'Trois' ], array_column( $this->shown( $fr )['fields']['steps'], 'title' ) );
        $this->assertSame( [ 'One', 'Two', 'Three' ], array_column( $this->shown( $talk )['fields']['steps'], 'title' ) );

        // The original moves its rows, removes one, adds one and changes a copied code.
        $this->set( $talk, [ 'steps' => [
            [ '_row' => $en[2]['_row'], 'title' => 'Three', 'code' => 'c' ],
            [ 'title' => 'Four', 'code' => 'd' ],
            [ '_row' => $en[0]['_row'], 'title' => 'One', 'code' => 'x' ],
        ] ] );
        $moved = $this->values( $fr )['steps'];
        $this->assertSame( [ 'Trois', 'Four', 'Un' ], array_column( $moved, 'title' ), 'each translated row stays with its row; the new row arrives with the original\'s words; the removed one is gone' );
        $this->assertSame( [ 'c', 'd', 'x' ], array_column( $moved, 'code' ), 'the copied sub-field follows' );
        $this->assertSame( array_column( $this->values( $talk )['steps'], '_row' ), array_column( $moved, '_row' ) );
        $this->assertSame( [ 'Trois', 'Four', 'Un' ], array_column( $this->shown( $fr )['fields']['steps'], 'title' ), 'what the French visitor reads' );
        $this->assertSame( [ '3' ], $this->site()->meta( $fr, 'fab_steps' ), 'three rows stored on the version' );
        $this->assertSame( [], $this->site()->meta( $fr, 'fab_steps_3_title' ), 'no ghost row left behind' );

        $this->set( $fr, [ 'steps' => [ $moved[1], $moved[0], $moved[2] ] ] );
        $this->assertSame( [ $moved[1]['_row'], $moved[0]['_row'], $moved[2]['_row'] ], array_column( $this->values( $talk )['steps'], '_row' ) );
        // Its words in each row stay its own: the new row is translated in place.
        $moved[1]['title'] = 'Quatre';
        $this->set( $fr, [ 'steps' => $moved ] );
        $this->assertSame( [ 'Trois', 'Quatre', 'Un' ], array_column( $this->shown( $fr )['fields']['steps'], 'title' ) );
        $this->assertSame( [ 'Three', 'Four', 'One' ], array_column( $this->values( $talk )['steps'], 'title' ), 'the original keeps its words' );

        // All rows removed on the original: the version's list is empty too.
        $this->set( $talk, [ 'steps' => [] ] );
        $this->assertNull( $this->values( $fr )['steps'] );
        $this->assertEmpty( $this->shown( $fr )['fields']['steps'] );
    }

    public function test_a_group_the_original_clears_keeps_what_the_translation_owns(): void {
        $talk = $this->record( self::TYPE, 'Talk in a city' );
        $this->set( $talk, [ 'details' => [ 'city' => 'Amsterdam', 'intro' => 'Welcome', 'stops' => [ [ 'name' => 'Station' ], [ 'name' => 'Harbour' ] ] ] ] );
        $fr    = $this->translated( $talk, 'fr', 'qa-city-fr' );
        $stops = $this->values( $fr )['details']['stops'];
        $this->set( $fr, [ 'details' => [ 'city' => 'Amsterdam', 'intro' => 'Bienvenue', 'memo' => 'Interne', 'stops' => [
            [ '_row' => $stops[0]['_row'], 'name' => 'Gare' ], [ '_row' => $stops[1]['_row'], 'name' => 'Port' ],
        ] ] ] );
        $shown = $this->shown( $fr )['fields']['details'];
        $this->assertSame( [ 'Amsterdam', 'Bienvenue', 'Interne' ], [ $shown['city'], $shown['intro'], $shown['memo'] ] );
        $this->assertSame( [ 'Gare', 'Port' ], array_column( $shown['stops'], 'name' ), 'a repeater inside the group, translated row by row' );

        // The original clears the group: only what it copies is cleared in the version.
        $this->set( $talk, [ 'details' => null ] );
        $details = $this->values( $fr )['details'];
        $this->assertNull( $details['city'], 'what the original copies is cleared with it' );
        $this->assertSame( 'Bienvenue', $details['intro'], 'the translated words stay' );
        $this->assertSame( 'Interne', $details['memo'], 'the translation\'s own value stays' );
        $this->assertSame( [ null ], $this->site()->meta( $talk, 'fab_details_city' ), 'cleared on the original: stored NULL' );

        // Filled again: copied again, the words and the own value kept.
        $this->set( $talk, [ 'details' => [ 'city' => 'Paris', 'intro' => 'Hello' ] ] );
        $details = $this->values( $fr )['details'];
        $this->assertSame( [ 'Paris', 'Bienvenue', 'Interne' ], [ $details['city'], $details['intro'], $details['memo'] ] );
        $shown = $this->shown( $fr )['fields']['details'];
        $this->assertSame( 'Paris', $shown['city'], 'the French visitor reads the new city' );
        $this->assertSame( 'Hello', $this->shown( $talk )['fields']['details']['intro'] );
        $this->set( $fr, [ 'details' => [ 'city' => 'Lyon' ] ] );
        $this->assertSame( 'Lyon', $this->values( $talk )['details']['city'] );
    }

    public function test_a_field_retired_and_brought_back_and_a_choice_removed_in_every_language(): void {
        $talk = $this->record( self::TYPE, 'Talk with a level' );
        $this->set( $talk, [ 'tagline' => 'Learn to negotiate', 'level' => 'advanced' ] );
        $fr = $this->translated( $talk, 'fr', 'qa-level-fr' );
        $this->set( $fr, [ 'tagline' => 'Apprendre à négocier' ] );

        // The agent retires the tagline: hidden everywhere, its values kept.
        $this->fields( self::TYPE, array_values( array_filter( self::definitions_of_talk(), static fn( array $f ): bool => $f['name'] !== 'tagline' ) ) );
        $this->assertTrue( $this->definitions( self::TYPE )['tagline']['retired'] ?? false, 'get-fields lists it as retired' );
        $this->assertArrayNotHasKey( 'tagline', $this->values( $talk ) );
        $this->assertArrayNotHasKey( 'tagline', $this->values( $fr ) );
        $this->assertArrayNotHasKey( 'tagline', $this->shown( $fr )['fields'], 'the French page no longer shows it' );
        $this->assertArrayNotHasKey( 'tagline', $this->shown( $talk )['fields'] );
        $this->assertSame( [ 'Learn to negotiate' ], $this->site()->meta( $talk, 'fab_tagline' ), 'the value stays in the database' );
        $this->assertSame( [ 'Apprendre à négocier' ], $this->site()->meta( $fr, 'fab_tagline' ) );
        $this->assertStringContainsString( 'tagline', $this->mcp_error( 'set-field-values', [ 'postId' => $talk, 'values' => [ 'tagline' => 'x' ] ] ), 'a retired field takes no value' );

        // Brought back: each language reads its own words again.
        $this->fields( self::TYPE, self::definitions_of_talk() );
        $this->assertArrayNotHasKey( 'retired', $this->definitions( self::TYPE )['tagline'] );
        $this->assertSame( 'Learn to negotiate', $this->values( $talk )['tagline'] );
        $this->assertSame( 'Apprendre à négocier', $this->shown( $fr )['fields']['tagline'] );

        // The "advanced" choice is removed: what records hold stays, and does not block their saves.
        $definitions = self::definitions_of_talk();
        foreach ( $definitions as $i => $field ) {
            if ( $field['name'] === 'level' ) {
                $definitions[ $i ]['choices'] = [ [ 'value' => 'beginner', 'label' => 'Beginner' ] ];
            }
        }
        $this->fields( self::TYPE, $definitions );
        $this->assertSame( [ 'advanced' ], $this->site()->meta( $talk, 'fab_level' ) );
        $this->assertSame( [ 'advanced' ], $this->site()->meta( $fr, 'fab_level' ), 'the copy the version holds stays too' );
        $this->assertNull( $this->values( $talk )['level'], 'An unavailable optional choice reads as unselected.' );
        $this->set( $talk, [ 'level' => 'advanced', 'tagline' => 'Learn to negotiate well' ] );   // sent back as read, beside a change
        $this->assertSame( [ null ], $this->site()->meta( $talk, 'fab_level' ) );
        $this->assertSame( [ null ], $this->site()->meta( $fr, 'fab_level' ) );
        $this->set( $fr, array_intersect_key( $this->values( $fr ), [ 'level' => 1 ] ) + [ 'tagline' => 'Bien négocier' ] );
        $this->assertSame( 'Bien négocier', $this->shown( $fr )['fields']['tagline'], 'the version saves its words beside a copied value its field no longer offers' );
        $this->refused( $talk, [ 'level' => 'expert' ] );
        $this->set( $talk, [ 'level' => 'beginner' ] );
        $this->assertSame( 'beginner', $this->values( $fr )['level'], 'a choice still offered reaches the version' );
        $this->fields( self::TYPE, self::definitions_of_talk() );
    }

    public function test_what_a_translator_cleared_stays_cleared_and_a_sub_field_added_later_is_seeded(): void {
        $talk = $this->record( self::TYPE, 'Talk with an abstract' );
        $this->set( $talk, [ 'abstract' => 'EN abstract', 'day' => '2026-10-01', 'steps' => [ [ 'title' => 'H1', 'code' => 'c1' ] ] ] );
        $fr = $this->translated( $talk, 'fr', 'qa-abstract-fr' );

        // The translator clears its words and its own date: stored empty, not a missing row.
        $this->set( $fr, [ 'abstract' => null, 'day' => null ] );
        $this->assertSame( [ null ], $this->site()->meta( $fr, 'fab_abstract' ), 'cleared in a translation = stored NULL' );
        $this->set( $talk, [ 'abstract' => 'EN abstract v2', 'day' => '2026-11-01', 'venue' => 'EN venue' ] );
        $values = $this->values( $fr );
        $this->assertNull( $values['abstract'], 'the original does not seed a cleared field again' );
        $this->assertNull( $values['day'] );
        $this->assertSame( 'EN venue', $values['venue'], 'a field the translation never held follows the original' );
        $this->assertSame( '', $this->shown( $fr )['fields']['abstract'], 'the French page shows it empty, not the English words' );
        $this->set( $talk, [ 'venue' => null ] );
        $this->assertSame( [ null ], $this->site()->meta( $talk, 'fab_venue' ), 'on the original, clearing stores explicit null' );

        // A sub-field added to the rows later is seeded into the version's rows from the original.
        $this->fields( self::TYPE, self::definitions_of_talk( true ) );
        $row = $this->values( $talk )['steps'][0]['_row'];
        $this->set( $talk, [ 'steps' => [ [ '_row' => $row, 'title' => 'H1', 'code' => 'c1', 'hint' => 'EN hint' ] ] ] );
        $this->assertSame( 'EN hint', $this->values( $fr )['steps'][0]['hint'], 'seeded from the original' );

        $this->set( $fr, [ 'steps' => [ [ '_row' => $row, 'title' => 'H1 FR', 'code' => 'c1', 'hint' => '' ] ] ] );
        $this->set( $talk, [ 'steps' => [ [ '_row' => $row, 'title' => 'H1', 'code' => 'c2', 'hint' => 'EN hint 2' ] ] ] );
        $step = $this->values( $fr )['steps'][0];
        $this->assertNull( $step['hint'], 'written empty, it stays empty' );
        $this->assertSame( 'H1 FR', $step['title'] );
        $this->assertSame( 'c2', $step['code'], 'a copied sub-field still follows' );
        $this->assertSame( 'H1 FR', $this->shown( $fr )['fields']['steps'][0]['title'] );
        $this->fields( self::TYPE, self::definitions_of_talk() );
    }

    public function test_a_copy_value_left_stale_is_healed_when_the_translation_saves_its_words(): void {
        $talk = $this->record( self::TYPE, 'Office talk' );
        $this->set( $talk, [ 'details' => [ 'city' => 'Kyiv', 'intro' => 'Welcome' ] ] );
        $fr = $this->translated( $talk, 'fr', 'qa-office-fr' );
        // An import plugin wrote the version's row straight, bypassing the sync: stale.
        $this->site()->php( "update_post_meta( $fr, 'fab_details_city', 'Stale city' );" );

        // The form sends the stale value back unchanged with the words: the words are saved, the copy healed.
        $this->set( $fr, [ 'details' => [ 'city' => 'Stale city', 'intro' => 'Bienvenue' ] ] );
        $this->assertSame( [ 'Bienvenue' ], $this->site()->meta( $fr, 'fab_details_intro' ) );
        $this->assertSame( [ 'Kyiv' ], $this->site()->meta( $fr, 'fab_details_city' ), 'healed from the original' );
        $this->assertSame( 'Kyiv', $this->shown( $fr )['fields']['details']['city'] );
        $this->set( $fr, [ 'details' => [ 'city' => 'Kiev', 'intro' => 'Bienvenue' ] ] );
        $this->assertSame( 'Kiev', $this->values( $talk )['details']['city'] );
    }

    public function test_a_picked_version_is_stored_as_its_original_and_each_language_shows_its_own(): void {
        $target = $this->record( 'page', 'Venue hall' );
        $hall_fr = $this->translated( $target, 'fr', 'qa-salle' );
        $talk   = $this->record( self::TYPE, 'Talk in the hall' );
        $answer = $this->set( $talk, [ 'ref' => $hall_fr ] );
        $this->assertSame( $target, $answer['values']['ref'], 'the answer shows the original\'s id' );
        $this->assertSame( [ (string) $target ], $this->site()->meta( $talk, 'fab_ref' ), 'one id for every language: the original' );
        $this->assertSame( $target, $this->shown( $talk )['fields']['ref'], 'the English page points at the English hall' );

        $fr = $this->translated( $talk, 'fr', 'qa-hall-talk-fr' );
        $this->assertSame( [ (string) $target ], $this->site()->meta( $fr, 'fab_ref' ) );
        $this->assertSame( $hall_fr, $this->shown( $fr )['fields']['ref'], 'the French page points at the French hall' );

        // A type WPML does not translate: the record picked is stored exactly.
        $partner = $this->record( self::PARTNER, 'Partner A' );
        $this->assertNull( $this->wpml()->language( $partner, self::PARTNER ), 'no language of its own' );
        $this->set( $talk, [ 'partner' => $partner ] );
        $this->assertSame( [ (string) $partner ], $this->site()->meta( $talk, 'fab_partner' ) );
        $this->assertSame( $partner, $this->shown( $fr )['fields']['partner'], 'the same partner in every language' );

        // ...and it has no language versions: translate-content refuses and creates nothing.
        $count = fn(): string => (string) $this->site()->value( 'SELECT COUNT(*) FROM wp_posts WHERE post_type = ?', [ self::PARTNER ] );
        $before = $count();
        $this->mcp_error( 'translate-content', [ 'contentId' => $partner, 'language' => 'fr' ] );
        $this->assertSame( $before, $count() );
    }

    public function test_a_retired_values_token_is_refused_like_any_unknown_argument(): void {
        $talk = $this->record( self::TYPE, 'Old habits talk' );
        $this->assertStringContainsString( 'valuesToken', $this->mcp_error( 'set-field-values', [ 'postId' => $talk, 'values' => [ 'tagline' => 'Old habits' ], 'valuesToken' => 'anything' ] ) );
        $this->assertArrayNotHasKey( 'valuesToken', $this->mcp( 'get-field-values', [ 'postId' => $talk ] ) );
        $this->assertSame( [], $this->site()->meta( $talk, 'fab_tagline' ), 'nothing was written' );
    }

    // ── what a crawler reads: structured data in each language ───────────────────────────────

    public function test_each_language_page_carries_its_own_event_node_and_follows_copied_values(): void {
        $this->site()->wp( 'option', 'update', 'timezone_string', 'Europe/Kyiv' );
        $this->post_type( 'qa_event', [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
        $this->fields( 'qa_event', [
            self::field( 'price', 'number', [ 'format' => 'decimal' ] ),
            self::field( 'starts', 'date_time_picker' ),
            self::field( 'city', 'text' ),
        ] );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-event-site', 'expectedRevision' => 0, 'page' => [ 'title' => 'Site' ], 'fields' => [ self::field( 'phone', 'text', [ 'translation' => 'copy' ] ) ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-event-site', 'values' => [ 'phone' => '+380 44 111 22 33' ] ] );
        // Options have their own explicit language versions; the FR event does not inherit EN options on read.
        $missing = $this->mcp( 'get-field-values', [ 'options' => 'qa-event-site', 'language' => 'fr' ] );
        $this->assertFalse( $missing['versionExists'] );
        $this->assertNull( $missing['values']['phone'] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-event-site', 'language' => 'fr', 'values' => [ 'phone' => '+380 44 111 22 33' ] ] );

        $event = $this->record( 'qa_event', 'Negotiation workshop' );
        $this->mcp( 'set-field-values', [ 'postId' => $event, 'values' => [ 'price' => 59.9, 'starts' => '2026-07-15T18:00', 'city' => 'Lviv' ] ] );
        $this->mcp( 'set-schema', [ 'postType' => 'qa_event', 'graph' => [ [
            '@type'     => 'Event',
            '@id'       => '{{url}}#event',
            'name'      => '{{title}}',
            'startDate' => '{{field:starts}}',
            'location'  => [ '@type' => 'Place', 'name' => '{{field:city}}', 'telephone' => '{{option:qa-event-site.phone}}' ],
            'offers'    => [ '@type' => 'Offer', 'price' => '{{field:price}}' ],
        ] ] ] );

        $fr = $this->translated( $event, 'fr', 'atelier-de-negociation' );
        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $fr, 'title' => 'Atelier de négociation' ] );   // renamed, nothing added
        $this->mcp( 'set-field-values', [ 'postId' => $fr, 'values' => [ 'city' => 'Lviv (Lwów)' ] ] );
        // The original's price changes after the version was made: a copied value follows it.
        $this->mcp( 'set-field-values', [ 'postId' => $event, 'values' => [ 'price' => 64.5 ] ] );

        $french_url = $this->url( $fr );
        $this->assertStringContainsString( '/fr/', $french_url );
        $french = $this->get( $french_url );
        $this->assertSame( 200, $french['status'], $french_url );
        $this->assertMatchesRegularExpression( '/<html[^>]+lang="fr-FR"/', $french['body'], 'WPML read French from the address' );
        $node = self::node( self::json_ld( $french['body'] ), 'Event' );
        $this->assertNotNull( $node, 'the French page carries the Event node' );
        $this->assertSame( 'Atelier de négociation', $node['name'] );
        $this->assertSame( 'Lviv (Lwów)', $node['location']['name'], 'its own words' );
        $this->assertSame( '64.5', $node['offers']['price'], 'the copied price follows the original\'s change' );
        $this->assertSame( '2026-07-15T18:00:00+03:00', $node['startDate'] );
        $this->assertSame( '+380 44 111 22 33', $node['location']['telephone'] );
        $this->assertStringContainsString( '/fr/', (string) $node['@id'], '{{url}} is the French address' );

        $english = $this->get( $this->url( $event ) );
        $this->assertMatchesRegularExpression( '/<html[^>]+lang="en-US"/', $english['body'] );
        $node = self::node( self::json_ld( $english['body'] ), 'Event' );
        $this->assertNotNull( $node );
        $this->assertSame( 'Negotiation workshop', $node['name'], 'the English page keeps its own words' );
        $this->assertSame( 'Lviv', $node['location']['name'] );
        $this->assertSame( '64.5', $node['offers']['price'] );
        $this->assertStringNotContainsString( '/fr/', (string) $node['@id'] );
    }
}
