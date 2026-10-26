<?php

namespace Fabrement\Api\Support;

use Fabrement\Api\FieldsException;
use Fabrement\Framework\Log;

defined( 'ABSPATH' ) || exit;

/**
 * How a read of fabrement() never throws: the read runs, and a FieldsException becomes one line in
 * the debug log, "Class::method(): …", and the read's empty answer.
 */
final class Quietly {

    /**
     * @template T
     * @param callable(): T $read
     * @param T             $empty
     * @return T
     */
    public static function run( string $where, callable $read, $empty, bool $log = true ) {
        try {
            return $read();
        } catch ( FieldsException $e ) {
            if ( $log ) {
                self::log( $where, $e->getMessage() );
            }
            return $empty;
        }
    }

    /** @param array<int|string, mixed> $unknown */
    public static function unknown( string $method, array $unknown ): void {
        if ( $unknown === [] ) {
            return;
        }
        $known = [];
        foreach ( ( new \ReflectionMethod( $method ) )->getParameters() as $param ) {
            if ( ! $param->isVariadic() ) {
                $known[] = $param->getName();
            }
        }
        $where = substr( (string) strrchr( '\\' . $method, '\\' ), 1 ) . '()';
        foreach ( array_keys( $unknown ) as $name ) {
            self::log( $where, ( is_string( $name ) ? 'unknown option "' . $name . '"' : 'an argument too many' ) . ' — ' . ( $known === [] ? 'it takes none' : 'it takes ' . implode( ', ', $known ) ) . '; left out.' );
        }
    }

    public static function log( string $where, string $message ): void {
        Log::debug( '[fabrement][fields] ' . $where . ': ' . $message );
    }
}
