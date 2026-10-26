<?php

namespace Fabrement\Tests\Wpml;

use Fabrement\Modules\Mcp\Ability\SaveContentAbility;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * The translation tools on the real WPML: a record's language version (translate-content), a
 * term's (translate-term), a global block's (translate-synced-pattern), parents across languages,
 * and what the bridge answers about groups — the facts tests/Wp could only imitate.
 */
final class TranslationToolsTest extends WpmlTestCase {

    private function page( array $args = [] ): int {
        return self::factory()->post->create( $args + [ 'post_type' => 'page', 'post_status' => 'publish' ] );
    }

    private function assert_refused( $result, string $code ): void {
        $this->assertInstanceOf( WP_Error::class, $result, 'refused with ' . $code );
        $this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
    }

    private function parent_of( int $id ): int {
        clean_post_cache( $id );
        return (int) get_post( $id )->post_parent;
    }

    /** @return array<string,mixed> */
    private function translate( int $id, string $lang ) {
        return $this->tool( 'translate-content', [ 'contentId' => $id, 'language' => $lang ] );
    }

    // ── translate-content ────────────────────────────────────────────────────────────────────

    public function test_the_version_is_a_linked_draft_copy_of_the_original(): void {
        $image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
        $page  = $this->page( [ 'post_title' => 'Services', 'post_content' => '<!-- wp:paragraph --><p>We build sites.</p><!-- /wp:paragraph -->' ] );
        set_post_thumbnail( $page, $image );
        update_post_meta( $page, '_wp_page_template', 'page-wide.php' );

        $out = $this->translate( $page, 'fr' );
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );
        $this->assertTrue( $out['created'] );
        $fr = (int) $out['contentId'];

        $this->assertSame( 'draft', get_post_status( $fr ), 'words to be changed before anyone reads them' );
        $this->assertSame( $fr, $this->version( $page, 'fr' ) );
        $this->assertSame( 'fr', apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $fr, 'element_type' => 'post_page' ] ) );
        $this->assertSame( get_post_field( 'post_content', $page ), get_post_field( 'post_content', $fr ) );
        $this->assertSame( $image, (int) get_post_thumbnail_id( $fr ) );
        $this->assertSame( 'page-wide.php', get_post_meta( $fr, '_wp_page_template', true ) );
    }

    public function test_a_slug_given_is_the_versions_address_and_none_leaves_it_to_the_title(): void {
        $page = $this->page( [ 'post_title' => 'About', 'post_name' => 'about' ] );

        $fr = $this->tool( 'translate-content', [ 'contentId' => $page, 'language' => 'fr', 'slug' => 'À propos' ] );
        $this->assertIsArray( $fr, $fr instanceof WP_Error ? $fr->get_error_message() : '' );
        $this->assertSame( 'a-propos', $fr['slug'] );
        $this->assertSame( 'a-propos', get_post_field( 'post_name', (int) $fr['contentId'] ) );

        $again = $this->tool( 'translate-content', [ 'contentId' => $page, 'language' => 'fr', 'slug' => 'autre' ] );
        $this->assertSame( 'a-propos', $again['slug'], 'an existing version keeps its own' );

        $pl = $this->translate( $page, 'pl' );
        $this->assertSame( '', $pl['slug'], 'WordPress derives it from the title at publish' );
    }

    public function test_a_second_call_returns_the_version_there_is(): void {
        $page  = $this->page();
        $first = $this->translate( $page, 'fr' );
        $again = $this->translate( $page, 'fr' );

        $this->assertFalse( $again['created'] );
        $this->assertSame( $first['contentId'], $again['contentId'] );
    }

    public function test_what_cannot_be_translated_is_refused(): void {
        $page = $this->page();
        $fr   = (int) $this->translate( $page, 'fr' )['contentId'];

        $not_original = $this->translate( $fr, 'pl' );
        $this->assert_refused( $not_original, 'not_an_original' );
        $this->assertSame( $page, (int) ( $not_original->get_error_data()['originalId'] ?? 0 ), 'the original is named' );
        $this->assert_refused( $this->translate( $page, 'en' ), 'fabrement_ct_same_language' );
        $this->assert_refused( ( new \Fabrement\Modules\Mcp\Ability\TranslateContentAbility() )->execute( [ 'contentId' => $page, 'language' => 'de' ] ), 'fabrement_ct_bad_language' );

        register_post_type( 'qa_shared', [ 'public' => true, 'label' => 'Shared' ] ); // not translatable in WPML
        try {
            $this->assert_refused( $this->translate( self::factory()->post->create( [ 'post_type' => 'qa_shared', 'post_status' => 'publish' ] ), 'fr' ), 'type_not_translated' );
        } finally {
            unregister_post_type( 'qa_shared' );
        }
    }

    public function test_the_versions_terms_are_their_versions_in_its_language(): void {
        $news  = self::factory()->category->create( [ 'name' => 'News' ] );
        $other = self::factory()->category->create( [ 'name' => 'Other' ] );
        $fr_news = $this->tool( 'translate-term', [ 'termId' => $news, 'language' => 'fr', 'name' => 'Actualités', 'slug' => 'actualites' ] );
        $this->assertIsArray( $fr_news, $fr_news instanceof WP_Error ? $fr_news->get_error_message() : '' );
        $post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        wp_set_object_terms( $post, [ $news, $other ], 'category' );

        $fr = (int) $this->translate( $post, 'fr' )['contentId'];

        $terms = Translation::bridge()->in_language( 'fr', static fn() => wp_get_object_terms( $fr, 'category', [ 'fields' => 'ids' ] ) );
        $fr_id = (int) apply_filters( 'wpml_object_id', $news, 'category', false, 'fr' );
        $this->assertGreaterThan( 0, $fr_id );
        $this->assertContains( $fr_id, array_map( 'intval', $terms ), 'the translated category' );
        $this->assertNotContains( $news, array_map( 'intval', $terms ), 'not the English one' );
    }

    // ── parents across languages (were imitated in tests/Wp/ContentParentTest) ────────────────

    public function test_translate_content_places_the_version_under_the_parents_version(): void {
        $root  = $this->page();
        $child = $this->page( [ 'post_parent' => $root, 'menu_order' => 5 ] );

        $first = $this->translate( $child, 'pl' );
        $this->assertTrue( $first['created'] );
        $this->assertSame( 0, $first['parent'], 'no Polish root yet: top level' );
        $this->assertStringContainsString( (string) $root, $first['note'] ?? '', 'the note names the record to translate' );
        $this->assertSame( 5, (int) get_post_field( 'menu_order', $first['contentId'] ) );

        $pl_root = $this->translate( $root, 'pl' );
        $second  = $this->page( [ 'post_parent' => $root ] );
        $version = $this->translate( $second, 'pl' );
        $this->assertSame( $pl_root['contentId'], $version['parent'] );
        $this->assertArrayNotHasKey( 'note', $version );
    }

    public function test_translate_content_with_a_trashed_or_deleted_parent(): void {
        $root  = $this->page();
        $child = $this->page( [ 'post_parent' => $root ] );
        wp_trash_post( $root );
        $out = $this->translate( $child, 'pl' );
        $this->assertSame( 0, $out['parent'] );
        $this->assertStringContainsString( 'trash', $out['note'] ?? '' );

        $root2  = $this->page();
        $child2 = $this->page( [ 'post_parent' => $root2 ] );
        $gone   = (int) $this->translate( $root2, 'pl' )['contentId'];
        wp_delete_post( $gone, true );
        $out = $this->translate( $child2, 'pl' );
        $this->assertSame( 0, $out['parent'] );
        $this->assertStringContainsString( 'no "pl" version', $out['note'] ?? '' );
    }

    public function test_a_parent_in_another_language_is_taken_in_the_records_language(): void {
        $en_root = $this->page();
        $pl_root = (int) $this->translate( $en_root, 'pl' )['contentId'];
        $pl_page = (int) Translation::bridge()->in_language( 'pl', fn() => $this->page() );
        Translation::bridge()->set_language( $pl_page, 'pl', 'page' );

        $out = ( new SaveContentAbility() )->execute( [ 'mode' => 'add', 'contentId' => $pl_page, 'parent' => $en_root ] );
        $this->assertSame( $pl_root, $out['parent'] ?? null, $out instanceof WP_Error ? $out->get_error_message() : '' );
        $this->assertSame( $pl_root, $this->parent_of( $pl_page ) );
    }

    public function test_a_parent_version_that_is_trashed_or_deleted_is_no_parent(): void {
        $en_root = $this->page();
        $pl_root = (int) $this->translate( $en_root, 'pl' )['contentId'];
        $pl_page = $this->page();
        Translation::bridge()->set_language( $pl_page, 'pl', 'page' );

        wp_trash_post( $pl_root );
        $this->assert_refused( ( new SaveContentAbility() )->execute( [ 'mode' => 'add', 'contentId' => $pl_page, 'parent' => $en_root ] ), 'parent_language' );
        wp_delete_post( $pl_root, true );
        $this->assert_refused( ( new SaveContentAbility() )->execute( [ 'mode' => 'add', 'contentId' => $pl_page, 'parent' => $en_root ] ), 'parent_language' );
        $this->assertSame( 0, $this->parent_of( $pl_page ) );
    }

    // ── the bridge on the real WPML (what FakeLanguages imitated) ────────────────────────────

    public function test_what_the_bridge_answers_about_a_group(): void {
        $bridge = Translation::bridge();
        $page   = $this->page();
        $fr     = (int) $this->translate( $page, 'fr' )['contentId'];

        $this->assertSame( [ 'en' => $page, 'fr' => $fr ], array_map( 'intval', $bridge->visible_versions( $page, 'page' ) ) );
        $this->assertTrue( $bridge->is_original( $page, 'page' ) );
        $this->assertFalse( $bridge->is_original( $fr, 'page' ) );
        $this->assertSame( $page, $bridge->original_of( $fr, 'page' ) );
        $this->assertSame( $fr, $bridge->version_in( $page, 'page', 'fr' ) );
        $this->assertSame( 0, $bridge->version_in( $page, 'page', 'pl' ), 'a named language with no version: 0' );
        $this->assertSame( 'fr', $bridge->version_facts( [ $fr ], 'page' )[ $fr ]['language'] ?? null );

        wp_trash_post( $fr );
        $this->assertArrayNotHasKey( $fr, $bridge->version_facts( [ $fr ], 'page' ), 'a trashed record has no language row to report' );
        $this->assertArrayHasKey( 'fr', $bridge->visible_versions( $page, 'page' ), 'but the group still names it' );
    }

    // ── translate-term, translate-synced-pattern ────────────────────────────────────────────

    public function test_translate_term_links_a_version_with_its_own_address(): void {
        $news = self::factory()->category->create( [ 'name' => 'News', 'slug' => 'news' ] );
        $out  = $this->tool( 'translate-term', [ 'termId' => $news, 'language' => 'fr', 'name' => 'Actualités', 'slug' => 'actualites' ] );
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );

        $fr = (int) apply_filters( 'wpml_object_id', $news, 'category', false, 'fr' );
        $this->assertGreaterThan( 0, $fr );
        $this->assertNotSame( $news, $fr );
        $this->assertSame( 'actualites', get_term( $fr )->slug );
        $this->assertSame( 'fr', Translation::bridge()->term_language( $fr, 'category' ) );
    }

    public function test_translate_synced_pattern_makes_a_published_linked_version(): void {
        $pattern = self::factory()->post->create( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Clients', 'post_content' => '<!-- wp:paragraph --><p>Our clients</p><!-- /wp:paragraph -->' ] );
        $out     = $this->tool( 'translate-synced-pattern', [ 'patternId' => $pattern, 'language' => 'fr' ] );
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );
        $fr = (int) $out['patternId'];

        $this->assertSame( $fr, (int) apply_filters( 'wpml_object_id', $pattern, 'wp_block', false, 'fr' ) );
        $this->assertSame( 'publish', get_post_status( $fr ), 'a draft version would empty that section on every French page' );
    }
}
