<?php

namespace Fabrement\Api\Media;

use Fabrement\Api\FieldsException;

defined( 'ABSPATH' ) || exit;

/**
 * The media values fabrement() reads, checked strictly: an attachment id, an {id,
 * url} array and an {id, url, filename, fileSize, mimeType, label} array. A value of another
 * shape throws FieldsException.
 */
final class MediaValue {

    /**
     * @param mixed $value
     * @return array{0: int, 1: string, 2: string}
     */
    public static function media( $value ): array {
        if ( $value === null ) {
            return [ 0, '', '' ];
        }
        if ( is_int( $value ) ) {
            return [ self::id( $value ), '', '' ];
        }
        if ( ! is_array( $value ) ) {
            throw new FieldsException( 'give an attachment id or a media value {id, url}; got ' . esc_html( get_debug_type( $value ) ) . '.' );
        }
        $id = $value['id'] ?? 0;
        $url = $value['url'] ?? '';
        if ( ! is_int( $id ) || ! is_string( $url ) ) {
            throw new FieldsException( 'a media value is {id, url} — id an attachment id, url an address; got id ' . esc_html( get_debug_type( $id ) ) . ', url ' . esc_html( get_debug_type( $url ) ) . '.' );
        }
        $alt = $value['alt'] ?? '';
        if ( ! is_string( $alt ) ) {
            throw new FieldsException( 'a media value is {id, url, alt} — alt is text, not ' . esc_html( get_debug_type( $alt ) ) . '.' );
        }
        return [ self::id( $id ), $url, $alt ];
    }

    /**
     * @param mixed $value
     * @return array{id: int, url: string, filename: string, label: string, fileSize: int, mimeType: string}
     */
    public static function download( $value ): array {
        $out = [ 'id' => 0, 'url' => '', 'filename' => '', 'label' => '', 'fileSize' => 0, 'mimeType' => '' ];
        if ( $value === null ) {
            return $out;
        }
        if ( is_int( $value ) ) {
            return [ 'id' => self::id( $value ) ] + $out;
        }
        if ( ! is_array( $value ) ) {
            throw new FieldsException( 'give an attachment id or a download value {id, url, filename, fileSize, mimeType, label}; got ' . esc_html( get_debug_type( $value ) ) . '.' );
        }
        foreach ( [ 'url', 'filename', 'label', 'mimeType' ] as $key ) {
            if ( array_key_exists( $key, $value ) && ! is_string( $value[ $key ] ) ) {
                throw new FieldsException( 'a download value is {id, url, filename, fileSize, mimeType, label} — ' . esc_html( $key ) . ' is text, not ' . esc_html( get_debug_type( $value[ $key ] ) ) . '.' );
            }
        }
        foreach ( [ 'id', 'fileSize' ] as $key ) {
            if ( array_key_exists( $key, $value ) && ( ! is_int( $value[ $key ] ) || $value[ $key ] < 0 ) ) {
                throw new FieldsException( 'a download value is {id, url, filename, fileSize, mimeType, label} — ' . esc_html( $key ) . ' is an integer from 0, not ' . esc_html( self::shown( $value[ $key ] ) ) . '.' );
            }
        }
        return array_merge( $out, array_intersect_key( $value, $out ) );
    }

    private static function id( int $id ): int {
        if ( $id < 0 ) {
            throw new FieldsException( 'an attachment id is an integer from 0 (' . esc_html( $id ) . ' given).' );
        }
        return $id;
    }

    /** @param mixed $value */
    private static function shown( $value ): string {
        return is_int( $value ) ? (string) $value : get_debug_type( $value );
    }
}
