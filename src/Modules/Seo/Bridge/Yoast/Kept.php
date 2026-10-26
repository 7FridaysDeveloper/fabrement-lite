<?php

namespace Fabrement\Modules\Seo\Bridge\Yoast;

/**
 * Whether Yoast keeps a value as it was sent, its own cleaning applied in a dry run first: ends
 * trimmed and runs of white space made one are not a change, nor is an address only re-encoded or
 * with its host in lower case.
 *
 * @internal
 */
final class Kept {

    public static function same( string $sent, string $kept, bool $address = false ): bool {
        $sent = self::spaces( $sent );
        $kept = self::spaces( $kept );
        if ( $sent === $kept ) {
            return true;
        }
        if ( ! $address ) {
            return false;
        }
        return self::address( $sent ) === self::address( $kept )
            || self::address( str_replace( '+', ' ', $sent ) ) === self::address( str_replace( '+', ' ', $kept ) );
    }

    private static function spaces( string $text ): string {
        return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
    }

    private static function address( string $url ): string {
        $url = rawurldecode( $url );
        $url = (string) preg_replace_callback( '#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', static fn( array $m ): string => strtolower( $m[0] ), $url );
        return (string) preg_replace( '/=(?=&|\#|$)/', '', $url );
    }
}
