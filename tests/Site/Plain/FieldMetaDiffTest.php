<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/** Compare physical meta rows, not just resolved values, through the public writers. */
final class FieldMetaDiffTest extends SiteTestCase {
    private const TYPE = 'qa_meta_diff';
    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->post_type( self::TYPE );
            $this->fields( self::TYPE, [
                self::field( 'pm_title', 'text' ),
                self::field( 'pm_group', 'group', [ 'sub_fields' => [
                    self::field( 'phone', 'text' ), self::field( 'count', 'number', [ 'default' => 7 ] ),
                    self::field( 'nested', 'group', [ 'sub_fields' => [
                        self::field( 'label', 'text' ), self::field( 'empty', 'text' ),
                    ] ] ),
                    self::field( 'links', 'relationship' ), self::field( 'photos', 'gallery' ),
                ] ] ),
                self::field( 'pm_rows', 'repeater', [ 'sub_fields' => [
                    self::field( 'title', 'text' ), self::field( 'details', 'group', [ 'sub_fields' => [
                        self::field( 'note', 'text' ), self::field( 'amount', 'number' ),
                    ] ] ),
                ] ] ),
            ] );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    private function set( int $id, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] )['values'];
    }

    private function rows( int $id ): array {
        return $this->site()->rows( "SELECT meta_id, meta_key, meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key LIKE 'fab_%' ORDER BY meta_id", [ $id ] );
    }

    private function assert_only_keys_changed( array $before, int $id, array $keys ): void {
        $untouched = static fn( array $rows ): array => array_values( array_filter( $rows,
            static fn( array $row ): bool => ! in_array( $row['meta_key'], $keys, true ) ) );
        $this->assertSame( $untouched( $before ), $untouched( $this->rows( $id ) ),
            'Untouched values AND meta IDs must survive; equal values reinserted under new IDs are not enough.' );
    }

    /** @dataProvider writers */
    public function test_one_nested_group_edit_preserves_every_other_meta_row( string $writer ): void {
        $id = $this->record( self::TYPE, 'One child ' . $writer );
        $this->set( $id, [
            'pm_title' => 'Other top-level field',
            'pm_group' => [ 'phone' => 'Before', 'count' => 0, 'nested' => [ 'label' => 'Label', 'empty' => null ], 'links' => null, 'photos' => null ],
            'pm_rows' => [ [ 'title' => 'Other container' ] ],
        ] );
        $before = $this->rows( $id );
        if ( $writer === 'mcp' ) {
            $this->set( $id, [ 'pm_group' => [ 'nested' => [ 'label' => 'Changed' ] ] ] );
        } elseif ( $writer === 'php' ) {
            $this->assertTrue( $this->site_runs( "return fabrement()->fields($id)->set_text('pm_group.nested.label', 'Changed')->save();" ) );
        } else {
            $url = $this->site()->url( '/wp-json/fabrement/v1/fields/post/' . $id );
            $headers = [ 'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ), 'Content-Type' => 'application/json' ];
            $loaded = Http::request( $this->site(), 'GET', $url, $headers );
            $this->assertSame( 200, $loaded['status'], $loaded['body'] );
            $values = json_decode( $loaded['body'], true )['values'];
            $values['fab_pm_group']['nested']['label'] = 'Changed';
            $saved = Http::request( $this->site(), 'PUT', $url, $headers, (string) json_encode( [
                'values' => [ 'fab_pm_group' => $values['fab_pm_group'] ],
                'intent' => [ 'fab_pm_group' => [ 'nested' => [ 'label' => true ] ] ], 'intentVersion' => 1,
            ] ) );
            $this->assertSame( 200, $saved['status'], $saved['body'] );
        }
        $this->assert_only_keys_changed( $before, $id, [ 'fab_pm_group_nested_label' ] );
        $this->assertSame( [ 'Changed' ], $this->site()->meta( $id, 'fab_pm_group_nested_label' ) );
        $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_pm_group_nested_empty' ) );
        $this->assertSame( [ '0' ], $this->site()->meta( $id, 'fab_pm_group_count' ) );
    }

    public static function writers(): array {
        return [ 'PHP' => [ 'php' ], 'MCP' => [ 'mcp' ], 'REST' => [ 'rest' ] ];
    }

    public function test_repeater_edit_move_and_removal_touch_only_different_storage_positions(): void {
        $id = $this->record( self::TYPE, 'Row positions' );
        $values = $this->set( $id, [ 'pm_rows' => array_map( static fn( string $title ): array => [
            'title' => $title, 'details' => [ 'note' => 'Same note', 'amount' => null ],
        ], [ 'A', 'B', 'C' ] ) ] );
        [ $a, $b, $c ] = array_column( $values['pm_rows'], '_row' );
        $before = $this->rows( $id );
        $this->site_runs( "return fabrement()->fields($id)->set_text('pm_rows.$a.details.note', 'Changed')->save();" );
        $this->assert_only_keys_changed( $before, $id, [ 'fab_pm_rows_0_details_note' ] );

        $before = $this->rows( $id );
        $this->site_runs( "return fabrement()->fields($id)->move_row('pm_rows', '$a', after: '$b')->save();" );
        $this->assert_only_keys_changed( $before, $id, [
            'fab_pm_rows_0__rid', 'fab_pm_rows_1__rid', 'fab_pm_rows_0_title', 'fab_pm_rows_1_title',
            'fab_pm_rows_0_details_note', 'fab_pm_rows_1_details_note',
        ] );
        $read = $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values']['pm_rows'];
        $this->assertSame( [ $b, $a, $c ], array_column( $read, '_row' ) );
        $this->assertSame( [ 'Same note', 'Changed', 'Same note' ], array_column( array_column( $read, 'details' ), 'note' ) );

        $before = $this->rows( $id );
        $this->site_runs( "return fabrement()->fields($id)->delete_row('pm_rows', '$c')->save();" );
        $this->assert_only_keys_changed( $before, $id, [
            'fab_pm_rows', 'fab_pm_rows_2__rid', 'fab_pm_rows_2_title', 'fab_pm_rows_2_details',
            'fab_pm_rows_2_details_note', 'fab_pm_rows_2_details_amount',
        ] );
        $this->assertSame( [ '2' ], $this->site()->meta( $id, 'fab_pm_rows' ) );
        $this->assertSame( [], array_values( array_filter( $this->rows( $id ), static fn( array $row ): bool => str_starts_with( $row['meta_key'], 'fab_pm_rows_2_' ) ) ) );
    }

    public function test_list_keys_preserve_order_and_unchanged_siblings(): void {
        $id = $this->record( self::TYPE, 'List rows' );
        $a = $this->record( 'page', 'First pick' );
        $b = $this->record( 'page', 'Second pick' );
        $image = $this->image( 'diff.png' );
        $second = $this->image( 'diff-second.png' );
        $this->set( $id, [ 'pm_group' => [ 'phone' => 'Before', 'links' => [ $a, $b ], 'photos' => [ $second, $image ] ] ] );
        $this->assertSame( [ (string) $second, (string) $image ], $this->site()->meta( $id, 'fab_pm_group_photos' ) );
        $before = $this->rows( $id );
        $this->set( $id, [ 'pm_group' => [ 'phone' => 'After' ] ] );
        $this->assert_only_keys_changed( $before, $id, [ 'fab_pm_group_phone' ] );
        $before = $this->rows( $id );
        $this->set( $id, [ 'pm_group' => [ 'links' => [ $b, $a ] ] ] );
        $this->assert_only_keys_changed( $before, $id, [ 'fab_pm_group_links' ] );
        $this->assertSame( array_map( 'strval', [ $b, $a ] ), $this->site()->meta( $id, 'fab_pm_group_links' ) );
        $before = $this->rows( $id );
        $this->set( $id, [ 'pm_group' => [ 'links' => [ $a ] ] ] );
        $this->assert_only_keys_changed( $before, $id, [ 'fab_pm_group_links' ] );
        $this->assertSame( [ (string) $a ], $this->site()->meta( $id, 'fab_pm_group_links' ) );
        $before = $this->rows( $id );
        $this->set( $id, [ 'pm_group' => [ 'phone' => 'Changed beside one item', 'links' => [ $a ] ] ] );
        $this->assert_only_keys_changed( $before, $id, [ 'fab_pm_group_phone' ] );
        $this->assertSame( [ (string) $a ], $this->site()->meta( $id, 'fab_pm_group_links' ) );
        $before = $this->rows( $id );
        $this->set( $id, [ 'pm_group' => [ 'links' => null ] ] );
        $this->assert_only_keys_changed( $before, $id, [ 'fab_pm_group_links' ] );
        $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_pm_group_links' ) );
        $before = $this->rows( $id );
        $this->set( $id, [ 'pm_group' => [ 'links' => null ] ] );
        $this->assertSame( $before, $this->rows( $id ), 'Repeated explicit clear must not rewrite the existing SQL NULL.' );
    }

    public function test_absence_null_legacy_empty_and_explicit_default_are_distinct(): void {
        $id = $this->record( self::TYPE, 'Presence and default' );
        $this->set( $id, [ 'pm_group' => [ 'phone' => 'Before' ] ] );
        $this->assertFalse( $this->site()->has_meta( $id, 'fab_pm_group_count' ) );
        $before = $this->rows( $id );
        $this->set( $id, [ 'pm_group' => [ 'count' => 7, 'nested' => [ 'empty' => null ] ] ] );
        $this->assert_only_keys_changed( $before, $id, [ 'fab_pm_group_count', 'fab_pm_group_nested', 'fab_pm_group_nested_empty' ] );
        $this->assertSame( [ '7' ], $this->site()->meta( $id, 'fab_pm_group_count' ) );
        $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_pm_group_nested_empty' ) );
        // A legacy empty string is a fixture only; the next explicit clear must canonicalize it.
        $this->site_runs( "update_post_meta($id, 'fab_pm_group_phone', ''); return true;" );
        $before = $this->rows( $id );
        $this->assertTrue( $this->site_runs( "return fabrement()->fields($id)->clear('pm_group.phone')->save();" ) );
        $this->assert_only_keys_changed( $before, $id, [ 'fab_pm_group_phone' ] );
        $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_pm_group_phone' ) );
        $before = $this->rows( $id );
        $this->assertFalse( $this->site_runs( "return fabrement()->fields($id)->clear('pm_group.phone')->save();" ) );
        $this->assertSame( $before, $this->rows( $id ) );
    }

    public function test_meta_hooks_fire_only_for_changed_keys_and_save_hook_still_fires_on_noop(): void {
        $id = $this->record( self::TYPE, 'Hook scope' );
        $this->set( $id, [ 'pm_group' => [ 'phone' => 'Before', 'count' => 0, 'nested' => [ 'empty' => null ] ] ] );
        $result = $this->site_runs( str_replace( 'POST_ID', (string) $id, <<<'PHP'
$events = [];
$saved = 0;
$cleaned = 0;
$writes = [];
foreach (['added_post_meta', 'deleted_post_meta'] as $hook) {
    add_action($hook, static function($metaId, $id, $key) use (&$events, $hook) {
        if ($id === POST_ID && strpos($key, 'fab_') === 0) { $events[] = [$hook, $key]; }
    }, 10, 3);
}
add_action('fabrement_fields_saved', static function($type, $id) use (&$saved) {
    if ($type === 'post' && $id === POST_ID) { ++$saved; }
}, 10, 2);
add_action('clean_post_cache', static function($id) use (&$cleaned) {
    if ($id === POST_ID) { ++$cleaned; }
});
add_filter('query', static function($sql) use (&$writes) {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql) && strpos($sql, 'postmeta') !== false) { $writes[] = $sql; }
    return $sql;
});
$first = fabrement()->fields(POST_ID)->set_text('pm_group.phone', 'After')->save();
$changed = [$first, $events, $saved, $cleaned > 0];
$events = [];
$saved = $cleaned = 0;
$writes = [];
$second = fabrement()->fields(POST_ID)->clear('pm_group.nested.empty')->save();
return [$changed, [$second, $events, $saved, $cleaned > 0, count($writes)]];
PHP
        ) );
        $this->assertSame( [ true, [
            [ 'deleted_post_meta', 'fab_pm_group_phone' ], [ 'added_post_meta', 'fab_pm_group_phone' ],
        ], 1, true ], $result[0] );
        $this->assertSame( [ false, [], 1, true, 0 ], $result[1], 'A no-op issues no meta writes, retains cache refresh and the save notification used for sync retries.' );
    }

    public function test_failed_diff_write_rolls_back_values_meta_ids_and_cached_reads(): void {
        $id = $this->record( self::TYPE, 'Rollback' );
        $this->set( $id, [ 'pm_group' => [ 'phone' => 'Before', 'count' => 0 ] ] );
        $before = $this->rows( $id );
        $result = $this->site_runs( str_replace( 'POST_ID', (string) $id, <<<'PHP'
get_post_meta(POST_ID);
add_filter('add_post_metadata', static function($check, $id, $key) {
    return $id === POST_ID && $key === 'fab_pm_group_count' ? false : $check;
}, 10, 3);
try {
    fabrement()->fields(POST_ID)->set_group('pm_group', ['phone' => 'Must roll back', 'count' => 12])->save();
    return ['unexpected success'];
} catch (\Fabrement\Api\FieldsException $error) {
    return [$error->getMessage(), get_post_meta(POST_ID, 'fab_pm_group_phone', true), get_post_meta(POST_ID, 'fab_pm_group_count', true)];
}
PHP
        ) );
        $this->assertStringContainsString( 'Could not store field values', $result[0] );
        $this->assertSame( [ 'Before', '0' ], array_slice( $result, 1 ) );
        $this->assertSame( $before, $this->rows( $id ) );
    }

    public function test_options_update_once_and_skip_storage_hooks_on_repeated_clear(): void {
        $slug = 'qa-meta-diff';
        $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => 0, 'page' => [ 'title' => 'Diff options' ],
            'fields' => [ self::field( 'phone', 'text' ), self::field( 'neighbor', 'text' ) ],
        ] );
        $this->mcp( 'set-field-values', [ 'options' => $slug, 'values' => [ 'neighbor' => 'Keep' ] ] );
        $result = $this->site_runs( <<<'PHP'
$updates = 0;
$saved = 0;
add_filter('pre_update_option_fabrement_options_qa-meta-diff', static function($value) use (&$updates) {
    ++$updates;
    return $value;
});
add_action('fabrement_fields_saved', static function($type) use (&$saved) {
    if ($type === 'options') { ++$saved; }
});
$first = fabrement()->options('qa-meta-diff')->clear('phone')->save();
$second = fabrement()->options('qa-meta-diff')->clear('phone')->save();
return [$first, $second, $updates, $saved];
PHP
        );
        $this->assertSame( [ true, false, 1, 2 ], $result );
        $this->assertSame( [ 'fab_neighbor' => 'Keep', 'fab_phone' => null ], $this->site()->option( 'fabrement_options_' . $slug ) );
    }

    public function test_diff_uses_fresh_storage_and_refreshes_cache_even_when_nothing_remains_to_write(): void {
        $id = $this->record( self::TYPE, 'Fresh diff' );
        $this->set( $id, [ 'pm_group' => [ 'phone' => 'Before', 'count' => 0 ] ] );
        $result = $this->site_runs( str_replace( 'POST_ID', (string) $id, <<<'PHP'
$fields = fabrement()->fields(POST_ID)->set_text('pm_group.phone', 'After');
global $wpdb;
// Simulate another writer committing after staging, without refreshing this request's cache.
$wpdb->update($wpdb->postmeta, ['meta_value' => 'After'], ['post_id' => POST_ID, 'meta_key' => 'fab_pm_group_phone']);
$wpdb->update($wpdb->postmeta, ['meta_value' => '23'], ['post_id' => POST_ID, 'meta_key' => 'fab_pm_group_count']);
$sql = $wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", POST_ID);
$before = $wpdb->get_results($sql, ARRAY_A);
$changed = $fields->save();
return [$changed, get_post_meta(POST_ID, 'fab_pm_group_phone', true),
    get_post_meta(POST_ID, 'fab_pm_group_count', true), $before === $wpdb->get_results($sql, ARRAY_A)];
PHP
        ) );
        $this->assertSame( [ false, 'After', '23', true ], $result );
    }

    public function test_retired_repeater_child_keeps_its_row_identity_through_edits_moves_and_deletion(): void {
        $type = 'qa_diff_retired';
        $this->post_type( $type );
        $fields = [ self::field( 'rd_other', 'text' ), self::field( 'rd_rows', 'repeater', [ 'sub_fields' => [
            self::field( 'title', 'text' ), self::field( 'legacy', 'text' ),
        ] ] ) ];
        $this->fields( $type, $fields );
        $id = $this->record( $type, 'Retired row values' );
        $values = $this->set( $id, [ 'rd_other' => 'Keep', 'rd_rows' => array_map(
            static fn( string $title ): array => [ 'title' => $title, 'legacy' => 'Old ' . $title ], [ 'A', 'B', 'C' ]
        ) ] );
        [ $a, $b, $c ] = array_column( $values['rd_rows'], '_row' );
        $before = $this->rows( $id );
        array_pop( $fields[1]['sub_fields'] );
        $this->fields( $type, $fields );
        $this->assertSame( $before, $this->rows( $id ), 'Retirement retains stored values.' );

        $this->site_runs( "return fabrement()->fields($id)->set_text('rd_rows.$a.title', 'Changed A')->save();" );
        $this->assert_only_keys_changed( $before, $id, [ 'fab_rd_rows_0_title' ] );
        $before = $this->rows( $id );
        $this->site_runs( "return fabrement()->fields($id)->move_row('rd_rows', '$b', before: '$a')->save();" );
        $this->assert_only_keys_changed( $before, $id, [
            'fab_rd_rows_0__rid', 'fab_rd_rows_1__rid', 'fab_rd_rows_0_title', 'fab_rd_rows_1_title',
            'fab_rd_rows_0_legacy', 'fab_rd_rows_1_legacy',
        ] );
        $this->assertSame( [ 'Old B' ], $this->site()->meta( $id, 'fab_rd_rows_0_legacy' ) );
        $this->assertSame( [ 'Old A' ], $this->site()->meta( $id, 'fab_rd_rows_1_legacy' ) );

        $before = $this->rows( $id );
        $this->site_runs( "return fabrement()->fields($id)->delete_row('rd_rows', '$a')->save();" );
        $this->assert_only_keys_changed( $before, $id, [
            'fab_rd_rows', 'fab_rd_rows_1__rid', 'fab_rd_rows_1_title', 'fab_rd_rows_1_legacy',
            'fab_rd_rows_2__rid', 'fab_rd_rows_2_title', 'fab_rd_rows_2_legacy',
        ] );
        $this->assertSame( [ '2' ], $this->site()->meta( $id, 'fab_rd_rows' ) );
        $this->assertSame( [ $c ], $this->site()->meta( $id, 'fab_rd_rows_1__rid' ) );
        $this->assertSame( [ 'Old B' ], $this->site()->meta( $id, 'fab_rd_rows_0_legacy' ) );
        $this->assertSame( [ 'Old C' ], $this->site()->meta( $id, 'fab_rd_rows_1_legacy' ) );
        $this->assertSame( [], array_values( array_filter( $this->rows( $id ), static fn( array $row ): bool => str_starts_with( $row['meta_key'], 'fab_rd_rows_2_' ) ) ) );
    }
}
