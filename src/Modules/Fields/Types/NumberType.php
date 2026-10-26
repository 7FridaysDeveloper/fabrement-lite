<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\FieldValueError;

/** A number or null; `format` integer|decimal is fixed for good. */
final class NumberType extends FieldType {

    public function name(): string {
        return 'number';
    }

    protected function check_format( array $raw, array &$field, string $at, ?array $stored ): void {
        $format = $raw['format'] ?? ( $stored['format'] ?? 'decimal' );
        if ( ! in_array( $format, [ 'integer', 'decimal' ], true ) ) {
            throw new SchemaError( esc_html( $at . '.format' ), 'Expected integer or decimal.' );
        }
        if ( $stored !== null && ( $stored['format'] ?? 'decimal' ) !== $format ) {
            throw new SchemaError( esc_html( $at . '.format' ), 'A number field keeps its format (' . esc_html( $stored['format'] ?? 'decimal' ) . ').', 'field_type_locked' );
        }
        $field['format'] = $format;
    }

    protected function check_bounds( array $raw, array &$field, string $at ): void {
        foreach ( [ 'min', 'max' ] as $bound ) {
            if ( ! array_key_exists( $bound, $raw ) ) {
                continue;
            }
            $n = $raw[ $bound ];
            if ( ! ( is_int( $n ) || is_float( $n ) ) || ! is_finite( (float) $n ) || abs( (float) $n ) > ValueCodec::NUMBER_LIMIT ) {
                throw new SchemaError( esc_html( $at . '.' . $bound ), 'Invalid ' . esc_html( $bound ) . ' for a number field.' );
            }
            $field[ $bound ] = $n;
        }
        if ( isset( $field['min'], $field['max'] ) && $field['min'] > $field['max'] ) {
            throw new SchemaError( esc_html( $at ), 'min is greater than max.' );
        }
    }

    public function check_default( $value, array $field, string $at ) {
        $ok = ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value )
            && abs( (float) $value ) <= ValueCodec::NUMBER_LIMIT
            && ( $field['format'] !== 'integer' || floor( (float) $value ) === (float) $value )
            && ( ! isset( $field['min'] ) || $value >= $field['min'] )
            && ( ! isset( $field['max'] ) || $value <= $field['max'] );
        if ( ! $ok ) {
            throw new SchemaError( esc_html( $at ), 'The default does not fit this field.' );
        }
        return $value;
    }

    public function empty_value( array $field ) {
        return null;
    }

    /** Compared by value: 2 is 2.0; two integers exactly (no float rounding past 2^53). */
    public function same( $value, $stored ): bool {
        if ( is_int( $value ) && is_int( $stored ) ) {
            return $value === $stored;
        }
        return $value === $stored || ( is_numeric( $value ) && is_numeric( $stored ) && (float) $value === (float) $stored );
    }

    public function normalize( array $field, $value, $previous, bool $kept, string $at ) {
        if ( $value === null ) {
            return null;
        }
        if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::NOT_NUMBER );
        }
        if ( abs( (float) $value ) > ValueCodec::NUMBER_LIMIT ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::NUMBER_TOO_LARGE );
        }
        if ( ( $field['format'] ?? 'decimal' ) === 'integer' ) {
            if ( floor( (float) $value ) !== (float) $value ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
                throw new FieldValueError( FieldValueError::WHOLE_NUMBER );
            }
            $value = (int) $value;
        }
        if ( ! $kept && ( ( isset( $field['min'] ) && $value < $field['min'] ) || ( isset( $field['max'] ) && $value > $field['max'] ) ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::OUT_OF_RANGE, ValueCodec::limits( $field ) + [ 'value' => $value ] );
        }
        return $value;
    }

    public function flatten( array $field, $value, string $path, array &$meta ): void {
        $meta[ $path ] = $value === null ? '' : self::to_text( $value );
    }

    /**
     * The shortest text that reads back as the same number: 59.9, not 59.899999999999999.
     * json_encode() gave that only under PHP's default serialize_precision (-1); a host that sets
     * it to 17 (Local does) stored every decimal with its binary noise, which is what meta
     * queries, SEO templates and anyone reading the raw row then saw.
     *
     * @param int|float $value
     */
    public static function to_text( $value ): string {
        if ( is_int( $value ) ) {
            return (string) $value;
        }
        for ( $digits = 15; $digits < 17; $digits++ ) {
            $text = sprintf( '%.' . $digits . 'H', $value );
            if ( (float) $text === (float) $value ) {
                break;
            }
        }
        if ( (float) $text !== (float) $value ) {
            $text = sprintf( '%.17H', $value );
        }
        // A whole float stays a float when read back (3.0, not 3), as json_encode wrote it.
        return preg_match( '/[.EN]/', $text ) === 1 ? $text : $text . '.0';
    }

    public function inflate( array $field, $raw, string $path, array $meta, array &$present ) {
        if ( $raw === '' || $raw === null || ! is_numeric( $raw ) ) {
            return null;
        }
        return ( $field['format'] ?? 'decimal' ) === 'integer' ? (int) $raw : 0 + $raw;
    }
}
