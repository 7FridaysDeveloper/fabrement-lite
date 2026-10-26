<?php

namespace Fabrement\Modules\Fields\Values;

use Fabrement\Modules\Fields\Types\Choices;

/**
 * Compatibility reader used only by released fabrement_get_* helpers. Modern Fields reads do
 * not depend on this class. Shared choice labels live in Choices. No permission check, no token, no
 * retired values: just the active fields, keyed by name, read through the primed meta cache.
 * Record and media ids come back in the reader's language ({@see ValueCodec::reader_value()}).
 */
final class FieldReader {

    /** The released template helpers keep their boolean empty shape. */
    private static function legacy_value( array $field, $value ) {
        if ( $field['type'] === 'true_false' && $value === null ) {
            return false;
        }
        if ( $field['type'] === 'group' ) {
            foreach ( ValueCodec::active( $field['sub_fields'] ) as $child ) {
                if ( is_array( $value ) && array_key_exists( $child['name'], $value ) ) {
                    $value[ $child['name'] ] = self::legacy_value( $child, $value[ $child['name'] ] );
                }
            }
        } elseif ( $field['type'] === 'repeater' ) {
            foreach ( $value as &$row ) {
                foreach ( ValueCodec::active( $field['sub_fields'] ) as $child ) {
                    if ( array_key_exists( $child['name'], $row ) ) {
                        $row[ $child['name'] ] = self::legacy_value( $child, $row[ $child['name'] ] );
                    }
                }
            }
            unset( $row );
        }
        return $value;
    }

    private static function read_value( array $field, $value ) {
        return self::legacy_value( $field, ValueCodec::reader_value( $field, ValueCodec::public_value( $field, $value ) ) );
    }

    /** @param int|string $ref */
    public static function get( string $selector, $ref ) {
        $object = FieldObject::resolve( $ref );
        $field = $object ? $object->field( $selector ) : null;
        if ( ! $field ) {
            return null;
        }
        $stored = $object->values( [ $field['key'] => $field ] );
        return self::read_value( $field, $stored['values'][ $field['key'] ] );
    }

    /** @param int|string $ref */
    public static function all( $ref ): array {
        $object = FieldObject::resolve( $ref );
        if ( ! $object ) {
            return [];
        }
        $active = ValueCodec::active( $object->all_fields() );
        $stored = $object->values( $active );
        $out = [];
        foreach ( $active as $key => $field ) {
            $out[ $field['name'] ] = self::read_value( $field, $stored['values'][ $key ] );
        }
        return $out;
    }

    /**
     * The field definition plus `value` and `exists` (stored vs default). Null when there is no
     * such active field on this record.
     *
     * @param int|string $ref
     */
    public static function object( string $selector, $ref ): ?array {
        $object = FieldObject::resolve( $ref );
        $field = $object ? $object->field( $selector ) : null;
        if ( ! $field ) {
            return null;
        }
        $stored = $object->values( [ $field['key'] => $field ] );
        return ValueCodec::without_retired( $field ) + [
            'value'  => self::read_value( $field, $stored['values'][ $field['key'] ] ),
            'exists' => $stored['exists'][ $field['key'] ],
        ];
    }

    /**
     * The label of a choice field's value, as stored. $selector: a name (or key) or a dotted path
     * through groups and repeaters; $value: the value to label. Left out, it is read from the
     * record when the path runs through groups only; a repeater has one value per row, so the
     * caller passes the row's.
     *
     * @param int|string $ref
     * @param mixed      $value
     */
    public static function choice_label( string $selector, $ref, $value = null ): string {
        $object = FieldObject::resolve( $ref );
        $path = explode( '.', $selector );
        $field = $object ? $object->field( array_shift( $path ) ) : null;
        if ( ! $field ) {
            return '';
        }
        $groups_only = true;
        $current = $value === null ? self::get( $field['name'], $ref ) : null;
        foreach ( $path as $name ) {
            if ( $field['type'] !== 'group' ) {
                $groups_only = false;
            }
            $next = null;
            foreach ( $field['sub_fields'] ?? [] as $child ) {
                if ( empty( $child['retired'] ) && ( $child['name'] === $name || $child['key'] === $name ) ) {
                    $next = $child;
                }
            }
            if ( ! $next ) {
                return '';
            }
            $current = is_array( $current ) ? ( $current[ $next['name'] ] ?? null ) : null;
            $field = $next;
        }
        if ( empty( $field['choices'] ) ) {
            return '';
        }
        if ( $value === null ) {
            if ( ! $groups_only ) {
                return '';   // inside a repeater: the caller passes the row's value
            }
            $value = $current;
        }
        return Choices::label( $field, $value ) ?? '';
    }
}
