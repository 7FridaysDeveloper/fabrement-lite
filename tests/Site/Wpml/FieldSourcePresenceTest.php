<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/** Sparse source children and explicit null use the same seed on posts and options. */
final class FieldSourcePresenceTest extends SiteTestCase {
    protected const SITE = 'wpml';

    /** @dataProvider owners */
    public function test_translation_seed_preserves_absence_and_null( string $owner ): void {
        $slug = 'qa-sparse-' . $owner;
        $children = [ self::field( 'absent', 'number', [ 'translation' => 'copy_once', 'default' => 7 ] ),
            self::field( 'cleared', 'number', [ 'translation' => 'copy_once', 'default' => 9 ] ),
            self::field( 'local', 'text', [ 'translation' => 'ignore' ] ) ];
        $fields = [ self::field( 'title', 'text' ), self::field( 'group', 'group', [ 'sub_fields' => $children ] ),
            self::field( 'rows', 'repeater', [ 'sub_fields' => $children ] ) ];
        if ( $owner === 'post' ) {
            $this->post_type( 'qa_sparse', [ 'translationMode' => 'translate' ] );
            $this->fields( 'qa_sparse', $fields );
            $id = $this->record( 'qa_sparse', 'Sparse' );
            $target = [ 'postId' => $id ];
        } else {
            $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => 0, 'page' => [ 'title' => 'Sparse' ], 'fields' => $fields ] );
            $target = [ 'options' => $slug, 'language' => 'en' ];
        }
        $source = $this->mcp( 'set-field-values', $target + [ 'values' => [ 'title' => 'EN',
            'group' => [ 'cleared' => null, 'local' => 'Private' ], 'rows' => [ [ 'cleared' => null, 'local' => 'Private' ] ] ] ] );
        // Old objects can lack children added to their schema after their last save.
        $missing = [ 'fab_group_absent', 'fab_rows_0_absent' ];
        if ( $owner === 'post' ) {
            $this->site_runs( 'foreach (' . var_export( $missing, true ) . " as \$key) { delete_post_meta($id, \$key); }" );
            $translation = $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => 'pl' ] )['contentId'];
            $own = [ 'postId' => $translation ];
        } else {
            $this->site_runs( "\$raw=get_option('fabrement_options_$slug'); foreach (" . var_export( $missing, true ) . " as \$key) { unset(\$raw[\$key]); } update_option('fabrement_options_$slug', \$raw);" );
            $own = [ 'options' => $slug, 'language' => 'pl' ];
        }
        $this->mcp( 'set-field-values', $own + [ 'values' => [ 'title' => 'PL' ] ] );
        $this->mcp( 'set-field-values', $target + [ 'values' => [ 'title' => 'Changed EN' ] ] );
        $read = $this->mcp( 'get-field-values', $own )['values'];
        $this->assertSame( 'PL', $read['title'] );
        $this->assertSame( $source['values']['rows'][0]['_row'], $read['rows'][0]['_row'] );
        foreach ( [ 'fab_group', 'fab_rows_0' ] as $prefix ) {
            if ( $owner === 'post' ) {
                $this->assertFalse( $this->site()->has_meta( $translation, $prefix . '_absent' ) );
                $this->assertFalse( $this->site()->has_meta( $translation, $prefix . '_local' ) );
                $this->assertSame( [ null ], $this->site()->meta( $translation, $prefix . '_cleared' ) );
            } else {
                $raw = $this->site()->option( 'fabrement_options_' . $slug . '.pl' );
                $this->assertArrayNotHasKey( $prefix . '_absent', $raw );
                $this->assertArrayNotHasKey( $prefix . '_local', $raw );
                $this->assertArrayHasKey( $prefix . '_cleared', $raw );
                $this->assertNull( $raw[ $prefix . '_cleared' ] );
            }
        }
    }

    public static function owners(): array {
        return [ [ 'post' ], [ 'options' ] ];
    }
}
