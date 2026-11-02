<?php

namespace Fabrement\Tests;

use Fabrement\Page\McpConnectionPage;
use PHPUnit\Framework\TestCase;

final class McpCredentialsPageTest extends TestCase {

    protected function setUp(): void {
        WordPressState::reset();
    }

    protected function tearDown(): void {
        unset( $_GET['fabrement_setup'] );
    }

    public static function view_parameters(): iterable {
        yield 'a key' => [ 'Codex', 'codex' ];
        yield 'missing' => [ null, '' ];
        yield 'array' => [ [ 'codex' ], '' ];
    }

    /** @dataProvider view_parameters */
    public function test_view_parameter_reads_only_a_key( $value, string $expected ): void {
        if ( null !== $value ) {
            $_GET['fabrement_setup'] = $value;
        }
        self::assertSame( $expected, McpConnectionPage::view_parameter( 'fabrement_setup' ) );
    }
}
