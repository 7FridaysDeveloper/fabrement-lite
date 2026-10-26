<?php

namespace Fabrement\Tests\Unit;

use Fabrement\Modules\Mcp\Support\StrictBooleans;
use PHPUnit\Framework\TestCase;

/** Boolean inputs of MCP tools take only true, false and, where allowed, null. */
final class StrictBooleansTest extends TestCase {

    private const SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'site'    => [ 'type' => 'boolean' ],
            'noindex' => [ 'type' => [ 'boolean', 'null' ] ],
            'title'   => [ 'type' => [ 'string', 'null' ] ],
            'rows'    => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'on' => [ 'type' => 'boolean' ] ] ] ],
        ],
    ];

    public function test_real_booleans_and_allowed_null_pass(): void {
        $this->assertNull( StrictBooleans::check( self::SCHEMA, [ 'site' => false, 'noindex' => null, 'title' => 'false', 'rows' => [ [ 'on' => true ] ] ] ) );
    }

    /** @dataProvider strings */
    public function test_strings_and_numbers_are_refused_with_the_field( $value ): void {
        $error = StrictBooleans::check( self::SCHEMA, [ 'noindex' => $value ] );
        $this->assertNotNull( $error );
        $this->assertSame( 'invalid_boolean', $error->get_error_code() );
        $this->assertStringStartsWith( '`noindex` must be true or false', $error->get_error_message() );
    }

    public static function strings(): array {
        return [ [ 'false' ], [ 'true' ], [ '0' ], [ '1' ], [ 0 ], [ 1 ], [ '' ] ];
    }

    public function test_null_is_refused_where_the_schema_does_not_allow_it(): void {
        $this->assertStringStartsWith( '`site` must be', StrictBooleans::check( self::SCHEMA, [ 'site' => null ] )->get_error_message() );
    }

    public function test_a_nested_switch_is_named_by_its_path(): void {
        $error = StrictBooleans::check( self::SCHEMA, [ 'rows' => [ [ 'on' => true ], [ 'on' => 'false' ] ] ] );
        $this->assertStringStartsWith( '`rows[1].on` must be', $error->get_error_message() );
    }
}
