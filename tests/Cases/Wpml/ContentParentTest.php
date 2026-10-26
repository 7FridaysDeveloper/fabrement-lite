<?php

namespace Fabrement\Tests\Cases\Wpml;

use Fabrement\Modules\Translation\Translation;
use Fabrement\Tests\CommittedCleanup;
use Fabrement\Tests\McpCalls;
use Fabrement\Tests\Wpml\WpmlTestCase;

/**
 * Nested pages and their language versions on the real WPML, built through the site's MCP server:
 * a version sits under the parent's version, never under a page of another language — WPML's own
 * page-parent sync included. The rules themselves are tests/Wp/ContentParentTest.
 */
final class ContentParentTest extends WpmlTestCase {

    use CommittedCleanup;
    use McpCalls;

    public function set_up(): void {
        parent::set_up();
        $this->start_cleanup( [], get_current_user_id() );
    }

    public function tear_down(): void {
        $this->end_cleanup();
        parent::tear_down();
    }

    private function page( string $title, int $parent = 0 ): int {
        return (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => $title, 'parent' => $parent, 'blocks' => [] ] )['contentId'];
    }

    private function parent_of( int $id ): int {
        clean_post_cache( $id );
        return (int) get_post_field( 'post_parent', $id );
    }

    private function language_of( int $id ): string {
        return (string) ( Translation::bridge()->version_facts( [ $id ], 'page' )[ $id ]['language'] ?? '' );
    }

    public function test_a_version_made_before_its_parents_is_top_level_and_says_why(): void {
        $root    = $this->page( 'Root' );
        $child   = $this->page( 'Child', $root );
        $version = $this->mcp( 'translate-content', [ 'contentId' => $child, 'language' => 'fr' ] );
        $this->assertTrue( $version['created'] );
        $this->assertSame( 0, $version['parent'] );
        $this->assertArrayHasKey( 'note', $version );
        $this->assertSame( 0, $this->parent_of( (int) $version['contentId'] ), 'not under the English root' );
    }

    public function test_naming_the_original_parent_places_a_version_under_the_parents_version(): void {
        $root         = $this->page( 'Root' );
        $child        = $this->page( 'Child', $root );
        $child_version = (int) $this->mcp( 'translate-content', [ 'contentId' => $child, 'language' => 'fr' ] )['contentId'];
        $root_version  = (int) $this->mcp( 'translate-content', [ 'contentId' => $root, 'language' => 'fr' ] )['contentId'];

        $moved = $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $child_version, 'parent' => $root ] );
        $this->assertSame( $root_version, $moved['parent'] );
        $this->assertSame( $root_version, $this->parent_of( $child_version ) );
    }

    public function test_a_child_translated_after_its_parent_goes_straight_under_the_parents_version(): void {
        $root        = $this->page( 'Root' );
        $root_version = (int) $this->mcp( 'translate-content', [ 'contentId' => $root, 'language' => 'fr' ] )['contentId'];
        $child       = $this->page( 'Child', $root );
        $version     = $this->mcp( 'translate-content', [ 'contentId' => $child, 'language' => 'fr' ] );
        $this->assertSame( $root_version, $version['parent'] );
        $this->assertArrayNotHasKey( 'note', $version );
        $this->assertSame( $root_version, $this->parent_of( (int) $version['contentId'] ) );
    }

    public function test_saving_or_moving_the_original_never_puts_a_version_under_another_language(): void {
        $root         = $this->page( 'Root' );
        $root_version  = (int) $this->mcp( 'translate-content', [ 'contentId' => $root, 'language' => 'fr' ] )['contentId'];
        $child        = $this->page( 'Child', $root );
        $child_version = (int) $this->mcp( 'translate-content', [ 'contentId' => $child, 'language' => 'fr' ] )['contentId'];

        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $child, 'title' => 'Child resaved' ] );
        $this->assertSame( $root_version, $this->parent_of( $child_version ), 'saved again: still under the French root' );

        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $child, 'parent' => 0 ] );
        $after = $this->parent_of( $child_version );
        $this->assertTrue( $after === 0 || $this->language_of( $after ) === 'fr', 'moved to the top: the version is at the top or under a French page (now ' . $after . ')' );
    }
}
