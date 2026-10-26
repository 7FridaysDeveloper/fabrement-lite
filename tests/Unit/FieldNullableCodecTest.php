<?php

use Fabrement\Modules\Fields\Translation\TranslationPolicy;
use Fabrement\Modules\Fields\Values\Store\MetaStore;
use Fabrement\Modules\Fields\Values\Store\OptionStore;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueRepository;
use Fabrement\Tests\Api\ApiTestCase;

require_once __DIR__ . '/Api/ApiTestCase.php';

final class FieldNullableCodecTest extends ApiTestCase {
    private static function field( string $type, array $extra = [] ): array {
        return $extra + [ 'key' => 'fab_value', 'name' => 'value', 'label' => 'Value', 'type' => $type, 'required' => false, 'translation' => 'copy_once' ];
    }

    public function test_all_types_accept_null_without_reviving_defaults(): void {
        foreach ( [ 'text', 'textarea', 'wysiwyg', 'number', 'true_false', 'select', 'date_picker', 'date_time_picker', 'image', 'file', 'post_object', 'gallery', 'relationship', 'link', 'group', 'repeater' ] as $type ) {
            $field = self::field( $type, [ 'sub_fields' => [], 'choices' => [] ] );
            $value = ValueCodec::normalize( $field, null );
            $meta = [];
            ValueCodec::flatten( $field, $value, 'fab_value', $meta );
            self::assertArrayHasKey( 'fab_value', $meta, $type );
            self::assertSame( $type === 'group' ? '1' : null, $meta['fab_value'], $type . ' storage' );
            $stored = ValueRepository::decode( [ 'fab_value' => $field ], $meta );
            self::assertTrue( $stored['exists']['fab_value'], $type );
            self::assertArrayHasKey( 'fab_value', $stored['present'], $type );
            self::assertNull( ValueCodec::api_value( $field, ValueCodec::public_value( $field, $stored['values']['fab_value'] ) ), $type );
        }
    }

    public function test_missing_uses_default_and_explicit_blank_never_does(): void {
        foreach ( [ [ 'number', 7 ], [ 'text', 'Initial' ], [ 'true_false', true ], [ 'select', 'initial' ] ] as [ $type, $default ] ) {
            $field = self::field( $type, [ 'default' => $default ] );
            self::assertSame( $default, ValueCodec::inflate( $field, 'fab_value', [] ) );
            $meta = [];
            ValueCodec::flatten( $field, ValueCodec::normalize( $field, null ), 'fab_value', $meta );
            $field['default'] = $type === 'text' ? 'Changed' : ( $type === 'number' ? 19 : false );
            self::assertNull( ValueCodec::api_value( $field, ValueCodec::inflate( $field, 'fab_value', $meta ) ) );
        }
    }

    public function test_zero_false_and_string_zero_remain_values(): void {
        foreach ( [ [ 'number', 0 ], [ 'true_false', false ], [ 'text', '0' ] ] as [ $type, $value ] ) {
            $field = self::field( $type );
            $meta = [];
            ValueCodec::flatten( $field, ValueCodec::normalize( $field, $value ), 'fab_value', $meta );
            self::assertSame( $value, ValueCodec::api_value( $field, ValueCodec::inflate( $field, 'fab_value', $meta ) ) );
            self::assertNotNull( $meta['fab_value'], $type . ' storage' );
        }
    }

    private static function children(): array {
        return [
            self::field( 'number', [ 'key' => 'price', 'name' => 'price', 'default' => 7, 'translation' => 'copy' ] ),
            self::field( 'text', [ 'key' => 'title', 'name' => 'title', 'translation' => 'translate' ] ),
        ];
    }

    public function test_group_and_repeater_neighbor_edits_keep_cleared_number(): void {
        $group = self::field( 'group', [ 'sub_fields' => self::children() ] );
        $previous = [ 'price' => null, 'title' => 'Before' ];
        self::assertNull( ValueCodec::editor_value( $group, $previous )['price'] );
        self::assertNull( ValueCodec::public_value( $group, $previous )['price'] );
        $next = ValueCodec::normalize( $group, ValueCodec::from_public( $group, [ 'title' => 'After' ], $previous ), '', [ $previous ] );
        self::assertSame( [ 'price' => null, 'title' => 'After' ], $next );

        $row_id = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $repeater = self::field( 'repeater', [ 'sub_fields' => self::children() ] );
        $rows = [ [ 'id' => $row_id, 'values' => $previous ] ];
        $next = ValueCodec::normalize( $repeater, ValueCodec::from_public( $repeater, [ [ '_row' => $row_id, 'title' => 'After' ] ], $rows ), '', [ $rows ] );
        self::assertNull( $next[0]['values']['price'] );
        $meta = [];
        ValueCodec::flatten( $repeater, $next, 'fab_value', $meta );
        self::assertNull( ValueCodec::api_value( $repeater, ValueCodec::public_value( $repeater, ValueCodec::inflate( $repeater, 'fab_value', $meta ) ) )[0]['price'] );
    }

    public function test_translation_merge_and_write_back_preserve_explicit_null(): void {
        $group = self::field( 'group', [ 'translation' => 'translate', 'sub_fields' => self::children() ] );
        $original = [ 'price' => null, 'title' => 'Original' ];
        $translated = [ 'price' => null, 'title' => 'Translated' ];
        $merged = TranslationPolicy::merge( $group, $original, $translated, true );
        self::assertSame( $translated, $merged );
        TranslationPolicy::assert_editable( $group, $translated, $original, 'value', $translated );
        self::assertSame( $original, TranslationPolicy::to_original( $group, $translated, $translated, $original ) );
        self::assertNull( TranslationPolicy::with_original( $group, $translated, $original )['price'] );
        $original['price'] = 19;
        TranslationPolicy::assert_editable( $group, $translated, $original, 'value', $translated );
        self::assertSame( 19, TranslationPolicy::with_original( $group, $translated, $original )['price'], 'An unchanged stale null heals from the original without blocking its sibling.' );
    }

    public function test_empty_lists_have_a_present_null_storage_value_and_stable_round_trip(): void {
        foreach ( [ 'gallery', 'relationship', 'repeater' ] as $type ) {
            $field = self::field( $type, [ 'sub_fields' => [] ] );
            $meta = [];
            ValueCodec::flatten( $field, [], 'fab_value', $meta );
            self::assertSame( [ 'fab_value' => null ], $meta, $type );
            foreach ( [ new MetaStore( 'post' ), new OptionStore() ] as $store ) {
                $stored = $store->stored( $meta );
                self::assertSame( $meta, $stored );
                $again = [];
                ValueCodec::flatten( $field, ValueCodec::inflate( $field, 'fab_value', $stored ), 'fab_value', $again );
                self::assertSame( $stored, $store->stored( $again ) );
            }
            foreach ( [ '', '0', [] ] as $legacy ) {
                self::assertSame( [], ValueCodec::inflate( $field, 'fab_value', [ 'fab_value' => $legacy ] ) );
            }
        }
    }

    public function test_legacy_empty_values_remain_readable_and_rewrite_as_null(): void {
        $legacy = [
            'text' => '', 'textarea' => '', 'wysiwyg' => '', 'select' => '',
            'date_picker' => '', 'date_time_picker' => '', 'number' => '', 'true_false' => '',
            'image' => '0', 'file' => '0', 'post_object' => '0',
            'gallery' => '', 'relationship' => '', 'repeater' => '0',
            'link' => [ 'url' => '', 'label' => '', 'postId' => 0, 'lang' => '', 'newTab' => false, 'useRecordTitle' => false ],
        ];
        foreach ( $legacy as $type => $raw ) {
            $field = self::field( $type, [ 'sub_fields' => [] ] );
            $value = ValueCodec::inflate( $field, 'fab_value', [ 'fab_value' => $raw ] );
            self::assertNull( ValueCodec::api_value( $field, ValueCodec::public_value( $field, $value ) ), $type );
            $meta = [];
            ValueCodec::flatten( $field, $value, 'fab_value', $meta );
            self::assertSame( [ 'fab_value' => null ], $meta, $type );
            foreach ( [ new MetaStore( 'post' ), new OptionStore() ] as $store ) {
                $stored = $store->stored( $meta );
                self::assertSame( $meta, $stored, $type );
                self::assertSame( $value, ValueCodec::inflate( $field, 'fab_value', $stored ), $type );
            }
        }
    }

    public function test_stored_null_remains_owned_by_copy_once_while_copy_follows_source(): void {
        foreach ( [ [ 'text', 'New' ], [ 'select', 'new' ], [ 'number', 12 ], [ 'true_false', true ] ] as [ $type, $source ] ) {
            foreach ( [ 'copy', 'copy_once' ] as $policy ) {
                $field = self::field( $type, [ 'translation' => $policy, 'default' => $source,
                    'choices' => [ [ 'value' => 'new', 'label' => 'New' ] ] ] );
                $held = ValueRepository::decode( [ 'fab_value' => $field ], [ 'fab_value' => null ] );
                $value = TranslationPolicy::merge( $field, $source, $held['values']['fab_value'], $held['exists']['fab_value'] );
                self::assertSame( $policy === 'copy' ? $source : null, ValueCodec::api_value( $field, $value ), $type . ' ' . $policy );
                $absent = ValueRepository::decode( [ 'fab_value' => $field ], [] );
                self::assertSame( $source, TranslationPolicy::merge( $field, $source, $absent['values']['fab_value'], $absent['exists']['fab_value'] ) );
            }
        }
    }

    public function test_null_children_preserve_row_identity_neighbors_and_retired_values(): void {
        $city = self::field( 'select', [ 'key' => 'city', 'name' => 'city', 'default' => 'kyiv' ] );
        $title = self::field( 'text', [ 'key' => 'title', 'name' => 'title' ] );
        $retired = self::field( 'text', [ 'key' => 'retired', 'name' => 'retired', 'retired' => true ] );
        $group = self::field( 'group', [ 'key' => 'address', 'name' => 'address', 'sub_fields' => [ $city, $title, $retired ] ] );
        $rows = self::field( 'repeater', [ 'sub_fields' => [ $group ] ] );
        $id = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $previous = [ [ 'id' => $id, 'values' => [ 'address' => [ 'city' => 'lviv', 'title' => 'Keep', 'retired' => 'History' ] ] ] ];
        $next = [ [ 'id' => $id, 'values' => [ 'address' => [ 'city' => '', 'title' => 'Keep' ] ] ] ];
        $meta = [];
        ValueCodec::flatten( $rows, ValueCodec::with_retired( $rows, $next, $previous ), 'fab_value', $meta );
        self::assertSame( [
            'fab_value' => '1', 'fab_value_0__rid' => $id, 'fab_value_0_address' => '1',
            'fab_value_0_address_city' => null, 'fab_value_0_address_title' => 'Keep',
            'fab_value_0_address_retired' => 'History',
        ], $meta );
        $read = ValueRepository::decode( [ 'fab_value' => $rows ], $meta );
        self::assertArrayHasKey( 'fab_value_0_address_city', $read['present'] );
        $public = ValueCodec::api_value( $rows, ValueCodec::public_value( $rows, $read['values']['fab_value'], true ) );
        self::assertSame( [ [ '_row' => $id, 'address' => [ 'city' => null, 'title' => 'Keep' ] ] ], $public );
    }
}
