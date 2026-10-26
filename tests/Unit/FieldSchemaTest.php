<?php

use Fabrement\Modules\Fields\Schema\LayoutValidator;
use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Fields\Schema\SchemaValidator;
use Fabrement\Modules\Fields\Schema\StorageLayout;
use PHPUnit\Framework\TestCase;

final class FieldSchemaTest extends TestCase {

    private function validator( ?array $shared = null ): SchemaValidator {
        return new SchemaValidator( static fn( string $key ): ?array => $shared && $shared['key'] === $key ? $shared : null );
    }

    private static function field( string $name, string $type = 'text', array $extra = [] ): array {
        return $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type, 'translation' => SchemaValidator::default_translation( $type ) ];
    }

    public function test_names_and_keys_cannot_look_like_row_paths(): void {
        foreach ( [ 'client', 'hero_title', 'step2' ] as $ok ) {
            self::assertTrue( StorageLayout::valid_name( $ok ), $ok );
        }
        foreach ( [ 'Client', '_client', 'client_', 'a__b', 'step_1', 'items_0_title', '1st' ] as $bad ) {
            self::assertFalse( StorageLayout::valid_name( $bad ), $bad );
        }
        self::assertSame( 'fab_milestones', StorageLayout::key_for( 'milestones', true ) );
        self::assertSame( 'title', StorageLayout::key_for( 'title', false ) );
        self::assertTrue( StorageLayout::valid_key( 'fab_client', 'client', true ) );
        self::assertTrue( StorageLayout::valid_key( 'client', 'client', false ) );
        self::assertFalse( StorageLayout::valid_key( 'client', 'client', true ) );
        self::assertFalse( StorageLayout::valid_key( 'client_k7m2q9', 'client', true ) );
        self::assertFalse( StorageLayout::valid_key( 'fab_step_1', 'step_1', true ) );
        // A name that looks like a key would make a selector reach the wrong field.
        self::assertFalse( StorageLayout::valid_name( 'fab_price' ) );
        self::assertTrue( StorageLayout::valid_name( 'fabric' ) );
    }

    public function test_translation_policy_defaults_by_type_and_is_kept_on_existing_fields(): void {
        $plain = static function ( string $name, string $type, array $extra = [] ): array {
            $field = self::field( $name, $type, $extra );
            unset( $field['translation'] );
            return $field;
        };
        $saved = array_column( $this->validator()->normalize( [
            $plain( 'title', 'text' ),
            $plain( 'cta', 'link' ),
            $plain( 'price', 'number', [ 'format' => 'decimal' ] ),
            $plain( 'steps', 'repeater', [ 'sub_fields' => [ $plain( 'heading', 'text' ), $plain( 'photo', 'image' ) ] ] ),
            self::field( 'note', 'textarea', [ 'translation' => 'ignore' ] ),
        ], [] ), null, 'name' );
        self::assertSame( 'translate', $saved['title']['translation'] );
        self::assertSame( 'translate', $saved['cta']['translation'] );
        self::assertSame( 'copy', $saved['price']['translation'] );
        self::assertSame( 'copy', $saved['steps']['translation'] );
        self::assertSame( [ 'translate', 'copy' ], array_column( $saved['steps']['sub_fields'], 'translation' ) );
        self::assertSame( 'ignore', $saved['note']['translation'] );

        // Sent again without a policy, an existing field keeps the one it has.
        $again = $plain( 'note', 'textarea' ) + [ 'key' => $saved['note']['key'] ];
        $kept = $this->validator()->normalize( [ $again ], array_values( $saved ) );
        self::assertSame( 'ignore', $kept[0]['translation'] );
    }

    public function test_translate_is_for_words_only(): void {
        foreach ( [ [ 'price', 'number', [ 'format' => 'decimal' ] ], [ 'starts', 'date_picker', [] ], [ 'course', 'post_object', [] ], [ 'steps', 'repeater', [ 'sub_fields' => [ self::field( 't' ) ] ] ] ] as [ $name, $type, $extra ] ) {
            try {
                $this->validator()->normalize( [ self::field( $name, $type, [ 'translation' => 'translate' ] + $extra ) ], [] );
                self::fail( 'translate accepted for ' . $type );
            } catch ( SchemaError $e ) {
                self::assertSame( 'fields[0].translation', $e->path );
                self::assertStringContainsString( 'copy_once', $e->getMessage() );
            }
        }
        $ok = $this->validator()->normalize( [ self::field( 'price', 'number', [ 'format' => 'decimal', 'translation' => 'copy_once' ] ), self::field( 'cta', 'link', [ 'translation' => 'translate' ] ) ], [] );
        self::assertSame( [ 'copy_once', 'translate' ], array_column( $ok, 'translation' ) );

        // A schema stored before keeps working: its translate becomes what it always behaved as.
        $old = [
            [ 'key' => 'fab_price', 'name' => 'price', 'label' => 'P', 'description' => 'P', 'type' => 'number', 'format' => 'decimal', 'translation' => 'translate', 'required' => false ],
            [ 'key' => 'fab_steps', 'name' => 'steps', 'label' => 'S', 'description' => 'S', 'type' => 'repeater', 'translation' => 'translate', 'required' => false, 'sub_fields' => [
                [ 'key' => 't', 'name' => 't', 'label' => 'T', 'description' => 'T', 'type' => 'text', 'translation' => 'translate', 'required' => false ],
            ] ],
        ];
        $resent = array_map( static function ( array $f ): array {
            unset( $f['translation'] );
            if ( isset( $f['sub_fields'] ) ) {
                $f['sub_fields'] = array_map( static function ( array $c ): array { unset( $c['translation'] ); return $c; }, $f['sub_fields'] );
            }
            return $f;
        }, $old );
        $migrated = $this->validator()->normalize( $resent, $old );
        self::assertSame( [ 'copy_once', 'copy' ], array_column( $migrated, 'translation' ) );
        self::assertSame( 'translate', $migrated[1]['sub_fields'][0]['translation'] );
    }

    public function test_record_pickers_may_be_limited_to_public_post_types(): void {
        \Fabrement\Tests\WordPressState::$types['project'] = (object) [ 'name' => 'project', 'public' => true, 'show_in_rest' => true ];
        \Fabrement\Tests\WordPressState::$types['secret'] = (object) [ 'name' => 'secret', 'public' => false, 'show_in_rest' => true ];
        $saved = $this->validator()->normalize( [ self::field( 'related', 'post_object', [ 'postTypes' => [ 'project' ] ] ) ], [] );
        self::assertSame( [ 'project' ], $saved[0]['postTypes'] );
        foreach ( [
            [ self::field( 'related', 'post_object', [ 'postTypes' => [ 'secret' ] ] ), 'fields[0].postTypes[0]' ],
            [ self::field( 'related', 'post_object', [ 'postTypes' => [ 'missing' ] ] ), 'fields[0].postTypes[0]' ],
            [ self::field( 'related', 'relationship', [ 'postTypes' => [] ] ), 'fields[0].postTypes' ],
            [ self::field( 'title', 'text', [ 'postTypes' => [ 'project' ] ] ), 'fields[0].postTypes' ],
        ] as [ $field, $path ] ) {
            try {
                $this->validator()->normalize( [ $field ], [] );
                self::fail( 'Accepted ' . json_encode( $field ) );
            } catch ( SchemaError $e ) {
                self::assertSame( $path, $e->path );
            }
        }
    }

    public function test_a_name_goes_into_its_key_whole(): void {
        $longest = 'product_specifications_x';
        self::assertSame( StorageLayout::NAME_MAX, strlen( $longest ) );
        self::assertSame( 'fab_' . $longest, $this->validator()->normalize( [ self::field( $longest ) ], [] )[0]['key'] );
        self::assertFalse( StorageLayout::valid_name( 'product_specifications_3d_model' ) );
        try {
            $this->validator()->normalize( [ self::field( 'product_specifications_3d_model' ) ], [] );
            self::fail( 'A name longer than the limit was accepted.' );
        } catch ( SchemaError $e ) {
            self::assertStringContainsString( 'at most 24 characters', $e->getMessage() );
        }
    }

    public function test_ownership_is_exact_not_by_prefix(): void {
        $repeater = [ 'key' => 'fab_items', 'type' => 'repeater', 'sub_fields' => [
            [ 'key' => 'title', 'type' => 'text' ],
            [ 'key' => 'meta', 'type' => 'group', 'sub_fields' => [ [ 'key' => 'city', 'type' => 'text' ] ] ],
        ] ];
        $pattern = StorageLayout::owned_pattern( $repeater );
        foreach ( [ 'fab_items', 'fab_items_0__rid', 'fab_items_12_title', 'fab_items_3_meta', 'fab_items_3_meta_city' ] as $owned ) {
            self::assertSame( 1, preg_match( $pattern, $owned ), $owned );
        }
        foreach ( [ 'fab_items_extra', 'fab_itemsx', 'fab_items_0_other', 'fab_items_title', 'fab_items_2x_title', 'items' ] as $foreign ) {
            self::assertSame( 0, preg_match( $pattern, $foreign ), $foreign );
        }
    }

    public function test_keys_follow_names_and_left_out_fields_are_retired_not_deleted(): void {
        $first = $this->validator()->normalize( [
            self::field( 'client' ),
            self::field( 'milestones', 'repeater', [ 'sub_fields' => [ self::field( 'title' ), self::field( 'quote' ) ] ] ),
        ], [] );
        self::assertSame( [ 'fab_client', 'fab_milestones' ], array_column( $first, 'key' ) );
        self::assertSame( [ 'title', 'quote' ], array_column( $first[1]['sub_fields'], 'key' ) );

        // Drop client and the repeater's quote; heading is a new name, so a new field, and title
        // is retired: there is no rename. A key sent back unchanged is accepted.
        $second = $this->validator()->normalize( [
            self::field( 'milestones', 'repeater', [ 'key' => 'fab_milestones', 'sub_fields' => [ self::field( 'heading' ) ] ] ),
        ], $first );
        self::assertSame( 'fab_milestones', $second[0]['key'] );
        self::assertSame( [ 'heading', 'title', 'quote' ], array_column( $second[0]['sub_fields'], 'key' ) );
        self::assertSame( [ false, true, true ], array_map( static fn( array $f ): bool => ! empty( $f['retired'] ), $second[0]['sub_fields'] ) );
        self::assertSame( 'fab_client', $second[1]['key'] );
        self::assertTrue( $second[1]['retired'] );

        // Sending the name again restores it.
        $third = $this->validator()->normalize( [ self::field( 'client' ) ], $second );
        self::assertArrayNotHasKey( 'retired', $third[0] );
        self::assertTrue( $third[1]['retired'] );
    }

    public function test_a_name_keeps_its_type_and_a_key_cannot_be_chosen(): void {
        $stored = $this->validator()->normalize( [ self::field( 'budget', 'number', [ 'format' => 'integer' ] ), self::field( 'old', 'text' ) ], [] );
        $stored = $this->validator()->normalize( [ self::field( 'budget', 'number' ) ], $stored ); // old is retired
        foreach ( [
            [ [ self::field( 'budget', 'text' ) ], 'never changes' ],
            [ [ self::field( 'budget', 'number', [ 'format' => 'decimal' ] ) ], 'keeps its format' ],
            [ [ self::field( 'budget', 'number' ), self::field( 'old', 'number' ) ], 'retired text field' ],
            [ [ self::field( 'budget', 'number', [ 'key' => 'fab_cost' ] ) ], 'cannot be renamed' ],
            [ [ self::field( 'budget', 'number', [ 'key' => 'budget_a1b2c3' ] ) ], 'follows from the name' ],
        ] as [ $case, $message ] ) {
            try {
                $this->validator()->normalize( $case, $stored );
                self::fail( 'Accepted ' . json_encode( $case ) );
            } catch ( SchemaError $e ) {
                self::assertStringContainsString( 'fields[', $e->path );
                self::assertStringContainsString( $message, $e->getMessage() );
            }
        }
        // The same name one level down is another key: a group may hold its own budget.
        $nested = $this->validator()->normalize( [ self::field( 'budget', 'number' ), self::field( 'box', 'group', [ 'sub_fields' => [ self::field( 'budget', 'text' ) ] ] ) ], $stored );
        self::assertSame( 'budget', $nested[1]['sub_fields'][0]['key'] );
    }

    public function test_a_name_may_not_reach_into_a_sibling_group(): void {
        $group = self::field( 'details', 'group', [ 'sub_fields' => [ self::field( 'city' ) ] ] );
        foreach ( [ [ $group, self::field( 'details_city' ) ], [ self::field( 'box', 'group', [ 'sub_fields' => [ $group, self::field( 'details_x' ) ] ] ) ] ] as $i => $case ) {
            try {
                $this->validator()->normalize( $case, [] );
                self::fail( 'Accepted case ' . $i );
            } catch ( SchemaError $e ) {
                self::assertStringContainsString( 'inside the group "details"', $e->getMessage() );
            }
        }
        // A retired group still owns its keys.
        $stored = $this->validator()->normalize( [ $group ], [] );
        $stored = $this->validator()->normalize( [ self::field( 'other' ) ], $stored );
        $this->expectException( SchemaError::class );
        $this->validator()->normalize( [ self::field( 'other' ), self::field( 'details_city' ) ], $stored );
    }

    public function test_repeater_rows_never_clash_with_sibling_names(): void {
        $out = $this->validator()->normalize( [ self::field( 'items', 'repeater', [ 'sub_fields' => [ self::field( 'title' ) ] ] ), self::field( 'items_title' ), self::field( 'items_2x' ) ], [] );
        self::assertCount( 3, $out );
        $pattern = StorageLayout::owned_pattern( $out[0] );
        self::assertSame( 0, preg_match( $pattern, 'fab_items_title' ) );
        self::assertSame( 0, preg_match( $pattern, 'fab_items_2x' ) );
    }

    /** Fixed and repeated keys share one traversal, including groups inside nested repeaters. */
    public function test_storage_keys_name_every_field_and_repeater_child(): void {
        $fields = [
            [ 'name' => 'title', 'type' => 'text' ],
            [ 'name' => 'venue', 'type' => 'group', 'sub_fields' => [ [ 'name' => 'city', 'type' => 'text' ] ] ],
            [ 'name' => 'agenda', 'type' => 'repeater', 'sub_fields' => [
                [ 'name' => 'time', 'type' => 'text' ],
                [ 'name' => 'room', 'type' => 'group', 'sub_fields' => [ [ 'name' => 'floor', 'type' => 'number' ] ] ],
                [ 'name' => 'talks', 'type' => 'repeater', 'sub_fields' => [ [ 'name' => 'speaker', 'type' => 'text' ] ] ],
            ] ],
        ];
        self::assertSame(
            [
                'fab_title' => [ 'path' => 'title', 'type' => 'text' ],
                'fab_venue' => [ 'path' => 'venue', 'type' => 'group' ],
                'fab_venue_city' => [ 'path' => 'venue.city', 'type' => 'text' ],
                'fab_agenda' => [ 'path' => 'agenda', 'type' => 'repeater' ],
                'fab_agenda_{n}_time'                  => [ 'path' => 'agenda[].time', 'type' => 'text' ],
                'fab_agenda_{n}_room'                  => [ 'path' => 'agenda[].room', 'type' => 'group' ],
                'fab_agenda_{n}_room_floor'            => [ 'path' => 'agenda[].room.floor', 'type' => 'number' ],
                'fab_agenda_{n}_talks'                 => [ 'path' => 'agenda[].talks', 'type' => 'repeater' ],
                'fab_agenda_{n}_talks_{n}_speaker'     => [ 'path' => 'agenda[].talks[].speaker', 'type' => 'text' ],
            ],
            StorageLayout::keys( $fields )
        );
    }

    public function test_the_same_name_on_another_post_type_needs_the_same_type(): void {
        $shared = [ 'key' => 'fab_client', 'name' => 'client', 'type' => 'text', 'label' => 'Client', 'description' => 'x', 'translation' => 'copy', 'required' => false ];
        $out = $this->validator( $shared )->normalize( [ self::field( 'client', 'text' ) ], [] );
        self::assertSame( 'fab_client', $out[0]['key'] );
        self::assertSame( 'translate', $out[0]['translation'], 'The policy is per owner.' );

        try {
            $this->validator( $shared )->normalize( [ self::field( 'client', 'number' ) ], [] );
            self::fail( 'Another type under the same key was accepted.' );
        } catch ( SchemaError $e ) {
            self::assertStringContainsString( 'another post type', $e->getMessage() );
        }
        $group = [ 'key' => 'fab_box', 'name' => 'box', 'type' => 'group', 'label' => 'B', 'description' => 'x', 'translation' => 'copy', 'required' => false, 'sub_fields' => [
            [ 'key' => 'a', 'name' => 'a', 'label' => 'A', 'description' => 'x', 'type' => 'text', 'translation' => 'translate', 'required' => false ],
        ] ];
        $own = $this->validator( $group )->normalize( [ self::field( 'box', 'group', [ 'sub_fields' => [ self::field( 'b' ) ] ] ) ], [] );
        self::assertSame( [ 'b' ], array_column( $own[0]['sub_fields'], 'key' ), 'Sub-fields are per post type.' );
    }

    /** @dataProvider invalid_fields */
    public function test_rejects_invalid_definitions( array $field, string $path ): void {
        try {
            $this->validator()->normalize( [ $field ], [] );
            self::fail( 'Accepted' );
        } catch ( SchemaError $e ) {
            self::assertSame( $path, $e->path );
        }
    }

    public function invalid_fields(): array {
        return [
            'unknown property'   => [ self::field( 'a', 'text', [ 'metaName' => 'x' ] ), 'fields[0].metaName' ],
            'bad name'           => [ self::field( 'step_1' ), 'fields[0].name' ],
            'bad translation'    => [ self::field( 'a', 'text', [ 'translation' => 'always' ] ), 'fields[0].translation' ],
            'empty description'  => [ self::field( 'a', 'text', [ 'description' => ' ' ] ), 'fields[0].description' ],
            'select no choices'  => [ self::field( 'a', 'select' ), 'fields[0].choices' ],
            'default not choice' => [ self::field( 'a', 'select', [ 'choices' => [ [ 'value' => 'x', 'label' => 'X' ] ], 'default' => 'y' ] ), 'fields[0].default' ],
            'empty repeater'     => [ self::field( 'a', 'repeater', [ 'sub_fields' => [] ] ), 'fields[0].sub_fields' ],
            'bad date default'   => [ self::field( 'a', 'date_picker', [ 'default' => '2025-02-29' ] ), 'fields[0].default' ],
            'required group'     => [ self::field( 'a', 'group', [ 'required' => true, 'sub_fields' => [ self::field( 'b' ) ] ] ), 'fields[0].required' ],
        ];
    }

    public function test_sibling_names_are_unique_but_levels_are_independent(): void {
        $out = $this->validator()->normalize( [
            self::field( 'title' ),
            self::field( 'box', 'group', [ 'sub_fields' => [ self::field( 'title' ) ] ] ),
        ], [] );
        self::assertSame( 'title', $out[1]['sub_fields'][0]['name'] );
        $this->expectException( SchemaError::class );
        $this->validator()->normalize( [ self::field( 'title' ), self::field( 'title' ) ], [] );
    }

    public function test_layout_points_only_at_active_fields_and_never_hides_one(): void {
        $fields = $this->validator()->normalize( [ self::field( 'client' ), self::field( 'rows', 'repeater', [ 'sub_fields' => [ self::field( 'x' ) ] ] ) ], [] );
        $layout = LayoutValidator::normalize( [ 'tabs' => [ [ 'label' => 'Main', 'items' => [
            [ 'field' => $fields[0]['key'], 'width' => '1/2' ],
            [ 'field' => $fields[1]['key'], 'display' => 'table' ],
        ] ] ] ], $fields );
        self::assertSame( '1/2', $layout['tabs'][0]['items'][0]['width'] );

        // New fields have no key until the same save generates one, so a name works too.
        $by_name = LayoutValidator::normalize( [ 'tabs' => [ [ 'label' => 'Main', 'items' => [ [ 'field' => 'client' ] ] ] ] ], $fields );
        self::assertSame( $fields[0]['key'], $by_name['tabs'][0]['items'][0]['field'] );

        $fields[0]['retired'] = true;
        self::assertCount( 1, LayoutValidator::prune( $layout, $fields )['tabs'][0]['items'] );

        foreach ( [
            [ [ 'field' => $fields[0]['key'] ] ],
            [ [ 'field' => $fields[1]['key'], 'width' => '1/5' ] ],
            [ [ 'field' => $fields[1]['key'] ], [ 'field' => $fields[1]['key'] ] ],
        ] as $items ) {
            try {
                LayoutValidator::normalize( [ 'tabs' => [ [ 'label' => 'Main', 'items' => $items ] ] ], $fields );
                self::fail( 'Accepted ' . json_encode( $items ) );
            } catch ( SchemaError $e ) {
                self::assertStringStartsWith( 'layout.tabs[0].items', $e->path );
            }
        }
    }

    public function test_number_bounds_and_default_stay_within_two_to_the_53(): void {
        foreach ( [ [ 'max' => 1e20 ], [ 'min' => -9007199254740992 ], [ 'default' => 1e19 ] ] as $extra ) {
            try {
                $this->validator()->normalize( [ self::field( 'n', 'number', $extra + [ 'format' => 'decimal' ] ) ], [] );
                self::fail( 'Accepted ' . json_encode( $extra ) );
            } catch ( SchemaError $e ) {
                self::assertStringStartsWith( 'fields[0]', $e->path );
            }
        }
        self::assertSame( 9007199254740991, $this->validator()->normalize( [ self::field( 'n', 'number', [ 'format' => 'integer', 'max' => 9007199254740991 ] ) ], [] )[0]['max'] );
    }
}
