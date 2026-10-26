<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Seo\Content\AnalysisText;
use Fabrement\Modules\Seo\Content\ContentImages;
use Fabrement\Modules\Seo\Content\FieldText;
use Fabrement\Modules\Seo\SiteQueryVars;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The SEO services that do not depend on an SEO plugin — what an integration (Yoast today) hands
 * over: images SEO plugins cannot see, fields as words, the site's own query args, the rendered
 * text of Fabrement blocks. Real WordPress; the Yoast wiring itself is YoastIntegrationTest.
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
        register_post_type( 'qa_teacher', [ 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'label' => 'Teachers' ] );
        $f = static fn( string $name, string $type, array $extra = [] ): array => $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
        $saved = wp_get_ability( 'fabrement/save-fields' )->execute( [ 'type' => 'post', 'slug' => 'qa_course', 'expectedRevision' => 0, 'fields' => [
            $f( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => 'For beginners' ], [ 'value' => 'pro', 'label' => 'For pros' ] ], 'default' => 'beginner' ] ),
            $f( 'price', 'number', [ 'format' => 'decimal' ] ),
            $f( 'day', 'date_picker' ),
            $f( 'starts', 'date_time_picker' ),
            $f( 'photo', 'image' ),
            $f( 'photos', 'gallery' ),
            $f( 'teacher', 'post_object', [ 'postTypes' => [ 'qa_teacher' ] ] ),
            $f( 'speakers', 'relationship', [ 'postTypes' => [ 'qa_teacher' ] ] ),
            $f( 'venue', 'group', [ 'sub_fields' => [ $f( 'city', 'text' ), $f( 'map', 'image' ), $f( 'slots', 'repeater', [ 'sub_fields' => [ $f( 'time', 'text' ) ] ] ) ] ] ),
            $f( 'modules', 'repeater', [ 'sub_fields' => [ $f( 'title', 'text' ), $f( 'cover', 'image' ) ] ] ),
            $f( 'free', 'true_false' ),
        ] ] );
        $this->assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
    }

    public function tear_down(): void {
        SchemaRepository::delete( Owner::of( 'post', 'qa_course' ) );
        foreach ( [ 'qa-site', 'qa-seo' ] as $page ) {
            if ( $owner = Owner::of( 'options', $page ) ) {
                SchemaRepository::delete( $owner );
            }
        }
        // Field values are written in a transaction of their own, which commits past the test's.
        delete_option( 'fabrement_options_qa-seo' );
        SchemaRepository::flush();
        unregister_post_type( 'qa_course' );
        unregister_post_type( 'qa_teacher' );
        parent::tear_down();
    }

    private function media( int $id ): array {
        return [ 'id' => $id, 'url' => (string) wp_get_attachment_url( $id ) ];
    }

    // ── ContentImages ────────────────────────────────────────────────────────────────────────

    public function test_images_from_blocks_then_fields_first_seen_first_without_repeats(): void {
        [ $a, $b, $c, $d, $e ] = $this->images;
        $block = '<!-- wp:fabrement/hero ' . wp_json_encode( [ 'bg' => $this->media( $a ), 'cards' => [ [ 'pic' => $this->media( $b ) ], [ 'pic' => $this->media( $a ) ] ] ] ) . ' /-->';
        $inner = '<!-- wp:group --><div class="wp-block-group"><!-- wp:fabrement/card ' . wp_json_encode( [ 'img' => $this->media( $c ) ] ) . ' /--></div><!-- /wp:group -->';
        $core  = '<!-- wp:image {"id":' . $e . '} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->';
        $post  = self::factory()->post->create( [ 'post_type' => 'qa_course', 'post_content' => $block . $inner . $core ] );
        fabrement()->fields( $post )->set( 'photo', $d )->save();
        fabrement()->fields( $post )->set( 'modules', [ [ 'title' => 'One', 'cover' => $b ] ] )->save();

        // A page view asks for its first image, then the sitemap for all, in one request.
        $this->assertSame( [ $a ], ContentImages::of_post( $post, 1 ) );
        $this->assertSame( [ $a, $b, $c, $d ], ContentImages::of_post( $post ), 'blocks in order (nested included), then fields; repeats once' );
        $this->assertNotContains( $e, ContentImages::of_post( $post ), 'core image blocks are left to the SEO plugin, which reads them itself' );
    }

    public function test_images_inside_the_global_blocks_the_page_shows(): void {
        [ $a, $b, $c, $d, $e ] = $this->images;
        $card   = fn( int $id ): string => '<!-- wp:fabrement/card ' . wp_json_encode( [ 'img' => $this->media( $id ) ] ) . ' /-->';
        $inner  = $this->pattern( $card( $c ) );
        $outer  = $this->pattern( '' );
        $core   = '<!-- wp:image {"id":' . $e . '} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->';
        wp_update_post( [ 'ID' => $outer, 'post_content' => $card( $a ) . $core . $this->place( $inner ) . $this->place( $outer ) ] );
        $draft  = $this->pattern( $card( $d ), [ 'post_status' => 'draft' ] );
        $locked = $this->pattern( $card( $b ), [ 'post_password' => 'x' ] );
        $page   = self::factory()->post->create( [
            'post_content' => $this->place( $outer ) . $this->place( $draft ) . $this->place( $locked )
                . '<!-- wp:group --><div class="wp-block-group"><!-- wp:core/block {"ref":' . $inner . '} /--></div><!-- /wp:group -->',
        ] );

        $this->assertSame( [ $a, $e, $c ], ContentImages::of_post( $page ), 'pattern images, including core images; not a draft or locked one' );
        $this->assertContains( $e, ContentImages::of_post( $page ), 'a referenced pattern\'s core image is absent from the page\'s own saved HTML, so Fabrement supplies it' );
        $this->assertCount( 3, $this->walked( $page ), 'outer (a), inner (c), inner again from the group (wp:core/block) — outer inside itself is not walked again' );

        $quoted = self::factory()->post->create( [ 'post_content' => '<!-- wp:block {"ref":"' . $inner . '"} /-->' ] );
        $this->assertSame( [ $c ], ContentImages::of_post( $quoted ), 'a ref written as quoted digits' );

        $edited = $this->pattern( $card( $c ) );
        $placer = self::factory()->post->create( [ 'post_content' => $this->place( $edited ) ] );
        $this->assertSame( [ $c ], ContentImages::of_post( $placer ) );
        wp_update_post( [ 'ID' => $edited, 'post_content' => $card( $d ) ] );
        $this->assertSame( [ $d ], ContentImages::of_post( $placer ), 'a global block saved in the same request: the pages placing it read it afresh' );

        // A inside B inside A: the patterns open above are passed down, so the loop stops at once.
        $loop_a = $this->pattern( '' );
        $loop_b = $this->pattern( $card( $b ) . $this->place( $loop_a ) );
        wp_update_post( [ 'ID' => $loop_a, 'post_content' => $card( $a ) . $this->place( $loop_b ) ] );
        $looped = self::factory()->post->create( [ 'post_content' => $this->place( $loop_a ) ] );
        $this->assertCount( 2, $this->walked( $looped ) );

        // Six patterns deep: the fifth is read, the sixth is not.
        $chain = $this->pattern( $card( $e ) );
        $chain = $this->pattern( $card( $d ) . $this->place( $chain ) );
        for ( $i = 0; $i < 4; $i++ ) {
            $chain = $this->pattern( $this->place( $chain ) );
        }
        $deep = self::factory()->post->create( [ 'post_content' => $this->place( $chain ) ] );
        $this->assertSame( [ $d ], ContentImages::of_post( $deep ) );

        // A pattern among a Fabrement block's inner blocks.
        $wrapped = self::factory()->post->create( [ 'post_content' => '<!-- wp:fabrement/wrap --><div>' . $this->place( $inner ) . '</div><!-- /wp:fabrement/wrap -->' ] );
        $this->assertSame( [ $c ], ContentImages::of_post( $wrapped ) );
    }

    /** @return list<array> every Fabrement block the record shows */
    private function walked( int $post ): array {
        return \Fabrement\Modules\Seo\Content\ContentBlocks::all( parse_blocks( (string) get_post_field( 'post_content', $post ) ), $post );
    }

    private function pattern( string $content, array $args = [] ): int {
        return self::factory()->post->create( $args + [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'QA pattern', 'post_content' => $content ] );
    }

    private function place( int $pattern ): string {
        return '<!-- wp:block {"ref":' . $pattern . '} /-->';
    }

    public function test_images_from_every_field_shape_and_nothing_behind_a_password(): void {
        [ $a, $b, $c, $d ] = $this->images;
        $post = self::factory()->post->create( [ 'post_type' => 'qa_course' ] );
        fabrement()->fields( $post )->set( 'photos', [ $a, $b ] )->save();
        fabrement()->fields( $post )->set( 'venue', [ 'city' => 'Lviv', 'map' => $c ] )->save();
        fabrement()->fields( $post )->set( 'modules', [ [ 'title' => 'One', 'cover' => $d ], [ 'title' => 'Two', 'cover' => $this->images[4] ] ] )->save();
        $this->assertSame( [ $a, $b, $c, $d, $this->images[4] ], ContentImages::of_post( $post ), 'field order: gallery, group, every repeater row' );

        wp_update_post( [ 'ID' => $post, 'post_password' => 'x' ] );
        $this->assertSame( [], ContentImages::of_post( $post ) );
    }

    public function test_images_are_read_again_once_the_record_or_a_field_changes(): void {
        [ $a, $b ] = $this->images;
        $post = self::factory()->post->create( [ 'post_type' => 'qa_course' ] );
        $this->assertSame( [], ContentImages::of_post( $post ) );

        fabrement()->fields( $post )->set( 'photo', $a )->save();
        $this->assertSame( [ $a ], ContentImages::of_post( $post ), 'a field written in the same request' );

        wp_update_post( [ 'ID' => $post, 'post_content' => '<!-- wp:fabrement/hero ' . wp_json_encode( [ 'bg' => $this->media( $b ) ] ) . ' /-->' ] );
        $this->assertSame( [ $b, $a ], ContentImages::of_post( $post ), 'content saved in the same request' );
    }

    public function test_a_record_is_read_once_and_text_fields_cost_nothing(): void {
        [ $a ] = $this->images;
        $post = self::factory()->post->create( [ 'post_type' => 'qa_course', 'post_content' => '<!-- wp:fabrement/hero ' . wp_json_encode( [ 'bg' => $this->media( $a ) ] ) . ' /-->' ] );
        fabrement()->fields( $post )->set( 'venue', [ 'city' => 'Lviv' ] )->save();
        fabrement()->fields( $post )->set( 'price', 10 )->save();

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
        $this->assertSame( $first, $reads, 'the second ask is answered from memory' );

        // 11 fields, 4 can hold a picture (photo, photos, venue, modules).
        $all = 0;
        foreach ( \Fabrement\Modules\Fields\Values\FieldObject::post( $post )->all_fields() as $field ) {
            $before = $reads;
            \Fabrement\Modules\Fields\Values\FieldReader::object( (string) $field['name'], $post );
            $all += $reads - $before;
        }
        remove_filter( 'get_post_metadata', $spy, 10 );
        $this->assertGreaterThan( 0, $first );
        $this->assertLessThan( $all, $first, 'text, number, date and choice fields are not read for pictures' );
    }

    public function test_a_media_pair_that_is_not_an_image_is_skipped(): void {
        $pdf  = self::factory()->attachment->create( [ 'post_mime_type' => 'application/pdf', 'post_title' => 'Doc' ] );
        $post = self::factory()->post->create( [ 'post_content' => '<!-- wp:fabrement/doc ' . wp_json_encode( [ 'file' => [ 'id' => $pdf, 'url' => 'https://example.org/doc.pdf' ] ] ) . ' /-->' ] );

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

    // ── FieldText ────────────────────────────────────────────────────────────────────────────

    public function test_variable_names_come_from_the_field_files(): void {
        wp_get_ability( 'fabrement/save-options-page' )->execute( [ 'slug' => 'qa-site', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA' ], 'fields' => [
            [ 'name' => 'phone', 'label' => 'Phone', 'description' => 'Phone', 'type' => 'text' ],
        ] ] );
        $names = array_keys( FieldText::names() );

        foreach ( [ 'fab_level', 'fab_price', 'fab_starts', 'fab_teacher', 'fab_speakers', 'fab_venue_city', 'fab_venue_slots', 'fab_modules', 'fabopt_qa-site__phone' ] as $name ) {
            $this->assertContains( $name, $names, $name );
        }
        foreach ( [ 'fab_photo', 'fab_photos', 'fab_free', 'fab_venue_map', 'fab_modules_count', 'fab_modules_title' ] as $name ) {
            $this->assertNotContains( $name, $names, $name . ' has no words for a title' );
        }
    }

    public function test_fields_as_a_reader_reads_them(): void {
        $olena = self::factory()->post->create( [ 'post_type' => 'qa_teacher', 'post_title' => 'Olena', 'post_status' => 'publish' ] );
        $ivan  = self::factory()->post->create( [ 'post_type' => 'qa_teacher', 'post_title' => 'Ivan', 'post_status' => 'publish' ] );
        $maria = self::factory()->post->create( [ 'post_type' => 'qa_teacher', 'post_title' => 'Maria', 'post_status' => 'publish' ] );
        $petro = self::factory()->post->create( [ 'post_type' => 'qa_teacher', 'post_title' => 'Petro', 'post_status' => 'publish' ] );
        $draft = self::factory()->post->create( [ 'post_type' => 'qa_teacher', 'post_title' => 'Draft', 'post_status' => 'draft' ] );
        $post  = self::factory()->post->create( [ 'post_type' => 'qa_course', 'post_status' => 'publish' ] );
        fabrement()->fields( $post )->set( 'price', 1250.5 )->save();
        fabrement()->fields( $post )->set( 'day', '2026-07-15' )->save();
        fabrement()->fields( $post )->set( 'starts', '2026-07-15T18:00' )->save();
        fabrement()->fields( $post )->set( 'teacher', $olena )->save();
        fabrement()->fields( $post )->set( 'speakers', [ $olena, $draft, $ivan, $maria, $petro ] )->save();
        fabrement()->fields( $post )->set( 'venue', [ 'city' => 'Lviv', 'slots' => [ [ 'time' => '10:00' ], [ 'time' => '12:00' ], [ 'time' => '14:00' ] ] ] )->save();
        fabrement()->fields( $post )->set( 'modules', [ [ 'title' => 'A' ], [ 'title' => 'B' ] ] )->save();

        $this->assertSame( 'For beginners', FieldText::for_variable( 'fab_level', $post ), 'never set: the default, as its label' );
        $this->assertSame( number_format_i18n( 1250.5, 1 ), FieldText::for_variable( 'fab_price', $post ) );
        $this->assertSame( '15 July 2026', FieldText::for_variable( 'fab_day', $post ) );
        $this->assertSame( '15 July 2026 18:00', FieldText::for_variable( 'fab_starts', $post ) );
        $this->assertSame( 'Olena', FieldText::for_variable( 'fab_teacher', $post ) );
        $this->assertSame( 'Olena, Ivan, Maria…', FieldText::for_variable( 'fab_speakers', $post ), 'drafts skipped, three named' );
        $this->assertSame( 'Lviv', FieldText::for_variable( 'fab_venue_city', $post ) );
        $this->assertSame( '2', FieldText::for_variable( 'fab_modules', $post ) );
        $this->assertSame( '3', FieldText::for_variable( 'fab_venue_slots', $post ), 'a repeater inside a group counts its rows too' );

        fabrement()->fields( $post )->set( 'teacher', $draft )->save();
        $this->assertNull( FieldText::for_variable( 'fab_teacher', $post ), 'a draft is not named' );
        $this->assertNull( FieldText::for_variable( 'fab_nope', $post ) );
        $this->assertNull( FieldText::for_variable( 'fab_level', 0 ), 'no record: no field' );

        wp_update_post( [ 'ID' => $post, 'post_password' => 'x' ] );
        $this->assertNull( FieldText::for_variable( 'fab_venue_city', $post ), 'behind the password' );
    }

    public function test_empty_fields_give_no_words(): void {
        $post = self::factory()->post->create( [ 'post_type' => 'qa_course', 'post_status' => 'publish' ] );
        foreach ( [ 'fab_teacher', 'fab_speakers', 'fab_modules', 'fab_venue_city', 'fab_price', 'fab_day' ] as $name ) {
            $this->assertNull( FieldText::for_variable( $name, $post ), $name . ' never set' );
        }

        fabrement()->fields( $post )->set( 'modules', [] )->save();
        fabrement()->fields( $post )->set( 'speakers', [] )->save();
        fabrement()->fields( $post )->set( 'venue', [ 'city' => '' ] )->save();
        $this->assertNull( FieldText::for_variable( 'fab_modules', $post ), 'no rows: dropped, not "0"' );
        $this->assertNull( FieldText::for_variable( 'fab_speakers', $post ) );
        $this->assertNull( FieldText::for_variable( 'fab_venue_city', $post ) );
    }

    public function test_picked_records_named_only_while_published(): void {
        $t = fn( string $title, string $status = 'publish' ): int => self::factory()->post->create( [ 'post_type' => 'qa_teacher', 'post_title' => $title, 'post_status' => $status ] );
        [ $olena, $ivan, $maria ] = [ $t( 'Olena' ), $t( 'Ivan' ), $t( 'Maria' ) ];
        $draft = $t( 'Draft', 'draft' );
        $post  = self::factory()->post->create( [ 'post_type' => 'qa_course', 'post_status' => 'publish' ] );

        fabrement()->fields( $post )->set( 'speakers', [ $olena, $ivan, $maria ] )->save();
        $this->assertSame( 'Olena, Ivan, Maria', FieldText::for_variable( 'fab_speakers', $post ), 'exactly three: no "…"' );
        fabrement()->fields( $post )->set( 'speakers', [ $olena, $draft, $ivan, $maria ] )->save();
        $this->assertSame( 'Olena, Ivan, Maria', FieldText::for_variable( 'fab_speakers', $post ), 'four picked, three public: no "…"' );

        fabrement()->fields( $post )->set( 'teacher', $ivan )->save();
        wp_trash_post( $ivan );
        $this->assertNull( FieldText::for_variable( 'fab_teacher', $post ), 'trashed' );
        $this->assertSame( 'Olena, Maria', FieldText::for_variable( 'fab_speakers', $post ) );
        wp_delete_post( $olena, true );
        $this->assertSame( 'Maria', FieldText::for_variable( 'fab_speakers', $post ), 'deleted' );
    }

    public function test_options_page_variables_read_the_page_not_the_record(): void {
        $phone = [ 'name' => 'phone', 'label' => 'Phone', 'description' => 'Phone', 'type' => 'text' ];
        $save  = wp_get_ability( 'fabrement/save-options-page' );
        $first = $save->execute( [ 'slug' => 'qa-seo', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA' ], 'fields' => [
            $phone,
            [ 'name' => 'old', 'label' => 'Old', 'description' => 'Old', 'type' => 'text' ],
        ] ] );
        $this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
        $this->assertArrayHasKey( 'fabopt_qa-seo__old', FieldText::names() );
        $second = $save->execute( [ 'slug' => 'qa-seo', 'expectedRevision' => (int) $first['revision'], 'page' => [ 'title' => 'QA' ], 'fields' => [ $phone ] ] );
        $this->assertIsArray( $second, is_wp_error( $second ) ? $second->get_error_message() : '' );

        $this->assertNull( FieldText::for_variable( 'fabopt_qa-seo__phone', 0 ), 'not filled yet' );
        fabrement()->options( 'qa-seo' )->set( 'phone', '+380 44 000 00 00' )->save();

        $this->assertSame( '+380 44 000 00 00', FieldText::for_variable( 'fabopt_qa-seo__phone', 0 ), 'a page with no record' );
        $post = self::factory()->post->create( [ 'post_password' => 'x' ] );
        $this->assertSame( '+380 44 000 00 00', FieldText::for_variable( 'fabopt_qa-seo__phone', $post ), 'site-wide words are not behind a record password' );
        $this->assertArrayNotHasKey( 'fabopt_qa-seo__old', FieldText::names(), 'a removed field has no variable' );
    }

    // ── AnalysisText ─────────────────────────────────────────────────────────────────────────

    public function test_the_rendered_text_of_fabrement_blocks_only(): void {
        register_block_type( 'fabrement/qa-probe', [
            'render_callback' => static fn( array $attrs ): string => '<h2>' . esc_html( $attrs['title'] ?? '' ) . ' — ' . esc_html( get_the_title() ) . '</h2>',
        ] );
        register_block_type( 'fabrement/qa-wrap', [ 'render_callback' => static fn( array $attrs, string $content ): string => $content ] );
        try {
            $this->assert_rendered_text();
        } finally {
            unregister_block_type( 'fabrement/qa-probe' );
            unregister_block_type( 'fabrement/qa-wrap' );
        }
    }

    private function assert_rendered_text(): void {
        $post    = self::factory()->post->create( [ 'post_title' => 'The page' ] );
        $content = '<!-- wp:paragraph --><p>Core words</p><!-- /wp:paragraph -->'
            . '<!-- wp:fabrement/qa-probe {"title":"First"} /-->'
            . '<!-- wp:group --><div class="wp-block-group"><!-- wp:fabrement/qa-probe {"title":"Nested"} /--></div><!-- /wp:group -->';

        $html = AnalysisText::render( $content, $post );

        $this->assertStringContainsString( '<h2>First — The page</h2>', $html, 'rendered for THAT record' );
        $this->assertStringContainsString( '<h2>Nested — The page</h2>', $html );
        $this->assertStringNotContainsString( 'Core words', $html, 'core text is already in what the analyser reads' );
        $this->assertSame( '', AnalysisText::render( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', $post ) );

        // A page of global blocks only: theirs, for this record; a draft one shows nothing.
        $pattern = $this->pattern( '<!-- wp:fabrement/qa-probe {"title":"In a pattern"} /-->' );
        $draft   = $this->pattern( '<!-- wp:fabrement/qa-probe {"title":"Draft"} /-->', [ 'post_status' => 'draft' ] );
        $html    = AnalysisText::render( $this->place( $pattern ) . $this->place( $draft ), $post );
        $this->assertStringContainsString( '<h2>In a pattern — The page</h2>', $html );
        $this->assertStringNotContainsString( 'Draft', $html );
        $this->assertStringContainsString( 'In a pattern', AnalysisText::render( '<!-- wp:core/block {"ref":' . $pattern . '} /-->', $post ), 'the other spelling' );

        // A pattern inside a Fabrement block renders with it, once — not a second time on its own.
        $html = AnalysisText::render( '<!-- wp:fabrement/qa-wrap --><div>' . $this->place( $pattern ) . '</div><!-- /wp:fabrement/qa-wrap -->', $post );
        $this->assertSame( 1, substr_count( $html, 'In a pattern' ) );
    }
}
