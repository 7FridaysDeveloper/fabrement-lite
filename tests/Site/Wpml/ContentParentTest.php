<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * Nested pages and their language versions, built by an agent over MCP: a version sits under the
 * parent's version, never under a page of another language — WPML's own page-parent sync included
 * — and its address is the French path the visitor opens.
 */
final class ContentParentTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private function page( string $title, int $parent = 0, array $extra = [] ): int {
        $id = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => $title, 'parent' => $parent, 'blocks' => [] ] + $extra )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $id, 'status' => 'publish' ] );
        return $id;
    }

    private function translate( int $id, string $lang, string $slug ): array {
        $out = $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => $lang, 'slug' => $slug ] );
        $this->mcp( 'set-content-status', [ 'contentId' => (int) $out['contentId'], 'status' => 'publish' ] );
        return $out;
    }

    private function parent_of( int $id ): int {
        return (int) $this->site()->value( 'SELECT post_parent FROM wp_posts WHERE ID = ?', [ $id ] );
    }

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    public function test_a_version_made_before_its_parents_is_top_level_until_placed_under_the_parents_version(): void {
        $root  = $this->page( 'Root A' );
        $child = $this->page( 'Child A', $root, [ 'menuOrder' => 5 ] );

        $version = $this->translate( $child, 'fr', 'enfant-a' );
        $this->assertTrue( $version['created'] );
        $this->assertSame( 0, $version['parent'], 'no French root yet: top level, not under the English root' );
        $this->assertStringContainsString( (string) $root, $version['note'] ?? '', 'the note names the record to translate' );
        $this->assertSame( 0, $this->parent_of( (int) $version['contentId'] ) );
        $this->assertSame( '5', $this->site()->value( 'SELECT menu_order FROM wp_posts WHERE ID = ?', [ (int) $version['contentId'] ] ), 'its place among siblings is kept' );

        $root_version = (int) $this->translate( $root, 'fr', 'racine-a' )['contentId'];
        // Naming the ORIGINAL parent places the version under the parent's version.
        $moved = $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => (int) $version['contentId'], 'parent' => $root ] );
        $this->assertSame( $root_version, $moved['parent'] );
        $this->assertSame( $root_version, $this->parent_of( (int) $version['contentId'] ) );
        $this->assertSame( $this->site()->url( '/fr/racine-a/enfant-a/' ), $this->url( (int) $version['contentId'] ) );
        $this->assertSame( 200, $this->get( '/fr/racine-a/enfant-a/' )['status'], 'the visitor opens the French path' );
    }

    public function test_a_child_translated_after_its_parent_goes_straight_under_the_parents_version(): void {
        $root        = $this->page( 'Root B' );
        $root_version = (int) $this->translate( $root, 'fr', 'racine-b' )['contentId'];
        $child       = $this->page( 'Child B', $root );
        $version     = $this->translate( $child, 'fr', 'enfant-b' );
        $this->assertSame( $root_version, $version['parent'] );
        $this->assertArrayNotHasKey( 'note', $version );
        $this->assertSame( $root_version, $this->parent_of( (int) $version['contentId'] ) );
        $this->assertSame( 200, $this->get( '/fr/racine-b/enfant-b/' )['status'] );
    }

    public function test_saving_or_moving_the_original_never_puts_a_version_under_another_language(): void {
        $root         = $this->page( 'Root C' );
        $root_version  = (int) $this->translate( $root, 'fr', 'racine-c' )['contentId'];
        $child        = $this->page( 'Child C', $root );
        $child_version = (int) $this->translate( $child, 'fr', 'enfant-c' )['contentId'];

        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $child, 'title' => 'Child C resaved' ] );
        $this->assertSame( $root_version, $this->parent_of( $child_version ), 'saved again: still under the French root' );

        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $child, 'parent' => 0 ] );
        $after = $this->parent_of( $child_version );
        $this->assertTrue( $after === 0 || ( new WpmlSite( $this->site() ) )->language( $after ) === 'fr', 'moved to the top: the version is at the top or under a French page (now ' . $after . ')' );
    }

    public function test_a_trashed_or_deleted_parent_version_is_no_parent(): void {
        // The original parent in the trash: the version goes to the top, and is told why.
        $root  = $this->page( 'Root D' );
        $child = $this->page( 'Child D', $root );
        $this->mcp( 'set-content-status', [ 'contentId' => $root, 'status' => 'trash' ] );
        $out = $this->translate( $child, 'pl', 'dziecko-d' );
        $this->assertSame( 0, $out['parent'] );
        $this->assertStringContainsString( 'trash', $out['note'] ?? '' );

        // The parent's version deleted: no version to go under.
        $root2  = $this->page( 'Root E' );
        $child2 = $this->page( 'Child E', $root2 );
        $gone   = (int) $this->translate( $root2, 'pl', 'korzen-e' )['contentId'];
        $this->mcp( 'delete-content', [ 'contentId' => $gone, 'force' => true ] );
        $out = $this->translate( $child2, 'pl', 'dziecko-e' );
        $this->assertSame( 0, $out['parent'] );
        $this->assertStringContainsString( 'no "pl" version', $out['note'] ?? '' );
    }

    public function test_a_parent_in_another_language_is_taken_in_the_records_language(): void {
        $en_root = $this->page( 'Root F' );
        $pl_root = (int) $this->translate( $en_root, 'pl', 'korzen-f' )['contentId'];
        // A Polish page that is nobody's translation.
        $pl_page = $this->page( 'Strona F', 0, [ 'language' => 'pl', 'newOriginal' => true ] );

        $out = $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $pl_page, 'parent' => $en_root ] );
        $this->assertSame( $pl_root, $out['parent'], 'the English parent, taken as its Polish version' );
        $this->assertSame( $pl_root, $this->parent_of( $pl_page ) );

        // The Polish root trashed, then deleted: refused, and the page stays where it was put.
        $other = $this->page( 'Strona G', 0, [ 'language' => 'pl', 'newOriginal' => true ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $pl_root, 'status' => 'trash' ] );
        $this->mcp_error( 'save-content', [ 'mode' => 'add', 'contentId' => $other, 'parent' => $en_root ] );
        $this->mcp( 'delete-content', [ 'contentId' => $pl_root, 'force' => true ] );
        $this->mcp_error( 'save-content', [ 'mode' => 'add', 'contentId' => $other, 'parent' => $en_root ] );
        $this->assertSame( 0, $this->parent_of( $other ) );
    }
}
