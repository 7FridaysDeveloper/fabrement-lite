<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\Browser;
use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/** The same creation, seed, validation and synchronization through every public entry. */
final class OptionsLifecycleTest extends SiteTestCase {
    protected const SITE = 'wpml';

    private function create( string $slug ): array {
        $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => 0,
            'page' => [ 'title' => 'Language lifecycle', 'autoload' => false ], 'fields' => [
                self::field( 'title', 'text', [ 'required' => true ] ),
                self::field( 'phone', 'text', [ 'translation' => 'copy' ] ),
                self::field( 'price', 'number', [ 'translation' => 'copy_once', 'default' => 7 ] ),
                self::field( 'local', 'text', [ 'translation' => 'ignore' ] ),
                self::field( 'unset', 'text', [ 'default' => 'Default' ] ),
                self::field( 'rows', 'repeater', [ 'sub_fields' => [
                    self::field( 'word', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ),
                ] ] ),
            ],
        ] );
        $this->assertSame( [], $this->site()->option( 'fabrement_options_' . $slug ), 'The primary version exists even before values are set.' );
        return $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'en', 'values' => [
            'title' => 'English', 'phone' => '123', 'price' => null, 'local' => 'EN only',
            'rows' => [ [ 'word' => 'One', 'code' => 'a' ], [ 'word' => 'Two', 'code' => 'b' ] ],
        ] ] )['values'];
    }

    private function read( string $slug, string $lang = 'pl' ): array {
        return $this->mcp( 'get-field-values', [ 'options' => $slug, 'language' => $lang ] );
    }

    private function rest( string $slug, string $method, ?array $body = null ): array {
        $response = Http::request( $this->site(), $method, $this->site()->url( '/wp-json/fabrement/v1/fields/options/' . $slug . '?lang=pl' ), [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ),
            'Content-Type' => 'application/json',
        ], $body === null ? null : (string) json_encode( $body ) );
        $this->assertSame( 200, $response['status'], $response['body'] );
        return json_decode( $response['body'], true );
    }

    /** A language the site does not have is said as such, not as an options page that is not there. */
    public function test_a_wrong_language_is_said_as_a_wrong_language(): void {
        $slug = 'qa-life-lang';
        $this->create( $slug );
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        $this->assertNull( $this->site_runs( "return fabrement()->options('$slug', lang: 'xx')->get('title');" ) );
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        $this->assertStringContainsString( 'the site has no language "xx"', $log );
        $this->assertStringNotContainsString( 'there is no options page', $log );
    }

    /** @dataProvider writers */
    public function test_first_write_seeds_once_and_all_entries_share_sync( string $writer ): void {
        $slug = 'qa-life-' . $writer;
        $original = $this->create( $slug );
        $missing = $this->read( $slug );
        $this->assertFalse( $missing['versionExists'] );
        $this->assertNull( $missing['values']['title'] );
        $this->assertNull( $missing['values']['phone'] );
        $this->assertSame( 7, $missing['values']['price'] );
        $this->assertSame( 'Default', $missing['values']['unset'] );
        $this->assertNull( $this->site_runs( "return fabrement()->options('$slug', lang: 'pl')->get('title');" ) );
        $this->rest( $slug, 'GET' );
        $this->assertNull( $this->site()->option( 'fabrement_options_' . $slug . '.pl' ), 'All reads leave the version absent.' );

        if ( $writer === 'mcp' ) {
            $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'pl', 'values' => [ 'title' => 'Polski' ] ] );
        } elseif ( $writer === 'php' ) {
            $this->assertTrue( $this->site_runs( "return fabrement()->options('$slug', lang: 'pl')->set('title', 'Polski')->save();" ) );
        } else {
            $this->rest( $slug, 'PUT', [ 'values' => [ 'fab_title' => 'Polski' ], 'intentVersion' => 1, 'intent' => [ 'fab_title' => true ] ] );
        }
        $own = $this->read( $slug );
        $this->assertTrue( $own['versionExists'] );
        $this->assertSame( 'Polski', $own['values']['title'] );
        $this->assertSame( '123', $own['values']['phone'] );
        $this->assertNull( $own['values']['price'], 'A stored source null is copied, not replaced by the default.' );
        $this->assertNull( $own['values']['local'] );
        $this->assertFalse( $own['stored']['local'] );
        $this->assertFalse( $own['stored']['unset'] );
        $this->assertSame( $original['rows'], $own['values']['rows'] );
        $this->assertContains( $this->site()->value( 'SELECT autoload FROM wp_options WHERE option_name = ?', [ 'fabrement_options_' . $slug . '.pl' ] ), [ 'no', 'off' ] );

        $rows = $own['values']['rows'];
        $rows[0]['word'] = 'Jeden';
        $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'pl', 'values' => [ 'rows' => $rows, 'phone' => '456' ] ] );
        $this->assertSame( '456', $this->read( $slug, 'en' )['values']['phone'] );
        $this->assertSame( '456', $this->read( $slug )['values']['phone'], 'Routed copy edits synchronize physical PL storage.' );
        $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'en', 'values' => [
            'title' => 'Changed EN', 'phone' => '789', 'price' => 99,
            'rows' => [ $original['rows'][1], [ 'word' => 'New', 'code' => 'c' ], $original['rows'][0] ],
        ] ] );
        $after = $this->read( $slug )['values'];
        $this->assertSame( 'Polski', $after['title'] );
        $this->assertNull( $after['price'] );
        $this->assertSame( '789', $after['phone'] );
        $this->assertSame( [ 'Two', 'New', 'Jeden' ], array_column( $after['rows'], 'word' ) );
        $this->assertNull( $this->site()->option( 'fabrement_options_' . $slug . '.fr' ), 'Saving the source never creates other versions.' );
    }

    public static function writers(): array {
        return [ [ 'mcp' ], [ 'php' ], [ 'rest' ] ];
    }

    public function test_invalid_write_creates_nothing_and_first_repeater_edit_accepts_source_ids(): void {
        $slug = 'qa-life-invalid';
        $source = $this->create( $slug );
        $before = $this->site()->option( 'fabrement_options_' . $slug );
        $error = $this->mcp_error( 'set-field-values', [ 'options' => $slug, 'language' => 'pl', 'values' => [ 'title' => null, 'phone' => 'Must not route' ] ] );
        $this->assertStringContainsString( 'title', $error );
        $this->assertSame( $before, $this->site()->option( 'fabrement_options_' . $slug ) );
        $this->assertNull( $this->site()->option( 'fabrement_options_' . $slug . '.pl' ) );
        $rows = array_map( static fn( array $row ): array => [ '_row' => $row['_row'], 'word' => 'PL ' . $row['word'] ], $source['rows'] );
        $saved = $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'pl', 'values' => [ 'rows' => $rows ] ] );
        $this->assertSame( [ 'PL One', 'PL Two' ], array_column( $saved['values']['rows'], 'word' ) );
        $this->assertSame( [ 'a', 'b' ], array_column( $saved['values']['rows'], 'code' ) );
        $this->assertSame( 'English', $saved['values']['title'], 'Required validation includes the projected seed.' );
    }

    public function test_admin_navigation_creates_nothing_and_existing_empty_version_is_not_reseeded(): void {
        $slug = 'qa-life-admin';
        $source = $this->create( $slug );
        $browser = new Browser( $this->site() );
        $browser->login( 'admin', 'admin' );
        $url = $this->site()->url( '/wp-admin/admin.php?page=fabrement-options-' . $slug . '&lang=pl' );
        $response = $browser->get( $url );
        $this->assertSame( 200, $response['status'] );
        $this->assertNull( $this->site()->option( 'fabrement_options_' . $slug . '.pl' ), 'opening the page writes nothing' );
        $this->assertFalse( $this->read( $slug )['versionExists'] );
        $box = $this->rest( $slug, 'GET' );
        $this->assertSame( 'English', $box['values']['fab_title'], 'the form shows what the version will start from' );
        $this->assertSame( array_column( $source['rows'], '_row' ), array_column( $box['values']['fab_rows'], 'id' ) );
        $this->assertNull( $this->site()->option( 'fabrement_options_' . $slug . '.pl' ), 'reading the form writes nothing either' );
        $this->rest( $slug, 'PUT', [ 'values' => [ 'fab_local' => 'PL only' ], 'intentVersion' => 1, 'intent' => [ 'fab_local' => true ] ] );
        $this->assertTrue( $this->read( $slug )['versionExists'], 'the first save creates it' );
        $this->assertSame( 'English', $this->read( $slug )['values']['title'] );
        $this->assertSame( 'PL only', $this->read( $slug )['values']['local'] );
        $this->site()->wp( 'option', 'delete', 'fabrement_options_' . $slug . '.pl' );

        // Simulate a pre-existing empty version; opening it must never run its initial seed again.
        $this->site()->wp( 'option', 'update', 'fabrement_options_' . $slug . '.pl', '[]', '--format=json' );
        $response = $browser->get( $url );
        $this->assertSame( 200, $response['status'] );
        $this->assertSame( [], $this->site()->option( 'fabrement_options_' . $slug . '.pl' ) );
        $this->assertTrue( $this->read( $slug )['versionExists'] );
        $this->assertNull( $this->read( $slug )['values']['title'] );
        $this->assertSame( 7, $this->read( $slug )['values']['price'] );
        $source_before = $this->site()->option( 'fabrement_options_' . $slug );
        $this->assertStringContainsString( 'title', $this->mcp_error( 'set-field-values', [ 'options' => $slug, 'language' => 'pl', 'values' => [ 'phone' => 'Refused' ] ] ) );
        $this->assertSame( $source_before, $this->site()->option( 'fabrement_options_' . $slug ) );
        $this->assertSame( [], $this->site()->option( 'fabrement_options_' . $slug . '.pl' ) );
    }

    public function test_copy_only_write_creates_version_and_creation_race_cannot_replace_winner(): void {
        $slug = 'qa-life-race';
        $this->create( $slug );
        $saved = $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'fr', 'values' => [ 'phone' => '123' ] ] );
        $this->assertTrue( $saved['versionExists'] );
        $this->assertSame( 'English', $saved['values']['title'] );

        // Another writer publishes PL after our existence check but before its seed is inserted.
        // A source-option filter gives the same deterministic interleaving as competing requests.
        $this->site_code( 'qa-options-creation-race', "add_filter('option_fabrement_options_$slug', static function(\$values) {
            add_option('fabrement_options_$slug.pl', ['fab_title' => 'Winner'], '', false);
            return \$values;
        });" );
        $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'pl', 'values' => [ 'local' => 'Mine' ] ] );
        $this->assertSame( 'Winner', $this->site()->option( 'fabrement_options_' . $slug . '.pl' )['fab_title'], 'the version that won the race is not replaced by our seed' );
    }
}
