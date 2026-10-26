<?php

namespace Fabrement\Modules\Fields\Values;

use Fabrement\Modules\Fields\Types\Choices;

/** Choice availability without changing raw values used for validation and translation routing. */
final class ChoiceValues {
    public static function visible( array $field, $value ) {
        return self::map( $field, $value, null, true, [ Choices::class, 'available' ] );
    }

    /** Only submitted, unchanged obsolete optional choices are cleared; validate input first. */
    public static function clear_removed( array $field, $value, $previous, $selected ) {
        return self::map( $field, $value, $previous, $selected, static function ( array $child, $next, $before ) {
            return empty( $child['required'] ) && ValueCodec::same( $child['type'], $next, $before )
                ? Choices::available( $child, $next ) : $next;
        } );
    }

    private static function map( array $field, $value, $previous, $selected, callable $leaf ) {
        if ( $selected === false ) {
            return $value;
        }
        if ( $field['type'] === 'group' ) {
            foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                if ( array_key_exists( $key, (array) $value ) ) {
                    $value[ $key ] = self::map( $child, $value[ $key ], $previous[ $key ] ?? null,
                        $selected === true ? true : ( $selected[ $key ] ?? false ), $leaf );
                }
            }
        } elseif ( $field['type'] === 'repeater' ) {
            $held = array_column( (array) $previous, 'values', 'id' );
            $group = array_replace( $field, [ 'type' => 'group' ] );
            foreach ( $value as &$row ) {
                $row['values'] = self::map( $group, $row['values'], $held[ $row['id'] ] ?? null,
                    $selected === true ? true : ( $selected[ $row['id'] ] ?? false ), $leaf );
            }
            unset( $row );
        } else {
            return $leaf( $field, $value, $previous );
        }
        return $value;
    }
}
