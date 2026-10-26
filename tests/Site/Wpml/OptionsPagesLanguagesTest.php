<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\ThemeFields;

/**
 * An options page in three languages, as an agent makes and fills it over MCP and visitors read it
 * on pages in English, French and Polish (the theme prints the options — ThemeFields): each
 * language's stored row in wp_options seeded once from the original, what a language may not change, rows
 * a language translates by their id, lists, the page deleted in every language, and the
 * site's default language changing under it.
 */
final class OptionsPagesLanguagesTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private const SLUG = 'qa-site';

    private static bool $ready = false;

    /** @var array<string,int> A page per language the visitor opens: en, fr, pl. */
    private static array $pages = [];

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            ThemeFields::install( $this->site(), [ self::SLUG, 'qa-team', 'qa-gone' ] );
            $en          = $this->record( 'page', 'Contacts' );
            self::$pages = [ 'en' => $en ];
            foreach ( [ 'fr' => 'contacts-fr', 'pl' => 'kontakty' ] as $lang => $slug ) {
                self::$pages[ $lang ] = (int) $this->mcp( 'translate-content', [ 'contentId' => $en, 'language' => $lang, 'slug' => $slug ] )['contentId'];
                $this->mcp( 'set-content-status', [ 'contentId' => self::$pages[ $lang ], 'status' => 'publish' ] );
            }
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        self::$pages = [];
        parent::tearDownAfterClass();
    }

    private static function fields_of_page(): array {
        return [
            self::field( 'phone', 'text', [ 'translation' => 'copy' ] ),
            self::field( 'slogan', 'text' ),
            self::field( 'since', 'number', [ 'format' => 'integer', 'translation' => 'copy_once' ] ),
            self::field( 'steps', 'repeater', [ 'max' => 5, 'sub_fields' => [ self::field( 'title', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ] ] ),
        ];
    }

    private function create( string $slug, array $fields ): array {
        return $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => 0, 'page' => [ 'title' => 'QA ' . $slug ], 'fields' => $fields ] );
    }

    private function set( array $values, string $lang = '', string $slug = self::SLUG ): array {
        return $this->mcp( 'set-field-values', [ 'options' => $slug, 'values' => $values ] + ( $lang !== '' ? [ 'language' => $lang ] : [] ) );
    }

    private function shown_to_agent( string $lang = '', string $slug = self::SLUG ): array {
        return $this->mcp( 'get-field-values', [ 'options' => $slug ] + ( $lang !== '' ? [ 'language' => $lang ] : [] ) );
    }

    /** The options page as the visitor's page in $lang shows it. */
    private function shown( string $lang, string $slug = self::SLUG ): array {
        $url  = (string) $this->mcp( 'get-content', [ 'contentId' => self::$pages[ $lang ] ] )['url'];
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'], $url );
        $theme = ThemeFields::read( $page['body'] );
        $this->assertSame( $lang, $theme['lang'] ?? null, 'WPML read the language from ' . $url );
        return (array) ( $theme['options'][ $slug ] ?? [] );
    }

    /** A language's own row in wp_options; null when it has none. */
    private function row( string $lang = '', string $slug = self::SLUG ) {
        return $this->site()->option( 'fabrement_options_' . $slug . ( $lang !== '' ? '.' . $lang : '' ) );
    }

    public function test_an_options_page_lives_in_three_languages(): void {
        $this->create( self::SLUG, self::fields_of_page() );
        $this->set( [ 'phone' => '+380 44 000', 'slogan' => 'Hello', 'since' => 2001, 'steps' => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ] ] ] );

        // Missing versions read their own defaults/empties, without creating a row.
        $this->assertSame( 'Hello', $this->shown( 'en' )['slogan'] );
        foreach ( [ 'fr', 'pl' ] as $lang ) {
            $this->assertSame( '', $this->shown( $lang )['slogan'], $lang );
            $agent = $this->shown_to_agent( $lang );
            $this->assertFalse( $agent['versionExists'] );
            $this->assertNull( $agent['values']['steps'] );
            $this->assertNull( $this->row( $lang ) );
        }

        // French writes its own words: its row holds them; the others are untouched.
        $this->set( [ 'slogan' => 'Bonjour' ], 'fr' );
        $this->assertSame( 'Bonjour', $this->shown( 'fr' )['slogan'] );
        $this->assertSame( 'Hello', $this->shown( 'en' )['slogan'], 'the original keeps its words' );
        $this->assertSame( '', $this->shown( 'pl' )['slogan'], 'Polish does not exist yet' );
        $this->assertSame( 'Bonjour', $this->row( 'fr' )['fab_slogan'] );
        $agent = $this->shown_to_agent( 'fr' );
        $this->assertTrue( $agent['stored']['slogan'] );
        $this->assertTrue( $agent['stored']['steps'], 'Creation copied the rows into French storage.' );

        // A copy value is shared; an edit from another language routes to the original.
        $this->set( [ 'phone' => '+380 44 999' ] );
        $this->assertSame( '+380 44 999', $this->shown( 'fr' )['phone'] );
        $this->assertSame( '', $this->shown( 'pl' )['phone'] );
        $this->set( [ 'slogan' => 'Cześć' ], 'pl' );
        $this->assertSame( '+380 44 999', $this->shown( 'pl' )['phone'] );
        $saved = $this->set( [ 'phone' => '+33 1 00' ], 'fr' );
        $this->assertSame( 'en', $saved['routed'][0]['language'] );
        $this->assertSame( '+33 1 00', $this->shown( 'en' )['phone'] );

        // copy_once: French sets its own.
        $this->set( [ 'since' => 1999 ], 'fr' );
        $this->assertSame( 1999, $this->shown( 'fr' )['since'] );
        $this->assertSame( 2001, $this->shown( 'en' )['since'] );

        // French translates each row's own words; reordering routes to the original.
        $rows = $this->shown_to_agent( 'fr' )['values']['steps'];
        $this->set( [ 'steps' => [
            [ '_row' => $rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ],
            [ '_row' => $rows[1]['_row'], 'title' => 'Deux', 'code' => 'b' ],
        ] ], 'fr' );
        $this->assertSame( [ 'Un', 'Deux' ], array_column( $this->shown( 'fr' )['steps'], 'title' ) );
        $this->set( [ 'steps' => [
            [ '_row' => $rows[1]['_row'], 'title' => 'Deux', 'code' => 'b' ],
            [ '_row' => $rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ],
        ] ], 'fr' );

        $this->assertSame( [ $rows[1]['_row'], $rows[0]['_row'] ], array_column( $this->shown_to_agent()['values']['steps'], '_row' ) );
        $this->set( [ 'steps' => array_map( static fn( array $row ): array => [ '_row' => $row['_row'] ], $rows ) ], 'fr' );

        // The original reorders, adds a row and changes a copied code: French keeps its words by row.
        $en = $this->shown_to_agent()['values']['steps'];
        $this->set( [ 'steps' => [
            [ '_row' => $en[1]['_row'], 'title' => 'Two', 'code' => 'b' ],
            [ 'title' => 'Three', 'code' => 'c' ],
            [ '_row' => $en[0]['_row'], 'title' => 'One', 'code' => 'x' ],
        ] ] );
        $french = $this->shown( 'fr' )['steps'];
        $this->assertSame( [ 'Deux', 'Three', 'Un' ], array_column( $french, 'title' ), 'its words by row, the new row from the original' );
        $this->assertSame( [ 'b', 'c', 'x' ], array_column( $french, 'code' ), 'the copied code follows' );
        $this->assertSame( [ 'Two', 'Three', 'One' ], array_column( $this->shown( 'pl' )['steps'], 'title' ), 'Polish reads the original\'s rows' );

        // Values sent back unchanged need no rewrite: only the changed word differs in storage.
        $all    = $this->shown_to_agent( 'fr' )['values'];
        $before = (array) $this->row( 'fr' );
        $this->set( [ 'since' => $all['since'], 'steps' => $all['steps'], 'phone' => $all['phone'], 'slogan' => 'Salut' ], 'fr' );
        $this->assertSame( [ 'fab_slogan' ], array_keys( array_diff_assoc( (array) $this->row( 'fr' ), $before ) ) );

        // A language that clears its words keeps them empty — not the original's.
        $this->set( [ 'slogan' => null ], 'fr' );
        $this->assertSame( '', $this->shown( 'fr' )['slogan'] );
        $this->assertSame( 'Hello', $this->shown( 'en' )['slogan'] );

        // A language the site does not have is refused.
        $this->assertStringStartsWith( 'language', $this->mcp_error( 'get-field-values', [ 'options' => self::SLUG, 'language' => 'xx' ] ) );
    }

    public function test_a_language_saves_its_words_though_a_copied_value_no_longer_fits_its_field(): void {
        $page   = $this->mcp( 'get-options-page', [ 'slug' => self::SLUG ] );
        $fields = array_values( array_filter( $page['fields'], static fn( array $f ): bool => empty( $f['retired'] ) ) );
        $level  = self::field( 'level', 'select', [ 'translation' => 'copy', 'choices' => [ [ 'value' => 'a', 'label' => 'A' ], [ 'value' => 'b', 'label' => 'B' ] ] ] );
        $saved  = $this->mcp( 'save-options-page', [ 'slug' => self::SLUG, 'expectedRevision' => $page['revision'], 'fields' => array_merge( $fields, [ $level ] ) ] );
        $this->set( [ 'level' => 'a' ] );
        $level['choices'] = [ [ 'value' => 'b', 'label' => 'B' ] ];
        $this->mcp( 'save-options-page', [ 'slug' => self::SLUG, 'expectedRevision' => $page['revision'] + 1,
            'fields' => array_merge( $fields, [ [ 'key' => array_column( $saved['fields'], 'key', 'name' )['level'] ] + $level ] ) ] );

        $shown = $this->shown_to_agent( 'fr' )['values'];
        $this->assertNull( $shown['level'], 'An unavailable optional choice reads as unselected.' );
        $this->set( array_merge( $shown, [ 'slogan' => 'Salut !' ] ), 'fr' );
        $this->assertSame( 'Salut !', $this->shown( 'fr' )['slogan'] );
        $this->assertSame( '', $this->shown( 'fr' )['level'], 'The legacy reader keeps its empty-string shape after the explicit clear.' );
        $this->assertNull( $this->site()->option( 'fabrement_options_' . self::SLUG )['fab_level'] );
        $this->set( [ 'slogan' => 'Hi again' ] );
        $this->assertSame( 'Hi again', $this->shown( 'en' )['slogan'] );
        $this->assertStringContainsString( 'level', $this->mcp_error( 'set-field-values', [ 'options' => self::SLUG, 'values' => [ 'level' => 'c' ] ] ) );
    }

    public function test_a_list_on_an_options_page_is_the_originals_and_each_language_reads_its_versions(): void {
        $people = [];
        foreach ( [ 'Ann', 'Cid' ] as $name ) {
            $en = $this->record( 'page', 'Team ' . $name );
            $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $en, 'language' => 'fr', 'slug' => 'equipe-' . strtolower( $name ) ] )['contentId'];
            $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
            $people[ $name ] = [ 'en' => $en, 'fr' => $fr ];
        }
        $this->create( 'qa-team', [ self::field( 'team', 'relationship' ) ] );
        $this->set( [ 'team' => [ $people['Cid']['fr'], $people['Ann']['en'] ] ], '', 'qa-team' );

        $this->assertSame( [ $people['Cid']['en'], $people['Ann']['en'] ], $this->shown_to_agent( '', 'qa-team' )['values']['team'], 'stored as the originals, in order' );
        $this->assertSame( [ $people['Cid']['en'], $people['Ann']['en'] ], $this->shown( 'en', 'qa-team' )['team'] );
        $this->set( [ 'team' => [ $people['Cid']['en'], $people['Ann']['en'] ] ], 'fr', 'qa-team' );
        $this->set( [ 'team' => [ $people['Cid']['en'], $people['Ann']['en'] ] ], 'pl', 'qa-team' );
        $this->assertSame( [ $people['Cid']['fr'], $people['Ann']['fr'] ], $this->shown( 'fr', 'qa-team' )['team'], 'the French page lists the French versions' );
        $this->assertSame( [], $this->shown( 'pl', 'qa-team' )['team'], 'No Polish picked records: the typed reader hides missing versions.' );
        $this->set( [ 'team' => [ $people['Ann']['fr'] ] ], 'fr', 'qa-team' );
        $this->assertSame( [ $people['Ann']['en'] ], $this->shown_to_agent( '', 'qa-team' )['values']['team'] );
    }

    public function test_deleting_the_page_deletes_its_values_in_every_language(): void {
        $this->create( 'qa-gone', self::fields_of_page() );
        $this->set( [ 'slogan' => 'Hello' ], '', 'qa-gone' );
        $this->set( [ 'slogan' => 'Bonjour' ], 'fr', 'qa-gone' );
        $this->set( [ 'slogan' => 'Cześć' ], 'pl', 'qa-gone' );
        $this->assertSame( 'Cześć', $this->shown( 'pl', 'qa-gone' )['slogan'] );

        $deleted = $this->mcp( 'delete-options-page', [ 'slug' => 'qa-gone', 'expectedRevision' => $this->mcp( 'get-options-page', [ 'slug' => 'qa-gone' ] )['revision'] ] );
        $this->assertSame( 3, $deleted['valueRowsDeleted'] );
        foreach ( [ '', 'fr', 'pl' ] as $lang ) {
            $this->assertNull( $this->row( $lang, 'qa-gone' ), 'row "' . $lang . '"' );
        }
        $this->assertSame( [], $this->shown( 'fr', 'qa-gone' ), 'the French page reads nothing of it' );
    }

    public function test_changing_the_default_language_keeps_every_languages_values(): void {
        $slug = 'qa-switch';
        $this->create( $slug, self::fields_of_page() );
        $this->set( [ 'slogan' => 'Hello', 'steps' => [ [ 'title' => 'One', 'code' => 'a' ] ] ], 'en', $slug );
        $rows = $this->shown_to_agent( 'en', $slug )['values']['steps'];
        $this->set( [ 'slogan' => null, 'steps' => [ [ '_row' => $rows[0]['_row'], 'title' => 'Un', 'code' => 'a' ] ] ], 'fr', $slug );
        $this->set( [ 'steps' => [ $rows[0], [ 'title' => 'Two', 'code' => 'b' ] ] ], 'en', $slug );
        $this->create( 'qa-lone', [ self::field( 'word', 'text' ) ] );
        $this->set( [ 'word' => 'Mot' ], 'fr', 'qa-lone' );
        $this->site()->wp( 'option', 'delete', 'fabrement_options_qa-lone' ); // Existing legacy page without a primary row.
        $before = [];
        foreach ( [ $slug, 'qa-lone' ] as $page ) {
            foreach ( [ '', 'en', 'fr', 'pl' ] as $lang ) {
                $before[ $page ][ $lang ] = $this->row( $lang, $page );
            }
        }
        $this->assertSame( '2', $before[ $slug ]['fr']['fab_steps'], 'The new source row was synchronized before switching the site language.' );

        $default = static fn( string $lang ): string => "global \$sitepress; \$sitepress->set_default_language( '$lang' ); wp_cache_flush();";
        $this->site()->php( $default( 'fr' ) );
        try {
            $this->assertSame( $before[ $slug ]['fr'], $this->row( '', $slug ), 'French values are now the unsuffixed row' );
            $this->assertSame( $before[ $slug ][''], $this->row( 'en', $slug ), 'English values moved to their own row' );
            $this->assertNull( $this->row( 'fr', $slug ) );
            $this->assertSame( $before[ $slug ]['pl'], $this->row( 'pl', $slug ) );
            $this->assertNull( $this->shown_to_agent( 'fr', $slug )['values']['slogan'], 'French keeps its own cleared slogan' );
            $this->assertSame( 'Hello', $this->shown_to_agent( 'en', $slug )['values']['slogan'], 'English keeps its words' );
            foreach ( $before['qa-lone'] as $lang => $row ) {
                $this->assertSame( $row, $this->row( $lang, 'qa-lone' ), "qa-lone.$lang: a page without a primary row is left alone" );
            }
        } finally {
            $this->site()->php( $default( 'en' ) );
        }
        foreach ( $before as $page => $languages ) {
            foreach ( $languages as $lang => $row ) {
                $this->assertSame( $row, $this->row( $lang, $page ), "$page.$lang: switching back restores every row exactly" );
            }
        }
    }

    public function test_a_new_default_language_without_values_starts_from_the_old_default(): void {
        $slug = 'qa-switch-seed';
        $this->create( $slug, [ self::field( 'slogan', 'text' ), self::field( 'phone', 'text', [ 'translation' => 'copy' ] ), self::field( 'local', 'text', [ 'translation' => 'ignore' ] ) ] );
        $this->set( [ 'slogan' => 'Hello', 'phone' => '123', 'local' => 'EN only' ], 'en', $slug );
        $this->assertNull( $this->row( 'pl', $slug ) );
        $default = static fn( string $lang ): string => "global \$sitepress; \$sitepress->set_default_language( '$lang' ); wp_cache_flush();";
        $this->site()->php( $default( 'pl' ) );
        try {
            $this->assertSame( [ 'slogan' => 'Hello', 'phone' => '123', 'local' => null ], $this->shown_to_agent( 'pl', $slug )['values'], 'seeded by policy: words and copies, not ignore' );
            $this->assertSame( 'EN only', $this->shown_to_agent( 'en', $slug )['values']['local'], 'English keeps everything' );
            $this->set( [ 'phone' => '456' ], 'pl', $slug );
            $this->assertSame( '456', $this->shown_to_agent( 'en', $slug )['values']['phone'], 'the new default is the original copies follow' );
        } finally {
            $this->site()->php( $default( 'en' ) );
        }
        $this->assertSame( 'Hello', $this->shown_to_agent( 'en', $slug )['values']['slogan'] );
        $this->assertSame( '456', $this->shown_to_agent( 'pl', $slug )['values']['phone'] );
    }
}
