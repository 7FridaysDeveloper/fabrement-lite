<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\Browser;
use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\ThemeFields;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * Fields that point at records — a picked record, a list of them (relationship), a gallery — on a
 * three-language WPML site: stored as the originals, a row per item, in every language; shown to
 * each visitor as the versions in their language; carried to a translation by each policy and by
 * every way WPML makes one; and found by a page's filter or any WP_Query in either language's id.
 *
 * The filter runs as an archive page's code runs it (the Filter its $this->filter() returns), in
 * the language WPML would take from the address.
 */
final class ListFieldsLanguagesTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private static bool $ready = false;

    /** @var array<string,array{en:int,fr:int}> People pages by name, with their French versions. */
    private static array $people = [];

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->fields( 'post', [
                self::field( 'host', 'post_object' ),
                self::field( 'speakers', 'relationship' ),                                   // copy, the default
                self::field( 'guests', 'relationship', [ 'translation' => 'copy_once' ] ),
                self::field( 'notes', 'relationship', [ 'translation' => 'ignore' ] ),
                self::field( 'photos', 'gallery' ),
            ] );
            ThemeFields::install( $this->site() );
            foreach ( [ 'Ann', 'Bob', 'Cid' ] as $name ) {
                $en = $this->record( 'page', $name );
                self::$people[ $name ] = [ 'en' => $en, 'fr' => $this->french( $en, strtolower( $name ) . '-fr', $name . ' (fr)' ) ];
            }
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready  = false;
        self::$people = [];
        parent::tearDownAfterClass();
    }

    private function wpml(): WpmlSite {
        return new WpmlSite( $this->site() );
    }

    /** The agent's French version, published (and renamed when $title is given). */
    private function french( int $id, string $slug, string $title = '' ): int {
        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => 'fr', 'slug' => $slug ] )['contentId'];
        if ( $title !== '' ) {
            $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $fr, 'title' => $title ] );
        }
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        return $fr;
    }

    private function en( string ...$names ): array {
        return array_map( static fn( string $n ): int => self::$people[ $n ]['en'], $names );
    }

    private function fr( string ...$names ): array {
        return array_map( static fn( string $n ): int => self::$people[ $n ]['fr'], $names );
    }

    /** @return list<string> The original ids as stored rows. */
    private function stored( string ...$names ): array {
        return array_map( 'strval', $this->en( ...$names ) );
    }

    private function set( int $id, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
    }

    private function event( string $title, array $values ): int {
        $id = $this->record( 'post', $title );
        $this->set( $id, $values );
        return $id;
    }

    private function shown( int $id ): array {
        $url   = (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
        $page  = $this->get( $url );
        $theme = ThemeFields::read( $page['body'] );
        $this->assertSame( 200, $page['status'], $url );
        $this->assertNotNull( $theme, $url );
        return $theme['fields'];
    }

    /** What an archive's filter lists, in $lang, with $get as the address's query. @return int[] */
    private function filtered( string $lang, array $fields, array $get = [] ): array {
        return $this->site_runs( "do_action( 'wpml_switch_language', '$lang' );
            \$_GET = json_decode( '" . json_encode( $get ) . "', true );
            \$f    = \\Fabrement\\Modules\\Query\\Filter::from( [ 'type' => 'post', 'limit' => -1, 'sort' => [ 'title' => 'ASC' ], 'fields' => json_decode( '" . json_encode( $fields ) . "', true ) ] );
            return array_map( 'intval', wp_list_pluck( \$f->posts(), 'ID' ) );" );
    }

    public function test_each_policy_carries_a_list_its_own_way(): void {
        $event = $this->event( 'Event of lists', [ 'speakers' => [ ...$this->fr( 'Bob' ), ...$this->en( 'Ann' ) ], 'guests' => $this->en( 'Cid' ), 'notes' => $this->en( 'Ann' ) ] );
        $this->assertSame( $this->stored( 'Bob', 'Ann' ), $this->site()->meta( $event, 'fab_speakers' ), 'picked as the French Bob, stored as the original; a row each, in order' );
        $fr = $this->french( $event, 'event-of-lists-fr' );

        $this->assertSame( $this->stored( 'Bob', 'Ann' ), $this->site()->meta( $fr, 'fab_speakers' ), 'copy: the rows, in order' );
        $this->assertSame( $this->stored( 'Cid' ), $this->site()->meta( $fr, 'fab_guests' ), 'copy_once: copied to start from' );
        $this->assertSame( [], $this->site()->meta( $fr, 'fab_notes' ), 'ignore: never copied' );
        $this->assertSame( $this->fr( 'Bob', 'Ann' ), $this->shown( $fr )['speakers'], 'the French page lists the French versions' );
        $this->assertSame( $this->en( 'Bob', 'Ann' ), $this->shown( $event )['speakers'] );

        $this->set( $event, [ 'speakers' => $this->en( 'Cid' ), 'guests' => $this->en( 'Ann', 'Bob' ), 'notes' => $this->en( 'Bob' ) ] );
        $this->assertSame( $this->stored( 'Cid' ), $this->site()->meta( $fr, 'fab_speakers' ), 'copy follows the original' );
        $this->assertSame( $this->stored( 'Cid' ), $this->site()->meta( $fr, 'fab_guests' ), 'copy_once does not' );
        $this->assertSame( [], $this->site()->meta( $fr, 'fab_notes' ) );
        $this->assertSame( $this->fr( 'Cid' ), $this->shown( $fr )['speakers'] );

        $this->set( $fr, [ 'speakers' => $this->fr( 'Ann' ) ] );
        $this->assertSame( $this->stored( 'Ann' ), $this->site()->meta( $event, 'fab_speakers' ), 'A copied list is routed to the original.' );
        $this->assertSame( $this->stored( 'Ann' ), $this->site()->meta( $fr, 'fab_speakers' ) );

        $this->set( $fr, [ 'guests' => $this->fr( 'Ann' ), 'notes' => $this->fr( 'Cid' ) ] );
        $this->assertSame( $this->stored( 'Ann' ), $this->site()->meta( $fr, 'fab_guests' ), 'its own list, stored as the originals' );
        $this->assertSame( $this->stored( 'Cid' ), $this->site()->meta( $fr, 'fab_notes' ) );
        $this->assertSame( $this->stored( 'Bob' ), $this->site()->meta( $event, 'fab_notes' ), 'and the original keeps its own' );
        $this->assertSame( $this->fr( 'Ann' ), $this->shown( $fr )['guests'] );

        // An empty list keeps one null row, copied as the translation's empty list too.
        $this->set( $event, [ 'speakers' => [] ] );
        $this->assertSame( [ null ], $this->site()->meta( $event, 'fab_speakers' ) );
        $this->assertSame( [ null ], $this->site()->meta( $fr, 'fab_speakers' ) );
        $this->assertSame( [], $this->shown( $fr )['speakers'] );
        // copy_once stays the translation's own when the original empties its list.
        $this->set( $event, [ 'guests' => [] ] );
        $this->assertSame( $this->stored( 'Ann' ), $this->site()->meta( $fr, 'fab_guests' ) );
    }

    public function test_a_list_cannot_be_translated_word_by_word(): void {
        $current = $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => 'post' ] );
        $fields  = $current['fields'];
        foreach ( $fields as $i => $field ) {
            if ( $field['name'] === 'speakers' ) {
                $fields[ $i ]['translation'] = 'translate';
            }
        }
        $this->assertStringContainsString( 'translation', $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => 'post', 'expectedRevision' => $current['revision'], 'fields' => $fields ] ) );
        $this->assertSame( $current['revision'], $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => 'post' ] )['revision'], 'nothing saved' );
    }

    public function test_get_field_values_of_a_translation_round_trips_without_a_change(): void {
        $event = $this->event( 'Round trip', [ 'speakers' => $this->en( 'Bob', 'Ann' ), 'guests' => $this->en( 'Cid' ) ] );
        $fr    = $this->french( $event, 'round-trip-fr' );
        $read  = $this->mcp( 'get-field-values', [ 'postId' => $fr ] );
        $this->assertSame( $this->en( 'Bob', 'Ann' ), $read['values']['speakers'], 'the stored list: the originals\' ids' );

        $this->site()->php( "global \$wpdb; \$wpdb->update( \$wpdb->posts, [ 'post_modified' => '2001-01-01 00:00:00' ], [ 'ID' => $fr ] ); clean_post_cache( $fr );" );
        $this->set( $fr, [ 'guests' => $read['values']['guests'] ] );
        $this->assertSame( '2001-01-01 00:00:00', $this->site()->value( 'SELECT post_modified FROM wp_posts WHERE ID = ?', [ $fr ] ), 'the same list sent back is no change' );
    }

    public function test_a_translation_wpml_makes_holds_the_rows(): void {
        // Linked by a person in WPML's language box.
        $event = $this->event( 'Linked event', [ 'speakers' => $this->en( 'Cid', 'Ann' ), 'notes' => $this->en( 'Bob' ) ] );
        $fr    = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'post', 'title' => 'Événement lié', 'blocks' => [] ] )['contentId'];
        $this->wpml()->link( $fr, $this->wpml()->trid( $event, 'post' ), 'fr', 'post' );
        $this->assertSame( $this->stored( 'Cid', 'Ann' ), $this->site()->meta( $fr, 'fab_speakers' ) );
        $this->assertSame( [], $this->site()->meta( $fr, 'fab_notes' ) );

        // "Duplicate" in WPML's language box: the duplicate holds the rows, and follows the original.
        $dup = $this->wpml()->duplicate( $event, 'pl' );
        $this->assertGreaterThan( 0, $dup );
        $this->assertSame( $this->stored( 'Cid', 'Ann' ), $this->site()->meta( $dup, 'fab_speakers' ) );
        $this->set( $event, [ 'speakers' => $this->en( 'Bob' ) ] );
        $this->assertSame( $this->stored( 'Bob' ), $this->site()->meta( $dup, 'fab_speakers' ), 'the duplicate follows' );
        $this->assertSame( $this->stored( 'Bob' ), $this->site()->meta( $fr, 'fab_speakers' ), 'the linked translation too' );
    }

    public function test_a_plus_link_draft_shows_the_list_and_saves_it_as_a_translation(): void {
        $event   = $this->event( 'Plus event', [ 'speakers' => $this->en( 'Bob', 'Ann' ), 'guests' => $this->en( 'Cid' ) ] );
        $trid    = $this->wpml()->trid( $event, 'post' );
        $browser = new Browser( $this->site() );
        $browser->login( 'admin', 'admin' );
        // WPML's "+" for French in the original's language box opens the post editor.
        $page = $browser->get( $this->site()->url( "/wp-admin/post-new.php?post_type=post&trid=$trid&lang=fr&source_lang=en" ) );
        $this->assertSame( 200, $page['status'] );
        $this->assertSame( 1, preg_match( '#window\.fabrementFieldsState\["(\d+)"\] = (.*);$#m', $page['body'], $m ), 'the fields box rides in with the page' );
        $draft = (int) $m[1];
        $state = json_decode( $m[2], true );
        $this->assertSame( $this->en( 'Bob', 'Ann' ), $state['values']['fab_speakers'], 'the original\'s list, shown' );
        $this->assertSame( [], $this->site()->meta( $draft, 'fab_speakers' ), 'shown, not written' );

        // The editor saves: the box sends what the translator changed.
        $saved = \Fabrement\Tests\Site\Support\Http::request( $this->site(), 'PUT', $this->site()->url( '/wp-json/fabrement/v1/fields/post/' . $draft ), [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ),
            'Content-Type'  => 'application/json',
        ], (string) json_encode( [ 'values' => [ 'fab_guests' => $this->en( 'Ann' ) ], 'intent' => [ 'fab_guests' => true ], 'intentVersion' => 1 ] ) );
        $this->assertSame( 200, $saved['status'], $saved['body'] );
        $this->assertSame( $this->stored( 'Bob', 'Ann' ), $this->site()->meta( $draft, 'fab_speakers' ), 'what was not sent is the original\'s, stored' );
        $this->assertSame( $this->stored( 'Ann' ), $this->site()->meta( $draft, 'fab_guests' ) );

        $this->wpml()->link( $draft, $trid, 'fr', 'post' );   // WPML links it on its first save
        $this->assertSame( $this->stored( 'Ann' ), $this->site()->meta( $draft, 'fab_guests' ), 'linking keeps its own list' );
    }

    public function test_a_gallery_reads_each_languages_copy_of_an_image_and_is_found_by_either(): void {
        // WPML → Media Translation: every upload copied into each language.
        $this->site()->php( "\\WPML\\Media\\Option::setNewContentSettings( [ 'always_translate_media' => true, 'duplicate_media' => true, 'duplicate_featured' => true ] ); \\WPML\\Media\\Option::setShouldHandleMediaAuto( false );" );
        $image = $this->image( 'qa-hall.png' );
        $copy  = $this->wpml()->version( $image, 'fr', 'attachment' );
        $this->assertGreaterThan( 0, $copy );

        $event = $this->event( 'Gallery event', [ 'photos' => [ $copy ] ] );
        $fr    = $this->french( $event, 'gallery-event-fr' );
        $this->assertSame( [ (string) $image ], $this->site()->meta( $event, 'fab_photos' ), 'the copy picked, the original stored' );
        $this->assertSame( [ (string) $image ], $this->site()->meta( $fr, 'fab_photos' ) );
        $this->assertSame( [ $image ], $this->shown( $event )['photos'] );
        $this->assertSame( [ $copy ], $this->shown( $fr )['photos'], 'the French copy on the French page' );

        foreach ( [ $image, $copy ] as $either ) {
            $this->assertSame( [ $fr ], $this->filtered( 'fr', [ 'photos' => $either ] ), "the French event by image $either" );
            $this->assertSame( [ $event ], $this->filtered( 'en', [ 'photos' => $either ] ), "the English event by image $either" );
        }
    }

    private function speaker_counts(): array {
        return $this->site_runs( "do_action( 'wpml_switch_language', 'fr' );
            \$_GET = [];
            \$f    = \\Fabrement\\Modules\\Query\\Filter::from( [ 'type' => 'post', 'limit' => -1, 'fields' => [ 'speakers' => '?speaker' ] ] );
            \$out  = [];
            foreach ( \$f->counts( 'speakers' ) as \$id => \$n ) {
                \$out[ get_the_title( (int) apply_filters( 'wpml_object_id', (int) \$id, 'page', true, 'fr' ) ) ] = \$n;
            }
            ksort( \$out );
            return \$out;" );
    }
}
