<?php

namespace Fabrement\Tests\Wpml;

use Fabrement\Route\Fields\PostFieldsRestRoute;
use Fabrement\Modules\Fields\Write\FieldWrite;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Fields\Translation\PostTranslations;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\FieldRead;
use Fabrement\Modules\Seo\Schema\SchemaStore;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Fabrement fields across languages on the real WPML: each translation policy of a record's field
 * (translate / copy / copy_once / ignore), repeater rows followed by their row id, an options page
 * per language, and schema tokens read in the reader's language.
 */
final class FieldsTranslationTest extends WpmlTestCase {

    private const OPTIONS = 'qa-wpml-site';

    public function set_up(): void {
        parent::set_up();
        $f     = static fn( string $name, string $type, array $extra = [] ): array => $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
        $saved = $this->tool( 'save-fields', [ 'type' => 'post', 'slug' => 'page', 'expectedRevision' => 0, 'fields' => [
            $f( 'tagline', 'text' ),                                   // translate (the default for words)
            $f( 'price', 'number', [ 'format' => 'decimal' ] ),        // copy (the default for a number)
            $f( 'since', 'number', [ 'format' => 'integer', 'translation' => 'copy_once' ] ),
            $f( 'note', 'text', [ 'translation' => 'ignore' ] ),
            $f( 'steps', 'repeater', [ 'sub_fields' => [
                $f( 'title', 'text' ),
                $f( 'code', 'text', [ 'translation' => 'copy' ] ),
            ] ] ),
            $f( 'cta', 'link' ),                                       // translate: caption and typed address
            $f( 'program', 'repeater', [ 'translation' => 'copy_once', 'sub_fields' => [ $f( 'topic', 'text' ) ] ] ),
            $f( 'details', 'group', [ 'translation' => 'copy', 'sub_fields' => [
                $f( 'city', 'text', [ 'translation' => 'copy' ] ),
                $f( 'intro', 'text' ),
                $f( 'memo', 'text', [ 'translation' => 'ignore' ] ),
                $f( 'stops', 'repeater', [ 'sub_fields' => [ $f( 'name', 'text' ) ] ] ),
            ] ] ),
        ] ] );
        $this->assertIsArray( $saved, $saved instanceof WP_Error ? $saved->get_error_message() : '' );
    }

    public function tear_down(): void {
        foreach ( [ Owner::of( 'post', 'page' ), Owner::of( 'options', self::OPTIONS ) ] as $owner ) {
            if ( $owner ) {
                SchemaRepository::delete( $owner );
            }
        }
        SchemaRepository::flush();
        parent::tear_down();
    }

    /** Set fields the way an agent does. @return mixed */
    private function set( array $target, array $values ) {
        return $this->tool( 'set-field-values', $target + [ 'values' => $values ] );
    }

    private function in_french( callable $read ) {
        return Translation::bridge()->in_language( 'fr', $read );
    }

    /** @return array{0:int,1:int} the English page and its French version */
    private function translated_page( array $values ): array {
        $page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $ok   = $this->set( [ 'postId' => $page ], $values );
        $this->assertIsArray( $ok, $ok instanceof WP_Error ? $ok->get_error_message() : '' );
        $fr = $this->tool( 'translate-content', [ 'contentId' => $page, 'language' => 'fr' ] );
        $this->assertIsArray( $fr, $fr instanceof WP_Error ? $fr->get_error_message() : '' );
        return [ $page, (int) $fr['contentId'] ];
    }

    public function test_translate_starts_from_the_original_then_is_the_versions_own(): void {
        [ $page, $fr ] = $this->translated_page( [ 'tagline' => 'We build sites' ] );
        $this->assertSame( 'We build sites', fabrement_get_field( 'tagline', $fr ), 'the words to translate, to start from' );

        $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'tagline' => 'Nous créons des sites' ] ) );
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'tagline' => 'We build websites' ] ) );
        $this->assertSame( 'Nous créons des sites', fabrement_get_field( 'tagline', $fr ), 'a change of the original does not overwrite the translation' );
    }

    public function test_copy_follows_the_original_and_version_edits_route_back(): void {
        [ $page, $fr ] = $this->translated_page( [ 'price' => 12.5 ] );
        $this->assertEquals( 12.5, fabrement_get_field( 'price', $fr ) );

        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'price' => 15 ] ) );
        $this->assertEquals( 15, fabrement_get_field( 'price', $fr ), 'the original\'s change reaches the version' );

        $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'price' => 99 ] ), 'the value is routed to the original' );
        $this->assertEquals( 99, fabrement_get_field( 'price', $fr ) );
        $this->assertEquals( 99, fabrement_get_field( 'price', $page ) );
    }

    public function test_copy_once_is_copied_once_then_the_versions_own(): void {
        [ $page, $fr ] = $this->translated_page( [ 'since' => 2001 ] );
        $this->assertSame( 2001, fabrement_get_field( 'since', $fr ) );

        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'since' => 2005 ] ) );
        $this->assertSame( 2001, fabrement_get_field( 'since', $fr ), 'not followed after the first copy' );
        $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'since' => 1999 ] ) );
        $this->assertSame( 1999, fabrement_get_field( 'since', $fr ) );
    }

    public function test_ignore_is_never_copied(): void {
        [ $page, $fr ] = $this->translated_page( [ 'note' => 'Internal' ] );
        $this->assertEmpty( fabrement_get_field( 'note', $fr ) );

        $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'note' => 'Interne' ] ) );
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'note' => 'Changed' ] ) );
        $this->assertSame( 'Interne', fabrement_get_field( 'note', $fr ) );
    }

    public function test_a_group_the_original_clears_keeps_what_the_translation_owns(): void {
        [ $page, $fr ] = $this->translated_page( [ 'details' => [ 'city' => 'Amsterdam', 'intro' => 'Welcome' ] ] );
        $own = $this->set( [ 'postId' => $fr ], [ 'details' => [ 'city' => 'Amsterdam', 'intro' => 'Bienvenue', 'memo' => 'Interne' ] ] );
        $this->assertIsArray( $own, $own instanceof WP_Error ? $own->get_error_message() : '' );
        $details = fn(): array => $this->tool( 'get-field-values', [ 'postId' => $fr ] )['values']['details'];

        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'details' => null ] ) );
        $this->assertSame( '', $details()['city'], 'what the original copies is cleared with it' );
        $this->assertSame( 'Bienvenue', $details()['intro'], 'the translated words stay' );
        $this->assertSame( 'Interne', $details()['memo'], 'the translation\'s own value stays' );

        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'details' => [ 'city' => 'Paris', 'intro' => 'Hello' ] ] ) );
        $this->assertSame( [ 'Paris', 'Bienvenue', 'Interne' ], array_values( array_intersect_key( $details(), array_flip( [ 'city', 'intro', 'memo' ] ) ) ), 'filled again: copied again, words and own value kept' );
    }

    public function test_a_translated_address_that_is_no_address_costs_the_job_no_other_word(): void {
        [ $page, $fr ] = $this->translated_page( [ 'tagline' => 'We build sites', 'cta' => [ 'url' => 'https://example.com/contact', 'label' => 'Contact us' ] ] );
        // The words a WPML job carries for the original, as the translator sends them back.
        $texts = PostTranslations::texts( FieldObject::post( $page ) );
        $translated = [ 'We build sites' => 'Nous créons des sites', 'Contact us' => 'Contactez-nous', 'https://example.com/contact' => 'exemple fr/contact' ];
        $this->assertCount( 3, $texts );
        $texts = array_map( static fn( array $t ): string => $translated[ $t['text'] ], $texts );

        $this->assertTrue( PostTranslations::sync( $page, $fr, $texts ) );
        $this->assertSame( 'Nous créons des sites', fabrement_get_field( 'tagline', $fr ), 'the other words are written' );
        $cta = $this->tool( 'get-field-values', [ 'postId' => $fr ] )['values']['cta'];
        $this->assertSame( 'Contactez-nous', $cta['label'], 'the link\'s caption is translated' );
        $this->assertSame( 'https://example.com/contact', $cta['url'], 'the link keeps the address it had' );
        $this->assertSame( '', get_post_meta( $fr, '_fabrement_fields_sync_error', true ) );
    }

    public function test_repeater_rows_keep_their_words_when_the_original_moves_them(): void {
        [ $page, $fr ] = $this->translated_page( [ 'steps' => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ] ] ] );
        $rows = $this->tool( 'get-field-values', [ 'postId' => $fr ] )['values']['steps'];
        $this->assertCount( 2, $rows );
        $words = $this->set( [ 'postId' => $fr ], [ 'steps' => [
            [ '_row' => $rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ],
            [ '_row' => $rows[1]['_row'], 'title' => 'Deux', 'code' => 'b' ],
        ] ] );
        $this->assertIsArray( $words, $words instanceof WP_Error ? $words->get_error_message() : '' );

        // The original swaps its rows, adds one and changes a copied code.
        $en = $this->tool( 'get-field-values', [ 'postId' => $page ] )['values']['steps'];
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'steps' => [
            [ '_row' => $en[1]['_row'], 'title' => 'Two', 'code' => 'b' ],
            [ 'title' => 'Three', 'code' => 'c' ],
            [ '_row' => $en[0]['_row'], 'title' => 'One', 'code' => 'x' ],
        ] ] ) );

        $moved = fabrement_get_field( 'steps', $fr );
        $this->assertSame( [ 'Deux', 'Three', 'Un' ], array_column( $moved, 'title' ), 'each translated row stays with its row; the new row arrives' );
        $this->assertSame( 'x', $moved[2]['code'], 'the copied sub-field follows' );
    }

    public function test_options_page_links_keep_the_language(): void {
        $f     = static fn( string $name, string $type ): array => [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
        $saved = $this->tool( 'save-options-page', [ 'slug' => self::OPTIONS, 'expectedRevision' => 0, 'page' => [ 'title' => 'Site' ], 'fields' => [ $f( 'phone', 'text' ) ] ] );
        $this->assertIsArray( $saved, $saved instanceof WP_Error ? $saved->get_error_message() : '' );
        $lang = static fn( string $url ): string => (string) ( wp_parse_args( (string) wp_parse_url( $url, PHP_URL_QUERY ) )['lang'] ?? '' );

        $screen = new \Fabrement\Page\SiteOptionsPage();
        $this->assertSame( '', $lang( $screen->tab_url( self::OPTIONS ) ), 'no language picked: the original' );
        $_GET['lang'] = 'fr';
        try {
            $this->assertSame( 'fr', $lang( $screen->tab_url( self::OPTIONS ) ), 'another page\'s tab opens in the language on screen' );
        } finally {
            unset( $_GET['lang'] );
        }

    }

    public function test_an_options_page_has_values_per_language(): void {
        $f     = static fn( string $name, string $type, array $extra = [] ): array => $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
        $saved = $this->tool( 'save-options-page', [ 'slug' => self::OPTIONS, 'expectedRevision' => 0, 'page' => [ 'title' => 'Site' ], 'fields' => [
            $f( 'phone', 'text', [ 'translation' => 'copy' ] ),
            $f( 'slogan', 'text' ),
        ] ] );
        $this->assertIsArray( $saved, $saved instanceof WP_Error ? $saved->get_error_message() : '' );
        $options = [ 'options' => self::OPTIONS ];
        $this->assertIsArray( $this->set( $options, [ 'phone' => '+380 44 000', 'slogan' => 'Hello' ] ) );

        $this->assertSame( 'Hello', $this->in_french( static fn() => fabrement_get_option( 'slogan', self::OPTIONS ) ), 'a language nobody saved reads the original' );
        $this->assertFalse( get_option( 'fabrement_options_' . self::OPTIONS . '.fr' ), 'and has no row of its own' );

        $own = $this->set( $options + [ 'language' => 'fr' ], [ 'slogan' => 'Bonjour' ] );
        $this->assertIsArray( $own, $own instanceof WP_Error ? $own->get_error_message() : '' );
        $this->assertSame( 'Bonjour', $this->in_french( static fn() => fabrement_get_option( 'slogan', self::OPTIONS ) ) );
        $this->assertSame( 'Hello', fabrement_get_option( 'slogan', self::OPTIONS ), 'the original keeps its words' );

        $this->assertIsArray( $this->set( $options, [ 'phone' => '+380 44 999' ] ) );
        $this->assertSame( '+380 44 999', $this->in_french( static fn() => fabrement_get_option( 'phone', self::OPTIONS ) ), 'a copy value is the original\'s, at once' );
        $routed = $this->set( $options + [ 'language' => 'fr' ], [ 'phone' => '+33 1 00' ] );
        $this->assertIsArray( $routed );
        $this->assertSame( 'en', $routed['routed'][0]['language'] );
        $form = ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( FieldObject::options( self::OPTIONS, 'fr' ), [ 'fab_phone' => '+33 1 00' ], [], array_fill_keys( array_keys( [ 'fab_phone' => '+33 1 00' ] ), true ) );
        $this->assertIsArray( $form );
        $this->assertSame( '+33 1 00', fabrement_get_option( 'phone', self::OPTIONS ) );
    }

    private function trid( int $post_id ): int {
        return (int) apply_filters( 'wpml_element_trid', null, $post_id, 'post_page' );
    }

    /** Link a post into a group the way WPML's own screens do: not through our bridge. */
    private function link_by_wpml( int $post_id, int $trid, string $lang ): void {
        global $sitepress;
        $sitepress->set_element_language_details( $post_id, 'post_page', $trid, $lang, 'en' );
    }

    /** An auto-draft opened with WPML's "+" link (post-new.php?post_type=page&trid=…&lang=…). */
    private function pending_draft( int $trid, string $lang ): int {
        $draft = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'auto-draft', 'post_title' => 'Auto Draft' ] );
        $_GET['trid'] = (string) $trid;
        $_GET['lang'] = $lang;
        try {
            PostTranslations::remember_pending( get_post( $draft ) );
        } finally {
            unset( $_GET['trid'], $_GET['lang'] );
        }
        return $draft;
    }

    /** @return array{0:int,1:array} the English page and its steps as get-field-values gives them */
    private function original(): array {
        $page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $ok   = $this->set( [ 'postId' => $page ], [
            'tagline' => 'We build sites', 'price' => 12.5, 'since' => 2001, 'note' => 'Internal',
            'steps'   => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ] ],
        ] );
        $this->assertIsArray( $ok, $ok instanceof WP_Error ? $ok->get_error_message() : '' );
        return [ $page, $ok['values']['steps'] ];
    }

    /** A French page made on its own, in its own translation group, with its own values. */
    private function french_on_its_own( array $values ): int {
        global $sitepress;
        $fr = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $sitepress->set_element_language_details( $fr, 'post_page', false, 'fr', null );
        $ok = $this->set( [ 'postId' => $fr ], $values );
        $this->assertIsArray( $ok, $ok instanceof WP_Error ? $ok->get_error_message() : '' );
        return $fr;
    }

    private function column( int $post, string $field, string $sub ): array {
        return array_column( $this->tool( 'get-field-values', [ 'postId' => $post ] )['values'][ $field ], $sub );
    }

    public function test_two_pages_made_apart_then_linked_keep_the_translated_rows(): void {
        [ $page, $steps ] = $this->original();
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'details' => [ 'city' => 'Amsterdam', 'stops' => [ [ 'name' => 'Station' ], [ 'name' => 'Harbour' ] ] ] ] ) );
        $fr = $this->french_on_its_own( [
            'tagline' => 'Nous créons',
            'steps'   => [ [ 'title' => 'Un', 'code' => 'x' ], [ 'title' => 'Deux', 'code' => 'y' ], [ 'title' => 'Trois', 'code' => 'z' ] ],
            'details' => [ 'city' => 'Paris', 'intro' => 'Bienvenue', 'stops' => [ [ 'name' => 'Gare' ], [ 'name' => 'Port' ] ] ],
        ] );

        $this->link_by_wpml( $fr, $this->trid( $page ), 'fr' );
        $this->assertSame( array_column( $steps, '_row' ), $this->column( $fr, 'steps', '_row' ), 'the rows are the original\'s now' );
        $this->assertSame( [ 'Un', 'Deux' ], $this->column( $fr, 'steps', 'title' ), 'matched by position: the French words stay, the list is the original\'s' );
        $this->assertSame( [ 'a', 'b' ], $this->column( $fr, 'steps', 'code' ), 'copied values are the original\'s' );
        $this->assertSame( 'Nous créons', fabrement_get_field( 'tagline', $fr ) );
        $details = $this->tool( 'get-field-values', [ 'postId' => $fr ] )['values']['details'];
        $this->assertSame( [ 'Amsterdam', 'Bienvenue' ], [ $details['city'], $details['intro'] ] );
        $this->assertSame( [ 'Gare', 'Port' ], array_column( $details['stops'], 'name' ), 'a repeater in a group too' );

        // Linked once: from now on the rows follow the original by id, not by position.
        $en = $this->tool( 'get-field-values', [ 'postId' => $page ] )['values']['steps'];
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'steps' => [ $en[1], $en[0] ] ] ) );
        $this->assertSame( [ 'Deux', 'Un' ], $this->column( $fr, 'steps', 'title' ) );
    }

    public function test_a_linked_page_with_fewer_rows_gets_the_originals_for_the_rest(): void {
        [ $page ] = $this->original();
        $fr = $this->french_on_its_own( [ 'steps' => [ [ 'title' => 'Un', 'code' => 'x' ] ] ] );
        $this->link_by_wpml( $fr, $this->trid( $page ), 'fr' );
        $this->assertSame( [ 'Un', 'Two' ], $this->column( $fr, 'steps', 'title' ) );
    }

    public function test_a_wpml_duplicate_follows_its_original_until_translated_on_its_own(): void {
        global $sitepress;
        [ $page ] = $this->original();
        $dup = (int) $sitepress->make_duplicate( $page, 'fr' );
        $this->assertGreaterThan( 0, $dup );
        $this->assertSame( $page, (int) get_post_meta( $dup, '_icl_lang_duplicate_of', true ) );
        $this->assertSame( 'We build sites', fabrement_get_field( 'tagline', $dup ) );
        // Only the duplicate holds `note` (ignore): WPML duplicating again must not delete it.
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'note' => null ] ) );
        $this->assertIsArray( $this->set( [ 'postId' => $dup ], [ 'note' => 'Interne' ] ), 'ignore is the duplicate\'s own' );
        $sitepress->make_duplicate( $page, 'fr' );
        $this->assertSame( 'Interne', fabrement_get_field( 'note', $dup ), 'kept when WPML duplicates again' );

        // A change saved in the duplicate itself stays until the original is saved, as in WPML and ACF.
        $edited = $this->set( [ 'postId' => $dup ], [ 'tagline' => 'Mot à mot' ] );
        $this->assertIsArray( $edited );
        $this->assertStringContainsString( 'Translate independently', $edited['note'] ?? '', 'the agent is told it will not last' );
        $this->assertSame( 'Mot à mot', fabrement_get_field( 'tagline', $dup ), 'not reverted by its own save' );

        // The original changes words, a copy_once value and its rows: the duplicate follows all.
        $en = $this->tool( 'get-field-values', [ 'postId' => $page ] )['values']['steps'];
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'tagline' => 'We build websites', 'since' => 2005, 'steps' => [ $en[1] ] ] ) );
        $this->assertSame( 'We build websites', fabrement_get_field( 'tagline', $dup ), 'translate words follow in a duplicate' );
        $this->assertSame( 2005, fabrement_get_field( 'since', $dup ), 'copy_once too' );
        $this->assertSame( [ 'Two' ], $this->column( $dup, 'steps', 'title' ), 'rows too, no ghost rows' );
        $this->assertSame( 'Interne', fabrement_get_field( 'note', $dup ), 'ignore stays, though WPML deletes what it is not told to keep' );

        // An ignore sub-field inside a group is the duplicate's own too, filled or cleared above it.
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'details' => [ 'city' => 'Amsterdam' ] ] ) );
        $this->assertIsArray( $this->set( [ 'postId' => $dup ], [ 'details' => [ 'city' => 'Amsterdam', 'memo' => 'Mémo' ] ] ) );
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'details' => [ 'city' => 'Paris' ] ] ) );
        $details = $this->tool( 'get-field-values', [ 'postId' => $dup ] )['values']['details'];
        $this->assertSame( [ 'Paris', 'Mémo' ], [ $details['city'], $details['memo'] ], 'mirrored, the ignore sub-field kept' );
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'details' => null ] ) );
        $this->assertSame( 'Mémo', $this->tool( 'get-field-values', [ 'postId' => $dup ] )['values']['details']['memo'], 'kept when the original clears the group' );

        // Translated on its own: the policies apply again.
        delete_post_meta( $dup, '_icl_lang_duplicate_of' );
        $this->assertIsArray( $this->set( [ 'postId' => $dup ], [ 'tagline' => 'Nous créons' ] ) );
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'tagline' => 'We build apps' ] ) );
        $this->assertSame( 'Nous créons', fabrement_get_field( 'tagline', $dup ) );
    }

    public function test_wpmls_custom_fields_screen_shows_our_keys_locked_with_their_policy(): void {
        $tm = wpml_load_core_tm();
        $row = static function ( string $key ) use ( $tm ): array {
            $setting = new \WPML_Post_Custom_Field_Setting( $tm, $key );
            ob_start();
            $replaced = apply_filters( 'wpml_custom_field_settings_override_lock_render', false, $setting );
            return [ $setting->get_html_disabled(), $replaced, trim( (string) ob_get_clean() ) ];
        };
        foreach ( [
            'fab_tagline'              => 'Fabrement · Tagline: translate',
            'fab_price'                => 'Fabrement · Price: copy',
            'fab_steps_0_title'        => 'Fabrement · Steps / Title: translate',
            'fab_steps_1__rid'         => 'Fabrement · Steps: copy',
            'fab_details_stops_0_name' => 'Fabrement · Details / Stops / Name: translate',
            'fab_details_memo'         => 'Fabrement · Details / Memo: ignore',
        ] as $key => $label ) {
            [ $disabled, $replaced, $html ] = $row( $key );
            $this->assertSame( 'disabled="disabled"', $disabled, $key . ' cannot be set on WPML\'s screen' );
            $this->assertTrue( $replaced, $key );
            $this->assertStringContainsString( $label, $html, $key );
        }
        [ $disabled, $replaced, $html ] = $row( 'qa_another_plugin_key' );
        $this->assertSame( '', $disabled, 'another plugin\'s key is left alone' );
        $this->assertFalse( $replaced );
        $this->assertSame( '', $html );
    }

    public function test_a_translation_with_stale_rows_still_saves_its_words(): void {
        [ $page, $fr ] = $this->translated_page( [ 'steps' => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ] ] ] );
        $held = $this->tool( 'get-field-values', [ 'postId' => $fr ] )['values']['steps'];
        $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'steps' => [
            [ '_row' => $held[0]['_row'], 'title' => 'Un', 'code' => 'a' ],
            [ '_row' => $held[1]['_row'], 'title' => 'Deux', 'code' => 'b' ],
        ] ] ) );

        // The original adds a row and its sync to the translation fails: the translation keeps two.
        $en = $this->tool( 'get-field-values', [ 'postId' => $page ] )['values']['steps'];
        remove_action( 'fabrement_fields_saved', [ PostTranslations::class, 'saved' ], 10 );
        try {
            $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'steps' => [ $en[0], $en[1], [ 'title' => 'Three', 'code' => 'c' ] ] ] ) );
        } finally {
            add_action( 'fabrement_fields_saved', [ PostTranslations::class, 'saved' ], 10, 2 );
        }
        $this->assertCount( 2, fabrement_get_field( 'steps', $fr ), 'stale: the sync did not reach it' );

        // The translator changes one word, sending back the rows the form shows.
        $saved = $this->set( [ 'postId' => $fr ], [ 'steps' => [
            [ '_row' => $held[0]['_row'], 'title' => 'Un (corrigé)', 'code' => 'a' ],
            [ '_row' => $held[1]['_row'], 'title' => 'Deux', 'code' => 'b' ],
        ] ] );
        $this->assertIsArray( $saved, $saved instanceof WP_Error ? $saved->get_error_message() : '' );
        $this->assertSame( [ 'Un (corrigé)', 'Deux', 'Three' ], $this->column( $fr, 'steps', 'title' ), 'saved, and the rows healed from the original' );
        $this->assertSame( [ 'a', 'b', 'c' ], $this->column( $fr, 'steps', 'code' ) );

        // Changing the row list itself is still the original's.
        $rows = $this->tool( 'get-field-values', [ 'postId' => $fr ] )['values']['steps'];
        $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'steps' => [ $rows[1], $rows[0], $rows[2] ] ] ) );
    }

    public function test_wpmls_editor_leaves_a_copy_once_repeaters_own_rows_to_the_translation(): void {
        [ $page, $fr ] = $this->translated_page( [ 'program' => [ [ 'topic' => 'Opening' ], [ 'topic' => 'Workshop' ] ] ] );
        $rows = $this->tool( 'get-field-values', [ 'postId' => $fr ] )['values']['program'];
        $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'program' => [
            [ '_row' => $rows[0]['_row'], 'topic' => 'Ouverture' ], [ '_row' => $rows[1]['_row'], 'topic' => 'Atelier' ], [ 'topic' => 'Soirée' ],
        ] ] ) );
        // Now WPML's translation editor manages the translation.
        update_post_meta( $page, \WPML_TM_Post_Edit_TM_Editor_Mode::POST_META_KEY_EDITOR, \WPML_TM_Post_Edit_TM_Editor_Mode::EDITOR_WPML );
        $rows = $this->tool( 'get-field-values', [ 'postId' => $fr ] )['values']['program'];
        $context = FieldRead::for_editor( FieldObject::post( $fr ), '' )['translation'];
        $this->assertNotContains( 'fab_program/topic', $context['wordFields'], 'the form does not lock the words of its own list' );

        $own = $this->set( [ 'postId' => $fr ], [ 'program' => [ $rows[0], $rows[1], [ '_row' => $rows[2]['_row'], 'topic' => 'Soirée des partenaires' ], [ 'topic' => 'Clôture' ] ] ] );
        $this->assertIsArray( $own, $own instanceof WP_Error ? $own->get_error_message() : '' );
        $this->assertSame( [ 'Ouverture', 'Atelier', 'Soirée des partenaires', 'Clôture' ], $this->column( $fr, 'program', 'topic' ), 'its own rows are written here, and a new one added' );

        $shared = $this->set( [ 'postId' => $fr ], [ 'program' => [ [ '_row' => $rows[0]['_row'], 'topic' => 'Ouverture (main)' ] ] + $this->tool( 'get-field-values', [ 'postId' => $fr ] )['values']['program'] ] );
        $this->assertInstanceOf( WP_Error::class, $shared, 'a row it shares with the original is translated in WPML\'s editor' );
        $this->assertStringContainsString( 'Translation Editor', $shared->get_error_message() );

        // A WPML job still carries the shared rows, and only those.
        $texts = PostTranslations::texts( FieldObject::post( $page ) );
        $this->assertSame( [ 'Opening', 'Workshop' ], array_values( array_filter( array_column( $texts, 'text' ), static fn( $t ) => in_array( $t, [ 'Opening', 'Workshop' ], true ) ) ) );
    }

    public function test_a_post_linked_by_wpml_itself_is_seeded(): void {
        [ $page, $steps ] = $this->original();
        $fr = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft' ] );
        $this->link_by_wpml( $fr, $this->trid( $page ), 'fr' );

        $this->assertSame( 'We build sites', fabrement_get_field( 'tagline', $fr ), 'translate: seeded to start from' );
        $this->assertEquals( 12.5, fabrement_get_field( 'price', $fr ), 'copy' );
        $this->assertSame( 2001, fabrement_get_field( 'since', $fr ), 'copy_once' );
        $this->assertEmpty( fabrement_get_field( 'note', $fr ), 'ignore is never copied' );
        $read = $this->tool( 'get-field-values', [ 'postId' => $fr ] );
        $this->assertSame( array_column( $steps, '_row' ), array_column( $read['values']['steps'], '_row' ), 'the rows are the original\'s' );
        $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'tagline' => 'Nous créons des sites' ] ) );
    }

    public function test_a_pending_plus_translation_shows_the_original_and_saves_as_a_translation(): void {
        [ $page, $steps ] = $this->original();
        $trid  = $this->trid( $page );
        $draft = $this->pending_draft( $trid, 'fr' );
        $object = FieldObject::post( $draft );
        $this->assertTrue( $object->is_translation(), 'not linked by WPML yet, but a translation of the page' );

        $state = FieldRead::for_editor( $object );
        $this->assertIsArray( $state );
        $this->assertTrue( $state['translation']['isTranslation'] );
        $this->assertSame( 'We build sites', $state['values']['fab_tagline'] );
        $this->assertEquals( 12.5, $state['values']['fab_price'] );
        $this->assertSame( array_column( $steps, '_row' ), array_column( $state['values']['fab_steps'], 'id' ) );
        $this->assertSame( '', (string) get_post_meta( $draft, 'fab_price', true ), 'shown, not written: opening the form stores nothing' );

        // The form sends only what the translator changed: one translated row title.
        $rows = $state['values']['fab_steps'];
        $rows[0]['values']['title'] = 'Un';
        $saved = ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, [ 'fab_steps' => $rows ], [], array_fill_keys( array_keys( [ 'fab_steps' => $rows ] ), true ) );
        $this->assertIsArray( $saved, $saved instanceof WP_Error ? $saved->get_error_message() : '' );
        $this->assertEquals( 12.5, fabrement_get_field( 'price', $draft ), 'what was not sent is the original\'s, stored' );
        $this->assertSame( [ 'Un', 'Two' ], array_column( fabrement_get_field( 'steps', $draft ), 'title' ) );

        // WPML links the draft on its first save: nothing left to seed.
        $writes = did_action( 'added_post_meta' );
        $this->link_by_wpml( $draft, $trid, 'fr' );
        $this->assertSame( $writes, did_action( 'added_post_meta' ), 'the seed after linking writes nothing' );
        $this->assertSame( [ 'Un', 'Two' ], array_column( fabrement_get_field( 'steps', $draft ), 'title' ), 'the translated word is kept' );
        $this->assertSame( '', (string) get_post_meta( $draft, '_fabrement_fields_translation_of', true ), 'linked: no longer pending' );
    }

    public function test_a_pending_translation_cannot_add_rows_the_original_owns(): void {
        [ $page ] = $this->original();
        $object = FieldObject::post( $this->pending_draft( $this->trid( $page ), 'fr' ) );
        $state  = FieldRead::for_editor( $object );
        $rows   = $state['values']['fab_steps'];
        $rows[] = [ 'id' => '', 'values' => [ 'title' => 'Mine', 'code' => 'z' ] ];
        $this->assertSame( 'fields_invalid', ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, [ 'fab_steps' => $rows ], [], array_fill_keys( array_keys( [ 'fab_steps' => $rows ] ), true ) )->get_error_code() );
        $this->assertIsArray( ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, [ 'fab_price' => 99 ], [], array_fill_keys( array_keys( [ 'fab_price' => 99 ] ), true ) ), 'A copied value is routed to the original.' );
    }

    public function test_a_pending_translation_follows_the_originals_own_editor_choice(): void {
        global $sitepress;
        [ $page ] = $this->original();
        $tm     = (array) $sitepress->get_setting( 'translation-management', [] );
        $global = $tm;
        $global[ \WPML_TM_Post_Edit_TM_Editor_Mode::TM_KEY_GLOBAL_EDITOR ] = \WPML_TM_Post_Edit_TM_Editor_Mode::EDITOR_WPML;
        $sitepress->set_setting( 'translation-management', $global, true );
        update_post_meta( $page, \WPML_TM_Post_Edit_TM_Editor_Mode::POST_META_KEY_EDITOR, \WPML_TM_Post_Edit_TM_Editor_Mode::EDITOR_NATIVE );
        try {
            // The site translates with WPML's editor; this page with the WordPress one — which is
            // why "+" opened the post editor: the translator writes the words there.
            $this->assertFalse( Translation::bridge()->writes_translations_manually( self::factory()->post->create( [ 'post_type' => 'page' ] ) ), 'the site default is WPML\'s editor' );
            $object = FieldObject::post( $this->pending_draft( $this->trid( $page ), 'fr' ) );
            $state  = FieldRead::for_editor( $object );
            $this->assertFalse( $state['translation']['managedByWpml'] );
            $this->assertSame( [], $state['translation']['wordFields'] );
            $this->assertIsArray( ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, [ 'fab_tagline' => 'Nous créons des sites' ], [], array_fill_keys( array_keys( [ 'fab_tagline' => 'Nous créons des sites' ] ), true ) ) );
            $this->assertSame( 'Nous créons des sites', fabrement_get_field( 'tagline', (int) $object->id ) );
        } finally {
            $sitepress->set_setting( 'translation-management', $tm, true );
        }
    }

    public function test_the_words_go_into_a_wpml_job_and_come_back_translated(): void {
        [ $page ] = $this->original();
        $package  = ( new \WPML_Element_Translation_Package() )->create_translation_package( get_post( $page ), true );
        $ours     = array_filter( $package['contents'], static fn( $key ) => strpos( (string) $key, 'fabrement-field-' ) === 0, ARRAY_FILTER_USE_KEY );
        $words    = array_map( static fn( $entry ) => base64_decode( $entry['data'] ), $ours );
        sort( $words );
        $this->assertSame( [ 'One', 'Two', 'We build sites' ], $words, 'translate words only: no copied code, no ignored note, no number' );

        // The job comes back: WPML's elements carry our tokens with the translated words.
        $fr = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft' ] );
        $this->link_by_wpml( $fr, $this->trid( $page ), 'fr' );
        $french   = [ 'We build sites' => 'Nous créons des sites', 'One' => 'Un', 'Two' => 'Deux' ];
        $elements = [];
        foreach ( $ours as $token => $entry ) {
            $elements[] = (object) [ 'field_type' => $token, 'field_finished' => 1, 'field_format' => 'base64', 'field_data_translated' => base64_encode( $french[ base64_decode( $entry['data'] ) ] ) ];
        }
        // Our listener on wpml_pro_translation_completed, with the job fields it reads (WPML's other
        // listeners need a whole job from its database).
        do_action( 'wpml_pro_translation_completed', $fr, [], (object) [ 'original_doc_id' => $page, 'elements' => $elements ] );

        $this->assertSame( 'Nous créons des sites', fabrement_get_field( 'tagline', $fr ) );
        $this->assertSame( [ 'Un', 'Deux' ], array_column( fabrement_get_field( 'steps', $fr ), 'title' ) );
        $this->assertSame( [ 'a', 'b' ], array_column( fabrement_get_field( 'steps', $fr ), 'code' ), 'copied sub-fields stay the original\'s' );
    }

    public function test_a_retired_values_token_is_refused_like_any_unknown_argument(): void {
        [ $page ] = $this->original();
        $before = fabrement_get_field( 'tagline', $page );
        $refused = $this->tool( 'set-field-values', [ 'postId' => $page, 'values' => [ 'tagline' => 'Old habits' ], 'valuesToken' => 'anything' ] );
        $this->assertInstanceOf( WP_Error::class, $refused );
        $this->assertArrayNotHasKey( 'valuesToken', $this->tool( 'get-field-values', [ 'postId' => $page ] ) );
        $this->assertSame( $before, fabrement_get_field( 'tagline', $page ) );
    }

    public function test_a_plus_link_to_a_language_the_group_has_is_not_a_pending_translation(): void {
        [ $page, $fr ] = $this->translated_page( [ 'tagline' => 'We build sites' ] );
        $this->assertFalse( FieldObject::post( $this->pending_draft( $this->trid( $page ), 'fr' ) )->is_translation(), 'fr is taken' );
        $this->assertFalse( FieldObject::post( $this->pending_draft( 999999, 'pl' ) )->is_translation(), 'no such group' );
        $this->assertFalse( FieldObject::post( $this->pending_draft( $this->trid( $page ), 'xx' ) )->is_translation(), 'no such language' );
        $this->assertTrue( FieldObject::post( $this->pending_draft( $this->trid( $page ), 'pl' ) )->is_translation() );
    }

    public function test_schema_tokens_read_the_records_version_in_its_language(): void {
        [ , $fr ] = $this->translated_page( [ 'tagline' => 'We build sites', 'price' => 12.5 ] );
        $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'tagline' => 'Nous créons des sites' ] ) );
        $schema = $this->tool( 'set-schema', [ 'postType' => 'page', 'graph' => [ [ '@type' => 'Product', '@id' => '{{url}}#product', 'name' => '{{field:tagline}}', 'sku' => '{{field:price}}' ] ] ] );
        $this->assertIsArray( $schema, $schema instanceof WP_Error ? $schema->get_error_message() : '' );

        $node = $this->in_french( static fn() => SchemaStore::resolve_for_post( $fr )[0] ?? [] );
        $this->assertSame( 'Nous créons des sites', $node['name'] ?? null );
        $this->assertSame( '12.5', $node['sku'] ?? null );
        $this->tool( 'set-schema', [ 'postType' => 'page', 'graph' => [] ] );
    }

    /** WPML's "needs update" flag of a translation; null when WPML keeps no status for it. */
    private function needs_update( int $translation ): ?int {
        global $wpdb;
        $flag = $wpdb->get_var( $wpdb->prepare(
            "SELECT s.needs_update FROM {$wpdb->prefix}icl_translation_status s JOIN {$wpdb->prefix}icl_translations t ON t.translation_id = s.translation_id WHERE t.element_id = %d AND t.element_type = 'post_page'",
            $translation
        ) );
        return $flag === null ? null : (int) $flag;
    }

    private function age( int $post ): void {
        global $wpdb;
        $wpdb->update( $wpdb->posts, [ 'post_modified' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 00:00:00' ], [ 'ID' => $post ] );
        clean_post_cache( $post );
    }

    public function test_changed_words_mark_the_translation_outdated_and_copied_values_do_not(): void {
        [ $page, $fr ] = $this->translated_page( [ 'tagline' => 'We build sites', 'price' => 12.5 ] );
        // As a finished translation stands in WPML: its status holds the original's hash of today.
        global $wpdb;
        $status = (int) $wpdb->get_var( $wpdb->prepare( "SELECT translation_id FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = 'post_page'", $fr ) );
        $md5    = ( new \WPML_TM_Action_Helper() )->post_md5( get_post( $page ) );
        $wpdb->query( $wpdb->prepare( "REPLACE INTO {$wpdb->prefix}icl_translation_status (translation_id, status, needs_update, md5) VALUES (%d, 10, 0, %s)", $status, $md5 ) );
        $this->assertSame( 0, $this->needs_update( $fr ) );
        // WPML with Translation Management marks translations at the end of the request (shutdown),
        // after page builders registered their strings; a test has no end of request, so the same
        // updater runs at once here.
        add_filter( 'wpml_tm_delegate_translation_statuses_update', '__return_false', 99 );

        $this->age( $fr );
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'price' => 15 ] ) );
        $this->assertSame( 0, $this->needs_update( $fr ), 'a copied value is no word to translate' );
        $this->assertNotSame( '2020-01-01 00:00:00', get_post_field( 'post_modified', $fr ), 'the version it reached is dated now' );

        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'tagline' => 'We build websites' ] ) );
        $this->assertSame( 1, $this->needs_update( $fr ), 'changed words: WPML marks the translation outdated' );
        remove_filter( 'wpml_tm_delegate_translation_statuses_update', '__return_false', 99 );
    }

    /** A translated page whose fr version WPML counts as translated today. @return int[] [ page, fr, fr's translation_id ] */
    private function finished_translation( array $values ): array {
        global $wpdb;
        [ $page, $fr ] = $this->translated_page( $values );
        $status = (int) $wpdb->get_var( $wpdb->prepare( "SELECT translation_id FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = 'post_page'", $fr ) );
        $md5    = ( new \WPML_TM_Action_Helper() )->post_md5( get_post( $page ) );
        $wpdb->query( $wpdb->prepare( "REPLACE INTO {$wpdb->prefix}icl_translation_status (translation_id, status, needs_update, md5) VALUES (%d, 10, 0, %s)", $status, $md5 ) );
        // WPML with Translation Management marks translations at the end of the request (shutdown);
        // a test has no end of request, so the same updater runs at once here.
        add_filter( 'wpml_tm_delegate_translation_statuses_update', '__return_false', 99 );
        return [ $page, $fr, $status ];
    }

    /** How many times WordPress saved `$post` while `$run` ran. */
    private function saves_of( int $post, callable $run ): int {
        $saves = 0;
        $count = static function ( $id ) use ( $post, &$saves ): void {
            if ( (int) $id === $post ) {
                $saves++;
            }
        };
        add_action( 'save_post', $count );
        try {
            $run();
        } finally {
            remove_action( 'save_post', $count );
        }
        return $saves;
    }

    public function test_translating_the_words_of_a_hand_written_translation_brings_it_up_to_date(): void {
        [ $page, $fr ] = $this->finished_translation( [ 'tagline' => 'We build sites', 'price' => 12.5 ] );
        $content = get_post_field( 'post_content', $fr );

        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'tagline' => 'We build websites' ] ) );
        $this->assertSame( 1, $this->needs_update( $fr ) );

        $saves = $this->saves_of( $fr, function () use ( $fr ) {
            $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'tagline' => 'Nous créons des sites web' ] ) );
        } );
        $this->assertSame( 1, $saves, 'saved once, as its Update button would' );
        $this->assertSame( 0, $this->needs_update( $fr ), 'WPML counts it as up to date again' );
        $this->assertTrue( Translation::bridge()->writes_translations_manually( $fr ), 'still written by hand' );
        $this->assertSame( 'Nous créons des sites web', fabrement_get_field( 'tagline', $fr ) );
        $this->assertEquals( 12.5, fabrement_get_field( 'price', $fr ), 'what it copies is still the original\'s' );
        $this->assertSame( 'We build websites', fabrement_get_field( 'tagline', $page ), 'the original untouched' );
        $this->assertSame( $content, get_post_field( 'post_content', $fr ), 'its content untouched' );

        $this->assertSame( 0, $this->saves_of( $fr, function () use ( $fr ) {
            $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'tagline' => 'Nous créons des sites web' ] ) );
        } ), 'the same words again: nothing to save' );
        remove_filter( 'wpml_tm_delegate_translation_statuses_update', '__return_false', 99 );
    }

    public function test_a_translation_wpmls_editor_owns_is_not_saved_through_wordpress(): void {
        [ $page, $fr ] = $this->finished_translation( [ 'tagline' => 'We build sites' ] );
        update_post_meta( $page, \WPML_TM_Post_Edit_TM_Editor_Mode::POST_META_KEY_EDITOR, \WPML_TM_Post_Edit_TM_Editor_Mode::EDITOR_WPML );
        $this->assertFalse( Translation::bridge()->writes_translations_manually( $fr ) );
        $this->assertIsArray( $this->set( [ 'postId' => $page ], [ 'tagline' => 'We build websites' ] ) );
        $this->assertSame( 1, $this->needs_update( $fr ) );

        // Its words are the editor's; its own values (ignore) may still be set here.
        $this->assertInstanceOf( WP_Error::class, $this->set( [ 'postId' => $fr ], [ 'tagline' => 'Nous créons' ] ) );
        $saves = $this->saves_of( $fr, function () use ( $fr ) {
            $this->assertIsArray( $this->set( [ 'postId' => $fr ], [ 'note' => 'Interne' ] ) );
        } );
        $this->assertSame( 0, $saves, 'not saved through WordPress: WPML would take it out of its editor' );
        $this->assertSame( 1, $this->needs_update( $fr ), 'still to be updated in WPML\'s editor' );
        $this->assertFalse( Translation::bridge()->writes_translations_manually( $fr ), 'still in WPML\'s editor' );
        $this->assertSame( 'Interne', fabrement_get_field( 'note', $fr ) );
        remove_filter( 'wpml_tm_delegate_translation_statuses_update', '__return_false', 99 );
    }
}
