<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaError;

/** A definition's `choices`: [{value, label}], what a select field offers. */
final class Choices {

    /**
     * @param callable(string, string): void|null $check_value Refuses a value the type cannot offer
     *                                                          (value, its path); null = any text.
     * @throws SchemaError
     */
    public static function check( array $raw, array &$field, string $at, ?callable $check_value = null ): void {
        $choices = $raw['choices'] ?? null;
        if ( ! is_array( $choices ) || ! $choices || count( $choices ) > 200 ) {
            throw new SchemaError( esc_html( $at . '.choices' ), 'Expected 1–200 choices.' );
        }
        $values = [];
        foreach ( array_values( $choices ) as $i => $choice ) {
            $path = $at . '.choices[' . $i . ']';
            if ( ! is_array( $choice ) || array_diff( array_keys( $choice ), [ 'value', 'label' ] ) ) {
                throw new SchemaError( esc_html( $path ), 'A choice is {value, label}.' );
            }
            $value = self::text( $choice['value'] ?? null, $path . '.value' );
            if ( isset( $values[ $value ] ) ) {
                throw new SchemaError( esc_html( $path . '.value' ), 'Duplicate choice value.' );
            }
            if ( $check_value ) {
                $check_value( $value, $path . '.value' );
            }
            $values[ $value ] = true;
            $field['choices'][] = [ 'value' => $value, 'label' => self::text( $choice['label'] ?? null, $path . '.label' ) ];
        }
    }

    /** Whether a value is one the field offers. */
    public static function offers( array $field, string $value ): bool {
        return in_array( $value, array_column( $field['choices'] ?? [], 'value' ), true );
    }

    /** A choice's display name as stored; null for an empty or no-longer-offered value. */
    public static function label( array $field, $value ): ?string {
        if ( ! is_scalar( $value ) || (string) $value === '' ) {
            return null;
        }
        foreach ( $field['choices'] ?? [] as $choice ) {
            if ( (string) ( $choice['value'] ?? '' ) === (string) $value ) {
                return (string) $choice['label'];
            }
        }
        return null;
    }

    /** A removed choice looks unselected. */
    public static function available( array $field, $value ) {
        if ( $field['type'] === 'select' && is_string( $value ) && ! self::offers( $field, $value ) ) {
            return '';
        }
        return $value;
    }

    private static function text( $value, string $at ): string {
        if ( ! is_string( $value ) || trim( $value ) === '' || strlen( $value ) > 200 ) {
            throw new SchemaError( esc_html( $at ), 'Expected non-empty text, at most 200 characters.' );
        }
        return $value;
    }
}
