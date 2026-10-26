<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/** PHP operations compose before save, including groups nested inside repeater rows. */
final class OptionsOrderedWriteTest extends SiteTestCase {
    protected const SITE = 'wpml';

    /** @dataProvider policies */
    public function test_overwritten_clears_do_not_claim_inherited_values_but_omitted_clears_survive( string $policy ): void {
        $slug = 'qa-ordered-' . str_replace( '_', '-', $policy );
        $contact = self::field( 'contact', 'group', [ 'sub_fields' => [
            self::field( 'phone', 'text', [ 'translation' => $policy ] ),
            self::field( 'label', 'text' ),
        ] ] );
        $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => 0,
            'page' => [ 'title' => 'Ordered writes' ], 'fields' => [
                self::field( 'title', 'text' ), self::field( 'phone', 'text', [ 'translation' => $policy ] ),
                $contact, self::field( 'rows', 'repeater', [ 'sub_fields' => [ $contact ] ] ),
                self::field( 'empty_rows', 'repeater', [ 'translation' => 'copy_once', 'sub_fields' => [ self::field( 'text', 'text' ) ] ] ),
            ],
        ] );
        $original = $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'en', 'values' => [
            'title' => 'Original', 'phone' => null, 'contact' => [ 'phone' => null, 'label' => 'Contact' ],
            'rows' => [ [ 'contact' => [ 'phone' => null, 'label' => 'Row contact' ] ] ],
            'empty_rows' => null,
        ] ] )['values'];
        $row = $original['rows'][0]['_row'];
        $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'pl', 'values' => [ 'title' => 'Polski' ] ] );
        $source_before = $this->site()->option( 'fabrement_options_' . $slug );
        $own_before = $this->site()->option( 'fabrement_options_' . $slug . '.pl' );

        $changed = $this->site_runs( "\$f = fabrement()->options('$slug', lang: 'pl');
            \$f->clear('phone')->set_text('phone', 'Temporary')->set_text('phone', '');
            \$f->clear('contact.phone')->set_group('contact', ['phone' => '']);
            \$f->clear('rows.$row.contact.phone')->update_row('rows', '$row', ['contact' => ['phone' => '']]);
            \$f->clear('empty_rows')->set_rows('empty_rows', []);
            return \$f->save();" );
        $this->assertFalse( $changed );
        $this->assertSame( $own_before, $this->site()->option( 'fabrement_options_' . $slug . '.pl' ) );
        $this->assertSame( $source_before, $this->site()->option( 'fabrement_options_' . $slug ) );

        // These later partial assignments omit phone, so the pending phone clears must survive.
        $this->assertFalse( $this->site_runs( "\$f = fabrement()->options('$slug', lang: 'pl');
            \$f->set_text('phone', 'Temporary')->clear('phone');
            \$f->clear('contact')->set_group('contact', ['label' => 'Contact']);
            \$f->clear('rows.$row.contact')->update_row('rows', '$row', ['contact' => ['label' => 'Row contact']]);
            \$f->clear('empty_rows')->delete_row('empty_rows', '$row');
            return \$f->save();" ) );
        $own = $this->site()->option( 'fabrement_options_' . $slug . '.pl' );
        foreach ( [ 'fab_phone', 'fab_contact_phone', 'fab_rows_0_contact_phone', 'fab_empty_rows' ] as $key ) {
            $this->assertArrayHasKey( $key, $own );
            $this->assertNull( $own[ $key ] );
        }
        $this->assertSame( $row, $own['fab_rows_0__rid'] );
        $this->assertSame( 'Contact', $own['fab_contact_label'] );
        $this->assertSame( 'Row contact', $own['fab_rows_0_contact_label'] );
        $this->assertSame( $source_before, $this->site()->option( 'fabrement_options_' . $slug ) );

        $original['phone'] = 'Later phone';
        $original['contact']['phone'] = 'Later contact';
        $original['rows'][0]['contact']['phone'] = 'Later row';
        $this->mcp( 'set-field-values', [ 'options' => $slug, 'language' => 'en', 'values' => $original ] );
        $after = $this->mcp( 'get-field-values', [ 'options' => $slug, 'language' => 'pl' ] )['values'];
        $this->assertNull( $after['phone'] );
        $this->assertNull( $after['contact']['phone'] );
        $this->assertNull( $after['rows'][0]['contact']['phone'] );
        $this->assertSame( 'Polski', $after['title'] );
    }

    public static function policies(): array {
        return [ 'translate' => [ 'translate' ], 'copy_once' => [ 'copy_once' ] ];
    }
}
