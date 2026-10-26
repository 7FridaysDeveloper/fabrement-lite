<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Modules\Mcp\Ability\SaveContentAbility;
use Fabrement\Modules\Mcp\Ability\TranslateContentAbility;
use WP_Error;
use WP_UnitTestCase;

/**
 * save-content `parent` / `menuOrder` and what the listings report, on real WordPress + MySQL:
 * slugs, permalinks and queries are core's own, not stubs. Each test runs in a transaction that
 * is rolled back, so nothing depends on what an earlier test or a crashed run left behind.
 */
final class ContentParentTest extends WP_UnitTestCase {

    use FakeLanguages;

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        $this->forget_languages();
        parent::tear_down();
    }

    /** Through the registered ability, input schema included — what an MCP call goes through. */
    private function call( string $tool, array $input ) {
        $ability = wp_get_ability( 'fabrement/' . $tool );
        $this->assertNotNull( $ability, $tool . ' is registered' );

        return $ability->execute( $input );
    }

    private function page( array $args = [] ): int {
        return self::factory()->post->create( $args + [ 'post_type' => 'page', 'post_status' => 'publish' ] );
    }

    private function assert_refused( $result, string $code ): void {
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
    }

    private function stored( int $id ): array {
        clean_post_cache( $id );
        $post = get_post( $id );

        return [ 'parent' => (int) $post->post_parent, 'order' => (int) $post->menu_order, 'slug' => (string) $post->post_name ];
    }

    // ── create ───────────────────────────────────────────────────────────────────────────────

    public function test_create_under_a_parent_stores_and_reports_parent_and_order(): void {
        $root = $this->page( [ 'post_name' => 'root' ] );

        $out = $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'Child', 'slug' => 'child', 'parent' => $root, 'menuOrder' => 3, 'blocks' => [] ] );

        $this->assertIsArray( $out );
        $this->assertSame( [ $root, 3 ], [ $out['parent'], $out['menuOrder'] ] );
        $this->assertSame( [ 'parent' => $root, 'order' => 3, 'slug' => 'child' ], $this->stored( $out['contentId'] ) );
    }

    public function test_create_slug_is_unique_among_siblings_only(): void {
        $root  = $this->page( [ 'post_name' => 'root' ] );
        $other = $this->page( [ 'post_name' => 'other' ] );
        $this->page( [ 'post_name' => 'team', 'post_parent' => $root ] );
        $this->page( [ 'post_name' => 'faq', 'post_parent' => $other ] );
        $this->page( [ 'post_name' => 'contact' ] );

        $sibling = $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'Team', 'slug' => 'team', 'parent' => $root, 'blocks' => [] ] );
        $cousin  = $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'FAQ', 'slug' => 'faq', 'parent' => $root, 'blocks' => [] ] );
        $nested  = $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'Contact', 'slug' => 'contact', 'parent' => $root, 'blocks' => [] ] );

        $this->assertSame( 'team-2', $sibling['slug'], 'a sibling already has it' );
        $this->assertSame( 'faq', $cousin['slug'], 'only a child of ANOTHER parent has it' );
        $this->assertSame( 'contact', $nested['slug'], 'only a top-level page has it' );
    }

    public function test_a_draft_parent_may_hold_children_and_so_may_pending_private_and_scheduled(): void {
        foreach ( [ 'draft', 'pending', 'private', 'future' ] as $status ) {
            $args = [ 'post_status' => $status ];
            if ( $status === 'future' ) {
                $args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
            }
            $parent = $this->page( $args );
            $out    = $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'Under ' . $status, 'parent' => $parent, 'blocks' => [] ] );
            $this->assertIsArray( $out, $status . ': ' . ( is_wp_error( $out ) ? $out->get_error_message() : '' ) );
            $this->assertSame( $parent, $out['parent'], $status );
        }
    }

    public function test_create_refusals_create_nothing(): void {
        $root    = $this->page();
        $post    = self::factory()->post->create();
        $trashed = $this->page( [ 'post_status' => 'trash' ] );
        $auto    = $this->page( [ 'post_status' => 'auto-draft' ] );
        $before  = wp_count_posts( 'page' );
        $posts   = wp_count_posts( 'post' );

        $this->assert_refused( $this->call( 'save-content', [ 'mode' => 'create', 'postType' => 'post', 'title' => 'Flat', 'parent' => $root, 'blocks' => [] ] ), 'not_hierarchical' );
        $this->assert_refused( $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'X', 'parent' => $post, 'blocks' => [] ] ), 'invalid_parent' );
        $this->assert_refused( $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'X', 'parent' => 999999, 'blocks' => [] ] ), 'invalid_parent' );
        $this->assert_refused( $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'X', 'parent' => $trashed, 'blocks' => [] ] ), 'invalid_parent' );
        $this->assert_refused( $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'X', 'parent' => $auto, 'blocks' => [] ] ), 'invalid_parent' );
        // Refused by the input schema (minimum 0 / integer) before the tool runs.
        $this->assertInstanceOf( WP_Error::class, $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'X', 'parent' => -1, 'blocks' => [] ] ) );
        $this->assertInstanceOf( WP_Error::class, $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'X', 'menuOrder' => 'first', 'blocks' => [] ] ) );

        wp_cache_flush();
        $this->assertEquals( $before, wp_count_posts( 'page' ), 'no page was created' );
        $this->assertEquals( $posts, wp_count_posts( 'post' ), 'no post was created' );
    }

    // ── update ───────────────────────────────────────────────────────────────────────────────

    public function test_an_update_without_parent_or_order_keeps_both(): void {
        $root  = $this->page();
        $child = $this->page( [ 'post_parent' => $root, 'menu_order' => 4 ] );

        $out = $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $child, 'title' => 'Renamed' ] );

        $this->assertSame( [ $root, 4 ], [ $out['parent'], $out['menuOrder'] ] );
    }

    public function test_menu_order_alone_changes_only_the_order(): void {
        $root  = $this->page();
        $child = $this->page( [ 'post_parent' => $root, 'post_name' => 'kid' ] );

        $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $child, 'menuOrder' => -2 ] );

        $this->assertSame( [ 'parent' => $root, 'order' => -2, 'slug' => 'kid' ], $this->stored( $child ) );
    }

    public function test_moving_beside_a_sibling_with_the_same_slug_renames_it_published_or_draft(): void {
        $root = $this->page();
        $this->page( [ 'post_parent' => $root, 'post_name' => 'about' ] );
        $published = $this->page( [ 'post_name' => 'about' ] );
        $draft     = $this->page( [ 'post_status' => 'draft', 'post_name' => 'about', 'post_parent' => $this->page() ] );

        $moved = $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $published, 'parent' => $root ] );
        $this->assertSame( 'about-2', $moved['slug'] );
        $this->assertSame( 'about-2', $this->stored( $published )['slug'], 'the answer is what is stored' );

        // WordPress leaves a draft's slug alone — this is the case that kept the clash.
        $moved = $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $draft, 'parent' => $root ] );
        $this->assertSame( 'about-3', $moved['slug'] );
        $this->assertSame( 'about-3', $this->stored( $draft )['slug'] );
    }

    public function test_a_new_slug_and_a_new_parent_together_are_judged_among_the_new_siblings(): void {
        $root = $this->page();
        $this->page( [ 'post_parent' => $root, 'post_name' => 'pricing' ] );
        $page = $this->page( [ 'post_name' => 'old' ] );

        $out = $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $page, 'parent' => $root, 'slug' => 'pricing' ] );

        $this->assertSame( [ 'parent' => $root, 'order' => 0, 'slug' => 'pricing-2' ], $this->stored( $page ) );
        $this->assertSame( 'pricing-2', $out['slug'] );
    }

    public function test_loops_are_refused_and_nothing_moves(): void {
        $root       = $this->page();
        $child      = $this->page( [ 'post_parent' => $root ] );
        $grandchild = $this->page( [ 'post_parent' => $child ] );

        $this->assert_refused( $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $root, 'parent' => $root ] ), 'parent_loop' );
        $this->assert_refused( $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $root, 'parent' => $child ] ), 'parent_loop' );
        $this->assert_refused( $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $root, 'parent' => $grandchild ] ), 'parent_loop' );

        $this->assertSame( 0, $this->stored( $root )['parent'] );
        $this->assertSame( $root, $this->stored( $child )['parent'] );
    }

    public function test_moving_across_branches_and_to_the_top(): void {
        $a     = $this->page();
        $b     = $this->page();
        $child = $this->page( [ 'post_parent' => $a ] );

        $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $child, 'parent' => $b ] );
        $this->assertSame( $b, $this->stored( $child )['parent'] );

        $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $child, 'parent' => 0 ] );
        $this->assertSame( 0, $this->stored( $child )['parent'] );
    }

    public function test_keeping_a_parent_that_was_trashed_since_is_not_a_change(): void {
        $root  = $this->page();
        $child = $this->page( [ 'post_parent' => $root ] );
        wp_trash_post( $root );

        $out = $this->call( 'save-content', [ 'mode' => 'add', 'contentId' => $child, 'parent' => $root, 'title' => 'Still here' ] );

        $this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
        $this->assertSame( $root, $out['parent'] );
    }

    // ── listings ─────────────────────────────────────────────────────────────────────────────

    public function test_list_content_by_parent_and_order(): void {
        $root = $this->page( [ 'post_title' => 'Root' ] );
        $c    = $this->page( [ 'post_parent' => $root, 'menu_order' => 2, 'post_title' => 'Charlie' ] );
        $b    = $this->page( [ 'post_parent' => $root, 'menu_order' => 1, 'post_title' => 'Bravo' ] );
        $a    = $this->page( [ 'post_parent' => $root, 'menu_order' => 2, 'post_title' => 'Alpha' ] );

        $by_order = $this->call( 'list-content', [ 'postType' => 'page', 'parent' => $root, 'orderby' => 'menuOrder' ] );
        $this->assertSame( [ $b, $a, $c ], array_column( $by_order['items'], 'id' ), 'menuOrder, ties by title' );
        $this->assertSame( [ $root, 1 ], [ $by_order['items'][0]['parent'], $by_order['items'][0]['menuOrder'] ] );

        $by_title = $this->call( 'list-content', [ 'postType' => 'page', 'parent' => $root, 'orderby' => 'title' ] );
        $this->assertSame( [ $a, $b, $c ], array_column( $by_title['items'], 'id' ) );

        $top = $this->call( 'list-content', [ 'postType' => 'page', 'parent' => 0 ] );
        $this->assertSame( [ $root ], array_column( $top['items'], 'id' ) );

        // A known id is that record, whatever the other filters say.
        $one = $this->call( 'list-content', [ 'id' => $a, 'parent' => 0 ] );
        $this->assertSame( [ $a ], array_column( $one['items'], 'id' ) );

        $read = $this->call( 'get-content', [ 'contentId' => $a ] );
        $this->assertSame( [ $root, 2 ], [ $read['parent'], $read['menuOrder'] ] );
    }

    public function test_a_new_draft_reports_when_it_was_made_not_zeros(): void {
        $before = time();
        $draft  = $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'Fresh draft', 'blocks' => [] ] );
        $this->assertSame( '0000-00-00 00:00:00', get_post( $draft['contentId'] )->post_modified_gmt, 'precondition: WordPress leaves it empty' );

        $row  = $this->call( 'list-content', [ 'id' => $draft['contentId'] ] )['items'][0];
        $read = $this->call( 'get-content', [ 'contentId' => $draft['contentId'] ] );

        $this->assertNotSame( '0000-00-00 00:00:00', $row['modified'] );
        $this->assertEqualsWithDelta( $before, strtotime( $row['modified'] . ' UTC' ), 120, 'UTC, about now' );
        $this->assertSame( $row['modified'], $read['modified'] );
    }

    public function test_list_post_types_says_which_types_nest(): void {
        register_post_type( 'qa_guide', [ 'public' => true, 'hierarchical' => true, 'label' => 'Guides' ] );
        $types = array_column( $this->call( 'list-post-types', [] )['available'], 'hierarchical', 'slug' );

        $this->assertTrue( $types['page'] );
        $this->assertFalse( $types['post'] );
        $this->assertTrue( $types['qa_guide'] ?? null, 'a hierarchical custom type' );
    }

    public function test_addresses_follow_the_tree_for_pages_and_a_custom_type(): void {
        $this->set_permalink_structure( '/%postname%/' );
        register_post_type( 'qa_guide', [ 'public' => true, 'hierarchical' => true, 'label' => 'Guides', 'supports' => [ 'title', 'editor' ], 'rewrite' => [ 'slug' => 'guides' ] ] );
        flush_rewrite_rules();

        $root  = $this->page( [ 'post_name' => 'services' ] );
        $child = $this->call( 'save-content', [ 'mode' => 'create', 'title' => 'Design', 'slug' => 'design', 'parent' => $root, 'blocks' => [] ] );
        wp_publish_post( $child['contentId'] );
        $this->assertStringEndsWith( '/services/design/', get_permalink( $child['contentId'] ) );

        $guide = self::factory()->post->create( [ 'post_type' => 'qa_guide', 'post_name' => 'start', 'post_status' => 'publish' ] );
        $step  = $this->call( 'save-content', [ 'mode' => 'create', 'postType' => 'qa_guide', 'title' => 'Step', 'slug' => 'step', 'parent' => $guide, 'blocks' => [] ] );
        $this->assertIsArray( $step, is_wp_error( $step ) ? $step->get_error_message() : '' );
        wp_publish_post( $step['contentId'] );
        $this->assertStringEndsWith( '/guides/start/step/', get_permalink( $step['contentId'] ) );
    }

    // ── languages (a fake two-language bridge; real WPML: tests/Cases/Wpml/ContentParentTest.php) ─

    public function test_a_parent_in_another_language_is_taken_in_the_records_language(): void {
        $this->use_languages();
        $en_root = $this->page();
        $pl_root = $this->page();
        $pl_page = $this->page();
        $this->in_language( $en_root, 'en' );
        $this->link_version( $pl_root, $en_root, 'pl' );
        $this->in_language( $pl_page, 'pl' );

        $out = ( new SaveContentAbility() )->execute( [ 'mode' => 'add', 'contentId' => $pl_page, 'parent' => $en_root ] );
        $this->assertSame( $pl_root, $out['parent'] );

        // Create in Polish, naming the English parent.
        $new = ( new SaveContentAbility() )->execute( [ 'mode' => 'create', 'title' => 'Nowa', 'language' => 'pl', 'newOriginal' => true, 'parent' => $en_root, 'blocks' => [] ] );
        $this->assertIsArray( $new, is_wp_error( $new ) ? $new->get_error_message() : '' );
        $this->assertSame( $pl_root, $this->stored( $new['contentId'] )['parent'] );
    }

    public function test_a_parent_version_that_is_trashed_or_deleted_is_no_parent(): void {
        $this->use_languages();
        $en_root = $this->page();
        $pl_root = $this->page();
        $pl_page = $this->page();
        $this->in_language( $en_root, 'en' );
        $this->link_version( $pl_root, $en_root, 'pl' );
        $this->in_language( $pl_page, 'pl' );

        wp_trash_post( $pl_root );
        $this->assert_refused( ( new SaveContentAbility() )->execute( [ 'mode' => 'add', 'contentId' => $pl_page, 'parent' => $en_root ] ), 'parent_language' );

        // Deleted outright: the translation map still names it, as WPML's does.
        wp_delete_post( $pl_root, true );
        $this->assert_refused( ( new SaveContentAbility() )->execute( [ 'mode' => 'add', 'contentId' => $pl_page, 'parent' => $en_root ] ), 'parent_language' );
        $this->assertSame( 0, $this->stored( $pl_page )['parent'] );
    }

    public function test_translate_content_places_the_version_under_the_parents_version(): void {
        $this->use_languages();
        $root  = $this->page();
        $child = $this->page( [ 'post_parent' => $root, 'menu_order' => 5 ] );
        $this->in_language( $root, 'en' );
        $this->in_language( $child, 'en' );

        // No Polish root yet: top level, and the note names the record to translate.
        $first = ( new TranslateContentAbility() )->execute( [ 'contentId' => $child, 'language' => 'pl' ] );
        $this->assertTrue( $first['created'] );
        $this->assertSame( 0, $first['parent'] );
        $this->assertStringContainsString( (string) $root, $first['note'] ?? '' );
        $this->assertSame( 5, $this->stored( $first['contentId'] )['order'], 'menuOrder is copied' );

        $pl_root = ( new TranslateContentAbility() )->execute( [ 'contentId' => $root, 'language' => 'pl' ] );
        $second  = $this->page( [ 'post_parent' => $root ] );
        $this->in_language( $second, 'en' );
        $version = ( new TranslateContentAbility() )->execute( [ 'contentId' => $second, 'language' => 'pl' ] );
        $this->assertTrue( $version['created'] );
        $this->assertSame( $pl_root['contentId'], $version['parent'] );
        $this->assertArrayNotHasKey( 'note', $version );
    }

    public function test_translate_content_with_a_trashed_deleted_or_untracked_parent(): void {
        $this->use_languages();

        // The original's parent is in the trash: top level, with a note that says so.
        $root  = $this->page();
        $child = $this->page( [ 'post_parent' => $root ] );
        $this->in_language( $root, 'en' );
        $this->in_language( $child, 'en' );
        wp_trash_post( $root );
        $out = ( new TranslateContentAbility() )->execute( [ 'contentId' => $child, 'language' => 'pl' ] );
        $this->assertSame( 0, $out['parent'] );
        $this->assertStringContainsString( 'trash', $out['note'] ?? '' );

        // The parent's Polish version was deleted, but the map still names it.
        $root2    = $this->page();
        $gone     = $this->page();
        $child2   = $this->page( [ 'post_parent' => $root2 ] );
        $this->in_language( $root2, 'en' );
        $this->link_version( $gone, $root2, 'pl' );
        $this->in_language( $child2, 'en' );
        wp_delete_post( $gone, true );
        $out = ( new TranslateContentAbility() )->execute( [ 'contentId' => $child2, 'language' => 'pl' ] );
        $this->assertSame( 0, $out['parent'] );
        $this->assertStringContainsString( 'no "pl" version', $out['note'] ?? '' );

        // A parent the translation plugin does not track is one record for every language.
        $shared = $this->page();
        $child3 = $this->page( [ 'post_parent' => $shared ] );
        $this->in_language( $child3, 'en' );
        $out = ( new TranslateContentAbility() )->execute( [ 'contentId' => $child3, 'language' => 'pl' ] );
        $this->assertSame( $shared, $out['parent'] );
        $this->assertArrayNotHasKey( 'note', $out );
    }
}
