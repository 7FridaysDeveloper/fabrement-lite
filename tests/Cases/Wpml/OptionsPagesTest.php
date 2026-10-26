<?php

namespace Fabrement\Tests\Cases\Wpml;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Schema\FieldKind;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Tests\CommittedCleanup;
use Fabrement\Tests\McpCalls;
use Fabrement\Tests\Wpml\WpmlTestCase;

/**
 * An options page in several languages, as an agent fills it through the site's MCP server: rows a
 * language translates, what it may not change, what it sends back unchanged, and the site's default
 * language changing under it.
 */
final class OptionsPagesTest extends WpmlTestCase {

    use CommittedCleanup;
    use McpCalls;

    private const SLUG = 'qa-wpml-options';

    /** @var string[] */
    private array $slugs = [ self::SLUG ];

    public function set_up(): void {
        parent::set_up();
        $this->start_cleanup( [], get_current_user_id() );
        $this->forget();
        $f = static fn( string $name, string $type, array $extra = [] ): array => $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
        $this->mcp( 'save-options-page', [ 'slug' => self::SLUG, 'expectedRevision' => 0, 'page' => [ 'title' => 'QA options' ], 'fields' => [
            $f( 'phone', 'text', [ 'translation' => 'copy' ] ),
            $f( 'slogan', 'text' ),
            $f( 'since', 'number', [ 'format' => 'integer', 'translation' => 'copy_once' ] ),
            $f( 'steps', 'repeater', [ 'max' => 5, 'sub_fields' => [ $f( 'title', 'text' ), $f( 'code', 'text', [ 'translation' => 'copy' ] ) ] ] ),
        ] ] );
        $this->set( [ 'phone' => '+380 44 000', 'slogan' => 'Hello', 'since' => 2001, 'steps' => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ] ] ] );
    }

    public function tear_down(): void {
        // A test that died between the two switches of with_default() must not leave French the
        // site's default for every test after it.
        if ( apply_filters( 'wpml_default_language', null ) !== 'en' ) {
            $GLOBALS['sitepress']->set_default_language( 'en' );
        }
        $this->forget();
        $this->end_cleanup();
        parent::tear_down();
    }

    private function forget(): void {
        foreach ( $this->slugs as $slug ) {
            $file = SchemaRepository::dir() . '/options.' . $slug . '.json';
            if ( is_file( $file ) ) {
                unlink( $file );
            }
            foreach ( [ '', 'en', 'fr', 'pl' ] as $lang ) {
                delete_option( Keys::options_page( $slug, $lang ) );
            }
        }
        SchemaRepository::flush();
        FieldKind::forget();
    }

    private function set( array $values, string $lang = '', string $slug = self::SLUG ): array {
        return $this->mcp( 'set-field-values', [ 'options' => $slug, 'values' => $values ] + ( $lang !== '' ? [ 'language' => $lang ] : [] ) );
    }

    private function shown( string $lang = '' ): array {
        return $this->mcp( 'get-field-values', [ 'options' => self::SLUG ] + ( $lang !== '' ? [ 'language' => $lang ] : [] ) );
    }

    /** @return mixed What a page in French reads. */
    private function in_french( string $name, string $slug = self::SLUG ) {
        return Translation::bridge()->in_language( 'fr', static fn() => fabrement_get_option( $name, $slug ) );
    }

    /** The site's default language changed in WPML's own settings, then back; $while runs between. */
    private function with_default( string $lang, callable $while ): void {
        global $sitepress;
        $sitepress->set_default_language( $lang );
        wp_cache_delete( 'alloptions', 'options' );
        try {
            $while();
        } finally {
            $sitepress->set_default_language( 'en' );
            wp_cache_delete( 'alloptions', 'options' );
            Translation::set( null );
            Translation::forget_languages();
        }
    }

    public function test_get_field_values_shows_a_language_the_original_rows_and_what_it_stores_itself(): void {
        $this->set( [ 'slogan' => 'Bonjour' ], 'fr' );
        $shown = $this->shown( 'fr' );
        $this->assertSame( 'fr', $shown['language'] );
        $this->assertSame( [ 'One', 'Two' ], array_column( $shown['values']['steps'], 'title' ) );
        $this->assertFalse( $shown['stored']['steps'] );
        $this->assertTrue( $shown['stored']['slogan'] );
    }

    public function test_a_language_sets_its_own_copy_once_value(): void {
        $this->set( [ 'since' => 1999 ], 'fr' );
        $this->assertSame( 1999, $this->in_french( 'since' ) );
        $this->assertSame( 2001, fabrement_get_option( 'since', self::SLUG ) );
    }

    public function test_a_language_translates_the_words_of_each_row_but_cannot_reorder_them(): void {
        $rows = $this->shown( 'fr' )['values']['steps'];
        $this->set( [ 'steps' => [
            [ '_row' => $rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ],
            [ '_row' => $rows[1]['_row'], 'title' => 'Deux', 'code' => 'b' ],
        ] ], 'fr' );
        $this->assertSame( 'Deux', $this->in_french( 'steps' )[1]['title'] );

        $before = get_option( Keys::options_page( self::SLUG, 'fr' ) );
        $this->mcp_error( 'set-field-values', [ 'options' => self::SLUG, 'language' => 'fr', 'values' => [ 'steps' => [
            [ '_row' => $rows[1]['_row'], 'title' => 'Deux', 'code' => 'b' ],
            [ '_row' => $rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ],
        ] ] ] );
        $this->assertSame( $before, get_option( Keys::options_page( self::SLUG, 'fr' ) ), 'the refused order wrote nothing' );
    }

    public function test_rows_the_original_reorders_and_adds_keep_the_languages_words_by_row(): void {
        $rows = $this->shown( 'fr' )['values']['steps'];
        $this->set( [ 'steps' => [
            [ '_row' => $rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ],
            [ '_row' => $rows[1]['_row'], 'title' => 'Deux', 'code' => 'b' ],
        ] ], 'fr' );
        $en = $this->shown()['values']['steps'];
        $this->set( [ 'steps' => [
            [ '_row' => $en[1]['_row'], 'title' => 'Two', 'code' => 'b' ],
            [ 'title' => 'Three', 'code' => 'c' ],
            [ '_row' => $en[0]['_row'], 'title' => 'One', 'code' => 'x' ],
        ] ] );
        $fr = $this->in_french( 'steps' );
        $this->assertSame( [ 'Deux', 'Three', 'Un' ], array_column( $fr, 'title' ), 'its words by row, the new row from the original' );
        $this->assertSame( 'x', $fr[2]['code'], 'the copied code follows' );
    }

    public function test_a_language_that_clears_its_words_keeps_them_empty(): void {
        $this->set( [ 'slogan' => 'Bonjour' ], 'fr' );
        $this->set( [ 'slogan' => null ], 'fr' );
        $this->assertSame( '', $this->in_french( 'slogan' ), 'empty, not the original' );
    }

    public function test_values_sent_back_unchanged_stay_the_originals(): void {
        $this->set( [ 'slogan' => 'Bonjour' ], 'fr' );
        $all    = $this->shown( 'fr' )['values'];
        $before = get_option( Keys::options_page( self::SLUG, 'fr' ) );
        $this->set( [ 'since' => $all['since'], 'steps' => $all['steps'], 'phone' => $all['phone'], 'slogan' => 'Salut' ], 'fr' );
        $after = get_option( Keys::options_page( self::SLUG, 'fr' ) );
        $this->assertSame( [ 'fab_slogan' ], array_keys( array_diff_assoc( $after, $before ) ), 'only the changed one is stored for the language' );
    }

    public function test_a_language_saves_its_words_though_a_copied_value_no_longer_fits_its_field(): void {
        $page   = $this->mcp( 'get-options-page', [ 'slug' => self::SLUG ] );
        $fields = array_values( array_filter( $page['fields'], static fn( array $f ): bool => empty( $f['retired'] ) ) );
        $level  = [ 'name' => 'level', 'label' => 'Level', 'description' => 'Copied choice', 'type' => 'select', 'translation' => 'copy',
            'choices' => [ [ 'value' => 'a', 'label' => 'A' ], [ 'value' => 'b', 'label' => 'B' ] ] ];
        $saved  = $this->mcp( 'save-options-page', [ 'slug' => self::SLUG, 'expectedRevision' => $page['revision'], 'fields' => array_merge( $fields, [ $level ] ) ] );
        $this->set( [ 'level' => 'a' ] );
        $level['choices'] = [ [ 'value' => 'b', 'label' => 'B' ] ];
        $level_key         = array_column( $saved['fields'], 'key', 'name' )['level'];
        $this->mcp( 'save-options-page', [ 'slug' => self::SLUG, 'expectedRevision' => $page['revision'] + 1, 'fields' => array_merge( $fields, [ [ 'key' => $level_key ] + $level ] ) ] );

        $shown = $this->shown( 'fr' )['values'];
        $this->assertSame( 'a', $shown['level'] );
        $this->set( array_merge( $shown, [ 'slogan' => 'Salut !' ] ), 'fr' );
        $this->assertSame( 'Salut !', $this->in_french( 'slogan' ) );
        $this->assertSame( 'a', fabrement_get_option( 'level', self::SLUG ) );
        $this->set( [ 'level' => 'a', 'slogan' => 'Hi again' ] );   // the original keeps what it holds, saving something else
    }

    public function test_deleting_the_page_deletes_its_values_in_every_language(): void {
        $this->set( [ 'slogan' => 'Bonjour' ], 'fr' );
        $this->set( [ 'slogan' => 'Cześć' ], 'pl' );
        $deleted = $this->mcp( 'delete-options-page', [ 'slug' => self::SLUG, 'expectedRevision' => $this->mcp( 'get-options-page', [ 'slug' => self::SLUG ] )['revision'] ] );
        $this->assertSame( 3, $deleted['valueRowsDeleted'] );
        foreach ( [ '', 'fr', 'pl' ] as $lang ) {
            $this->assertFalse( get_option( Keys::options_page( self::SLUG, $lang ) ), 'row "' . $lang . '"' );
        }
        $this->assertNull( Translation::bridge()->in_language( 'fr', static fn() => fabrement_get_option( 'slogan', self::SLUG ) ) );
    }

    public function test_an_unknown_language_is_refused(): void {
        $this->assertStringStartsWith( 'language', $this->mcp_error( 'get-field-values', [ 'options' => self::SLUG, 'language' => 'xx' ] ) );
    }

    public function test_changing_the_default_language_leaves_option_rows_for_manual_updates(): void {
        $this->set( [ 'slogan' => 'Bonjour', 'since' => 1999 ], 'fr' );
        $before   = [ 'en' => fabrement_get_options( self::SLUG ), 'fr' => Translation::bridge()->in_language( 'fr', static fn() => fabrement_get_options( self::SLUG ) ) ];
        $original = get_option( Keys::options_page( self::SLUG ) );
        $fr_own    = get_option( Keys::options_page( self::SLUG, 'fr' ) );

        $this->with_default( 'fr', function () use ( $original, $fr_own ): void {
            $this->assertSame( $original, get_option( Keys::options_page( self::SLUG ) ) );
            $this->assertSame( $fr_own, get_option( Keys::options_page( self::SLUG, 'fr' ) ) );
            $this->assertFalse( get_option( Keys::options_page( self::SLUG, 'en' ) ), 'no language row is automatically created' );
        } );

        $this->assertSame( $before['en'], fabrement_get_options( self::SLUG ) );
        $this->assertSame( $before['fr'], Translation::bridge()->in_language( 'fr', static fn() => fabrement_get_options( self::SLUG ) ) );
        $this->assertFalse( get_option( Keys::options_page( self::SLUG, 'en' ) ) );
        $this->assertSame( $original, get_option( Keys::options_page( self::SLUG ) ) );
        $this->assertSame( $fr_own, get_option( Keys::options_page( self::SLUG, 'fr' ) ) );
    }

    public function test_changing_the_default_language_does_not_create_an_absent_base_row(): void {
        $lone          = 'qa-wpml-lone';
        $this->slugs[] = $lone;
        $this->mcp( 'save-options-page', [ 'slug' => $lone, 'expectedRevision' => 0, 'page' => [ 'title' => 'QA lone' ], 'fields' => [
            [ 'name' => 'word', 'label' => 'Word', 'description' => 'x', 'type' => 'text' ],
        ] ] );
        $this->set( [ 'word' => 'Mot' ], 'fr', $lone );

        $this->with_default( 'fr', function () use ( $lone ): void {
            $this->assertFalse( get_option( Keys::options_page( $lone ) ) );
            $this->assertSame( 'Mot', get_option( Keys::options_page( $lone, 'fr' ) )['fab_word'] );
        } );
        $this->assertSame( 'Mot', $this->in_french( 'word', $lone ) );
        $this->assertSame( 'Mot', get_option( Keys::options_page( $lone, 'fr' ) )['fab_word'] ?? null );
    }
}
