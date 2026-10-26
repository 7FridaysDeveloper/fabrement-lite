<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\Browser;
use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\ThemeFields;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * Fields and translations a PERSON makes in WPML's own screens, next to what the agent does over
 * MCP: a page linked as a translation in WPML's language box, WPML's "Duplicate", the "+" link that
 * opens a new translation in the editor, WPML translation jobs, its "needs update" flag, its choice
 * of translation editor, and its custom-field settings screen.
 *
 * What those screens do is done the way they do it — the person's browser where a page is enough
 * (the "+" link, the fields box's form), otherwise WPML's own public API run as site code (WP-CLI,
 * WpmlSite) — never by writing WPML's tables. Every comment names the screen it stands for.
 */
final class WpmlTranslationScreensTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private static bool $ready = false;

    private ?Browser $browser = null;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->fields( 'page', [
                self::field( 'tagline', 'text' ),
                self::field( 'price', 'number', [ 'format' => 'decimal' ] ),
                self::field( 'since', 'number', [ 'format' => 'integer', 'translation' => 'copy_once' ] ),
                self::field( 'note', 'text', [ 'translation' => 'ignore' ] ),
                self::field( 'steps', 'repeater', [ 'sub_fields' => [ self::field( 'title', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ] ] ),
                self::field( 'cta', 'link' ),
                self::field( 'program', 'repeater', [ 'translation' => 'copy_once', 'sub_fields' => [ self::field( 'topic', 'text' ) ] ] ),
                self::field( 'details', 'group', [ 'translation' => 'copy', 'sub_fields' => [
                    self::field( 'city', 'text', [ 'translation' => 'copy' ] ),
                    self::field( 'intro', 'text' ),
                    self::field( 'memo', 'text', [ 'translation' => 'ignore' ] ),
                    self::field( 'stops', 'repeater', [ 'sub_fields' => [ self::field( 'name', 'text' ) ] ] ),
                ] ] ),
            ] );
            ThemeFields::install( $this->site() );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
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

    private function column( int $id, string $field, string $sub ): array {
        return array_column( $this->values( $id )[ $field ], $sub );
    }

    private function meta_rows( int $id ): array {
        return $this->site()->rows( 'SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? ORDER BY meta_id', [ $id ] );
    }

    private function refused( int $id, array $values ): string {
        $before = $this->meta_rows( $id );
        $error  = $this->mcp_error( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
        $this->assertSame( $before, $this->meta_rows( $id ), 'a refused call writes nothing' );
        return $error;
    }

    /** @return array{0:int,1:array} An English page the agent filled, and its steps as get-field-values gives them. */
    private function original( string $title ): array {
        $page  = $this->record( 'page', $title );
        $saved = $this->set( $page, [
            'tagline' => 'We build sites', 'price' => 12.5, 'since' => 2001, 'note' => 'Internal',
            'steps'   => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ] ],
        ] );
        return [ $page, $saved['values']['steps'] ];
    }

    /** The agent's French version, published. */
    private function translated( int $page, string $slug ): int {
        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'fr', 'slug' => $slug ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        return $fr;
    }

    private function shown( int $id ): array {
        $url   = (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
        $page  = $this->get( $url );
        $theme = ThemeFields::read( $page['body'] );
        $this->assertSame( 200, $page['status'], $url );
        $this->assertNotNull( $theme, $url );
        return $theme;
    }

    /** post_modified of a record, as stored. */
    private function modified( int $id ): string {
        return (string) $this->site()->value( 'SELECT post_modified FROM wp_posts WHERE ID = ?', [ $id ] );
    }

    /** The record was last saved long ago: so a save now is seen in its date. */
    private function age( int $id ): void {
        $this->site()->php( "global \$wpdb; \$wpdb->update( \$wpdb->posts, [ 'post_modified' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 00:00:00' ], [ 'ID' => $id ] ); clean_post_cache( $id );" );
    }

    // ── the administrator in wp-admin: the "+" link and the fields box's form ───────────────

    private function browser(): Browser {
        if ( $this->browser === null ) {
            $this->browser = new Browser( $this->site() );
            $this->browser->login( 'admin', 'admin' );
        }
        return $this->browser;
    }

    /**
     * WPML's "+" next to a language in the original's language box: post-new.php with the group
     * and the language. @return array{status:int, draft:int, state:?array, location:string} The
     * auto-draft WordPress opened and the state the Fabrement fields box rides in with the page.
     */
    private function plus( int $trid, string $lang ): array {
        $answer = $this->browser()->get( $this->site()->url( "/wp-admin/post-new.php?post_type=page&trid=$trid&lang=$lang&source_lang=en" ) );
        $draft  = 0;
        $state  = null;
        if ( preg_match( '#window\.fabrementFieldsState\["(\d+)"\] = (.*);$#m', $answer['body'], $m ) ) {
            $draft = (int) $m[1];
            $state = json_decode( $m[2], true );
        }
        return [ 'status' => $answer['status'], 'draft' => $draft, 'state' => $state, 'location' => (string) ( $answer['headers']['location'] ?? '' ) ];
    }

    /** What the fields box sends when the editor saves: PUT on its route, with what it marks as changed (default: every sent field). @return array{status:int, body:array} */
    private function box_saves( int $id, array $values, ?array $intent = null ): array {
        $answer = Http::request( $this->site(), 'PUT', $this->site()->url( '/wp-json/fabrement/v1/fields/post/' . $id ), [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ),
            'Content-Type'  => 'application/json',
        ], (string) json_encode( [ 'values' => $values, 'intent' => $intent ?? array_fill_keys( array_keys( $values ), true ), 'intentVersion' => 1 ] ) );
        return [ 'status' => $answer['status'], 'body' => (array) json_decode( $answer['body'], true ) ];
    }

    /** What the fields box loads for a record. */
    private function box( int $id ): array {
        $answer = Http::request( $this->site(), 'GET', $this->site()->url( '/wp-json/fabrement/v1/fields/post/' . $id ), [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ),
        ] );
        $this->assertSame( 200, $answer['status'], $answer['body'] );
        return (array) json_decode( $answer['body'], true );
    }

    // ── WPML's language box: linking, duplicating ───────────────────────────────────────────

    public function test_a_draft_linked_as_a_translation_in_wpmls_language_box_is_seeded(): void {
        [ $page, $steps ] = $this->original( 'Linked page' );
        $fr = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'Page liée', 'blocks' => [] ] )['contentId'];
        $this->assertSame( [], $this->site()->meta( $fr, 'fab_tagline' ) );

        // A person sets it "fr, a translation of Linked page" in WPML's language box.
        $this->wpml()->link( $fr, $this->wpml()->trid( $page ), 'fr' );
        $this->assertSame( 'fr', $this->wpml()->language( $fr ) );
        $values = $this->values( $fr );
        $this->assertSame( 'We build sites', $values['tagline'], 'translate: seeded to start from' );
        $this->assertEquals( 12.5, $values['price'], 'copy' );
        $this->assertSame( 2001, $values['since'], 'copy_once' );
        $this->assertNull( $values['note'], 'ignore is never copied' );
        $this->assertSame( array_column( $steps, '_row' ), array_column( $values['steps'], '_row' ), 'the rows are the original\'s' );
        $this->assertSame( [ '12.5' ], $this->site()->meta( $fr, 'fab_price' ), 'written to the version' );

        $this->set( $fr, [ 'tagline' => 'Nous créons des sites' ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        $shown = $this->shown( $fr );
        $this->assertSame( 'fr', $shown['lang'] );
        $this->assertSame( 'Nous créons des sites', $shown['fields']['tagline'] );
    }

    public function test_two_pages_made_apart_then_linked_keep_the_translated_rows(): void {
        [ $page, $steps ] = $this->original( 'Made apart' );
        $this->set( $page, [ 'details' => [ 'city' => 'Amsterdam', 'stops' => [ [ 'name' => 'Station' ], [ 'name' => 'Harbour' ] ] ] ] );
        // A French page the agent made as an original of its own, before anyone linked them.
        $fr = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'Fait à part', 'blocks' => [], 'language' => 'fr', 'newOriginal' => true ] )['contentId'];
        $this->assertSame( 'fr', $this->wpml()->language( $fr ) );
        $this->set( $fr, [
            'tagline' => 'Nous créons',
            'steps'   => [ [ 'title' => 'Un', 'code' => 'x' ], [ 'title' => 'Deux', 'code' => 'y' ], [ 'title' => 'Trois', 'code' => 'z' ] ],
            'details' => [ 'city' => 'Paris', 'intro' => 'Bienvenue', 'stops' => [ [ 'name' => 'Gare' ], [ 'name' => 'Port' ] ] ],
        ] );

        // Then a person links it to the English page in WPML's language box.
        $this->wpml()->link( $fr, $this->wpml()->trid( $page ), 'fr' );
        $this->assertSame( array_column( $steps, '_row' ), $this->column( $fr, 'steps', '_row' ), 'the rows are the original\'s now' );
        $this->assertSame( [ 'Un', 'Deux' ], $this->column( $fr, 'steps', 'title' ), 'matched by position: the French words stay, the list is the original\'s' );
        $this->assertSame( [ 'a', 'b' ], $this->column( $fr, 'steps', 'code' ), 'copied values are the original\'s' );
        $values = $this->values( $fr );
        $this->assertSame( 'Nous créons', $values['tagline'] );
        $this->assertSame( [ 'Amsterdam', 'Bienvenue' ], [ $values['details']['city'], $values['details']['intro'] ] );
        $this->assertSame( [ 'Gare', 'Port' ], array_column( $values['details']['stops'], 'name' ), 'a repeater in a group too' );
        $this->assertSame( [ '2' ], $this->site()->meta( $fr, 'fab_steps' ), 'the third French row is gone' );

        // Linked once: from now on the rows follow the original by id, not by position.
        $en = $this->values( $page )['steps'];
        $this->set( $page, [ 'steps' => [ $en[1], $en[0] ] ] );
        $this->assertSame( [ 'Deux', 'Un' ], $this->column( $fr, 'steps', 'title' ) );

        // A linked page with fewer rows gets the original's for the rest.
        [ $other ] = $this->original( 'Fewer rows' );
        $short     = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'Moins de lignes', 'blocks' => [], 'language' => 'fr', 'newOriginal' => true ] )['contentId'];
        $this->set( $short, [ 'steps' => [ [ 'title' => 'Un', 'code' => 'x' ] ] ] );
        $this->wpml()->link( $short, $this->wpml()->trid( $other ), 'fr' );
        $this->assertSame( [ 'Un', 'Two' ], $this->column( $short, 'steps', 'title' ) );
    }

    public function test_a_wpml_duplicate_follows_its_original_until_translated_on_its_own(): void {
        [ $page ] = $this->original( 'Duplicated page' );
        // "Duplicate" ticked for French in WPML's language box of the original.
        $dup = $this->wpml()->duplicate( $page, 'fr' );
        $this->assertGreaterThan( 0, $dup );
        $this->assertSame( [ (string) $page ], $this->site()->meta( $dup, '_icl_lang_duplicate_of' ) );
        $this->assertSame( 'We build sites', $this->values( $dup )['tagline'] );
        $this->assertSame( 'We build sites', $this->shown( $dup )['fields']['tagline'], 'the French duplicate\'s page' );

        // Only the duplicate holds `note` (ignore): WPML duplicating again must not delete it.
        $this->set( $page, [ 'note' => null ] );
        $this->set( $dup, [ 'note' => 'Interne' ] );
        $this->wpml()->duplicate( $page, 'fr' );
        $this->assertSame( 'Interne', $this->values( $dup )['note'], 'kept when WPML duplicates again' );

        // A change saved in the duplicate itself stays until the original is saved, as in WPML and ACF.
        $edited = $this->set( $dup, [ 'tagline' => 'Mot à mot' ] );
        $this->assertStringContainsString( 'Translate independently', $edited['note'] ?? '', 'the agent is told it will not last' );
        $this->assertSame( 'Mot à mot', $this->values( $dup )['tagline'], 'not reverted by its own save' );

        // The original changes words, a copy_once value and its rows: the duplicate follows all.
        $en = $this->values( $page )['steps'];
        $this->set( $page, [ 'tagline' => 'We build websites', 'since' => 2005, 'steps' => [ $en[1] ] ] );
        $values = $this->values( $dup );
        $this->assertSame( 'We build websites', $values['tagline'], 'translate words follow in a duplicate' );
        $this->assertSame( 2005, $values['since'], 'copy_once too' );
        $this->assertSame( [ 'Two' ], array_column( $values['steps'], 'title' ), 'rows too' );
        $this->assertSame( [], $this->site()->meta( $dup, 'fab_steps_1_title' ), 'no ghost rows' );
        $this->assertSame( 'Interne', $values['note'], 'ignore stays, though WPML deletes what it is not told to keep' );
        $this->assertSame( 'We build websites', $this->shown( $dup )['fields']['tagline'] );

        // An ignore sub-field inside a group is the duplicate's own too, filled or cleared above it.
        $this->set( $page, [ 'details' => [ 'city' => 'Amsterdam' ] ] );
        $this->set( $dup, [ 'details' => [ 'city' => 'Amsterdam', 'memo' => 'Mémo' ] ] );
        $this->set( $page, [ 'details' => [ 'city' => 'Paris' ] ] );
        $details = $this->values( $dup )['details'];
        $this->assertSame( [ 'Paris', 'Mémo' ], [ $details['city'], $details['memo'] ], 'mirrored, the ignore sub-field kept' );
        $this->set( $page, [ 'details' => null ] );
        $this->assertSame( 'Mémo', $this->values( $dup )['details']['memo'], 'kept when the original clears the group' );

        // "Translate independently" in WPML's box: the duplicate mark goes, the policies apply again.
        $this->site()->php( "delete_post_meta( $dup, '_icl_lang_duplicate_of' );" );
        $this->set( $dup, [ 'tagline' => 'Nous créons' ] );
        $this->set( $page, [ 'tagline' => 'We build apps' ] );
        $this->assertSame( 'Nous créons', $this->values( $dup )['tagline'] );
        $this->assertSame( 'Nous créons', $this->shown( $dup )['fields']['tagline'] );
    }

    // ── the "+" link: a translation opened in the WordPress editor ───────────────────────────

    public function test_a_plus_link_draft_shows_the_original_and_saves_as_a_translation(): void {
        [ $page, $steps ] = $this->original( 'Plus page' );
        $trid  = $this->wpml()->trid( $page );
        $plus  = $this->plus( $trid, 'fr' );
        $this->assertSame( 200, $plus['status'] );
        $this->assertGreaterThan( 0, $plus['draft'], 'the editor opened an auto-draft with the fields box' );
        $draft = $plus['draft'];
        $state = $plus['state'];
        $this->assertTrue( $state['translation']['isTranslation'], 'not saved yet, but a translation of the page' );
        $this->assertSame( 'We build sites', $state['values']['fab_tagline'] );
        $this->assertEquals( 12.5, $state['values']['fab_price'] );
        $this->assertSame( array_column( $steps, '_row' ), array_column( $state['values']['fab_steps'], 'id' ) );
        $this->assertSame( [], $this->site()->meta( $draft, 'fab_price' ), 'shown, not written: opening the form stores nothing' );

        // The form sends only what the translator changed: one translated row title.
        $rows                       = $state['values']['fab_steps'];
        $rows[0]['values']['title'] = 'Un';
        $saved = $this->box_saves( $draft, [ 'fab_steps' => $rows ], [ 'fab_steps' => [ $rows[0]['id'] => [ 'title' => true ] ] ] );
        $this->assertSame( 200, $saved['status'], json_encode( $saved['body'] ) );
        $this->assertSame( [ '12.5' ], $this->site()->meta( $draft, 'fab_price' ), 'what was not sent is the original\'s, stored' );
        $this->assertSame( [ 'Un', 'Two' ], $this->column( $draft, 'steps', 'title' ) );

        // WPML links the draft on its first save (its language box, saved with the post).
        $rows_before = $this->meta_rows( $draft );
        $this->wpml()->link( $draft, $trid, 'fr' );
        $this->assertSame( 'fr', $this->wpml()->language( $draft ) );
        $this->assertSame( [ 'Un', 'Two' ], $this->column( $draft, 'steps', 'title' ), 'the translated word is kept' );
        $this->assertSame( array_column( array_filter( $rows_before, static fn( $r ) => str_starts_with( (string) $r['meta_key'], 'fab_' ) ), 'meta_value', 'meta_key' ),
            array_column( array_filter( $this->meta_rows( $draft ), static fn( $r ) => str_starts_with( (string) $r['meta_key'], 'fab_' ) ), 'meta_value', 'meta_key' ),
            'the seed after linking writes nothing over what the translator saved' );
        $this->assertSame( [], array_values( array_filter( $this->site()->meta( $draft, '_fabrement_fields_translation_of' ) ) ), 'linked: no longer pending' );
    }

    public function test_a_plus_link_draft_of_a_trashed_original_is_its_translation_as_wpml_keeps_it(): void {
        [ $page ] = $this->original( 'Plus of a trashed page' );
        $trid     = $this->wpml()->trid( $page );
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'trash' ] );
        $plus = $this->plus( $trid, 'fr' );
        $this->assertSame( 200, $plus['status'] );
        $this->assertTrue( $plus['state']['translation']['isTranslation'], 'WPML keeps a trashed original the original of its group' );
        $this->assertSame( $page, $plus['state']['translation']['originalId'] );
        $this->assertSame( 'We build sites', $plus['state']['values']['fab_tagline'], 'the draft shows what it will hold once linked' );
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'restore' ] );
    }

    public function test_pending_translation_rejects_malformed_rows_and_routes_shared_values(): void {
        [ $page ] = $this->original( 'Pending rows' );
        $plus     = $this->plus( $this->wpml()->trid( $page ), 'fr' );
        $draft    = $plus['draft'];
        $rows     = $plus['state']['values']['fab_steps'];
        $rows[]   = [ 'id' => '', 'values' => [ 'title' => 'Mine', 'code' => 'z' ] ];
        $before   = $this->meta_rows( $draft );

        $this->assertGreaterThanOrEqual( 400, $this->box_saves( $draft, [ 'fab_steps' => $rows ] )['status'], 'An empty row ID is malformed.' );
        $this->assertSame( $before, $this->meta_rows( $draft ), 'Refused before seeding.' );
        $this->assertSame( 200, $this->box_saves( $draft, [ 'fab_price' => 99 ] )['status'] );
        $this->assertSame( [ '99' ], $this->site()->meta( $page, 'fab_price' ) );
    }

    public function test_a_plus_link_to_a_language_the_group_has_is_not_a_pending_translation(): void {
        [ $page ] = $this->original( 'Taken language' );
        $fr       = $this->translated( $page, 'taken-language-fr' );
        $trid     = $this->wpml()->trid( $page );

        $taken = $this->plus( $trid, 'fr' );
        if ( $taken['status'] === 302 ) {
            // WPML sends the person to the translation there is.
            $this->assertStringContainsString( 'post=' . $fr, $taken['location'] );
        } else {
            $this->assertFalse( $taken['state']['translation']['isTranslation'], 'fr is taken' );
        }
        $this->assertFalse( $this->plus( 999999, 'pl' )['state']['translation']['isTranslation'] ?? false, 'no such group' );
        $this->assertFalse( $this->plus( $trid, 'xx' )['state']['translation']['isTranslation'] ?? false, 'no such language' );
        $this->assertTrue( $this->plus( $trid, 'pl' )['state']['translation']['isTranslation'], 'Polish is still missing: a translation' );
    }

    // ── WPML's translation editor and its jobs ──────────────────────────────────────────────

    public function test_the_words_go_into_a_wpml_job_and_come_back_translated(): void {
        [ $page ] = $this->original( 'Job page' );
        $this->set( $page, [ 'cta' => [ 'url' => 'https://example.com/contact', 'label' => 'Contact us' ] ] );

        // A translation job for French, sent from WPML's Translation Dashboard, and the words it carries.
        $job = $this->site_runs( "wp_set_current_user( 1 );
            \$factory = wpml_tm_load_job_factory();
            \$id      = (int) \$factory->create_local_post_job( $page, 'fr', 1 );
            \$job     = \$factory->get_translation_job( \$id, false, 0, false );
            \$words   = [];
            foreach ( \$job->elements as \$element ) {
                if ( strpos( (string) \$element->field_type, 'fabrement-field-' ) === 0 ) {
                    \$words[] = base64_decode( (string) \$element->field_data );
                }
            }
            sort( \$words );
            return [ 'id' => \$id, 'words' => \$words ];" );
        $this->assertGreaterThan( 0, $job['id'] );
        $this->assertSame( [ 'Contact us', 'One', 'Two', 'We build sites', 'https://example.com/contact' ], $job['words'], 'translate words only: no copied code, no ignored note, no number' );

        // The translator finishes the job in WPML's translation editor: every field sent back, ours translated.
        $french = [ 'We build sites' => 'Nous créons des sites', 'One' => 'Un', 'Two' => 'Deux', 'Contact us' => 'Contactez-nous', 'https://example.com/contact' => 'exemple fr/contact' ];
        $this->site()->php( "wp_set_current_user( 1 );
            // Loaded as WPML loads it for its own editor when a request has not yet.
            if ( ! function_exists( 'wpml_tm_save_data' ) && defined( 'WPML_TM_PATH' ) ) {
                require_once WPML_TM_PATH . '/inc/wpml-private-actions-tm.php';
            }
            \$french  = " . var_export( $french, true ) . ";
            \$factory = wpml_tm_load_job_factory();
            \$job     = \$factory->get_translation_job( {$job['id']}, false, 0, false );
            \$fields  = [];
            foreach ( \$job->elements as \$element ) {
                if ( ! \$element->field_translate ) {
                    continue;
                }
                \$text = base64_decode( (string) \$element->field_data );
                \$fields[ \$element->field_type ] = [ 'tid' => \$element->tid, 'field_type' => \$element->field_type, 'format' => 'base64', 'finished' => 1,
                    'data' => \$french[ \$text ] ?? ( \$element->field_type === 'title' ? 'Page du travail' : \$text ) ];
            }
            wpml_tm_save_data( [ 'job_id' => {$job['id']}, 'complete' => 1, 'fields' => \$fields ], false );" );

        $fr = $this->wpml()->version( $page, 'fr' );
        $this->assertGreaterThan( 0, $fr, 'WPML made the French page from the job' );
        $values = $this->values( $fr );
        $this->assertSame( 'Nous créons des sites', $values['tagline'] );
        $this->assertSame( [ 'Un', 'Deux' ], array_column( $values['steps'], 'title' ) );
        $this->assertSame( [ 'a', 'b' ], array_column( $values['steps'], 'code' ), 'copied sub-fields stay the original\'s' );
        $this->assertSame( 'Contactez-nous', $values['cta']['label'], 'the link\'s caption is translated' );
        $this->assertSame( 'https://example.com/contact', $values['cta']['url'], 'a translated address that is no address: the link keeps the one it had' );
        $this->assertSame( [], array_values( array_filter( $this->site()->meta( $fr, '_fabrement_fields_sync_error' ) ) ), 'and no error is left on the version' );
        // The refused address is said where a developer looks (WP_DEBUG), not left silent.
        $this->assertStringContainsString( 'Translated address of "cta" refused, the link keeps its address', $this->site()->debug_log() );
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
    }

    public function test_changed_words_mark_the_translation_outdated_and_translating_them_brings_it_up_to_date(): void {
        [ $page ] = $this->original( 'Outdated page' );
        $fr       = $this->translated( $page, 'outdated-page-fr' );
        $this->set( $fr, [ 'tagline' => 'Nous créons des sites' ] );
        // The translator ticks "Translation complete": WPML counts it as translated today.
        $this->wpml()->mark_complete( $page, $fr );
        $this->assertSame( 0, $this->wpml()->needs_update( $fr ) );

        $this->age( $fr );
        $this->set( $page, [ 'price' => 15 ] );
        $this->assertSame( 0, $this->wpml()->needs_update( $fr ), 'a copied value is no word to translate' );
        $this->assertSame( '2020-01-01 00:00:00', $this->modified( $fr ), 'values only, as ACF copies them: the version is not re-dated' );

        $this->set( $page, [ 'tagline' => 'We build websites' ] );
        $this->assertSame( 1, $this->wpml()->needs_update( $fr ), 'changed words: WPML marks the translation outdated' );

        $content = (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $fr ] );
        $this->set( $fr, [ 'tagline' => 'Nous créons des sites web' ] );
        $this->assertSame( 0, $this->wpml()->needs_update( $fr ), 'translated by hand: WPML counts it as up to date again' );
        $this->assertEquals( 15, $this->values( $fr )['price'], 'what it copies is still the original\'s' );
        $this->assertSame( 'We build websites', $this->values( $page )['tagline'], 'the original untouched' );
        $this->assertSame( $content, (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $fr ] ), 'its content untouched' );
        $this->assertSame( 'Nous créons des sites web', $this->shown( $fr )['fields']['tagline'] );

        $this->age( $fr );
        $this->set( $fr, [ 'tagline' => 'Nous créons des sites web' ] );
        $this->assertSame( '2020-01-01 00:00:00', $this->modified( $fr ), 'the same words again: nothing saved' );
    }

    public function test_a_translation_wpmls_editor_owns_takes_only_its_own_values_here(): void {
        [ $page ] = $this->original( 'WPML editor page' );
        $fr       = $this->translated( $page, 'wpml-editor-page-fr' );
        $this->wpml()->mark_complete( $page, $fr );
        // "Translate with WPML's editor" switched on in the original's language box.
        $this->wpml()->use_editor( $page, 'wpml' );
        $this->set( $page, [ 'tagline' => 'We build websites' ] );
        $this->assertSame( 1, $this->wpml()->needs_update( $fr ) );

        // Its words are the editor's; its own values (ignore) may still be set here.
        $this->refused( $fr, [ 'tagline' => 'Nous créons' ] );
        $this->age( $fr );
        $this->set( $fr, [ 'note' => 'Interne' ] );
        $this->assertSame( [ 'Interne' ], $this->site()->meta( $fr, 'fab_note' ) );
        $this->assertSame( '2020-01-01 00:00:00', $this->modified( $fr ), 'not saved through WordPress: WPML would take it out of its editor' );
        $this->assertSame( 1, $this->wpml()->needs_update( $fr ), 'still to be updated in WPML\'s editor' );
        $this->assertTrue( $this->box( $fr )['translation']['managedByWpml'], 'the fields box says WPML\'s editor manages it' );
        $this->wpml()->use_editor( $page, 'native' );
    }

    /**
     * The site's own code on a translation WPML's editor manages: what the original owns goes to the
     * original — a row deleted, a copied value, a new row with the words it was given, which WPML's job
     * then translates — while the translation's words stay the editor's.
     */
    public function test_the_sites_code_changes_rows_of_a_translation_wpmls_editor_manages(): void {
        [ $page, $rows ] = $this->original( 'Code on a WPML editor page' );
        $fr     = $this->translated( $page, 'code-wpml-editor-page-fr' );
        $fr_rows = $this->values( $fr )['steps'];
        $this->set( $fr, [ 'steps' => [ [ '_row' => $fr_rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ], [ '_row' => $fr_rows[1]['_row'], 'title' => 'Deux', 'code' => 'b' ] ] ] );
        $this->wpml()->mark_complete( $page, $fr );
        $this->wpml()->use_editor( $page, 'wpml' );
        [ $a, $b ] = [ $rows[0]['_row'], $rows[1]['_row'] ];
        $run = fn( string $code ) => $this->site_runs( "try { $code return 'saved'; } catch ( \Fabrement\Api\FieldsException \$e ) { return \$e->getMessage(); }" );
        $steps = fn( int $id ): array => array_map( static fn( array $r ): array => [ $r['title'], $r['code'] ], $this->values( $id )['steps'] );

        $this->assertStringContainsString( 'Translation Editor', $run( "fabrement()->fields( $fr )->set_text( 'steps', '$a', 'title', 'Un !' )->save();" ), 'its words belong to the editor' );

        $this->assertSame( 'saved', $run( "fabrement()->fields( $fr )->set_text( 'steps', '$a', 'code', 'a2' )->save();" ) );
        $this->assertSame( [ [ 'One', 'a2' ], [ 'Two', 'b' ] ], $steps( $page ), 'a copied value: changed on the original' );
        $this->assertSame( [ [ 'Un', 'a2' ], [ 'Deux', 'b' ] ], $steps( $fr ) );

        $this->assertSame( 'saved', $run( "\$f = fabrement()->fields( $fr ); \$f->add_row( 'steps', [ 'title' => 'Trois', 'code' => 'c' ] ); \$f->save();" ) );
        $this->assertSame( [ [ 'One', 'a2' ], [ 'Two', 'b' ], [ 'Trois', 'c' ] ], $steps( $page ), 'a new row: on the original, with the words it was given' );
        $this->assertSame( [ [ 'Un', 'a2' ], [ 'Deux', 'b' ], [ 'Trois', 'c' ] ], $steps( $fr ), 'and here, the words of the original until the WPML job translates them' );

        $this->assertSame( 'saved', $run( "fabrement()->fields( $fr )->delete_row( 'steps', '$b' )->save();" ) );
        $this->assertSame( [ [ 'One', 'a2' ], [ 'Trois', 'c' ] ], $steps( $page ), 'a row deleted: gone from the original' );
        $this->assertSame( [ [ 'Un', 'a2' ], [ 'Trois', 'c' ] ], $steps( $fr ), 'and from the translation, its words kept on the rows left' );
        $this->wpml()->use_editor( $page, 'native' );
    }

    public function test_wpmls_editor_leaves_a_copy_once_repeaters_own_rows_to_the_translation(): void {
        $page = $this->record( 'page', 'Program page' );
        $this->set( $page, [ 'program' => [ [ 'topic' => 'Opening' ], [ 'topic' => 'Workshop' ] ] ] );
        $fr   = $this->translated( $page, 'program-page-fr' );
        $rows = $this->values( $fr )['program'];
        $this->set( $fr, [ 'program' => [ [ '_row' => $rows[0]['_row'], 'topic' => 'Ouverture' ], [ '_row' => $rows[1]['_row'], 'topic' => 'Atelier' ], [ 'topic' => 'Soirée' ] ] ] );

        // Now WPML's translation editor manages the translation.
        $this->wpml()->use_editor( $page, 'wpml' );
        $this->assertNotContains( 'fab_program/topic', $this->box( $fr )['translation']['wordFields'], 'the form does not lock the words of its own list' );
        $rows = $this->values( $fr )['program'];
        $this->set( $fr, [ 'program' => [ $rows[0], $rows[1], [ '_row' => $rows[2]['_row'], 'topic' => 'Soirée des partenaires' ], [ 'topic' => 'Clôture' ] ] ] );
        $this->assertSame( [ 'Ouverture', 'Atelier', 'Soirée des partenaires', 'Clôture' ], $this->column( $fr, 'program', 'topic' ), 'its own rows are written here, and a new one added' );
        $this->assertSame( [ 'Ouverture', 'Atelier', 'Soirée des partenaires', 'Clôture' ], array_column( $this->shown( $fr )['fields']['program'], 'topic' ) );

        $rows    = $this->values( $fr )['program'];
        $shared  = $rows;
        $shared[0]['topic'] = 'Ouverture (main)';
        $this->assertStringContainsString( 'Translation Editor', $this->refused( $fr, [ 'program' => $shared ] ), 'a row it shares with the original is translated in WPML\'s editor' );

        // A WPML job still carries the shared rows, and only those (WPML's own translation package).
        $words = $this->site_runs( "\$package = ( new WPML_Element_Translation_Package() )->create_translation_package( get_post( $page ), true );
            \$words = [];
            foreach ( \$package['contents'] as \$key => \$entry ) {
                if ( strpos( (string) \$key, 'fabrement-field-' ) === 0 ) {
                    \$words[] = base64_decode( \$entry['data'] );
                }
            }
            sort( \$words );
            return \$words;" );
        $this->assertSame( [ 'Opening', 'Workshop' ], $words );
        $this->wpml()->use_editor( $page, 'native' );
    }

    // ── WPML's custom-field settings screen ─────────────────────────────────────────────────

    public function test_wpmls_custom_fields_screen_shows_our_keys_locked_with_their_policy(): void {
        // What WPML's Settings → Custom Fields Translation asks when it draws each row.
        $rows = $this->site_runs( "\$tm  = wpml_load_core_tm();
            \$out = [];
            foreach ( [ 'fab_tagline', 'fab_price', 'fab_steps_0_title', 'fab_steps_1__rid', 'fab_details_stops_0_name', 'fab_details_memo', 'qa_another_plugin_key' ] as \$key ) {
                \$setting = new WPML_Post_Custom_Field_Setting( \$tm, \$key );
                ob_start();
                \$replaced    = apply_filters( 'wpml_custom_field_settings_override_lock_render', false, \$setting );
                \$out[ \$key ] = [ 'disabled' => \$setting->get_html_disabled(), 'replaced' => (bool) \$replaced, 'html' => trim( (string) ob_get_clean() ) ];
            }
            return \$out;" );
        foreach ( [
            'fab_tagline'              => 'Fabrement · Tagline: translate',
            'fab_price'                => 'Fabrement · Price: copy',
            'fab_steps_0_title'        => 'Fabrement · Steps / Title: translate',
            'fab_steps_1__rid'         => 'Fabrement · Steps: copy',
            'fab_details_stops_0_name' => 'Fabrement · Details / Stops / Name: translate',
            'fab_details_memo'         => 'Fabrement · Details / Memo: ignore',
        ] as $key => $label ) {
            $this->assertSame( 'disabled="disabled"', $rows[ $key ]['disabled'], $key . ' cannot be set on WPML\'s screen' );
            $this->assertTrue( $rows[ $key ]['replaced'], $key );
            $this->assertStringContainsString( $label, $rows[ $key ]['html'], $key );
        }
        $this->assertSame( [ 'disabled' => '', 'replaced' => false, 'html' => '' ], $rows['qa_another_plugin_key'], 'another plugin\'s key is left alone' );
    }

    // ── a sync that did not reach the translation ───────────────────────────────────────────

    public function test_a_translation_with_stale_rows_still_saves_its_words_and_heals(): void {
        $page = $this->record( 'page', 'Stale rows page' );
        $this->set( $page, [ 'steps' => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ] ] ] );
        $fr   = $this->translated( $page, 'stale-rows-fr' );
        $held = $this->values( $fr )['steps'];
        $this->set( $fr, [ 'steps' => [ [ '_row' => $held[0]['_row'], 'title' => 'Un', 'code' => 'a' ], [ '_row' => $held[1]['_row'], 'title' => 'Deux', 'code' => 'b' ] ] ] );

        // Another plugin breaks the field listeners for a while: the original's new row does not reach the translation.
        $this->site_code( 'qa-sync-broken', "add_action( 'init', static function (): void { remove_all_actions( 'fabrement_fields_saved' ); }, 1000 );" );
        try {
            $en = $this->values( $page )['steps'];
            $this->set( $page, [ 'steps' => [ $en[0], $en[1], [ 'title' => 'Three', 'code' => 'c' ] ] ] );
        } finally {
            $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/qa-sync-broken.php' );
        }
        $this->assertSame( [ '2' ], $this->site()->meta( $fr, 'fab_steps' ), 'stale: the sync did not reach it' );

        // The translator changes one word, sending back the rows the form shows.
        $this->set( $fr, [ 'steps' => [ [ '_row' => $held[0]['_row'], 'title' => 'Un (corrigé)', 'code' => 'a' ], [ '_row' => $held[1]['_row'], 'title' => 'Deux', 'code' => 'b' ] ] ] );
        $this->assertSame( [ 'Un (corrigé)', 'Deux', 'Three' ], $this->column( $fr, 'steps', 'title' ), 'saved, and the rows healed from the original' );
        $this->assertSame( [ 'a', 'b', 'c' ], $this->column( $fr, 'steps', 'code' ) );
        $this->assertSame( [ 'Un (corrigé)', 'Deux', 'Three' ], array_column( $this->shown( $fr )['fields']['steps'], 'title' ) );

        // Changing the row list itself is still the original's.
        $rows = $this->values( $fr )['steps'];
        $this->set( $fr, [ 'steps' => [ $rows[1], $rows[0], $rows[2] ] ] );
        $this->assertSame( [ $rows[1]['_row'], $rows[0]['_row'], $rows[2]['_row'] ], array_column( $this->values( $page )['steps'], '_row' ) );
    }

    // ── last: changes a site-wide WPML setting ──────────────────────────────────────────────

    public function test_a_pending_translation_follows_the_originals_own_editor_choice(): void {
        [ $page ] = $this->original( 'Own editor choice' );
        // The site translates with WPML's editor (WPML → Settings → Translation Editor); this page
        // with the WordPress one — which is why "+" opened the post editor.
        $setting = "global \$sitepress; \$tm = (array) \$sitepress->get_setting( 'translation-management', [] );";
        $this->site()->php( $setting . " \$tm[ WPML_TM_Post_Edit_TM_Editor_Mode::TM_KEY_GLOBAL_EDITOR ] = WPML_TM_Post_Edit_TM_Editor_Mode::EDITOR_WPML; \$sitepress->set_setting( 'translation-management', \$tm, true );" );
        $this->wpml()->use_editor( $page, 'native' );
        try {
            $plus = $this->plus( $this->wpml()->trid( $page ), 'fr' );
            $this->assertGreaterThan( 0, $plus['draft'], 'the WordPress editor opened' );
            $this->assertFalse( $plus['state']['translation']['managedByWpml'] );
            $this->assertSame( [], $plus['state']['translation']['wordFields'] );
            $this->assertSame( 200, $this->box_saves( $plus['draft'], [ 'fab_tagline' => 'Nous créons des sites' ] )['status'] );
            $this->assertSame( [ 'Nous créons des sites' ], $this->site()->meta( $plus['draft'], 'fab_tagline' ) );
        } finally {
            $this->site()->php( $setting . " \$tm[ WPML_TM_Post_Edit_TM_Editor_Mode::TM_KEY_GLOBAL_EDITOR ] = WPML_TM_Post_Edit_TM_Editor_Mode::EDITOR_NATIVE; \$sitepress->set_setting( 'translation-management', \$tm, true );" );
        }
    }
}
