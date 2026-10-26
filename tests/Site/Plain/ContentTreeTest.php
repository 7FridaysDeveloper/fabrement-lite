<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The tree of pages an agent builds with save-content `parent` / `menuOrder`, on a real site:
 * what is stored, what list-content and get-content report, the addresses a visitor opens
 * (/parent/child/), and the order a page list prints (core's Page List block: menu order, then
 * title). Moves, renames that clash among new siblings, loops and parents that cannot hold
 * children — and that a refusal leaves the tree as it was.
 *
 * The cases of tests/Wp/ContentParentTest.php about languages belong to the wpml site.
 */
final class ContentTreeTest extends SiteTestCase {

    /** A page made by the agent, published unless said otherwise. @return array save-content's answer. */
    private function page( string $title, array $extra = [], bool $publish = true ): array {
        $made = $this->mcp( 'save-content', array_merge( [ 'mode' => 'create', 'title' => $title, 'blocks' => [] ], $extra ) );
        if ( $publish ) {
            $this->mcp( 'set-content-status', [ 'contentId' => (int) $made['contentId'], 'status' => 'publish' ] );
        }
        return $made;
    }

    private function id( string $title, array $extra = [], bool $publish = true ): int {
        return (int) $this->page( $title, $extra, $publish )['contentId'];
    }

    private function move( int $id, array $change ): array {
        return $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $id ] + $change );
    }

    /** @return array{parent:int, order:int, slug:string} As stored. */
    private function stored( int $id ): array {
        $row = $this->site()->rows( 'SELECT post_parent, menu_order, post_name FROM wp_posts WHERE ID = ?', [ $id ] )[0];
        return [ 'parent' => (int) $row['post_parent'], 'order' => (int) $row['menu_order'], 'slug' => (string) $row['post_name'] ];
    }

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    private function path( int $id ): string {
        return (string) parse_url( $this->url( $id ), PHP_URL_PATH );
    }

    private function children( int $parent, string $orderby ): array {
        return array_column( $this->mcp( 'list-content', [ 'postType' => 'page', 'parent' => $parent, 'orderby' => $orderby ] )['items'], 'id' );
    }

    private function page_rows(): array {
        return $this->site()->rows( "SELECT ID, post_parent, menu_order, post_name, post_status FROM wp_posts WHERE post_type IN ('page','post') ORDER BY ID" );
    }

    /** The titles a page list prints, in the order printed. */
    private function printed_order( string $list_url, array $titles ): array {
        $body = $this->get( $list_url )['body'];
        $at   = [];
        foreach ( $titles as $title ) {
            $position = strpos( $body, '>' . $title . '<' );
            $this->assertNotFalse( $position, $title . ' is in the page list' );
            $at[ $title ] = $position;
        }
        asort( $at );
        return array_keys( $at );
    }

    public function test_a_section_is_built_ordered_moved_and_its_addresses_follow_the_tree(): void {
        $root = $this->page( 'QA Services', [ 'slug' => 'qa-services' ] );
        $this->assertSame( [ 0, 0 ], [ $root['parent'], $root['menuOrder'] ], 'top level, first by default' );
        $root = (int) $root['contentId'];

        // Made under the parent, with their places.
        $design = $this->page( 'QA Design', [ 'slug' => 'qa-design', 'parent' => $root, 'menuOrder' => 2 ] );
        $this->assertSame( [ $root, 2 ], [ $design['parent'], $design['menuOrder'] ] );
        $this->assertSame( 'qa-design', $design['slug'] );
        $design   = (int) $design['contentId'];
        $branding = $this->id( 'QA Branding', [ 'parent' => $root, 'menuOrder' => 1 ] );
        $audit    = $this->id( 'QA Audit', [ 'parent' => $root, 'menuOrder' => 2 ] );
        $this->assertSame( [ 'parent' => $root, 'order' => 2, 'slug' => 'qa-design' ], $this->stored( $design ) );

        // The agent's listings.
        $this->assertSame( [ $branding, $audit, $design ], $this->children( $root, 'menuOrder' ), 'menuOrder, ties by title' );
        $this->assertSame( [ $audit, $branding, $design ], $this->children( $root, 'title' ) );
        $top = array_column( $this->mcp( 'list-content', [ 'postType' => 'page', 'parent' => 0, 'limit' => 100 ] )['items'], 'id' );
        $this->assertContains( $root, $top );
        $this->assertNotContains( $design, $top, 'children are not top level' );
        $this->assertSame( [ $audit ], array_column( $this->mcp( 'list-content', [ 'id' => $audit, 'parent' => 0 ] )['items'], 'id' ), 'a known id is that record, whatever the other filters say' );
        $read = $this->mcp( 'get-content', [ 'contentId' => $audit ] );
        $this->assertSame( [ $root, 2 ], [ $read['parent'], $read['menuOrder'] ] );

        // The visitor: the address follows the tree, and a page list prints the site's order.
        $this->assertSame( '/qa-services/qa-design/', $this->path( $design ) );
        $child = $this->get( '/qa-services/qa-design/' );
        $this->assertSame( 200, $child['status'] );
        $this->assertStringContainsString( 'QA Design', $child['body'] );
        $list = $this->url( $this->id( 'QA Site map', [ 'blocks' => [ [ 'markup' => '<!-- wp:page-list /-->' ] ] ] ) );
        $this->assertSame( [ 'QA Services', 'QA Branding', 'QA Audit', 'QA Design' ], $this->printed_order( $list, [ 'QA Services', 'QA Branding', 'QA Audit', 'QA Design' ] ) );

        // A rename keeps the place.
        $renamed = $this->move( $audit, [ 'title' => 'QA Audit and review' ] );
        $this->assertSame( [ $root, 2 ], [ $renamed['parent'], $renamed['menuOrder'] ] );

        // The order alone changes only the order.
        $this->move( $design, [ 'menuOrder' => -2 ] );
        $this->assertSame( [ 'parent' => $root, 'order' => -2, 'slug' => 'qa-design' ], $this->stored( $design ) );
        $this->assertSame( [ $design, $branding, $audit ], $this->children( $root, 'menuOrder' ) );
        $this->assertSame( [ 'QA Design', 'QA Branding', 'QA Audit and review' ], $this->printed_order( $list, [ 'QA Branding', 'QA Audit and review', 'QA Design' ] ), 'the visitor sees the new order' );

        // Across branches, then to the top: the address moves with it.
        $other = $this->id( 'QA Products', [ 'slug' => 'qa-products' ] );
        $this->move( $design, [ 'parent' => $other ] );
        $this->assertSame( $other, $this->stored( $design )['parent'] );
        $this->assertSame( '/qa-products/qa-design/', $this->path( $design ) );
        $this->assertSame( 200, $this->get( '/qa-products/qa-design/' )['status'] );
        $this->assertNotContains( $design, $this->children( $root, 'menuOrder' ) );

        $this->move( $design, [ 'parent' => 0 ] );
        $this->assertSame( 0, $this->stored( $design )['parent'] );
        $this->assertSame( '/qa-design/', $this->path( $design ) );
        $this->assertSame( 200, $this->get( '/qa-design/' )['status'] );
        $this->assertNotSame( 200, $this->get( '/qa-products/qa-design/' )['status'], 'the old address no longer serves it' );
    }

    public function test_a_slug_is_unique_among_siblings_only(): void {
        $a = $this->id( 'QA Root A', [ 'slug' => 'qa-root-a' ] );
        $b = $this->id( 'QA Root B', [ 'slug' => 'qa-root-b' ] );
        $this->id( 'QA Team', [ 'slug' => 'qa-team', 'parent' => $a ] );
        $this->id( 'QA FAQ of B', [ 'slug' => 'qa-faq', 'parent' => $b ] );
        $this->id( 'QA Contact', [ 'slug' => 'qa-contact' ] );

        $this->assertSame( 'qa-team-2', $this->page( 'QA Team again', [ 'slug' => 'qa-team', 'parent' => $a ] )['slug'], 'a sibling already has it' );
        $this->assertSame( 'qa-faq', $this->page( 'QA FAQ of A', [ 'slug' => 'qa-faq', 'parent' => $a ] )['slug'], 'only a child of ANOTHER parent has it' );
        $this->assertSame( 'qa-contact', $this->page( 'QA Contact of A', [ 'slug' => 'qa-contact', 'parent' => $a ] )['slug'], 'only a top-level page has it' );

        // Two pages at one slug, told apart by their addresses.
        $this->assertStringContainsString( 'QA FAQ of A', $this->get( '/qa-root-a/qa-faq/' )['body'] );
        $this->assertStringContainsString( 'QA FAQ of B', $this->get( '/qa-root-b/qa-faq/' )['body'] );
        $this->assertStringContainsString( 'QA Team again', $this->get( '/qa-root-a/qa-team-2/' )['body'] );
        $this->assertStringContainsString( 'QA Contact of A', $this->get( '/qa-root-a/qa-contact/' )['body'] );
        $this->assertStringContainsString( 'QA Contact', $this->get( '/qa-contact/' )['body'] );
    }

    public function test_moving_beside_a_sibling_with_the_same_slug_renames_it_published_or_draft(): void {
        $root = $this->id( 'QA Company', [ 'slug' => 'qa-company' ] );
        $this->id( 'QA About inside', [ 'slug' => 'qa-about', 'parent' => $root ] );
        $published = $this->id( 'QA About on top', [ 'slug' => 'qa-about' ] );
        $draft     = $this->id( 'QA About draft', [ 'slug' => 'qa-about', 'parent' => $this->id( 'QA Elsewhere' ) ], false );

        $moved = $this->move( $published, [ 'parent' => $root ] );
        $this->assertSame( 'qa-about-2', $moved['slug'] );
        $this->assertSame( 'qa-about-2', $this->stored( $published )['slug'], 'the answer is what is stored' );
        $this->assertStringContainsString( 'QA About on top', $this->get( '/qa-company/qa-about-2/' )['body'] );
        $this->assertStringContainsString( 'QA About inside', $this->get( '/qa-company/qa-about/' )['body'], 'the sibling keeps its address' );

        // WordPress leaves a draft's slug alone — the case that kept the clash.
        $moved = $this->move( $draft, [ 'parent' => $root ] );
        $this->assertSame( 'qa-about-3', $moved['slug'] );
        $this->assertSame( 'qa-about-3', $this->stored( $draft )['slug'] );
        $this->mcp( 'set-content-status', [ 'contentId' => $draft, 'status' => 'publish' ] );
        $this->assertStringContainsString( 'QA About draft', $this->get( '/qa-company/qa-about-3/' )['body'] );
        $this->assertStringContainsString( 'QA About inside', $this->get( '/qa-company/qa-about/' )['body'] );
    }

    public function test_a_new_slug_and_a_new_parent_together_are_judged_among_the_new_siblings(): void {
        $root = $this->id( 'QA Plans', [ 'slug' => 'qa-plans' ] );
        $this->id( 'QA Pricing', [ 'slug' => 'qa-pricing', 'parent' => $root ] );
        $page = $this->id( 'QA Old', [ 'slug' => 'qa-old' ] );

        $out = $this->move( $page, [ 'parent' => $root, 'slug' => 'qa-pricing' ] );
        $this->assertSame( 'qa-pricing-2', $out['slug'] );
        $this->assertSame( [ 'parent' => $root, 'order' => 0, 'slug' => 'qa-pricing-2' ], $this->stored( $page ) );
        $this->assertStringContainsString( 'QA Old', $this->get( '/qa-plans/qa-pricing-2/' )['body'] );
    }

    public function test_loops_are_refused_and_nothing_moves(): void {
        $root       = $this->id( 'QA Loop root', [ 'slug' => 'qa-loop' ] );
        $child      = $this->id( 'QA Loop child', [ 'slug' => 'qa-loop-child', 'parent' => $root ] );
        $grandchild = $this->id( 'QA Loop grandchild', [ 'slug' => 'qa-loop-grandchild', 'parent' => $child ] );
        $before     = $this->page_rows();

        foreach ( [ $root, $child, $grandchild ] as $parent ) {
            $this->assertStringContainsString( 'loop', $this->mcp_error( 'save-content', [ 'mode' => 'add', 'contentId' => $root, 'parent' => $parent ] ) );
        }
        $this->assertSame( $before, $this->page_rows(), 'nothing moved' );
        $this->assertSame( 200, $this->get( '/qa-loop/qa-loop-child/qa-loop-grandchild/' )['status'], 'the addresses stand' );
    }

    public function test_a_parent_may_be_a_draft_pending_private_or_scheduled(): void {
        $parents = [ 'draft' => $this->id( 'QA Draft parent', [], false ) ];
        foreach ( [ 'pending', 'private', 'future' ] as $status ) {
            $parents[ $status ] = $this->id( 'QA ' . ucfirst( $status ) . ' parent', [], false );
            // What a person does on the edit screen, done the way a host would: WP-CLI.
            $this->site()->wp( 'post', 'update', (string) $parents[ $status ], '--post_status=' . $status,
                ...( $status === 'future' ? [ '--post_date=' . gmdate( 'Y-m-d H:i:s', time() + 86400 * 2 ), '--edit_date=1' ] : [] ) );
            $this->assertSame( $status, $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $parents[ $status ] ] ) );
        }
        foreach ( $parents as $status => $parent ) {
            $out = $this->page( 'QA Under ' . $status, [ 'parent' => $parent ], false );
            $this->assertSame( $parent, $out['parent'], $status );
            $this->assertSame( $parent, $this->stored( (int) $out['contentId'] )['parent'], $status );
        }
    }

    public function test_a_refused_parent_creates_nothing(): void {
        $root    = $this->id( 'QA Refusal root' );
        $post    = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'post', 'title' => 'QA a post', 'blocks' => [] ] )['contentId'];
        $trashed = $this->id( 'QA Trashed parent' );
        $this->mcp( 'set-content-status', [ 'contentId' => $trashed, 'status' => 'trash' ] );
        $auto    = (int) $this->site()->wp( 'post', 'create', '--post_type=page', '--post_status=auto-draft', '--post_title=QA auto draft', '--porcelain' );
        $before  = $this->page_rows();

        $this->assertStringContainsString( 'not hierarchical', $this->mcp_error( 'save-content', [ 'mode' => 'create', 'postType' => 'post', 'title' => 'QA flat', 'parent' => $root, 'blocks' => [] ] ) );
        $this->assertStringContainsString( 'is not a "page" record', $this->mcp_error( 'save-content', [ 'mode' => 'create', 'title' => 'QA x', 'parent' => $post, 'blocks' => [] ] ) );
        $this->assertStringContainsString( 'is not a "page" record', $this->mcp_error( 'save-content', [ 'mode' => 'create', 'title' => 'QA x', 'parent' => 999999, 'blocks' => [] ] ) );
        $this->assertStringContainsString( 'cannot hold children', $this->mcp_error( 'save-content', [ 'mode' => 'create', 'title' => 'QA x', 'parent' => $trashed, 'blocks' => [] ] ) );
        $this->assertStringContainsString( 'cannot hold children', $this->mcp_error( 'save-content', [ 'mode' => 'create', 'title' => 'QA x', 'parent' => $auto, 'blocks' => [] ] ) );
        // Refused by the input schema (minimum 0 / integer) before the tool runs.
        $this->mcp_error( 'save-content', [ 'mode' => 'create', 'title' => 'QA x', 'parent' => -1, 'blocks' => [] ] );
        $this->mcp_error( 'save-content', [ 'mode' => 'create', 'title' => 'QA x', 'menuOrder' => 'first', 'blocks' => [] ] );

        $this->assertSame( $before, $this->page_rows(), 'no page and no post was created' );
    }

    public function test_keeping_a_parent_that_was_trashed_since_is_not_a_change(): void {
        $root  = $this->id( 'QA Soon trashed' );
        $child = $this->id( 'QA Orphan to be', [ 'parent' => $root ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $root, 'status' => 'trash' ] );

        $out = $this->move( $child, [ 'parent' => $root, 'title' => 'QA Still here' ] );
        $this->assertSame( $root, $out['parent'] );
        $this->assertSame( $root, $this->stored( $child )['parent'] );
        $this->assertSame( 'QA Still here', $this->site()->value( 'SELECT post_title FROM wp_posts WHERE ID = ?', [ $child ] ) );
    }

    public function test_a_new_draft_reports_when_it_was_made_not_zeros(): void {
        $before = time();
        $draft  = $this->id( 'QA Fresh draft', [], false );
        $this->assertSame( '0000-00-00 00:00:00', $this->site()->value( 'SELECT post_modified_gmt FROM wp_posts WHERE ID = ?', [ $draft ] ), 'precondition: WordPress leaves it empty' );

        $row  = $this->mcp( 'list-content', [ 'id' => $draft ] )['items'][0];
        $read = $this->mcp( 'get-content', [ 'contentId' => $draft ] );
        $this->assertNotSame( '0000-00-00 00:00:00', $row['modified'] );
        $this->assertEqualsWithDelta( $before, strtotime( $row['modified'] . ' UTC' ), 300, 'UTC, about now (the site\'s clock)' );
        $this->assertSame( $row['modified'], $read['modified'] );
    }

    public function test_a_custom_type_made_hierarchical_nests_and_its_addresses_follow_the_tree(): void {
        $this->post_type( 'qa_guide', [ 'singular' => 'QA guide', 'hierarchical' => true, 'rewrite' => 'qa-guides', 'supports' => [ 'title', 'editor', 'page-attributes' ] ] );
        $types = array_column( $this->mcp( 'list-post-types' )['available'], 'hierarchical', 'slug' );
        $this->assertTrue( $types['page'] );
        $this->assertFalse( $types['post'] );
        $this->assertTrue( $types['qa_guide'] ?? null, 'a hierarchical custom type' );

        $guide = $this->id( 'QA Start', [ 'postType' => 'qa_guide', 'slug' => 'start' ] );
        $step  = $this->page( 'QA Step', [ 'postType' => 'qa_guide', 'slug' => 'step', 'parent' => $guide ] );
        $this->assertSame( $guide, $step['parent'] );
        $this->assertSame( '/qa-guides/start/step/', $this->path( (int) $step['contentId'] ) );
        $visit = $this->get( '/qa-guides/start/step/' );
        $this->assertSame( 200, $visit['status'] );
        $this->assertStringContainsString( 'QA Step', $visit['body'] );

        // A flat type refuses a parent, and nothing is made.
        $this->post_type( 'qa_flat' );
        $flat   = $this->id( 'QA Flat one', [ 'postType' => 'qa_flat' ] );
        $before = (int) $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'qa_flat'" );
        $this->assertStringContainsString( 'not hierarchical', $this->mcp_error( 'save-content', [ 'mode' => 'create', 'postType' => 'qa_flat', 'title' => 'QA Flat two', 'parent' => $flat, 'blocks' => [] ] ) );
        $this->assertSame( $before, (int) $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'qa_flat'" ) );
    }

    public function test_a_new_draft_with_no_slug_says_the_one_it_would_get_and_one_given_a_slug_keeps_it(): void {
        $taken = $this->id( 'Future address' );
        $this->assertSame( 'future-address', $this->mcp( 'get-content', [ 'contentId' => $taken ] )['slug'] );
        $this->assertArrayNotHasKey( 'slugWhenPublished', $this->mcp( 'get-content', [ 'contentId' => $taken ] ), 'a record with its slug needs no forecast' );

        // No slug stored yet: the one WordPress would make now, numbered because the address is taken.
        $draft = $this->page( 'Future address', [], false );
        $id    = (int) $draft['contentId'];
        $this->assertSame( [ 'draft', '', 'future-address-2' ], [ $draft['status'], $draft['slug'], $draft['slugWhenPublished'] ] );
        $this->assertSame( 'future-address-2', $this->mcp( 'get-content', [ 'contentId' => $id ] )['slugWhenPublished'] );
        $this->assertSame( '', (string) $this->site()->value( 'SELECT post_name FROM wp_posts WHERE ID = ?', [ $id ] ), 'nothing was stored' );

        // Published, that is its address and the forecast is gone.
        $this->mcp( 'set-content-status', [ 'contentId' => $id, 'status' => 'publish' ] );
        $published = $this->mcp( 'get-content', [ 'contentId' => $id ] );
        $this->assertSame( 'future-address-2', $published['slug'] );
        $this->assertArrayNotHasKey( 'slugWhenPublished', $published );

        // A draft given a slug has it stored from the start.
        $named = $this->page( 'Future address named', [ 'slug' => 'named-at-once' ], false );
        $this->assertSame( 'named-at-once', $named['slug'] );
        $this->assertArrayNotHasKey( 'slugWhenPublished', $named );
    }
}
