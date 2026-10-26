<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/** Original routing and translation sync share the same per-key storage diff. */
final class FieldMetaDiffTranslationTest extends SiteTestCase {
    protected const SITE = 'wpml';

    private function rows( int $id ): array {
        return $this->site()->rows( "SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'fab_%' ORDER BY meta_id", [ $id ] );
    }

    public function test_copy_write_from_translation_changes_only_that_key_in_both_languages(): void {
        $type = 'qa_meta_sync';
        $this->post_type( $type, [ 'translationMode' => 'translate' ] );
        $this->fields( $type, [ self::field( 'md_group', 'group', [ 'sub_fields' => [
            self::field( 'phone', 'text', [ 'translation' => 'copy' ] ),
            self::field( 'label', 'text', [ 'translation' => 'translate' ] ),
            self::field( 'count', 'number', [ 'translation' => 'copy_once' ] ),
        ] ] ) ] );
        $en = $this->record( $type, 'Meta sync' );
        $this->mcp( 'set-field-values', [ 'postId' => $en, 'values' => [ 'md_group' => [
            'phone' => 'Before', 'label' => 'Original', 'count' => 0,
        ] ] ] );
        $pl = (int) $this->mcp( 'translate-content', [ 'contentId' => $en, 'language' => 'pl', 'slug' => 'qa-meta-sync-pl' ] )['contentId'];
        $this->mcp( 'set-field-values', [ 'postId' => $pl, 'values' => [ 'md_group' => [ 'label' => 'Polski' ] ] ] );
        $before = [ $en => $this->rows( $en ), $pl => $this->rows( $pl ) ];
        $this->assertTrue( $this->site_runs( "return fabrement()->fields($pl)->set_text('md_group.phone', 'After')->save();" ) );
        $siblings = static fn( array $rows ): array => array_values( array_filter( $rows, static fn( array $row ): bool => $row['meta_key'] !== 'fab_md_group_phone' ) );
        foreach ( [ $en, $pl ] as $id ) {
            $this->assertSame( $siblings( $before[ $id ] ), $siblings( $this->rows( $id ) ), 'Unchanged translated siblings retain their meta IDs.' );
            $this->assertSame( [ 'After' ], $this->site()->meta( $id, 'fab_md_group_phone' ) );
        }
        $this->assertSame( [ 'Polski' ], $this->site()->meta( $pl, 'fab_md_group_label' ) );
        $this->assertTrue( $this->site_runs( "return fabrement()->fields($pl)->clear('md_group.phone')->save();" ) );
        $before = [ $en => $this->rows( $en ), $pl => $this->rows( $pl ) ];
        $this->assertFalse( $this->site_runs( "return fabrement()->fields($pl)->clear('md_group.phone')->save();" ) );
        foreach ( [ $en, $pl ] as $id ) {
            $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_md_group_phone' ) );
            $this->assertSame( $before[ $id ], $this->rows( $id ), 'A sync retry leaves existing SQL NULL and siblings untouched.' );
        }
    }
}
