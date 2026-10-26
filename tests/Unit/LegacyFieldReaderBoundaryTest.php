<?php

use PHPUnit\Framework\TestCase;

/** Removing the legacy helper entry and reader must not leave production callers behind. */
final class LegacyFieldReaderBoundaryTest extends TestCase {
    public function test_only_legacy_helpers_depend_on_the_compatibility_reader(): void {
        $root = dirname( __DIR__, 2 ) . '/src';
        $allowed = [
            realpath( $root . '/Modules/Fields/helpers.php' ),
            realpath( $root . '/Modules/Fields/Values/FieldReader.php' ),
        ];
        $violations = [];
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
            if ( ! $file->isFile() || $file->getExtension() !== 'php' || in_array( $file->getRealPath(), $allowed, true ) ) {
                continue;
            }
            foreach ( token_get_all( file_get_contents( $file->getPathname() ) ) as $token ) {
                if ( ! is_array( $token ) || ! in_array( $token[0], [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ], true ) ) {
                    continue;
                }
                if ( in_array( ltrim( $token[1], '\\' ), [ 'FieldReader', 'Fabrement\\Modules\\Fields\\Values\\FieldReader' ], true ) ) {
                    $violations[] = $file->getPathname() . ':' . $token[2];
                }
            }
        }
        self::assertSame( [], $violations, 'Modern code must use the shared field core, not its legacy adapter.' );
    }
}
