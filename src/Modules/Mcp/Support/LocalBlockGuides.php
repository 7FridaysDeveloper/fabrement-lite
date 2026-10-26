<?php

namespace Fabrement\Modules\Mcp\Support;

/**
 * Hand-written notes for one block, returned beside its schema by get-block-source: what the schema
 * does not say (markup that the editor accepts, how the block behaves, pitfalls). One file per block
 * in skills/blocks/, named like Gutenberg's fixtures: core/navigation → core__navigation.xml.
 */
final class LocalBlockGuides {

    private const ROOT = __DIR__ . '/../../../../skills/blocks';

    /** The guide for a registered block name, or null when the block has none. */
    public static function for_block( string $name ): ?string {
        $file = self::file_of( $name );
        if ( $file === null ) {
            return null;
        }
        $content = file_get_contents( $file );
        return is_string( $content ) && trim( $content ) !== '' ? $content : null;
    }

    /** Whether the block has a guide, without reading it (list-blocks asks for every block). */
    public static function has( string $name ): bool {
        return self::file_of( $name ) !== null;
    }

    private static function file_of( string $name ): ?string {
        if ( ! preg_match( '/\A[a-z0-9-]+\/[a-z0-9-]+\z/', $name ) ) {
            return null;
        }
        $root = realpath( self::ROOT );
        if ( $root === false ) {
            return null;
        }
        $file = realpath( self::ROOT . '/' . str_replace( '/', '__', $name ) . '.xml' );
        if ( $file === false || strpos( $file, $root . DIRECTORY_SEPARATOR ) !== 0 || ! is_file( $file ) || ! is_readable( $file ) ) {
            return null;
        }
        return $file;
    }
}
