<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\Support\LocalBlockGuides;
use PHPUnit\Framework\TestCase;

final class LocalBlockGuidesTest extends TestCase {

    public function test_a_block_with_a_guide_gets_its_text(): void {
        $guide = LocalBlockGuides::for_block( 'core/navigation' );

        self::assertIsString( $guide );
        self::assertStringContainsString( 'CORE/NAVIGATION', $guide );
    }

    public function test_a_block_without_a_guide_gets_null(): void {
        self::assertNull( LocalBlockGuides::for_block( 'core/paragraph-that-does-not-exist' ) );
    }

    public function test_has_answers_without_reading(): void {
        self::assertTrue( LocalBlockGuides::has( 'core/navigation' ) );
        self::assertFalse( LocalBlockGuides::has( 'core/paragraph-that-does-not-exist' ) );
        self::assertFalse( LocalBlockGuides::has( '../core/navigation' ) );
    }

    /** Only "namespace/name" maps to a file; anything else never reaches the filesystem. */
    public function test_names_that_are_not_block_names_get_null(): void {
        foreach ( [ '', 'core', 'core/../base/content-contract', '../core/navigation', 'Core/Navigation', 'core/navigation/x', 'core\\navigation' ] as $name ) {
            self::assertNull( LocalBlockGuides::for_block( $name ), $name );
        }
    }

    /** Every guide file is named after a block and opens with its own name. */
    public function test_every_guide_file_names_its_block(): void {
        $files = glob( dirname( __DIR__, 2 ) . '/skills/blocks/*.xml' ) ?: [];
        self::assertNotEmpty( $files );
        foreach ( $files as $file ) {
            $name = str_replace( '__', '/', basename( $file, '.xml' ) );
            self::assertMatchesRegularExpression( '/\A[a-z0-9-]+\/[a-z0-9-]+\z/', $name, basename( $file ) );
            self::assertStringStartsWith( '<block-guide name="' . $name . '">', (string) LocalBlockGuides::for_block( $name ), basename( $file ) );
        }
    }
}
