<?php

use PHPUnit\Framework\TestCase;

/** Provider APIs must stay behind the adapter, including hooks and private metadata keys. */
final class FieldTranslationBoundaryTest extends TestCase {
    public function test_fields_and_their_entries_have_no_direct_wpml_dependencies(): void {
        $root = dirname( __DIR__, 2 ) . '/src';
        $paths = [];
        foreach ( [ '/Modules/Fields', '/Api/Fields', '/Route/Fields' ] as $directory ) {
            foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $directory ) ) as $file ) {
                if ( $file->isFile() && $file->getExtension() === 'php' ) {
                    $paths[] = $file->getPathname();
                }
            }
        }
        $paths = array_merge( $paths, glob( $root . '/Modules/Mcp/Ability/*Field*Ability.php' ) );
        $violations = [];
        foreach ( $paths as $path ) {
            $previous = null;
            foreach ( token_get_all( file_get_contents( $path ) ) as $token ) {
                if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    continue;
                }
                // Our own method names are not provider APIs.
                $member = is_array( $previous ) && in_array( $previous[0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ], true );
                $previous = $token;
                if ( ! is_array( $token ) || $token[0] === T_INLINE_HTML || ( $member && $token[0] === T_STRING ) ) {
                    continue;
                }
                $text = trim( $token[1], "\"'" );
                if ( preg_match( '/^(?:_?wpml_|_?icl_|sitepress$|\$sitepress$|\$iclTranslationManagement$)/i', $text )
                    || strpos( $text, 'Fabrement\\Modules\\Translation\\Bridge\\' ) !== false
                    || preg_match( '/^\\\\?WPML\\\\/i', $text ) ) {
                    $violations[] = substr( $path, strlen( $root ) + 1 ) . ':' . $token[2] . ' ' . $text;
                }
            }
        }
        self::assertSame( [], $violations, 'Use Translation::bridge() for provider APIs.' );
    }
}
