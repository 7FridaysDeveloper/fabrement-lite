<?php

namespace Fabrement\Api\Support;

defined( 'ABSPATH' ) || exit;

/**
 * ->or( $other ) on a shown object: the object itself when it exists, else the other one of the
 * same class. Another kind of value is one line in the debug log and keeps the first.
 */
trait OrElse {

    /** @param mixed $other */
    public function or( $other ): self {
        if ( ! $other instanceof self ) {
            $class = self::short_name( self::class );
            Quietly::log( self::short_name( static::class ) . '::or()', 'give another ' . $class . ' (' . ( is_object( $other ) ? self::short_name( get_class( $other ) ) : get_debug_type( $other ) ) . ' given); kept the first.' );
            return $this;
        }
        return $this->not_empty() ? $this : $other;
    }

    private static function short_name( string $class ): string {
        $at = strrpos( $class, '\\' );
        return $at === false ? $class : substr( $class, $at + 1 );
    }
}
