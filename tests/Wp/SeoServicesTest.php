<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Modules\Seo\Content\ContentImages;
use Fabrement\Modules\Seo\SiteQueryVars;
use WP_UnitTestCase;

/**
 * The SEO services that do not depend on an SEO plugin — what an integration (Yoast today) hands
 * over: images SEO plugins cannot see (inside global blocks) and the site's own query args. Real WordPress; the Yoast wiring itself is YoastIntegrationTest.
 */
final class SeoServicesTest extends WP_UnitTestCase {

    use FakeLanguages;

    private array $images = [];

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        ContentImages::flush();
        \Fabrement\Modules\Seo\Content\ContentBlocks::flush();
        update_option( 'date_format', 'j F Y' );
        update_option( 'time_format', 'H:i' );
        update_option( 'timezone_string', 'Europe/Kyiv' );
        for ( $i = 0; $i < 5; $i++ ) {
            $this->images[] = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
        }
        register_post_type( 'qa_course', [ 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'label' => 'Courses' ] );
    }

    public function tear_down(): void {
        unregister_post_type( 'qa_course' );
        parent::tear_down();
    }

    /** A core image block, as the editor saves it. */
    private function image( int $id ): string {
        return '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="x.jpg" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
    }

    // ── ContentImages ────────────────────────────────────────────────────────────────────────

    public function test_images_from_global_blocks_first_seen_first_without_repeats(): void {
        [ $a, $b, $c, , $e ] = $this->images;
        $first  = $this->pattern( $this->image( $a ) . $this->image( $b ) . $this->image( $a ) );
        $second = $this->pattern( $this->image( $c ) );
        $inner  = '<!-- wp:group --><div class="wp-block-group">' . $this->place( $second ) . '</div><!-- /wp:group -->';
        $post   = self::factory()->post->create( [ 'post_type' => 'qa_course', 'post_content' => $this->place( $first ) . $inner . $this->image( $e ) ] );

        // A page view asks for its first image, then the sitemap for all, in one request.
        $this->assertSame( [ $a ], ContentImages::of_post( $post, 1 ) );
        $this->assertSame( [ $a, $b, $c ], ContentImages::of_post( $post ), 'blocks in order (nested included); repeats once' );
        $this->assertNotContains( $e, ContentImages::of_post( $post ), 'the record\'s own core images are left to the SEO plugin, which reads them itself' );
    }

    public function test_images_inside_the_global_blocks_the_page_shows(): void {
        [ $a, $b, $c, $d, $e ] = $this->images;
        $inner  = $this->pattern( $this->image( $c ) );
        $outer  = $this->pattern( '' );
        wp_update_post( [ 'ID' => $outer, 'post_content' => $this->image( $a ) . $this->image( $e ) . $this->place( $inner ) . $this->place( $outer ) ] );
        $draft  = $this->pattern( $this->image($d ), [ 'post_status' => 'draft' ] );
        $locked = $this->pattern( $this->image($b ), [ 'post_password' => 'x' ] );
        $page   = self::factory()->post->create( [
            'post_content' => $this->place( $outer ) . $this->place( $draft ) . $this->place( $locked )
                . '<!-- wp:group --><div class="wp-block-group"><!-- wp:core/block {"ref":' . $inner . '} /--></div><!-- /wp:group -->',
        ] );

        $this->assertSame( [ $a, $e, $c ], ContentImages::of_post( $page ), 'pattern images in order; not a draft or locked one' );
        $this->assertCount( 4, $this->walked( $page ), 'outer (a, e), inner (c), inner again from the group (wp:core/block) — outer inside itself is not walked again' );

        $quoted = self::factory()->post->create( [ 'post_content' => '<!-- wp:block {"ref":"' . $inner . '"} /-->' ] );
        $this->assertSame( [ $c ], ContentImages::of_post( $quoted ), 'a ref written as quoted digits' );

        $edited = $this->pattern( $this->image($c ) );
        $placer = self::factory()->post->create( [ 'post_content' => $this->place( $edited ) ] );
        $this->assertSame( [ $c ], ContentImages::of_post( $placer ) );
        wp_update_post( [ 'ID' => $edited, 'post_content' => $this->image($d ) ] );
        $this->assertSame( [ $d ], ContentImages::of_post( $placer ), 'a global block saved in the same request: the pages placing it read it afresh' );

        // A inside B inside A: the patterns open above are passed down, so the loop stops at once.
        $loop_a = $this->pattern( '' );
        $loop_b = $this->pattern( $this->image($b ) . $this->place( $loop_a ) );
        wp_update_post( [ 'ID' => $loop_a, 'post_content' => $this->image($a ) . $this->place( $loop_b ) ] );
        $looped = self::factory()->post->create( [ 'post_content' => $this->place( $loop_a ) ] );
        $this->assertCount( 2, $this->walked( $looped ) );

        // Six patterns deep: the fifth is read, the sixth is not.
        $chain = $this->pattern( $this->image($e ) );
        $chain = $this->pattern( $this->image($d ) . $this->place( $chain ) );
        for ( $i = 0; $i < 4; $i++ ) {
            $chain = $this->pattern( $this->place( $chain ) );
        }
        $deep = self::factory()->post->create( [ 'post_content' => $this->place( $chain ) ] );
        $this->assertSame( [ $d ], ContentImages::of_post( $deep ) );
    }

    /** @return list<array> every core image the record's global blocks show, repeats included */
    private function walked( int $post ): array {
        return \Fabrement\Modules\Seo\Content\ContentBlocks::with_pattern_images( parse_blocks( (string) get_post_field( 'post_content', $post ) ), $post );
    }

    private function pattern( string $content, array $args = [] ): int {
        return self::factory()->post->create( $args + [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'QA pattern', 'post_content' => $content ] );
    }

    private function place( int $pattern ): string {
        return '<!-- wp:block {"ref":' . $pattern . '} /-->';
    }

    public function test_images_are_read_again_once_the_record_changes(): void {
        [ , $b ] = $this->images;
        $post = self::factory()->post->create( [ 'post_type' => 'qa_course' ] );
        $this->assertSame( [], ContentImages::of_post( $post ) );

        wp_update_post( [ 'ID' => $post, 'post_content' => $this->place( $this->pattern( $this->image( $b ) ) ) ] );
        $this->assertSame( [ $b ], ContentImages::of_post( $post ), 'content saved in the same request' );
    }

    public function test_a_record_is_read_once(): void {
        [ $a ] = $this->images;
        $post = self::factory()->post->create( [ 'post_type' => 'qa_course', 'post_content' => $this->place( $this->pattern( $this->image( $a ) ) ) ] );

        // Every read of the record's meta passes this filter, cached or not.
        $reads = 0;
        $spy   = static function ( $value, $object_id ) use ( &$reads, $post ) {
            if ( (int) $object_id === $post ) {
                ++$reads;
            }
            return $value;
        };
        add_filter( 'get_post_metadata', $spy, 10, 2 );

        ContentImages::flush();
        ContentImages::of_post( $post, 1 );
        $first = $reads;
        ContentImages::of_post( $post );
        remove_filter( 'get_post_metadata', $spy, 10 );
        $this->assertSame( $first, $reads, 'the second ask is answered from memory' );
    }

    public function test_an_image_block_naming_a_file_that_is_not_an_image_is_skipped(): void {
        $pdf  = self::factory()->attachment->create( [ 'post_mime_type' => 'application/pdf', 'post_title' => 'Doc' ] );
        $post = self::factory()->post->create( [ 'post_content' => $this->place( $this->pattern( $this->image( $pdf ) ) ) ] );

        $this->assertSame( [], ContentImages::of_post( $post ) );
    }

    // ── SiteQueryVars ────────────────────────────────────────────────────────────────────────

    public function test_query_vars_the_site_added_not_wordpress_own(): void {
        add_filter( 'query_vars', static fn( array $vars ): array => array_merge( $vars, [ 'year_filter' ] ) );
        register_taxonomy( 'qa_level', 'qa_course', [ 'public' => true, 'query_var' => 'level' ] );

        $added = SiteQueryVars::added();

        $this->assertContains( 'year_filter', $added );
        $this->assertContains( 'level', $added );
        $this->assertNotContains( 'p', $added );
        $this->assertNotContains( 's', $added );
    }
}
