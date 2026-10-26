<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/** Real metadata, options, MCP and PHP boundaries for the canonical empty storage format. */
final class FieldNullStorageTest extends SiteTestCase {

    private static function choice(): array {
        return [ 'default' => 'kyiv', 'choices' => [ [ 'value' => 'kyiv', 'label' => 'Kyiv' ], [ 'value' => '0', 'label' => 'Zero' ] ] ];
    }

    public function test_clears_store_sql_null_and_keep_nested_structure_in_posts_and_options(): void {
        $this->post_type( 'qa_nullstore' );
        $types = [ 'text', 'textarea', 'wysiwyg', 'number', 'true_false', 'select', 'date_picker', 'date_time_picker', 'image', 'file', 'post_object', 'gallery', 'relationship', 'link' ];
        $fields = [];
        foreach ( $types as $type ) {
            $extra = $type === 'select' ? self::choice() : [];
            if ( $type === 'text' ) { $extra['default'] = 'Initial'; }
            if ( $type === 'number' ) { $extra['default'] = 7; }
            if ( $type === 'true_false' ) { $extra['default'] = true; }
            $fields[] = self::field( 'ns_' . $type, $type, $extra );
        }
        $children = [ self::field( 'city', 'select', self::choice() ), self::field( 'title', 'text' ) ];
        $fields[] = self::field( 'ns_group', 'group', [ 'sub_fields' => $children ] );
        $fields[] = self::field( 'ns_rows', 'repeater', [ 'sub_fields' => [ self::field( 'address', 'group', [ 'sub_fields' => $children ] ) ] ] );
        $this->fields( 'qa_nullstore', $fields );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-nullstore', 'expectedRevision' => 0, 'page' => [ 'title' => 'Null storage' ], 'fields' => $fields ] );
        $id = $this->record( 'qa_nullstore', 'Storage' );
        foreach ( [ [ 'postId' => $id ], [ 'options' => 'qa-nullstore' ] ] as $target ) {
            $before = $this->mcp( 'get-field-values', $target );
            $this->assertSame( 'kyiv', $before['values']['ns_select'] );
            $this->assertFalse( $before['stored']['ns_select'] );
            $this->mcp( 'set-field-values', $target + [ 'values' => array_fill_keys( array_column( $fields, 'name' ), null ) ] );
            $after = $this->mcp( 'get-field-values', $target );
            foreach ( $after['values'] as $name => $value ) {
                $this->assertNull( $value, $name );
                $this->assertTrue( $after['stored'][ $name ], $name );
            }
        }
        $keys = array_merge( array_map( static fn( string $type ): string => 'fab_ns_' . $type, $types ), [ 'fab_ns_rows', 'fab_ns_group_city', 'fab_ns_group_title' ] );
        $options = $this->site()->option( 'fabrement_options_qa-nullstore' );
        foreach ( $keys as $key ) {
            $this->assertSame( [ null ], $this->site()->meta( $id, $key ), $key . ': one real SQL NULL row' );
            $this->assertArrayHasKey( $key, $options );
            $this->assertNull( $options[ $key ], $key );
        }
        $this->assertSame( [ '1' ], $this->site()->meta( $id, 'fab_ns_group' ) );
        $legacy = $this->site_runs( "return [fabrement_get_field('ns_select', $id), fabrement_get_field('ns_number', $id), fabrement_get_field('ns_true_false', $id), fabrement_get_field('ns_image', $id), fabrement_get_field('ns_gallery', $id)];" );
        $this->assertSame( [ '', null, false, 0, [] ], $legacy, 'Released helpers keep their return shapes.' );

        foreach ( [ [ 'postId' => $id ], [ 'options' => 'qa-nullstore' ] ] as $target ) {
            $saved = $this->mcp( 'set-field-values', $target + [ 'values' => [ 'ns_rows' => [
                [ 'address' => [ 'city' => 'kyiv', 'title' => 'First' ] ],
                [ 'address' => [ 'city' => '0', 'title' => 'Second' ] ],
            ] ] ] )['values']['ns_rows'];
            $saved[0]['address']['city'] = null;
            $saved = array_reverse( $saved );
            $this->mcp( 'set-field-values', $target + [ 'values' => [ 'ns_rows' => $saved ] ] );
            $this->assertSame( $saved, $this->mcp( 'get-field-values', $target )['values']['ns_rows'] );
            if ( isset( $target['postId'] ) ) {
                $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_ns_rows_1_address_city' ) );
                $this->assertSame( [ $saved[1]['_row'] ], $this->site()->meta( $id, 'fab_ns_rows_1__rid' ) );
                $this->assertSame( [ '2' ], $this->site()->meta( $id, 'fab_ns_rows' ) );
            } else {
                $map = $this->site()->option( 'fabrement_options_qa-nullstore' );
                $this->assertArrayHasKey( 'fab_ns_rows_1_address_city', $map );
                $this->assertNull( $map['fab_ns_rows_1_address_city'] );
                $this->assertSame( $saved[1]['_row'], $map['fab_ns_rows_1__rid'] );
            }
        }
        $this->site_runs( "fabrement()->fields($id)->set_select('ns_select', 'kyiv')->save(); fabrement()->fields($id)->clear('ns_select')->save();" );
        $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_ns_select' ) );
    }

    public function test_explicit_null_query_works_with_suppressed_filters_and_preserves_other_conditions(): void {
        $this->post_type( 'qa_nullarg' );
        $this->fields( 'qa_nullarg', [ self::field( 'qn_city', 'text' ), self::field( 'qn_flag', 'text' ), self::field( 'qn_rank', 'number' ) ] );
        $ids = [];
        foreach ( [ 'null_a', 'null_b', 'legacy', 'zero', 'text', 'absent' ] as $i => $name ) {
            $ids[ $name ] = $this->record( 'qa_nullarg', $name );
            $values = [ 'qn_flag' => $name === 'null_a' ? 'ready' : 'other', 'qn_rank' => 60 - $i * 10 ];
            if ( $name !== 'absent' ) {
                $values['qn_city'] = in_array( $name, [ 'null_a', 'null_b', 'legacy' ], true ) ? null : ( $name === 'zero' ? '0' : 'NULL' );
            }
            if ( $name === 'zero' ) { $values['qn_flag'] = null; }
            $this->mcp( 'set-field-values', [ 'postId' => $ids[ $name ], 'values' => $values ] );
        }
        $this->site_runs( "global \$wpdb; update_post_meta({$ids['legacy']}, 'fab_qn_city', '');"
            . " \$wpdb->insert(\$wpdb->postmeta, ['post_id' => {$ids['null_a']}, 'meta_key' => 'fab_qn_city', 'meta_value' => null]);" );
        $out = $this->site_runs( <<<'PHP'
$base = ['post_type' => 'qa_nullarg', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC'];
$run = static fn(array $extra): array => array_map('intval', (new WP_Query(array_merge($base, $extra)))->posts);
$null = ['fabrement_meta_null' => 'fab_qn_city'];
$original = ['score' => ['key' => 'fab_qn_rank', 'compare' => 'EXISTS', 'type' => 'NUMERIC']];
$reuse = new WP_Query($base + $null + ['meta_query' => $original]);
$first = array_map('intval', $reuse->posts);
$reuse->set('fabrement_meta_null', 'fab_qn_flag');
$second = array_map('intval', $reuse->get_posts());
$reuse->set('fabrement_meta_null', null);
$third = array_map('intval', $reuse->get_posts());
$page = new WP_Query(array_merge($base, $null, ['posts_per_page' => 1]));
$bad = false;
try { new WP_Query($base + ['fabrement_meta_null' => ['fab_qn_city']]); }
catch (InvalidArgumentException $e) { $bad = true; }
return [
    'default' => $run($null),
    'suppressed' => $run($null + ['suppress_filters' => true]),
    'get_posts' => array_map('intval', get_posts($base + $null)),
    'no_argument' => $run([]),
    'disabled' => $run(['fabrement_meta_null' => null]),
    'other_key' => $run(['fabrement_meta_null' => 'fab_qn_flag']),
    'quoted_key' => $run(['fabrement_meta_null' => "fab_qn_city' OR 1=1 --"]),
    'or' => $run($null + ['meta_query' => ['relation' => 'OR', ['key' => 'fab_qn_city', 'value' => 'NULL'], ['key' => 'fab_qn_flag', 'value' => 'ready']]]),
    'nested' => $run($null + ['meta_query' => ['relation' => 'AND', $original, ['relation' => 'OR', ['key' => 'fab_qn_flag', 'value' => 'ready'], ['key' => 'fab_qn_city', 'compare' => 'NOT EXISTS']]]]),
    'primary' => $run($null + ['meta_key' => 'fab_qn_flag', 'meta_value' => 'ready']),
    'sort' => $run($null + ['meta_query' => $original, 'orderby' => ['score' => 'ASC']]),
    'primary_sort' => $run($null + ['meta_key' => 'fab_qn_rank', 'orderby' => 'meta_value_num']),
    'reuse' => [$first, $second, $third],
    'page' => [array_map('intval', $page->posts), (int) $page->found_posts, (int) $page->max_num_pages],
    'invalid_rejected' => $bad,
];
PHP
        );
        $nulls = [ $ids['null_a'], $ids['null_b'] ];
        foreach ( [ 'default', 'suppressed', 'get_posts' ] as $case ) {
            $this->assertSame( $nulls, $out[ $case ], $case . ': only SQL NULL, with no duplicate posts.' );
        }
        foreach ( [ 'no_argument', 'disabled' ] as $case ) { $this->assertSame( array_values( $ids ), $out[ $case ], $case ); }
        $this->assertSame( [ $ids['zero'] ], $out['other_key'], 'Cached queries for different NULL keys stay distinct.' );
        $this->assertSame( [], $out['quoted_key'] );
        foreach ( [ 'or', 'nested', 'primary' ] as $case ) { $this->assertSame( [ $ids['null_a'] ], $out[ $case ], $case ); }
        foreach ( [ 'sort', 'primary_sort' ] as $case ) { $this->assertSame( array_reverse( $nulls ), $out[ $case ], $case ); }
        $this->assertSame( [ $nulls, [ $ids['zero'] ], array_values( $ids ) ], $out['reuse'] );
        $this->assertSame( [ [ $ids['null_a'] ], 2, 2 ], $out['page'] );
        $this->assertTrue( $out['invalid_rejected'] );
    }
}
