<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Fields\Types\NumberType;
use PHPUnit\Framework\TestCase;

final class NumberTypeTextTest extends TestCase {
    private string $precision = '';

    protected function setUp(): void {
        // What Local and some hosts set; json_encode() then wrote 59.9 as 59.899999999999999.
        $this->precision = (string) ini_get( 'serialize_precision' );
        ini_set( 'serialize_precision', '17' );
    }

    protected function tearDown(): void {
        ini_set( 'serialize_precision', $this->precision );
    }

    public static function numbers(): iterable {
        yield 'decimal' => [ 59.9, '59.9' ];
        yield 'cents' => [ 19.99, '19.99' ];
        yield 'binary exact' => [ 49.5, '49.5' ];
        yield 'negative' => [ -0.5, '-0.5' ];
        yield 'whole float stays a float' => [ 3.0, '3.0' ];
        yield 'integer' => [ 1200, '1200' ];
        yield 'needs 17 digits' => [ 0.1 + 0.2, '0.30000000000000004' ];
        yield 'large' => [ 123456789.123, '123456789.123' ];
    }

    /** @dataProvider numbers */
    public function test_stores_the_shortest_text_that_reads_back_the_same( $value, string $text ): void {
        self::assertSame( $text, NumberType::to_text( $value ) );
        self::assertSame( (float) $value, (float) NumberType::to_text( $value ) );
    }

    public function test_tiny_values_still_read_back(): void {
        $text = NumberType::to_text( 0.00001 );
        self::assertTrue( is_numeric( $text ) );
        self::assertSame( 0.00001, (float) $text );
    }

    public function test_comma_locale_does_not_change_storage_or_round_trip(): void {
        $before = setlocale( LC_NUMERIC, 0 );
        try {
            $locale = setlocale( LC_NUMERIC, 'German_Germany.1252', 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8' );
            if ( $locale === false || localeconv()['decimal_point'] !== ',' ) {
                self::markTestSkipped( 'No comma-decimal locale installed.' );
            }
            $type = new NumberType();
            foreach ( [ 59.9, 0.1 + 0.2, 3.0, 0.00001 ] as $value ) {
                $meta = [];
                $type->flatten( [ 'format' => 'decimal' ], $value, 'number', $meta );
                self::assertStringNotContainsString( ',', $meta['number'] );
                $present = [];
                self::assertSame( $value, $type->inflate( [ 'format' => 'decimal' ], $meta['number'], 'number', $meta, $present ) );
            }
        } finally {
            setlocale( LC_NUMERIC, $before );
        }
    }
}
