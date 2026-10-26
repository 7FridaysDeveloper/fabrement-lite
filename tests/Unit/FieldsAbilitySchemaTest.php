<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Fields\Schema\SchemaValidator;
use Fabrement\Modules\Mcp\Ability\SaveFieldsAbility;
use PHPUnit\Framework\TestCase;

/**
 * The tool schema and the validator agree on what a field cannot do without: every key the
 * schema declares required, the validator refuses a field without — and a field with all of
 * them passes. A client reads the schema before the first call; the validator is what decides.
 */
final class FieldsAbilitySchemaTest extends TestCase {

    private function required(): array {
        $items = ( new SaveFieldsAbility() )->input_schema()['properties']['fields']['items'];
        self::assertSame( 'object', $items['type'] );

        return $items['required'];
    }

    public function test_the_schema_declares_the_four_keys(): void {
        self::assertEqualsCanonicalizing( [ 'name', 'label', 'description', 'type' ], $this->required() );
    }

    public function test_the_validator_refuses_a_field_without_any_of_them(): void {
        $valid     = [ 'name' => 'title', 'label' => 'Title', 'description' => 'Shown above the text', 'type' => 'text' ];
        $validator = new SchemaValidator( static fn() => null );
        self::assertCount( 1, $validator->normalize( [ $valid ], [] ), 'the complete field passes' );

        foreach ( $this->required() as $key ) {
            $field = $valid;
            unset( $field[ $key ] );
            try {
                $validator->normalize( [ $field ], [] );
                self::fail( 'A field without "' . $key . '" was accepted.' );
            } catch ( SchemaError $e ) {
                self::assertStringStartsWith( 'fields[0]', $e->path, $key );
            }
        }
    }
}
