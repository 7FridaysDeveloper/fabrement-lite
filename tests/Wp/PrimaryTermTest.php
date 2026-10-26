<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Modules\Seo\Integration\YoastPrimaryTerm;
use Fabrement\Modules\Seo\PrimaryTerm;
use Fabrement\Modules\Seo\TermChanges;
use WP_UnitTestCase;

/**
 * A record's main term follows the terms Fabrement's tools change (set-post-terms, delete-term):
 * they announce it (TermChanges), and the SEO plugin's integration drops a main term the record no
 * longer holds. Changes made elsewhere (editor, WP-CLI, imports) are the SEO plugin's business.
 * Yoast's own tables are YoastIntegrationTest; this is the meta.
 *
 * Runs in both suites — `FABREMENT_TEST_PLUGINS=none` is the site without Yoast, where nothing
 * listens and nothing of Yoast's is touched.
 */
final class PrimaryTermTest extends WP_UnitTestCase {

    private const META = '_yoast_wpseo_primary_category';

    private int $parent;
    private int $child;
    private int $post;

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->parent = self::factory()->category->create( [ 'name' => 'QA Parent' ] );
        $this->child  = self::factory()->category->create( [ 'name' => 'QA Child', 'parent' => $this->parent ] );
        $this->post   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        wp_set_object_terms( $this->post, [ $this->parent, $this->child ], 'category' );
        // Chosen the way Yoast's editor stores it, so the test does not need Yoast to set it.
        update_post_meta( $this->post, self::META, (string) $this->parent );
    }

    public function tear_down(): void {
        YoastPrimaryTerm::sync(); // what the end of the request does; nothing may leak into the next test
        parent::tear_down();
    }

    private function needs_yoast(): void {
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Yoast SEO is not loaded in this run.' );
        }
    }

    private function tool( array $input ) {
        return wp_get_ability( 'fabrement/set-post-terms' )->execute( $input + [ 'contentId' => $this->post, 'taxonomy' => 'category' ] );
    }

    public function test_set_post_terms_remove_drops_the_main_term_it_takes_off(): void {
        $this->needs_yoast();
        $out = $this->tool( [ 'terms' => [ $this->parent ], 'mode' => 'remove' ] );

        $this->assertFalse( is_wp_error( $out ), is_wp_error( $out ) ? $out->get_error_message() : '' );
        $this->assertSame( '', get_post_meta( $this->post, self::META, true ) );
        $this->assertSame( 0, PrimaryTerm::get( $this->post, 'category' ) );
    }

    public function test_set_post_terms_replace_without_it_drops_it(): void {
        $this->needs_yoast();
        $this->tool( [ 'terms' => [ $this->child ], 'mode' => 'replace' ] );

        $this->assertSame( '', get_post_meta( $this->post, self::META, true ) );
    }

    public function test_delete_term_drops_the_main_term_it_deletes(): void {
        $this->needs_yoast();
        update_post_meta( $this->post, self::META, (string) $this->child );
        $out = wp_get_ability( 'fabrement/delete-term' )->execute( [ 'taxonomy' => 'category', 'term' => $this->child ] );

        $this->assertFalse( is_wp_error( $out ), is_wp_error( $out ) ? $out->get_error_message() : '' );
        $this->assertSame( '', get_post_meta( $this->post, self::META, true ) );
    }

    public function test_changes_made_elsewhere_are_the_seo_plugins_business(): void {
        $this->needs_yoast();
        wp_remove_object_terms( $this->post, [ $this->parent ], 'category' ); // the editor, WP-CLI, an import, another plugin
        $this->assertSame( (string) $this->parent, get_post_meta( $this->post, self::META, true ), 'not corrected by Fabrement' );

        update_post_meta( $this->post, self::META, (string) $this->child );
        wp_delete_term( $this->child, 'category' );
        $this->assertSame( (string) $this->child, get_post_meta( $this->post, self::META, true ), 'a term deleted outside Fabrement neither' );
    }

    public function test_a_main_term_the_record_still_holds_is_left_alone(): void {
        $this->needs_yoast();
        $this->tool( [ 'terms' => [ $this->child ], 'mode' => 'remove' ] );
        $this->tool( [ 'terms' => [ self::factory()->category->create() ], 'mode' => 'add' ] );

        $this->assertSame( (string) $this->parent, get_post_meta( $this->post, self::META, true ) );
        $this->assertSame( $this->parent, PrimaryTerm::get( $this->post, 'category' ) );
    }


    public function test_without_yoast_a_main_term_cannot_be_chosen_and_nothing_is_written(): void {
        if ( defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Yoast SEO is loaded in this run.' );
        }
        $out = $this->tool( [ 'terms' => [ $this->child ], 'mode' => 'replace', 'primary' => $this->child ] );

        $this->assertSame( 'yoast_inactive', $out->get_error_code() );
        $this->assertEqualsCanonicalizing( [ $this->parent, $this->child ], wp_get_object_terms( $this->post, 'category', [ 'fields' => 'ids' ] ), 'terms untouched' );
    }

    public function test_without_yoast_nothing_of_yoasts_is_touched(): void {
        if ( defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Yoast SEO is loaded in this run.' );
        }
        $this->tool( [ 'terms' => [ $this->parent ], 'mode' => 'remove' ] );

        $this->assertSame( (string) $this->parent, get_post_meta( $this->post, self::META, true ), 'while Yoast is off, its data is left as it is' );
        $this->assertSame( [], PrimaryTerm::taxonomies( 'post' ), 'no plugin, no main terms' );
        $this->assertSame( 0, PrimaryTerm::get( $this->post, 'category' ) );
    }

    public function test_only_fabrements_own_changes_are_announced(): void {
        $seen = [];
        $spy  = static function ( $post_id, $taxonomy ) use ( &$seen ): void {
            $seen[] = [ $post_id, $taxonomy ];
        };
        add_action( TermChanges::ACTION, $spy, 10, 2 );
        try {
            wp_remove_object_terms( $this->post, [ $this->child ], 'category' );
            wp_update_post( [ 'ID' => $this->post, 'post_title' => 'Renamed' ] );
            $this->assertSame( [], $seen, 'changes made elsewhere are not announced' );

            $this->tool( [ 'terms' => [ $this->child ], 'mode' => 'add' ] );
            $this->assertSame( [ [ $this->post, 'category' ] ], $seen, 'set-post-terms: once' );

            $other = self::factory()->post->create( [ 'post_status' => 'publish' ] );
            wp_set_object_terms( $other, [ $this->child ], 'category' );
            $seen = [];
            wp_get_ability( 'fabrement/delete-term' )->execute( [ 'taxonomy' => 'category', 'term' => $this->child ] );
            $this->assertEqualsCanonicalizing( [ [ $this->post, 'category' ], [ $other, 'category' ] ], $seen, 'delete-term: every record the term leaves' );
        } finally {
            remove_action( TermChanges::ACTION, $spy, 10 );
        }
    }
}
