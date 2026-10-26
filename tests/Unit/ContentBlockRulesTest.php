<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\Support\ContentBlockRules;
use PHPUnit\Framework\TestCase;

/** A template's content slot never goes into a record's content; other blocks are not affected. */
final class ContentBlockRulesTest extends TestCase {

    public function test_the_content_slot_is_refused_with_a_reason(): void {
        $reason = ContentBlockRules::refusal( 'fabrement/content-source' );
        self::assertNotNull( $reason );
        self::assertStringContainsString( 'belongs in a template', $reason );
        self::assertStringContainsString( 'save-template', $reason );
    }

    public function test_other_blocks_are_allowed(): void {
        foreach ( [ 'fabrement/archive-list', 'fabrement/hero', 'core/paragraph', 'core/block' ] as $name ) {
            self::assertNull( ContentBlockRules::refusal( $name ), $name );
        }
    }
}
