<?php

namespace Fabrement\Modules\Mcp\Support;

use WP_Error;

/**
 * Refuses a boolean input sent as anything but true or false: the Abilities API lets "false", "0" and
 * "1" through as booleans, and PHP reads the string "false" as true.
 */
final class StrictBooleans {

    /**
     * @param array<string,mixed> $schema
     * @param mixed               $value
     */
    public static function check( array $schema, $value, string $path = '' ): ?WP_Error {
        $types = (array) ( $schema['type'] ?? [] );
        if ( in_array( 'boolean', $types, true ) && ! is_bool( $value ) && ! ( $value === null && in_array( 'null', $types, true ) ) ) {
            return new WP_Error(
                'invalid_boolean',
                sprintf( '`%s` must be true or false (a JSON boolean), not %s.', $path, wp_json_encode( $value ) ),
                [ 'path' => $path ]
            );
        }
        if ( ! is_array( $value ) ) {
            return null;
        }
        foreach ( $value as $key => $item ) {
            $child = $schema['properties'][ $key ] ?? ( is_int( $key ) ? ( $schema['items'] ?? null ) : null );
            if ( ! is_array( $child ) ) {
                continue;
            }
            $error = self::check( $child, $item, $path === '' ? (string) $key : $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key ) );
            if ( $error !== null ) {
                return $error;
            }
        }
        return null;
    }
}
