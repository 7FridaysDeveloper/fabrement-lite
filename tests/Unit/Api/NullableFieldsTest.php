<?php

namespace Fabrement\Tests\Api;

use Fabrement\Api\Fabrement;
use Fabrement\Api\Fields\Fields;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Tests\WordPressState;

require_once __DIR__ . '/ApiTestCase.php';

/** The nullable contract applies at every field depth, before and after staged edits. */
final class NullableFieldsTest extends ApiTestCase {
    private string $schema;

    private const READERS = [
        'text' => 'get_text', 'textarea' => 'get_textarea', 'wysiwyg' => 'get_wysiwyg',
        'number' => 'get_number', 'true_false' => 'get_true_false', 'select' => 'get_select',
        'date_picker' => 'get_date', 'date_time_picker' => 'get_date_time', 'image' => 'get_image',
        'file' => 'get_file', 'post_object' => 'get_post_object', 'gallery' => 'get_gallery',
        'relationship' => 'get_relationship', 'link' => 'get_link',
        'group' => 'get_group', 'repeater' => 'get_rows',
    ];

    protected function setUp(): void {
        parent::setUp();
        $fields = [];
        foreach ( self::READERS as $type => $reader ) {
            $field = [ 'name' => $type, 'key' => 'fab_' . $type, 'type' => $type, 'label' => $type ];
            if ( $type === 'select' ) {
                $field['choices'] = [ [ 'value' => '0', 'label' => 'Zero' ] ];
            }
            if ( in_array( $type, [ 'group', 'repeater' ], true ) ) {
                $field['sub_fields'] = [
                    [ 'name' => 'price', 'key' => 'price', 'type' => 'number', 'label' => 'Price', 'default' => 7 ],
                    [ 'name' => 'title', 'key' => 'title', 'type' => 'text', 'label' => 'Title' ],
                ];
            }
            if ( $type === 'text' ) {
                $field['default'] = 'Initial text';
            }
            $fields[] = $field;
        }
        wp_mkdir_p( SchemaRepository::dir() );
        $this->schema = SchemaRepository::dir() . '/options.qa-nullable.json';
        file_put_contents( $this->schema, json_encode( [
            'schemaVersion' => 1, 'type' => 'options', 'slug' => 'qa-nullable', 'revision' => 1, 'fields' => $fields,
        ] ) );
        SchemaRepository::flush();
    }

    protected function tearDown(): void {
        unlink( $this->schema );
        SchemaRepository::flush();
        parent::tearDown();
    }

    public function test_choice_labels_keep_modern_null_and_legacy_empty_contracts(): void {
        $fields = $this->fields();
        self::assertNull( $fields->label( 'select' ) );
        self::assertSame( '', fabrement_get_option_label( 'select', 'qa-nullable' ) );
        $fields->set( 'select', '0' );
        self::assertSame( 'Zero', $fields->label( 'select' ), 'Modern labels use the staged value.' );
        self::assertSame( [ [ 'value' => '0', 'label' => 'Zero' ] ], $fields->choices( 'select' ) );
        self::assertSame( '', fabrement_get_option_label( 'select', 'qa-nullable' ), 'Legacy reads still use storage.' );
        WordPressState::$options['fabrement_options_qa-nullable'] = [ 'fab_select' => '0' ];
        $fields = $this->fields();
        self::assertSame( 'Zero', fabrement_get_option_label( 'fab_select', 'qa-nullable' ) );
        self::assertNull( $fields->label( 'select', value: 'removed' ) );
        self::assertSame( '', fabrement_get_option_label( 'select', 'qa-nullable', 'removed' ) );
        $fields->clear( 'select' );
        self::assertNull( $fields->label( 'select' ) );
        self::assertSame( 'Zero', fabrement_get_option_label( 'select', 'qa-nullable' ) );
    }

    private function fields(): Fields {
        return Fabrement::instance()->options( 'qa-nullable' );
    }

    public function test_released_helpers_keep_old_empty_shapes_beside_nullable_api(): void {
        $ref = 'options:qa-nullable';
        self::assertSame( 'Initial text', fabrement_get_field( 'text', $ref ) );
        self::assertSame( 'Initial text', $this->fields()->get_text( 'text' ) );
        self::assertSame( [ 'price' => 7, 'title' => '' ], fabrement_get_option( 'group', 'qa-nullable' ) );
        WordPressState::$options['fabrement_options_qa-nullable'] = [
            'fab_text' => '', 'fab_true_false' => '', 'fab_gallery' => null,
            'fab_relationship' => null, 'fab_repeater' => null,
            'fab_group' => '1', 'fab_group_price' => '', 'fab_group_title' => '',
        ];
        $legacy = [
            'text' => '', 'true_false' => false, 'gallery' => [], 'relationship' => [],
            'repeater' => [], 'group' => [ 'price' => null, 'title' => '' ],
        ];
        $old_fields = fabrement_get_fields( $ref );
        $old_options = fabrement_get_options( 'qa-nullable' );
        $fields = $this->fields();
        foreach ( $legacy as $name => $expected ) {
            self::assertSame( $expected, fabrement_get_field( $name, $ref ), $name . ' old field' );
            self::assertSame( $expected, fabrement_get_option( $name, 'qa-nullable' ), $name . ' old option' );
            self::assertSame( $expected, $old_fields[ $name ], $name . ' old fields map' );
            self::assertSame( $expected, $old_options[ $name ], $name . ' old options map' );
            self::assertNull( $fields->get( $name ), $name . ' new raw API' );
        }
    }

    /** What an empty field's typed reader gives: [] for a list, an empty object for an object, else null. */
    private static function assert_empty_typed( $value, string $name, string $message ): void {
        if ( in_array( $name, [ 'gallery', 'relationship', 'repeater' ], true ) ) {
            self::assertSame( [], $value, $message );
        } elseif ( in_array( $name, [ 'image', 'file', 'link', 'post_object' ], true ) ) {
            self::assertIsObject( $value, $message );
            self::assertFalse( $value->not_empty(), $message );
        } else {
            self::assertNull( $value, $message );
        }
    }

    public function test_missing_fields_use_defaults_and_otherwise_return_null_or_typed_empty_lists(): void {
        $fields = $this->fields();
        foreach ( self::READERS as $name => $reader ) {
            if ( in_array( $name, [ 'text', 'group' ], true ) ) {
                continue;
            }
            self::assertNull( $fields->get( $name ), $name . ' raw' );
            self::assert_empty_typed( $fields->$reader( $name ), $name, $name . ' typed' );
            self::assertNull( $fields->field( $name )['value'], $name . ' descriptor' );
            self::assertFalse( $fields->not_empty( $name ), $name . ' presence' );
        }
        self::assertSame( 'Initial text', $fields->get_text( 'text' ) );
        self::assertSame( 7.0, $fields->get_group( 'group' )->get_number( 'price' ), 'a decimal field reads as a float' );
        self::assertNull( $fields->get_text( 'missing' ) );
        self::assertNull( $fields->get_group( 'missing' )->get_text( 'title' ), 'an unknown group: an empty one' );
    }

    public function test_null_set_and_clear_are_identical_and_do_not_restore_defaults(): void {
        foreach ( self::READERS as $name => $reader ) {
            $fields = $this->fields();
            $fields->set( $name, null );
            self::assertNull( $fields->get( $name ), $name . ' set null' );
            if ( $name !== 'group' ) {
                self::assert_empty_typed( $fields->$reader( $name ), $name, $name . ' typed set null' );
            }
            self::assertTrue( $fields->field( $name )['exists'], $name . ' staged explicit empty' );
            $fields->clear( $name );
            self::assertNull( $fields->get( $name ), $name . ' clear' );
        }
    }

    public function test_zero_false_and_string_zero_remain_values(): void {
        $fields = $this->fields();
        $fields->set_number( 'number', 0 )->set_true_false( 'true_false', false )->set_text( 'text', '0' )->set_select( 'select', '0' );
        self::assertSame( 0.0, $fields->get_number( 'number' ) );
        self::assertFalse( $fields->get_true_false( 'true_false' ) );
        self::assertSame( '0', $fields->get_text( 'text' ) );
        self::assertSame( '0', $fields->get_select( 'select' ) );
        self::assertSame( 'Zero', $fields->get_select( 'select', label: true ) );
        self::assertTrue( $fields->not_empty( 'number' ) );
    }

    public function test_saved_clears_remain_empty_when_reopened(): void {
        WordPressState::$options['fabrement_options_qa-nullable'] = [];
        // OptionStore's transaction boundary, backed only by the in-memory WP fixture.
        $GLOBALS['wpdb'] = new class {
            public string $options = 'wp_test_options';
            private string $option = '';
            public function prepare( string $query, string $option ): string {
                $this->option = $option;
                return $query;
            }
            public function query( string $query ): bool {
                if ( ! in_array( $query, [ 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ], true ) ) {
                    throw new \RuntimeException( 'Unexpected field write: ' . $query );
                }
                return true;
            }
            public function get_var( string $query ) {
                if ( strpos( $query, 'SELECT option_value FROM ' ) !== 0 ) {
                    throw new \RuntimeException( 'Unexpected field read: ' . $query );
                }
                return isset( WordPressState::$options[ $this->option ] ) ? serialize( WordPressState::$options[ $this->option ] ) : null;
            }
        };
        $fields = $this->fields();
        foreach ( self::READERS as $name => $reader ) {
            $fields->clear( $name );
        }
        self::assertTrue( $fields->save() );
        $reopened = $this->fields();
        foreach ( self::READERS as $name => $reader ) {
            self::assertNull( $reopened->get( $name ), $name . ' saved clear' );
            self::assertTrue( $reopened->field( $name )['exists'], $name . ' explicit empty remains stored' );
        }
        self::assertSame( [], $reopened->get_rows( 'repeater' ) );
        self::assertSame( [], $reopened->get_gallery( 'gallery' ) );
        self::assertSame( [], $reopened->get_relationship( 'relationship' ) );
    }

    public function test_neighbor_edits_preserve_cleared_defaults_in_groups_and_rows(): void {
        $row = 'row_' . str_repeat( 'a', 32 );
        WordPressState::$options['fabrement_options_qa-nullable'] = [
            'fab_group' => '1', 'fab_group_price' => '', 'fab_group_title' => 'Before',
            'fab_repeater' => '1', 'fab_repeater_0__rid' => $row,
            'fab_repeater_0_price' => '', 'fab_repeater_0_title' => 'Before',
        ];
        $fields = $this->fields();
        $fields->get_group( 'group' )->set_text( 'title', 'After' );
        $fields->get_rows( 'repeater' )[0]->set_text( 'title', 'After' );
        self::assertNull( $fields->get_number( 'group.price' ) );
        self::assertNull( $fields->get_number( 'repeater', $row, 'price' ) );
        $fields->clear( 'group.price' )->clear( 'repeater.' . $row . '.price' );
        self::assertNull( $fields->get_number( 'group.price' ) );
        self::assertNull( $fields->get_rows( 'repeater' )[0]->get_number( 'price' ) );
    }

    public function test_a_sub_fields_exists_tells_its_own_stored_value_from_a_default(): void {
        $row = 'row_' . str_repeat( 'a', 32 );
        WordPressState::$options['fabrement_options_qa-nullable'] = [
            'fab_group' => '1', 'fab_group_title' => 'Kept',
            'fab_repeater' => '1', 'fab_repeater_0__rid' => $row, 'fab_repeater_0_price' => null,
        ];
        $fields = $this->fields();
        $group = $fields->get_group( 'group' );
        self::assertTrue( $group->field( 'title' )['exists'] );
        self::assertFalse( $group->field( 'price' )['exists'], 'never written in an existing group: its default shows' );
        self::assertSame( 7, $group->field( 'price' )['value'] );
        $first = $fields->get_rows( 'repeater' )[0];
        self::assertTrue( $first->field( 'price' )['exists'], 'written empty in the row' );
        self::assertNull( $first->field( 'price' )['value'] );
        self::assertFalse( $first->field( 'title' )['exists'] );
        self::assertTrue( $fields->field( 'group' )['exists'] );
    }

    public function test_empty_containers_can_be_edited_through_root_paths(): void {
        $fields = $this->fields();
        $fields->clear( 'group' );
        self::assertFalse( $fields->not_empty( 'group' ) );
        $fields->set_text( 'group.title', 'Created' );
        self::assertSame( 'Created', $fields->get_group( 'group' )->get_text( 'title' ) );
        $fields->clear( 'group' );
        self::assertNull( $fields->get_group( 'group' )->get_text( 'title' ) );
        $row = $fields->add_row( 'repeater', [ 'title' => 'New row' ] );
        self::assertSame( 7.0, $fields->get_rows( 'repeater' )[0]->get_number( 'price' ) );
        $fields->update_row( 'repeater', $row, [ 'price' => null ] );
        self::assertNull( $fields->get_rows( 'repeater' )[0]->get_number( 'price' ) );
        $fields->delete_row( 'repeater', $row );
        self::assertSame( [], $fields->get_rows( 'repeater' ) );
        $fields->delete_item( 'gallery', 5 )->delete_item( 'relationship', 5 );
        self::assertSame( [], $fields->get_gallery( 'gallery' ) );
        self::assertSame( [], $fields->get_relationship( 'relationship' ) );
    }
}
