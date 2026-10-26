<?php

namespace Fabrement\Api\Support;

use Fabrement\Api\FieldsException;
use Fabrement\Api\Media\Attachment;

defined( 'ABSPATH' ) || exit;

/** Named reader options, checked; the value is returned raw. */
final class ReadResult {
    public static function options( array $args, array $defaults = [], string $where = '' ): array {
        foreach ( $args as $key => $value ) {
            if ( ! is_string( $key ) || ! array_key_exists( $key, $defaults ) ) {
                // A wrong option is logged and left out; the value is still read.
                Quietly::log( $where, ( is_string( $key ) ? 'unknown option "' . $key . '"' : 'an argument too many (options are named)' ) . ' — it takes ' . implode( ', ', array_keys( $defaults ) ) . '; left out.' );
                unset( $args[ $key ] );
                continue;
            }
            // null is "not given": the default stands.
            if ( $value === null && ! in_array( $key, [ 'status', 'kind', 'lang' ], true ) ) {
                unset( $args[ $key ] );
                continue;
            }
            switch ( $key ) {
                case 'lang':
                    $args['lang'] = \Fabrement\Modules\Translation\Version::lang( $value );
                    $valid        = true;
                    break;
                case 'status':
                    $args['status'] = \Fabrement\Modules\Translation\Version::statuses( $value );
                    $valid          = true;
                    break;
                case 'decimals':
                    $valid = is_int( $value );
                    break;
                case 'separators':
                    $valid = is_array( $value );
                    break;
                case 'format':
                    $valid = is_string( $value );
                    break;
                case 'kind':
                    $args['kind'] = Attachment::kinds( $value );
                    $valid        = true;
                    break;
                case 'value':
                    $valid = is_string( $value ) || ( is_array( $value ) && is_string( $value['key'] ?? null ) );
                    break;
                default:
                    $valid = get_debug_type( $value ) === get_debug_type( $defaults[ $key ] );
            }
            if ( ! $valid ) {
                throw new FieldsException( esc_html( $key ) . ': invalid value (' . esc_html( get_debug_type( $value ) ) . ').' );
            }
        }
        return $args + $defaults;
    }

    public static function read( string $where, array $args, array $defaults, callable $read, $empty ) {
        return Quietly::run( $where, static fn() => $read( self::options( $args, $defaults, $where ) ), $empty );
    }
}
