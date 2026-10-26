<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/** Explicit nested editor clears stay empty after the original changes. */
final class OptionsEditorNullTest extends SiteTestCase {
    protected const SITE = 'wpml';

    /** @dataProvider policies */
    public function test_nested_editor_null_survives_original_updates( string $policy ): void {
        $slug = 'qa-editor-null-' . str_replace( '_', '-', $policy );
        $contact = self::field( 'contact', 'group', [ 'sub_fields' => [
            self::field( 'phone', 'text', [ 'translation' => $policy ] ),
            self::field( 'label', 'text' ),
        ] ] );
        $this->mcp( 'save-options-page', [
            'slug' => $slug, 'expectedRevision' => 0, 'page' => [ 'title' => 'Editor null' ],
            'fields' => [ self::field( 'title', 'text' ), $contact,
                self::field( 'rows', 'repeater', [ 'sub_fields' => [ $contact ] ] ),
            ],
        ] );
        $original = $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'en', 'values' => [
            'title' => 'Original', 'contact' => [ 'phone' => null, 'label' => 'Contact' ],
            'rows' => [ [ 'contact' => [ 'phone' => null, 'label' => 'Row contact' ] ] ],
        ] ] )['values'];
        $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'pl', 'values' => [ 'title' => 'Polski' ] ] );
        $source_before = $this->site()->option( 'fabrement_options_' . $slug );
        $url = $this->site()->url( '/wp-json/fabrement/v1/fields/options/' . $slug . '?lang=pl' );
        $headers = [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ),
            'Content-Type' => 'application/json',
        ];
        $loaded = Http::request( $this->site(), 'GET', $url, $headers );
        $this->assertSame( 200, $loaded['status'], $loaded['body'] );
        $values = json_decode( $loaded['body'], true )['values'];
        $this->assertSame( '', $values['fab_contact']['phone'] );
        $this->assertSame( '', $values['fab_rows'][0]['values']['contact']['phone'] );
        $values['fab_contact']['phone'] = null;
        $values['fab_rows'][0]['values']['contact']['phone'] = null;
        $row   = (string) $values['fab_rows'][0]['id'];
        $saved = Http::request( $this->site(), 'PUT', $url, $headers, (string) json_encode( [
            'values' => [ 'fab_contact' => $values['fab_contact'], 'fab_rows' => $values['fab_rows'] ],
            'intent' => [ 'fab_contact' => [ 'phone' => true ], 'fab_rows' => [ $row => [ 'contact' => [ 'phone' => true ] ] ] ], 'intentVersion' => 1,
        ] ) );
        $this->assertSame( 200, $saved['status'], $saved['body'] );
        $this->assertSame( $source_before, $this->site()->option( 'fabrement_options_' . $slug ) );

        $own = $this->site()->option( 'fabrement_options_' . $slug . '.pl' );
        foreach ( [ 'fab_contact_phone', 'fab_rows_0_contact_phone' ] as $key ) {
            $this->assertArrayHasKey( $key, $own );
            $this->assertNull( $own[ $key ] );
        }
        $this->assertSame( $original['rows'][0]['_row'], $own['fab_rows_0__rid'] );
        $this->assertSame( 'Contact', $own['fab_contact_label'], 'Initial seeding stores the sibling once; the clear preserves it.' );
        $this->assertSame( 'Row contact', $own['fab_rows_0_contact_label'] );

        $original['contact']['phone'] = '+380 44 100';
        $original['rows'][0]['contact']['phone'] = '+380 44 200';
        $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'en', 'values' => $original ] );
        $after = $this->mcp( 'get-field-values', [ 'options' => $slug, 'language' => 'pl' ] )['values'];
        $this->assertNull( $after['contact']['phone'] );
        $this->assertNull( $after['rows'][0]['contact']['phone'] );
        $this->assertSame( $original['rows'][0]['_row'], $after['rows'][0]['_row'] );
        $this->assertSame( 'Polski', $after['title'] );
    }

    public static function policies(): array {
        return [ 'translate' => [ 'translate' ], 'copy_once' => [ 'copy_once' ] ];
    }
}
