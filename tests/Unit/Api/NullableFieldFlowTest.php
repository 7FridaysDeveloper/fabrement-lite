<?php

namespace Fabrement\Tests\Api;

use Fabrement\Api\Fabrement;
use Fabrement\Api\FieldsException;
use Fabrement\Route\Fields\PostFieldsRestRoute;
use Fabrement\Modules\Fields\Write\FieldWrite;
use Fabrement\Container\PluginContainer;
use Fabrement\Modules\Fields\Write\WriteRules;
use Fabrement\Modules\Fields\Write\WriteDecision;
use Fabrement\Modules\Fields\Write\WriteMode;
use Fabrement\Route\Fields\OptionsFieldsRestRoute;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\FieldRead;
use Fabrement\Modules\Mcp\Ability\GetFieldValuesAbility;
use Fabrement\Modules\Mcp\Ability\SetFieldValuesAbility;
use Fabrement\Modules\Translation\Contract\TranslationBridge;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Tests\WordPressState;

require_once __DIR__ . '/ApiTestCase.php';

/** MCP, the editor and PHP agree after writing and reopening the same in-memory options. */
final class NullableFieldFlowTest extends ApiTestCase {
    private string $schema;
    private $previous_database;

    /** @dataProvider container_entries */
    public function test_container_rules_refuse_routing_before_any_write_but_site_php_keeps_standard_rules( string $entry ): void {
        // Creating the container must not select/freeze a translation bridge before it is ready.
        $container = PluginContainer::create();
        $this->translated_options();
        $schema = json_decode( file_get_contents( $this->schema ), true );
        $schema['fields'][1]['translation'] = 'copy';
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        $rules = new class extends WriteRules {
            public array $seen = [];
            public function original( FieldObject $target, FieldObject $original, array $paths, WriteMode $mode ): WriteDecision {
                $this->seen[] = [ $target->lang, $original->lang, $paths ];
                return WriteDecision::refuse( 'Edit shared values on the original.' );
            }
        };
        $container[ WriteRules::class ] = static fn() => $rules;
        $before = WordPressState::$options;
        $result = $entry === 'mcp'
            ? $container[ SetFieldValuesAbility::class ]->execute( [ 'options' => 'qa-null-flow', 'language' => 'uk', 'values' => [ 'amount' => 9, 'title' => 'Must not save' ] ] )
            : $container[ $entry ]->save_values( FieldObject::options( 'qa-null-flow', 'uk' ), [ 'fab_amount' => 9, 'fab_title' => 'Must not save' ] , [], array_fill_keys( array_keys( [ 'fab_amount' => 9, 'fab_title' => 'Must not save' ] ), true ) );
        self::assertSame( 'fields_routing_refused', $result->get_error_code() );
        self::assertSame( [ [ 'uk', 'en', [ 'amount' ] ] ], $rules->seen );
        self::assertSame( $before, WordPressState::$options, 'No original write or translated version created on refusal.' );

        self::assertTrue( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->set_number( 'amount', 9 )->save() );
        self::assertSame( '9', WordPressState::$options['fabrement_options_qa-null-flow']['fab_amount'] );
        self::assertSame( '9', WordPressState::$options['fabrement_options_qa-null-flow.uk']['fab_amount'] );
        self::assertCount( 1, $rules->seen, 'Site PHP is independent of the external writer binding.' );
    }

    public static function container_entries(): array {
        return [ 'MCP' => [ 'mcp' ], 'post REST' => [ PostFieldsRestRoute::class ], 'options REST' => [ OptionsFieldsRestRoute::class ] ];
    }

    public function test_rest_delete_stores_null_and_does_not_restore_the_default(): void {
        $object = FieldObject::options( 'qa-null-flow' );
        self::assertIsArray( ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, [], [ 'fab_amount' ], array_fill_keys( array_keys( [] ), true ) ) );
        self::assertArrayHasKey( 'fab_amount', WordPressState::$options['fabrement_options_qa-null-flow'] );
        self::assertNull( WordPressState::$options['fabrement_options_qa-null-flow']['fab_amount'] );
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow' )->get_number( 'amount' ) );
    }

    public function test_rest_intent_preserves_absent_siblings_and_persists_an_explicit_default(): void {
        $object = FieldObject::options( 'qa-null-flow' );
        $input = [ 'fab_group' => [ 'amount' => 7, 'text' => 'Changed' ] ];
        self::assertIsArray( ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, $input, [], [ 'fab_group' => [ 'text' => true ] ] ) );
        self::assertArrayNotHasKey( 'fab_group_amount', WordPressState::$options['fabrement_options_qa-null-flow'] );
        self::assertIsArray( ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, $input, [], [ 'fab_group' => [ 'amount' => true ] ] ) );
        self::assertSame( '7', WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_amount'] );
        $before = WordPressState::$options;
        $error = ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, $input, [], [ 'fab_group' => [ 'unknown' => true ] ] );
        self::assertSame( 'fields_invalid', $error->get_error_code() );
        self::assertSame( $before, WordPressState::$options );
    }

    /** @dataProvider shared_writers */
    public function test_every_writer_checks_required_fields_omitted_from_the_request( string $entry ): void {
        $schema = json_decode( file_get_contents( $this->schema ), true );
        $schema['fields'][0]['required'] = true;
        unset( $schema['fields'][0]['default'] );
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        $before = WordPressState::$options;
        if ( $entry === 'php' ) {
            $fields = Fabrement::instance()->options( 'qa-null-flow' );
            try {
                $fields->save();
                self::fail( 'A no-op save still validates required values.' );
            } catch ( FieldsException $e ) {
                self::assertStringContainsString( 'title', $e->getMessage() );
            }
        } else {
            $error = $entry === 'mcp'
                ? ( new SetFieldValuesAbility( FieldWrite::standard() ) )->execute( [ 'options' => 'qa-null-flow', 'values' => [ 'amount' => 0 ] ] )
                : ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( FieldObject::options( 'qa-null-flow' ), [ 'fab_amount' => 0 ], [], array_fill_keys( array_keys( [ 'fab_amount' => 0 ] ), true ) );
            self::assertSame( 'fields_invalid', $error->get_error_code() );
        }
        self::assertSame( $before, WordPressState::$options );
    }

    /** @dataProvider shared_writers */
    public function test_a_stored_value_broken_by_a_narrower_rule_is_named_to_each_audience( string $entry ): void {
        $schema = json_decode( file_get_contents( $this->schema ), true );
        $schema['fields'][1] = array_replace( $schema['fields'][1], [ 'required' => true, 'min' => 0, 'max' => 100 ] );
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        WordPressState::$options['fabrement_options_qa-null-flow'] = [ 'fab_amount' => '150' ];
        $before = WordPressState::$options;
        try {
            $result = $this->write_choices( $entry, [ 'title' => 'Changed' ] );
            self::assertNotSame( 'php', $entry );
            $message = $result->get_error_message();
            self::assertSame( 'fields_invalid', $result->get_error_code() );
        } catch ( FieldsException $e ) {
            self::assertSame( 'php', $entry );
            $message = $e->getMessage();
        }
        $expected = [
            'php'  => 'amount: amount: the value stored earlier no longer passes the field\'s rules: The number is outside the allowed range (0–100).',
            'mcp'  => 'amount: amount: the value stored earlier no longer passes the field\'s rules: The number is outside the allowed range (0–100). Send a new value for amount. Nothing was saved.',
            'rest' => 'amount: the number must be between 0 and 100. This is the value saved earlier. Change amount to save.',
        ];
        self::assertSame( $expected[ $entry ], $message );
        self::assertSame( $before, WordPressState::$options );
    }

    /** @dataProvider shared_writers */
    public function test_an_empty_required_child_names_its_row_as_a_person_counts_it( string $entry ): void {
        $schema = json_decode( file_get_contents( $this->schema ), true );
        $schema['fields'][6]['sub_fields'][0]['required'] = true;
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        $a = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        WordPressState::$options['fabrement_options_qa-null-flow'] = [ 'fab_rows' => '1', 'fab_rows_0__rid' => $a, 'fab_rows_0_text' => null ];
        $before = WordPressState::$options;
        try {
            $result = $this->write_choices( $entry, [ 'title' => 'Changed' ] );
            self::assertNotSame( 'php', $entry );
            $message = $result->get_error_message();
        } catch ( FieldsException $e ) {
            self::assertSame( 'php', $entry );
            $message = $e->getMessage();
        }
        $expected = [
            'php'  => 'rows[0].text: Text: This field is required.',
            'mcp'  => 'rows[0].text: Text: This field is required. Send a value for rows[0].text. Nothing was saved.',
            'rest' => 'rows, row 1 › Text is required. Fill it in to save.',
        ];
        self::assertSame( $expected[ $entry ], $message );
        self::assertSame( $before, WordPressState::$options );
    }

    /** @dataProvider shared_writers */
    public function test_every_empty_required_field_is_named_in_one_refusal( string $entry ): void {
        $schema = json_decode( file_get_contents( $this->schema ), true );
        $schema['fields'][0]['required'] = true;
        unset( $schema['fields'][0]['default'] );
        $schema['fields'][6]['sub_fields'][0]['required'] = true;
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        WordPressState::$options['fabrement_options_qa-null-flow'] = [ 'fab_rows' => '1', 'fab_rows_0__rid' => 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'fab_rows_0_text' => null ];
        $before = WordPressState::$options;
        try {
            $result = $this->write_choices( $entry, [ 'amount' => 3 ] );
            self::assertNotSame( 'php', $entry );
            $message = $result->get_error_message();
        } catch ( FieldsException $e ) {
            self::assertSame( 'php', $entry );
            $message = $e->getMessage();
        }
        $expected = [
            'php'  => 'Several fields need a value or a fix: title: title: This field is required. rows[0].text: Text: This field is required.',
            'mcp'  => 'Several fields need a value or a fix: title: title: This field is required. rows[0].text: Text: This field is required. Send a value for title. Send a value for rows[0].text. Nothing was saved.',
            'rest' => "Several fields need attention before saving:\ntitle is required. Fill it in to save.\nrows, row 1 › Text is required. Fill it in to save.",
        ];
        self::assertSame( $expected[ $entry ], $message );
        self::assertSame( $before, WordPressState::$options );
    }

    public static function shared_writers(): array {
        return [ 'php' => [ 'php' ], 'mcp' => [ 'mcp' ], 'rest' => [ 'rest' ] ];
    }

    private function choices( bool $required = false, string $policy = 'copy' ): void {
        $schema = json_decode( file_get_contents( $this->schema ), true );
        $city = [ 'name' => 'city', 'key' => 'city', 'label' => 'City', 'type' => 'select', 'required' => $required,
            'translation' => $policy, 'choices' => [ [ 'value' => 'kyiv', 'label' => 'Kyiv' ] ] ];
        $schema['fields'][] = array_replace( $city, [ 'key' => 'fab_city' ] );
        foreach ( [ 5, 6 ] as $index ) {
            $schema['fields'][ $index ]['sub_fields'][] = $city;
        }
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
    }

    private function write_choices( string $entry, array $values, string $lang = '' ) {
        if ( $entry === 'php' ) {
            $fields = Fabrement::instance()->options( 'qa-null-flow', lang: $lang );
            foreach ( $values as $name => $value ) {
                $fields->set( $name, $value );
            }
            return $fields->save();
        }
        if ( $entry === 'mcp' ) {
            return ( new SetFieldValuesAbility( FieldWrite::standard() ) )->execute( [ 'options' => 'qa-null-flow', 'language' => $lang, 'values' => $values ] );
        }
        $editor = $intent = [];
        foreach ( $values as $name => $value ) {
            $key = 'fab_' . $name;
            $editor[ $key ] = $value;
            $intent[ $key ] = $name === 'group' ? array_fill_keys( array_keys( $value ), true ) : true;
            if ( $name === 'rows' ) {
                $editor[ $key ] = $intent[ $key ] = [];
                foreach ( $value as $row ) {
                    $id = $row['_row'];
                    unset( $row['_row'] );
                    $editor[ $key ][] = [ 'id' => $id, 'values' => $row ];
                    $intent[ $key ][ $id ] = array_fill_keys( array_keys( $row ), true );
                }
            }
        }
        return ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( FieldObject::options( 'qa-null-flow', $lang ), $editor, [], $intent );
    }

    public function test_removed_choices_read_unselected_without_mutating_storage_or_legacy_reads(): void {
        $this->choices();
        $raw = [ 'fab_city' => 'lviv', 'fab_group' => '1', 'fab_group_city' => 'lviv',
            'fab_rows' => '1', 'fab_rows_0__rid' => 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'fab_rows_0_city' => 'lviv' ];
        WordPressState::$options['fabrement_options_qa-null-flow'] = $raw;
        $fields = Fabrement::instance()->options( 'qa-null-flow' );
        self::assertNull( $fields->get_select( 'city' ) );
        self::assertNull( $fields->get_select( 'group.city' ) );
        self::assertNull( $fields->get_select( 'city', label: true ) );
        self::assertFalse( $fields->not_empty( 'city' ) );
        $mcp = ( new GetFieldValuesAbility() )->execute( [ 'options' => 'qa-null-flow' ] );
        self::assertNull( $mcp['values']['city'] );
        self::assertTrue( $mcp['stored']['city'] );
        self::assertNull( $mcp['values']['rows'][0]['city'] );
        $editor = FieldRead::for_editor( FieldObject::options( 'qa-null-flow' ) );
        self::assertSame( '', $editor['values']['fab_city'] );
        self::assertSame( '', $editor['values']['fab_group']['city'] );
        self::assertSame( '', $editor['values']['fab_rows'][0]['values']['city'] );
        self::assertSame( 'lviv', fabrement_get_field( 'city', 'options:qa-null-flow' ) );
        self::assertSame( $raw, WordPressState::$options['fabrement_options_qa-null-flow'] );
        $schema = json_decode( file_get_contents( $this->schema ), true );
        $schema['fields'][7]['choices'][] = [ 'value' => 'lviv', 'label' => 'Lviv' ];
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        self::assertSame( 'lviv', Fabrement::instance()->options( 'qa-null-flow' )->get_select( 'city' ) );
    }

    /** @dataProvider shared_writers */
    public function test_optional_obsolete_choices_clear_only_submitted_paths_by_row_identity( string $entry ): void {
        $this->choices();
        $a = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $b = 'row_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        WordPressState::$options['fabrement_options_qa-null-flow'] = [ 'fab_city' => 'lviv', 'fab_group' => '1', 'fab_group_city' => 'lviv',
            'fab_rows' => '2', 'fab_rows_0__rid' => $a, 'fab_rows_0_city' => 'lviv', 'fab_rows_1__rid' => $b, 'fab_rows_1_city' => 'odesa' ];
        $result = $this->write_choices( $entry, [ 'group' => [ 'text' => 'Changed' ] ] );
        self::assertFalse( is_wp_error( $result ) );
        self::assertSame( 'lviv', WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_city'] );
        $result = $this->write_choices( $entry, [ 'city' => 'lviv', 'group' => [ 'city' => 'lviv' ],
            'rows' => [ [ '_row' => $b ], [ '_row' => $a, 'city' => 'lviv' ] ] ] );
        self::assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
        $raw = WordPressState::$options['fabrement_options_qa-null-flow'];
        foreach ( [ 'fab_city', 'fab_group_city', 'fab_rows_1_city' ] as $key ) {
            self::assertArrayHasKey( $key, $raw );
            self::assertNull( $raw[ $key ] );
        }
        self::assertSame( $b, $raw['fab_rows_0__rid'] );
        self::assertSame( $a, $raw['fab_rows_1__rid'] );
        self::assertSame( 'odesa', $raw['fab_rows_0_city'] );
        self::assertSame( 'Changed', $raw['fab_group_text'] );
        self::assertArrayNotHasKey( 'fab_group_amount', $raw );
    }

    /** @dataProvider shared_writers */
    public function test_a_new_invalid_choice_is_never_silently_cleared( string $entry ): void {
        $this->choices();
        WordPressState::$options['fabrement_options_qa-null-flow'] = [ 'fab_city' => 'lviv' ];
        $before = WordPressState::$options;
        try {
            $result = $this->write_choices( $entry, [ 'city' => 'odesa' ] );
            self::assertTrue( is_wp_error( $result ) );
            self::assertSame( 'fields_invalid', $result->get_error_code() );
        } catch ( FieldsException $e ) {
            self::assertSame( 'php', $entry );
            self::assertStringContainsString( 'Select one of the choices', $e->getMessage() );
        }
        self::assertSame( $before, WordPressState::$options );
    }

    /** @dataProvider shared_writers */
    public function test_required_removed_choices_need_valid_replacements_at_every_depth( string $entry ): void {
        $this->choices( true );
        $a = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        WordPressState::$options['fabrement_options_qa-null-flow'] = [ 'fab_city' => 'lviv', 'fab_group' => '1', 'fab_group_city' => 'lviv',
            'fab_rows' => '1', 'fab_rows_0__rid' => $a, 'fab_rows_0_city' => 'lviv' ];
        $before = WordPressState::$options;
        try {
            $result = $this->write_choices( $entry, [ 'city' => 'lviv', 'group' => [ 'city' => 'lviv' ], 'rows' => [ [ '_row' => $a, 'city' => 'lviv' ] ] ] );
            self::assertTrue( is_wp_error( $result ) );
        } catch ( FieldsException $e ) {
            self::assertSame( 'php', $entry );
        }
        self::assertSame( $before, WordPressState::$options );
        $result = $this->write_choices( $entry, [ 'city' => 'kyiv', 'group' => [ 'city' => 'kyiv' ], 'rows' => [ [ '_row' => $a, 'city' => 'kyiv' ] ] ] );
        self::assertFalse( is_wp_error( $result ) );
        foreach ( [ 'fab_city', 'fab_group_city', 'fab_rows_0_city' ] as $key ) {
            self::assertSame( 'kyiv', WordPressState::$options['fabrement_options_qa-null-flow'][ $key ] );
        }
    }

    /** @dataProvider shared_writers */
    public function test_obsolete_copy_cleanup_routes_to_original_but_preserves_a_newer_choice( string $entry ): void {
        $this->translated_options();
        $this->choices();
        foreach ( [ 'lviv' => null, 'kyiv' => 'kyiv', 'odesa' => 'odesa' ] as $source => $expected ) {
            WordPressState::$options['fabrement_options_qa-null-flow']['fab_city'] = $source;
            WordPressState::$options['fabrement_options_qa-null-flow.uk'] = [ 'fab_city' => 'lviv' ];
            $result = $this->write_choices( $entry, [ 'city' => 'lviv' ], 'uk' );
            self::assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
            self::assertSame( $expected, WordPressState::$options['fabrement_options_qa-null-flow']['fab_city'] );
        }
    }

    /** @dataProvider shared_writers */
    public function test_copy_once_cleanup_keeps_other_options_languages_and_omitted_children( string $entry ): void {
        $this->translated_options();
        $this->choices( false, 'copy_once' );
        $a = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $raw = [ 'fab_city' => 'lviv', 'fab_group' => '1', 'fab_group_city' => 'lviv',
            'fab_rows' => '1', 'fab_rows_0__rid' => $a, 'fab_rows_0_city' => 'lviv' ];
        WordPressState::$options['fabrement_options_qa-null-flow'] = $raw;
        WordPressState::$options['fabrement_options_qa-null-flow.uk'] = $raw;
        $result = $this->write_choices( $entry, [ 'city' => 'lviv', 'group' => [ 'text' => 'Local' ],
            'rows' => [ [ '_row' => $a, 'city' => 'lviv' ] ] ], 'uk' );
        self::assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
        self::assertSame( $raw, WordPressState::$options['fabrement_options_qa-null-flow'] );
        $local = WordPressState::$options['fabrement_options_qa-null-flow.uk'];
        self::assertNull( $local['fab_city'] );
        self::assertNull( $local['fab_rows_0_city'] );
        self::assertSame( 'lviv', $local['fab_group_city'] );
        self::assertSame( 'Local', $local['fab_group_text'] );
    }

    /** @dataProvider shared_writers */
    public function test_all_entries_route_options_copy_and_keep_own_words( string $entry ): void {
        $this->translated_options();
        $schema = json_decode( file_get_contents( $this->schema ), true );
        $schema['fields'][1]['translation'] = 'copy';
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_amount'] = '7';
        if ( $entry === 'php' ) {
            WordPressState::select_user( 0, [] );
            self::assertTrue( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->set( 'amount', 9 )->set( 'title', 'Local' )->save() );
        } elseif ( $entry === 'mcp' ) {
            $result = ( new SetFieldValuesAbility( FieldWrite::standard() ) )->execute( [ 'options' => 'qa-null-flow', 'language' => 'uk', 'values' => [ 'amount' => 9, 'title' => 'Local' ] ] );
            self::assertSame( 'en', $result['routed'][0]['language'] );
        } else {
            self::assertIsArray( ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( FieldObject::options( 'qa-null-flow', 'uk' ), [ 'fab_amount' => 9, 'fab_title' => 'Local' ], [], array_fill_keys( array_keys( [ 'fab_amount' => 9, 'fab_title' => 'Local' ] ), true ) ) );
        }
        self::assertSame( '9', WordPressState::$options['fabrement_options_qa-null-flow']['fab_amount'] );
        self::assertSame( '', WordPressState::$options['fabrement_options_qa-null-flow']['fab_title'] );
        self::assertSame( 'Local', WordPressState::$options['fabrement_options_qa-null-flow.uk']['fab_title'] );
    }

    /** @dataProvider required_paths */
    public function test_removed_required_choices_are_checked_in_existing_groups_and_rows( string $entry, string $position ): void {
        $schema = json_decode( file_get_contents( $this->schema ), true );
        foreach ( [ 5, 6 ] as $index ) {
            $schema['fields'][ $index ]['sub_fields'][] = [ 'name' => 'city', 'key' => 'city', 'label' => 'City', 'type' => 'select',
                'required' => true, 'choices' => [ [ 'value' => 'kyiv', 'label' => 'Kyiv' ] ] ];
        }
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        WordPressState::$options['fabrement_options_qa-null-flow'] = [ 'fab_group' => '1', 'fab_group_city' => $position === 'group' ? 'lviv' : 'kyiv',
            'fab_rows' => '1', 'fab_rows_0__rid' => 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'fab_rows_0_city' => $position === 'row' ? 'lviv' : 'kyiv' ];
        $before = WordPressState::$options;
        $object = FieldObject::options( 'qa-null-flow' );
        if ( $entry === 'php' ) {
            $fields = Fabrement::instance()->options( 'qa-null-flow' )->set( 'title', 'Must not save' );
            try {
                $fields->save();
                self::fail( 'Removed required choices must be replaced.' );
            } catch ( FieldsException $e ) {
                self::assertStringContainsString( 'city', $e->getMessage() );
            }
            self::assertSame( 'Must not save', $fields->get_text( 'title' ), 'A failed save retains its staged request.' );
        } else {
            $error = $entry === 'mcp' ? ( new SetFieldValuesAbility( FieldWrite::standard() ) )->execute( [ 'options' => 'qa-null-flow', 'values' => [ 'title' => 'Must not save' ] ] )
                : ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, [ 'fab_title' => 'Must not save' ], [], array_fill_keys( array_keys( [ 'fab_title' => 'Must not save' ] ), true ) );
            self::assertSame( 'fields_invalid', $error->get_error_code() );
        }
        self::assertSame( $before, WordPressState::$options );
    }

    public function test_a_touched_empty_text_in_rest_is_explicit_even_when_original_is_already_empty(): void {
        $this->translated_options();
        self::assertIsArray( ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( FieldObject::options( 'qa-null-flow', 'uk' ), [ 'fab_group' => [ 'amount' => 7, 'text' => '' ] ], [], [ 'fab_group' => [ 'text' => true ] ] ) );
        self::assertArrayHasKey( 'fab_group_text', WordPressState::$options['fabrement_options_qa-null-flow.uk'] );
        self::assertNull( WordPressState::$options['fabrement_options_qa-null-flow.uk']['fab_group_text'] );
        self::assertNull( WordPressState::$options['fabrement_options_qa-null-flow.uk']['fab_group_amount'], 'The sibling was seeded from the source, not assigned the submitted default.' );
    }

    public function test_malformed_rest_rows_and_intent_are_refused_without_writes(): void {
        $object = FieldObject::options( 'qa-null-flow' );
        $before = WordPressState::$options;
        foreach ( [ [], [ 'fab_rows' => [] ], [ 'fab_rows' => true ] ] as $intent ) {
            $error = ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, [ 'fab_rows' => [ [ 'id' => [ 'bad' ], 'values' => [] ] ] ], [], $intent );
            self::assertSame( 'fields_invalid', $error->get_error_code() );
            self::assertSame( $before, WordPressState::$options );
        }
    }

    public static function required_paths(): array {
        return [ [ 'php', 'group' ], [ 'mcp', 'group' ], [ 'rest', 'group' ], [ 'php', 'row' ], [ 'mcp', 'row' ], [ 'rest', 'row' ] ];
    }

    protected function setUp(): void {
        parent::setUp();
        WordPressState::select_user( 1, [ 'manage_options' ] );
        $this->previous_database = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $options = 'wp_test_options';
            private string $option = '';
            private array $args = [];
            public function prepare( string $sql, string ...$args ): string { $this->args = $args; $this->option = $args[0]; return $sql; }
            public function query( string $sql ) {
                if ( strpos( $sql, 'INSERT INTO wp_test_options ' ) === 0 ) {
                    if ( array_key_exists( $this->option, WordPressState::$options ) ) {
                        return 0;
                    }
                    WordPressState::$options[ $this->option ] = maybe_unserialize( $this->args[1] );
                    return 1;
                }
                if ( ! in_array( $sql, [ 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ], true ) ) {
                    throw new \RuntimeException( 'Unexpected SQL: ' . $sql );
                }
                return true;
            }
            public function get_var( string $sql ) {
                if ( strpos( $sql, 'SELECT option_value FROM ' ) !== 0 ) {
                    throw new \RuntimeException( 'Unexpected SQL: ' . $sql );
                }
                return array_key_exists( $this->option, WordPressState::$options ) ? serialize( WordPressState::$options[ $this->option ] ) : null;
            }
        };
        $field = static fn( string $name, string $type, array $extra = [] ): array => $extra + [
            'name' => $name, 'key' => 'fab_' . $name, 'type' => $type, 'label' => $name,
        ];
        $fields = [
            $field( 'title', 'text', [ 'default' => 'Default' ] ),
            $field( 'amount', 'number', [ 'default' => 7 ] ),
            $field( 'enabled', 'true_false', [ 'default' => true ] ),
            $field( 'gallery', 'gallery' ), $field( 'related', 'relationship' ),
            $field( 'group', 'group', [ 'sub_fields' => [
                [ 'name' => 'amount', 'key' => 'amount', 'type' => 'number', 'label' => 'Amount', 'default' => 7 ],
                [ 'name' => 'text', 'key' => 'text', 'type' => 'text', 'label' => 'Text' ],
            ] ] ),
            $field( 'rows', 'repeater', [ 'sub_fields' => [
                [ 'name' => 'text', 'key' => 'text', 'type' => 'text', 'label' => 'Text' ],
            ] ] ),
        ];
        wp_mkdir_p( SchemaRepository::dir() );
        $this->schema = SchemaRepository::dir() . '/options.qa-null-flow.json';
        file_put_contents( $this->schema, json_encode( [
            'schemaVersion' => 1, 'type' => 'options', 'slug' => 'qa-null-flow', 'revision' => 1,
            'fields' => $fields, 'layout' => [ 'tabs' => [] ],
        ] ) );
        SchemaRepository::flush();
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previous_database;
        unlink( $this->schema );
        SchemaRepository::flush();
        parent::tearDown();
    }

    public function test_mcp_clear_is_stored_and_survives_editor_neighbor_save(): void {
        $get = new GetFieldValuesAbility();
        $set = new SetFieldValuesAbility( FieldWrite::standard() );
        $target = [ 'options' => 'qa-null-flow' ];
        $before = $get->execute( $target );
        self::assertSame( 'Default', $before['values']['title'] );
        self::assertFalse( $before['stored']['title'] );
        $cleared = $set->execute( $target + [ 'values' => array_fill_keys( array_keys( $before['values'] ), null ) ] );
        self::assertIsArray( $cleared );
        foreach ( $cleared['values'] as $name => $value ) {
            self::assertNull( $value, $name );
            self::assertTrue( $cleared['stored'][ $name ], $name );
        }
        $modern = Fabrement::instance()->options( 'qa-null-flow' );
        self::assertSame( $cleared['values'], $modern->values(), 'Modern PHP and MCP share the nullable format.' );
        $legacy = fabrement_get_fields( 'options:qa-null-flow' );
        self::assertSame( '', $legacy['title'] );
        self::assertFalse( $legacy['enabled'] );
        self::assertSame( [], $legacy['rows'] );
        self::assertSame( [], $legacy['gallery'] );
        self::assertSame( [ 'amount' => null, 'text' => '' ], $legacy['group'] );
        self::assertNull( $modern->get( 'title' ) );
        self::assertNull( $modern->get( 'enabled' ) );
        self::assertNull( $modern->get( 'group' ) );
        $stored = WordPressState::$options['fabrement_options_qa-null-flow'];
        foreach ( [ 'fab_title', 'fab_amount', 'fab_enabled', 'fab_gallery', 'fab_related', 'fab_rows', 'fab_group_amount', 'fab_group_text' ] as $key ) {
            self::assertArrayHasKey( $key, $stored );
            self::assertNull( $stored[ $key ], 'every empty value is stored as null' );
        }
        $object = FieldObject::options( 'qa-null-flow' );
        $editor = FieldRead::for_editor( $object );
        self::assertNull( $editor['values']['fab_amount'] );
        self::assertNull( $editor['values']['fab_enabled'] );
        self::assertIsArray( ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( $object, [ 'fab_title' => 'Changed' ], [], array_fill_keys( array_keys( [ 'fab_title' => 'Changed' ] ), true ) ) );
        $after = $get->execute( $target );
        self::assertSame( 'Changed', $after['values']['title'] );
        self::assertNull( $after['values']['amount'] );
        self::assertNull( $after['values']['enabled'] );
        self::assertNull( $after['values']['group'] );
        $php = Fabrement::instance()->options( 'qa-null-flow' );
        self::assertSame( $after['values'], $php->values() );
        self::assertSame( [], $php->get_gallery( 'gallery' ) );
        self::assertSame( [], $php->get_relationship( 'related' ) );
        self::assertSame( [], $php->get_rows( 'rows' ) );
    }

    public function test_mcp_preserves_zero_false_and_nested_null_on_partial_edits(): void {
        $set = new SetFieldValuesAbility( FieldWrite::standard() );
        $target = [ 'options' => 'qa-null-flow' ];
        $first = $set->execute( $target + [ 'values' => [
            'amount' => 0, 'enabled' => false, 'group' => [ 'amount' => null, 'text' => 'Before' ],
        ] ] );
        self::assertIsArray( $first );
        self::assertSame( 0, $first['values']['amount'] );
        self::assertFalse( $first['values']['enabled'] );
        self::assertNull( $first['values']['group']['amount'] );
        $next = $set->execute( $target + [ 'values' => [ 'group' => [ 'text' => 'After' ] ] ] );
        self::assertSame( [ 'amount' => null, 'text' => 'After' ], $next['values']['group'] );
        self::assertSame( 0, $next['values']['amount'] );
        self::assertFalse( $next['values']['enabled'] );
    }

    private function translated_options(): void {
        $bridge = $this->createStub( TranslationBridge::class );
        $bridge->method( 'languages' )->willReturn( [ [ 'code' => 'en' ], [ 'code' => 'uk' ] ] );
        $bridge->method( 'default_language' )->willReturn( 'en' );
        $bridge->method( 'current_language' )->willReturn( 'uk' );
        Translation::set( $bridge );
        $schema = json_decode( file_get_contents( $this->schema ), true );
        $policies = static function ( array &$fields ) use ( &$policies ): void {
            foreach ( $fields as &$field ) {
                $field['translation'] = in_array( $field['type'], [ 'text', 'group' ], true ) ? 'translate' : 'copy_once';
                if ( isset( $field['sub_fields'] ) ) {
                    $policies( $field['sub_fields'] );
                }
            }
        };
        $policies( $schema['fields'] );
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        WordPressState::$options['fabrement_options_qa-null-flow'] = [
            'fab_title' => '', 'fab_group' => '1', 'fab_group_amount' => '', 'fab_group_text' => '',
        ];
    }

    public function test_php_clear_claims_inherited_empty_value_before_original_changes(): void {
        $this->translated_options();
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( 'title' );
        self::assertTrue( $fields->save() );
        self::assertArrayHasKey( 'fab_title', WordPressState::$options['fabrement_options_qa-null-flow.uk'] );
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_title'] = 'Later original';
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_text( 'title' ) );
    }

    public function test_mcp_nested_null_claims_inherited_empty_value_before_original_changes(): void {
        $this->translated_options();
        $set = new SetFieldValuesAbility( FieldWrite::standard() );
        $result = $set->execute( [ 'options' => 'qa-null-flow', 'language' => 'uk', 'values' => [ 'group' => [ 'text' => null ] ] ] );
        self::assertIsArray( $result );
        self::assertTrue( $result['stored']['group'] );
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_text'] = 'Later original';
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_text( 'group.text' ) );
    }

    public function test_first_write_materializes_seed_and_own_values_stop_following_original(): void {
        $this->translated_options();
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->set_text( 'title', '' );
        self::assertTrue( $fields->save(), 'The first write creates and seeds the version.' );
        $result = ( new SetFieldValuesAbility( FieldWrite::standard() ) )->execute( [ 'options' => 'qa-null-flow', 'language' => 'uk', 'values' => [ 'title' => '' ] ] );
        self::assertTrue( $result['stored']['title'] );
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_title'] = 'Later original';
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_text( 'title' ) );
    }

    public function test_php_clear_of_missing_copy_value_persists_empty_on_original(): void {
        $this->translated_options();
        $schema = json_decode( file_get_contents( $this->schema ), true );
        foreach ( $schema['fields'] as &$field ) {
            if ( $field['name'] === 'amount' ) {
                $field['translation'] = 'copy';
                unset( $field['default'] );
            }
        }
        unset( $field );
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( 'amount' );
        self::assertTrue( $fields->save() );
        self::assertArrayHasKey( 'fab_amount', WordPressState::$options['fabrement_options_qa-null-flow'] );
        foreach ( $schema['fields'] as &$field ) {
            if ( $field['name'] === 'amount' ) {
                $field['default'] = 25;
            }
        }
        unset( $field );
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow', lang: 'en' )->get_number( 'amount' ) );
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_number( 'amount' ) );
    }

    private function missing_copied_group_child(): void {
        $this->translated_options();
        $schema = json_decode( file_get_contents( $this->schema ), true );
        foreach ( $schema['fields'] as &$field ) {
            if ( $field['name'] === 'group' ) {
                $field['sub_fields'][0]['translation'] = 'copy';
                unset( $field['sub_fields'][0]['default'] );
            }
        }
        unset( $field );
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        unset( WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_amount'] );
    }

    public function test_translated_child_clear_does_not_claim_missing_copied_sibling_on_original(): void {
        $this->missing_copied_group_child();
        $before = WordPressState::$options['fabrement_options_qa-null-flow'];
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( 'group.text' );
        self::assertTrue( $fields->save() );
        self::assertSame( $before, WordPressState::$options['fabrement_options_qa-null-flow'] );
        // A later unrelated edit omits the cleared path, which must remain the translation's.
        $fields->set_text( 'title', 'Neighbor' );
        self::assertTrue( $fields->save() );
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_text'] = 'Later original';
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_text( 'group.text' ) );
    }

    public function test_nested_copy_clear_claims_only_its_target_on_original(): void {
        $this->missing_copied_group_child();
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( 'group.amount' );
        self::assertTrue( $fields->save() );
        self::assertArrayHasKey( 'fab_group_amount', WordPressState::$options['fabrement_options_qa-null-flow'] );
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow', lang: 'en' )->get_number( 'group.amount' ) );
    }

    public function test_translated_row_clear_follows_row_id_after_original_reorder(): void {
        $this->translated_options();
        $schema = json_decode( file_get_contents( $this->schema ), true );
        foreach ( $schema['fields'] as &$field ) {
            if ( $field['name'] === 'rows' ) {
                $field['translation'] = 'translate';
                $field['sub_fields'][] = [ 'name' => 'amount', 'key' => 'amount', 'type' => 'number', 'label' => 'Amount', 'translation' => 'copy' ];
            }
        }
        unset( $field );
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        $a = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $b = 'row_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        // The original has reordered its rows; copied amounts remain absent in both.
        WordPressState::$options['fabrement_options_qa-null-flow'] += [
            'fab_rows' => '2', 'fab_rows_0__rid' => $b, 'fab_rows_0_text' => 'Other row',
            'fab_rows_1__rid' => $a, 'fab_rows_1_text' => '',
        ];
        $before = WordPressState::$options['fabrement_options_qa-null-flow'];
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->update_row( 'rows', $a, [ 'text' => null ] );
        self::assertTrue( $fields->save() );
        self::assertSame( $before, WordPressState::$options['fabrement_options_qa-null-flow'] );
        $fields->set_text( 'title', 'Neighbor' );
        self::assertTrue( $fields->save() );
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_rows_1_text'] = 'Later original';
        $rows = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_rows( 'rows' );
        self::assertSame( $b, $rows[0]->id() );
        self::assertSame( 'Other row', $rows[0]->get_text( 'text' ) );
        self::assertSame( $a, $rows[1]->id() );
        self::assertNull( $rows[1]->get_text( 'text' ) );
    }

    public function test_overwriting_queued_clear_with_inherited_value_drops_clear_intent(): void {
        $this->translated_options();
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_title'] = 'Original';
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( 'title' )->set_text( 'title', 'Original' );
        self::assertTrue( $fields->save(), 'First write creates the version even when equal to the seed.' );
        self::assertSame( 'Original', WordPressState::$options['fabrement_options_qa-null-flow.uk']['fab_title'] );
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_title'] = 'Later';
        self::assertSame( 'Original', Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_text( 'title' ) );
    }

    public function test_overwriting_one_child_after_parent_clear_keeps_sibling_clear_intent(): void {
        $this->missing_copied_group_child();
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_text'] = 'Original';
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( 'group' )->set_text( 'group.text', 'Original' );
        self::assertTrue( $fields->save() );
        self::assertArrayHasKey( 'fab_group_amount', WordPressState::$options['fabrement_options_qa-null-flow'] );
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow', lang: 'en' )->get_number( 'group.amount' ) );
        self::assertSame( 'Original', Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_text( 'group.text' ) );
    }

    /** @dataProvider editor_null_paths */
    public function test_editor_nested_null_is_stored_even_when_the_display_was_already_empty( string $path, string $storage_key, array $input, bool $canonical ): void {
        $this->translated_options();
        $row = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        WordPressState::$options['fabrement_options_qa-null-flow'] += [
            'fab_rows' => '1', 'fab_rows_0__rid' => $row, 'fab_rows_0_text' => '',
        ];
        if ( $canonical ) {
            WordPressState::$options['fabrement_options_qa-null-flow'][ $storage_key ] = null;
        }
        WordPressState::$options['fabrement_options_qa-null-flow.uk'] = [ 'fab_enabled' => true ];
        $original = WordPressState::$options['fabrement_options_qa-null-flow'];

        self::assertIsArray( ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( FieldObject::options( 'qa-null-flow', 'uk' ), $input, [], array_fill_keys( array_keys( $input ), true ) ) );
        self::assertSame( $original, WordPressState::$options['fabrement_options_qa-null-flow'] );
        $stored = WordPressState::$options['fabrement_options_qa-null-flow.uk'];
        self::assertArrayHasKey( $storage_key, $stored );
        self::assertNull( $stored[ $storage_key ] );
        if ( $path === 'rows.' . $row . '.text' ) {
            self::assertSame( $row, $stored['fab_rows_0__rid'] );
        }

        WordPressState::$options['fabrement_options_qa-null-flow'][ $storage_key ] = 'Later original';
        $read = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        self::assertNull( $read->get_text( $path ) );
    }

    public static function editor_null_paths(): array {
        $row = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $paths = [
            'top level' => [ 'title', 'fab_title', [ 'fab_title' => null ] ],
            'group child' => [ 'group.text', 'fab_group_text', [ 'fab_group' => [ 'amount' => null, 'text' => null ] ] ],
            'repeater child' => [ 'rows.' . $row . '.text', 'fab_rows_0_text', [ 'fab_rows' => [ [ 'id' => $row, 'values' => [ 'text' => null ] ] ] ] ],
        ];
        $cases = [];
        foreach ( $paths as $label => $args ) {
            $cases[ $label . ' / legacy empty' ] = array_merge( $args, [ false ] );
            $cases[ $label . ' / stored null' ] = array_merge( $args, [ true ] );
        }
        return $cases;
    }

    public function test_editor_unknown_null_child_is_rejected_without_writes(): void {
        $this->translated_options();
        $before = WordPressState::$options;
        $saved = ( new PostFieldsRestRoute( FieldWrite::standard() ) )->save_values( FieldObject::options( 'qa-null-flow', 'uk' ), [
            'fab_group' => [ 'unknown' => null ],
        ], [], array_fill_keys( array_keys( [
            'fab_group' => [ 'unknown' => null ],
        ] ), true ) );
        self::assertInstanceOf( \WP_Error::class, $saved );
        self::assertSame( 'fields_invalid', $saved->get_error_code() );
        self::assertSame( $before, WordPressState::$options );
    }

    /** @dataProvider partial_write_cases */
    public function test_partial_write_preserves_sibling_storage_presence( string $writer, string $container, array $sibling ): void {
        $row = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        if ( $container === 'rows' ) {
            $schema = json_decode( file_get_contents( $this->schema ), true );
            foreach ( $schema['fields'] as &$field ) {
                if ( $field['name'] === 'rows' ) {
                    $field['sub_fields'][] = [ 'name' => 'amount', 'key' => 'amount', 'type' => 'number', 'label' => 'Amount', 'default' => 7 ];
                }
            }
            unset( $field );
            file_put_contents( $this->schema, json_encode( $schema ) );
            SchemaRepository::flush();
        }
        $prefix = $container === 'group' ? 'fab_group' : 'fab_rows_0';
        $storage = [ 'fab_title' => 'Unrelated', $prefix . '_text' => 'Before' ];
        $storage += $container === 'group' ? [ 'fab_group' => '1' ] : [ 'fab_rows' => '1', 'fab_rows_0__rid' => $row ];
        if ( $sibling !== [] ) {
            $storage[ $prefix . '_amount' ] = $sibling[0] === null ? null : (string) $sibling[0];
        }
        WordPressState::$options['fabrement_options_qa-null-flow'] = $storage;

        if ( $writer === 'mcp' ) {
            $patch = $container === 'group' ? [ 'text' => 'After' ] : [ [ '_row' => $row, 'text' => 'After' ] ];
            self::assertIsArray( ( new SetFieldValuesAbility( FieldWrite::standard() ) )->execute( [
                'options' => 'qa-null-flow', 'values' => [ $container => $patch ],
            ] ) );
        } else {
            $fields = Fabrement::instance()->options( 'qa-null-flow' );
            if ( $container === 'group' ) {
                $fields->set_text( 'group.text', 'After' );
            } else {
                $fields->update_row( 'rows', $row, [ 'text' => 'After' ] );
            }
            self::assertTrue( $fields->save() );
        }

        $storage[ $prefix . '_text' ] = 'After';
        $actual = WordPressState::$options['fabrement_options_qa-null-flow'];
        ksort( $storage );
        ksort( $actual );
        self::assertSame( $storage, $actual, 'Only the submitted child may change; reading a default must not create storage.' );
        $path = $container === 'group' ? 'group.amount' : 'rows.' . $row . '.amount';
        self::assertSame( $sibling === [] ? 7.0 : ( $sibling[0] === null ? null : (float) $sibling[0] ), Fabrement::instance()->options( 'qa-null-flow' )->get_number( $path ) );
    }

    public static function partial_write_cases(): array {
        $cases = [];
        foreach ( [ 'mcp', 'php' ] as $writer ) {
            foreach ( [ 'group', 'rows' ] as $container ) {
                foreach ( [ 'absent' => [], 'null' => [ null ], 'zero' => [ 0 ], 'value' => [ 23 ] ] as $state => $stored ) {
                    $cases[ "$writer / $container / $state" ] = [ $writer, $container, $stored ];
                }
            }
        }
        return $cases;
    }

    /** @dataProvider fresh_siblings */
    public function test_php_partial_write_preserves_a_sibling_changed_after_staging( array $fresh ): void {
        WordPressState::$options['fabrement_options_qa-null-flow'] = [
            'fab_group' => '1', 'fab_group_text' => 'Before', 'fab_group_amount' => '7',
        ];
        $fields = Fabrement::instance()->options( 'qa-null-flow' );
        $fields->set_text( 'group.text', 'After' );
        unset( WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_amount'] );
        if ( $fresh !== [] ) {
            WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_amount'] = $fresh[0];
        }
        $expected = WordPressState::$options['fabrement_options_qa-null-flow'];
        $expected['fab_group_text'] = 'After';
        self::assertTrue( $fields->save() );
        $actual = WordPressState::$options['fabrement_options_qa-null-flow'];
        ksort( $expected );
        ksort( $actual );
        self::assertSame( $expected, $actual );
    }

    public static function fresh_siblings(): array {
        return [ 'removed' => [ [] ], 'null' => [ [ null ] ], 'zero' => [ [ '0' ] ], 'changed' => [ [ '23' ] ] ];
    }

    /** @dataProvider explicit_default_writers */
    public function test_explicit_default_value_creates_the_missing_child( string $writer ): void {
        WordPressState::$options['fabrement_options_qa-null-flow'] = [ 'fab_group' => '1', 'fab_group_text' => 'Kept' ];
        if ( $writer === 'mcp' ) {
            self::assertIsArray( ( new SetFieldValuesAbility( FieldWrite::standard() ) )->execute( [
                'options' => 'qa-null-flow', 'values' => [ 'group' => [ 'amount' => 7 ] ],
            ] ) );
        } else {
            self::assertTrue( Fabrement::instance()->options( 'qa-null-flow' )->set_number( 'group.amount', 7 )->save() );
        }
        $stored = WordPressState::$options['fabrement_options_qa-null-flow'];
        self::assertArrayHasKey( 'fab_group_amount', $stored );
        self::assertSame( '7', $stored['fab_group_amount'] );
        self::assertSame( 'Kept', $stored['fab_group_text'] );
    }

    public static function explicit_default_writers(): array {
        return [ 'mcp' => [ 'mcp' ], 'php' => [ 'php' ] ];
    }

    /** @dataProvider explicit_default_writers */
    public function test_explicit_default_is_owned_by_the_translation( string $writer ): void {
        $this->translated_options();
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_amount'] = '7';
        WordPressState::$options['fabrement_options_qa-null-flow.uk'] = [ 'fab_enabled' => true ];
        if ( $writer === 'mcp' ) {
            self::assertIsArray( ( new SetFieldValuesAbility( FieldWrite::standard() ) )->execute( [
                'options' => 'qa-null-flow', 'language' => 'uk', 'values' => [ 'group' => [ 'amount' => 7 ] ],
            ] ) );
        } else {
            self::assertTrue( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->set_number( 'group.amount', 7 )->save() );
        }
        $own = WordPressState::$options['fabrement_options_qa-null-flow.uk'];
        self::assertSame( '7', $own['fab_group_amount'] );
        self::assertArrayNotHasKey( 'fab_group_text', $own );
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_amount'] = '10';
        self::assertSame( 7.0, Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_number( 'group.amount' ) );
    }

    public function test_copy_write_does_not_store_translated_sibling_on_original(): void {
        $this->missing_copied_group_child();
        unset( WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_text'] );
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->set_group( 'group', [ 'amount' => 2, 'text' => 'Translated' ] );
        self::assertTrue( $fields->save() );
        $original = WordPressState::$options['fabrement_options_qa-null-flow'];
        self::assertSame( '2', $original['fab_group_amount'] );
        self::assertArrayNotHasKey( 'fab_group_text', $original );
        self::assertSame( 'Translated', WordPressState::$options['fabrement_options_qa-null-flow.uk']['fab_group_text'] );
    }

    public function test_explicit_copy_default_is_stored_on_the_original(): void {
        $this->missing_copied_group_child();
        $schema = json_decode( file_get_contents( $this->schema ), true );
        foreach ( $schema['fields'] as &$field ) {
            if ( $field['name'] === 'group' ) {
                $field['sub_fields'][0]['default'] = 7;
            }
        }
        unset( $field );
        file_put_contents( $this->schema, json_encode( $schema ) );
        SchemaRepository::flush();
        self::assertTrue( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->set_number( 'group.amount', 7 )->save() );
        self::assertSame( '7', WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_amount'] );
    }

    /** @dataProvider composed_text_paths */
    public function test_clear_then_set_keeps_the_last_value_before_and_after_save( string $path ): void {
        $row = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        WordPressState::$options['fabrement_options_qa-null-flow'] = [
            'fab_title' => 'Before', 'fab_group' => '1', 'fab_group_text' => 'Before', 'fab_group_amount' => null,
            'fab_rows' => '1', 'fab_rows_0__rid' => $row, 'fab_rows_0_text' => 'Before',
        ];
        $fields = Fabrement::instance()->options( 'qa-null-flow' );
        $fields->clear( $path )->set_text( $path, 'After' );
        self::assertSame( 'After', $fields->get_text( $path ) );
        self::assertTrue( $fields->save() );
        self::assertSame( 'After', Fabrement::instance()->options( 'qa-null-flow' )->get_text( $path ) );
    }

    /** @dataProvider composed_text_paths */
    public function test_superseded_clear_does_not_change_a_later_empty_assignment( string $path ): void {
        $this->translated_options();
        $row = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        WordPressState::$options['fabrement_options_qa-null-flow'] += [
            'fab_rows' => '1', 'fab_rows_0__rid' => $row, 'fab_rows_0_text' => '',
        ];
        WordPressState::$options['fabrement_options_qa-null-flow.uk'] = WordPressState::$options['fabrement_options_qa-null-flow'] + [ 'fab_enabled' => true ];
        $before = WordPressState::$options;
        $direct = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $direct->set_text( $path, '' )->save();
        $expected = WordPressState::$options;

        WordPressState::$options = $before;
        $composed = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $composed->clear( $path )->set_text( $path, 'Temporary' )->set_text( $path, '' );
        self::assertNull( $composed->get_text( $path ) );
        $composed->save();
        self::assertSame( $expected, WordPressState::$options, 'Superseded operations must not affect what the final assignment stores.' );
    }

    public static function composed_text_paths(): array {
        return [
            'top level' => [ 'title' ],
            'group child' => [ 'group.text' ],
            'repeater child' => [ 'rows.row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.text' ],
        ];
    }

    /** @dataProvider replacement_setters */
    public function test_parent_and_generic_setters_cancel_only_superseded_clears( string $setter ): void {
        $this->translated_options();
        $row = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        WordPressState::$options['fabrement_options_qa-null-flow'] += [
            'fab_rows' => '1', 'fab_rows_0__rid' => $row, 'fab_rows_0_text' => '',
        ];
        $path = $setter === 'set' ? 'title' : ( $setter === 'set_group' ? 'group.text' : "rows.$row.text" );
        $assign = static function ( $fields ) use ( $setter, $row ): void {
            if ( $setter === 'set' ) {
                $fields->set( 'title', '' );
            } elseif ( $setter === 'set_group' ) {
                $fields->set_group( 'group', [ 'text' => '' ] );
            } elseif ( $setter === 'update_row' ) {
                $fields->update_row( 'rows', $row, [ 'text' => '' ] );
            } else {
                $fields->set_rows( 'rows', [ [ '_row' => $row, 'text' => '' ] ] );
            }
        };
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( $path );
        $assign( $fields );
        self::assertTrue( $fields->save(), 'Creation seeds the version; the following assignment still cancels the previous clear.' );
        self::assertArrayHasKey( 'fabrement_options_qa-null-flow.uk', WordPressState::$options );

        // Reverse order: an explicit null must still be persisted, including inherited empties.
        $assign( $fields );
        $fields->clear( $path );
        self::assertFalse( $fields->save(), 'The seed already stores null; clearing it again needs no physical change.' );
        self::assertNull( Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_text( $path ) );
        self::assertFalse( $fields->save(), 'Successful save consumes the queued operations.' );
    }

    public static function replacement_setters(): array {
        return [ 'generic' => [ 'set' ], 'group' => [ 'set_group' ], 'row update' => [ 'update_row' ], 'row list' => [ 'set_rows' ] ];
    }

    public function test_partial_group_assignment_keeps_an_omitted_siblings_clear(): void {
        $this->missing_copied_group_child();
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( 'group' )->set_group( 'group', [ 'text' => '' ] );
        self::assertTrue( $fields->save() );
        self::assertArrayHasKey( 'fab_group_amount', WordPressState::$options['fabrement_options_qa-null-flow'] );
        self::assertNull( WordPressState::$options['fabrement_options_qa-null-flow']['fab_group_amount'] );
    }

    public function test_row_reordering_preserves_clear_and_deleting_a_row_discards_its_edits(): void {
        $this->translated_options();
        $a = 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $b = 'row_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        WordPressState::$options['fabrement_options_qa-null-flow'] += [
            'fab_rows' => '2', 'fab_rows_0__rid' => $a, 'fab_rows_0_text' => '',
            'fab_rows_1__rid' => $b, 'fab_rows_1_text' => '',
        ];
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( "rows.$a.text" )->move_row( 'rows', $a, after: $b )->move_row( 'rows', $a, before: $b );
        self::assertTrue( $fields->save(), 'Moving back must retain the surviving row\'s explicit clear.' );
        self::assertNull( WordPressState::$options['fabrement_options_qa-null-flow.uk']['fab_rows_0_text'] );
        $fields->set_text( "rows.$a.text", 'Unsaved edit' )->delete_row( 'rows', $a );
        $new = $fields->add_row( 'rows', [ 'text' => 'New row' ], before: $b );
        self::assertNotSame( $a, $new );
        self::assertTrue( $fields->save() );
        $rows = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' )->get_rows( 'rows' );
        self::assertSame( [ $new, $b ], array_map( static fn( $row ) => $row->id(), $rows ) );
        self::assertSame( 'New row', $rows[0]->get_text( 'text' ) );
        self::assertNull( $rows[1]->get_text( 'text' ) );
    }

    public function test_failed_assignment_does_not_cancel_pending_clear_or_change_values(): void {
        $this->translated_options();
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( 'group.text' );
        try {
            $fields->set_group( 'group', [ 'text' => 'Must not stick', 'amount' => 'invalid number' ] );
            self::fail( 'Invalid number must be rejected.' );
        } catch ( FieldsException $error ) {
            self::assertStringContainsString( 'amount', $error->getMessage() );
        }
        self::assertNull( $fields->get_text( 'group.text' ) );
        self::assertTrue( $fields->save() );
        self::assertArrayHasKey( 'fab_group_text', WordPressState::$options['fabrement_options_qa-null-flow.uk'] );
        self::assertNull( WordPressState::$options['fabrement_options_qa-null-flow.uk']['fab_group_text'] );
    }

    public function test_empty_repeater_assignment_cancels_clear_but_deleting_a_missing_row_does_not(): void {
        $this->translated_options();
        WordPressState::$options['fabrement_options_qa-null-flow']['fab_rows'] = null;
        $fields = Fabrement::instance()->options( 'qa-null-flow', lang: 'uk' );
        $fields->clear( 'rows' )->set_rows( 'rows', [] );
        self::assertTrue( $fields->save(), 'Assigning [] supersedes the clear; the version is still created.' );
        $fields->clear( 'rows' )->delete_row( 'rows', 'row_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );
        self::assertFalse( $fields->save(), 'The created version already holds explicit null; deleting a nonexistent row changes nothing.' );
        self::assertArrayHasKey( 'fab_rows', WordPressState::$options['fabrement_options_qa-null-flow.uk'] );
        self::assertNull( WordPressState::$options['fabrement_options_qa-null-flow.uk']['fab_rows'] );
    }
}
