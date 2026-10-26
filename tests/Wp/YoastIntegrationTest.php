<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Seo\Integration\YoastIntegration;
use Fabrement\Modules\Seo\Seo;
use WP_UnitTestCase;

/**
 * Fabrement with the real Yoast SEO (vendor/wp-plugins/wordpress-seo, pinned in composer.lock):
 * what Yoast prints once Fabrement is plugged in, and what the SEO tools store in it. In-process —
 * no HTTP, no page cache. Over real HTTP: tests/Cases (the suites' web server).
 *
 * Skipped when the suite runs without Yoast (FABREMENT_TEST_PLUGINS=none).
 */
final class YoastIntegrationTest extends WP_UnitTestCase {

    private const TYPE = 'qa_ycourse';

    /** @var int[] */
    private array $images = [];

    public function set_up(): void {
        parent::set_up();
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Yoast SEO is not loaded in this run.' );
        }
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->forget_statics();
        for ( $i = 0; $i < 3; $i++ ) {
            $this->images[] = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
        }
        register_post_type( self::TYPE, [ 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'label' => 'Courses', 'supports' => [ 'title', 'editor' ] ] );
        $f     = static fn( string $name, string $type, array $extra = [] ): array => $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
        $saved = $this->tool( 'save-fields', [ 'type' => 'post', 'slug' => self::TYPE, 'expectedRevision' => 0, 'fields' => [
            $f( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => 'For beginners' ] ], 'default' => 'beginner' ] ),
            $f( 'place', 'group', [ 'sub_fields' => [ $f( 'town', 'text' ) ] ] ),
            $f( 'host', 'post_object', [ 'postTypes' => [ 'page' ] ] ),
            $f( 'guests', 'relationship', [ 'postTypes' => [ 'page' ] ] ),
            $f( 'steps', 'repeater', [ 'sub_fields' => [ $f( 'label', 'text' ) ] ] ),
            $f( 'photo', 'image' ),
        ] ] );
        $this->assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
        // Yoast registers variables once per request; fields saved in this "request" come now.
        YoastIntegration::register_variables();
    }

    public function tear_down(): void {
        // Site-wide Yoast options a test may have set: Yoast caches them in a static that a
        // database rollback does not reach.
        foreach ( [ 'og_default_image_id' => 0, 'og_default_image' => '', 'other_social_urls' => [], 'twitter_site' => '' ] as $key => $value ) {
            \WPSEO_Options::set( $key, $value );
        }
        $this->forget_statics();
        if ( $owner = Owner::of( 'post', self::TYPE ) ) {
            SchemaRepository::delete( $owner );
        }
        SchemaRepository::flush();
        unregister_post_type( self::TYPE );
        parent::tear_down();
    }

    /** Per-request memory that outlives a test's rollback. */
    private function forget_statics(): void {
        \Fabrement\Modules\Seo\Content\ContentImages::flush();
        \Fabrement\Modules\Seo\Integration\YoastPrimaryTerm::sync();
        YoastIntegration::remember_presentation( null );
        \WPSEO_Options::clear_cache();
    }

    /** @return mixed */
    private function tool( string $name, array $input ) {
        return wp_get_ability( 'fabrement/' . $name )->execute( $input );
    }

    private function page( string $title, string $status = 'publish', string $content = '' ): int {
        return self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => $title, 'post_status' => $status, 'post_content' => $content ] );
    }

    private function media( int $id ): array {
        return [ 'id' => $id, 'url' => (string) wp_get_attachment_url( $id ) ];
    }

    /** What Yoast prints on the page at $url: its meta for the current page, fresh. */
    private function head_at( string $url ): object {
        $this->go_to( $url );
        YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class )->clear();
        return YoastSEO()->meta->for_current_page();
    }

    private function og_urls( object $meta ): array {
        return array_values( array_map( static fn( $img ) => (string) ( $img['url'] ?? '' ), (array) $meta->open_graph_images ) );
    }

    // ── variables ────────────────────────────────────────────────────────────────────────────

    public function test_a_fab_variable_with_a_less_than_sign_does_not_cut_the_title(): void {
        $this->tool( 'set-seo-defaults', [ 'postType' => self::TYPE, 'titleTemplate' => '%%title%% — %%fab_place_town%% %%sep%% %%sitename%%' ] );
        $record = self::factory()->post->create( [ 'post_type' => self::TYPE, 'post_title' => 'Negotiation', 'post_status' => 'publish' ] );
        fabrement()->fields( $record )->set( 'place', [ 'town' => 'Kids <12 & more' ] )->save();

        $this->head_at( (string) get_permalink( $record ) );
        ob_start();
        do_action( 'wpseo_head' );
        $head = (string) ob_get_clean();
        $title = wp_get_document_title();
        // What a browser shows: "<" and "&" as typed, the rest of the title still there.
        $this->assertStringContainsString( 'Kids <12 & more', html_entity_decode( $title ), 'not cut at "<"' );
        $this->assertStringContainsString( get_bloginfo( 'name' ), html_entity_decode( $title ), 'the rest of the title stays' );
        $this->assertMatchesRegularExpression( '/og:title" content="[^"]*Kids &lt;12 &amp; more/', $head, 'og:title escaped once, not cut' );
    }

    public function test_fab_variables_in_a_type_template_print_the_fields(): void {
        $defaults = $this->tool( 'set-seo-defaults', [
            'postType'         => self::TYPE,
            'titleTemplate'    => '%%title%% — %%fab_level%%, %%fab_place_town%%',
            'metaDescTemplate' => 'Host %%fab_host%%. Guests %%fab_guests%%; %%fab_steps%% steps.',
        ] );
        $this->assertFalse( is_wp_error( $defaults ), is_wp_error( $defaults ) ? $defaults->get_error_message() : '' );

        $host   = $this->page( 'Olena' );
        $guests = [ $this->page( 'A' ), $this->page( 'Hidden', 'draft' ), $this->page( 'B' ), $this->page( 'C' ), $this->page( 'D' ) ];
        $record = self::factory()->post->create( [ 'post_type' => self::TYPE, 'post_title' => 'Negotiation', 'post_status' => 'publish' ] );
        fabrement()->fields( $record )->set( 'place', [ 'town' => 'Lviv' ] )->save();
        fabrement()->fields( $record )->set( 'host', $host )->save();
        fabrement()->fields( $record )->set( 'guests', $guests )->save();
        fabrement()->fields( $record )->set( 'steps', [ [ 'label' => 'One' ], [ 'label' => 'Two' ], [ 'label' => 'Three' ] ] )->save();

        $effective = Seo::bridge()->effective_post( $record );
        $this->assertStringStartsWith( 'Negotiation — For beginners, Lviv', $effective['title'] );
        $this->assertSame( 'Host Olena. Guests A, B, C…; 3 steps.', $effective['description'] );

        $empty = self::factory()->post->create( [ 'post_type' => self::TYPE, 'post_title' => 'Empty', 'post_status' => 'publish' ] );
        $desc  = Seo::bridge()->effective_post( $empty )['description'];
        $this->assertStringNotContainsString( '%%', $desc, 'unknown or empty variables are removed' );
        $this->assertStringNotContainsString( '0 steps', $desc, 'no rows: removed, not "0"' );
        $this->assertStringStartsWith( 'Negotiation — For beginners', Seo::bridge()->effective_post( $record )['title'], 'reading another record did not leak into this one' );
    }

    public function test_a_variable_behind_a_password_says_nothing(): void {
        $this->tool( 'set-seo-defaults', [ 'postType' => self::TYPE, 'titleTemplate' => '%%title%% in %%fab_place_town%%' ] );
        $record = self::factory()->post->create( [ 'post_type' => self::TYPE, 'post_title' => 'Locked', 'post_status' => 'publish', 'post_password' => 'x' ] );
        fabrement()->fields( $record )->set( 'place', [ 'town' => 'Secret town' ] )->save();

        $this->assertStringNotContainsString( 'Secret town', wpseo_replace_vars( '%%title%% in %%fab_place_town%%', get_post( $record ) ) );
    }

    // ── images ───────────────────────────────────────────────────────────────────────────────

    public function test_og_image_falls_back_to_a_block_image_then_a_field_image_never_over_a_chosen_one(): void {
        [ $a, $b, $c ] = $this->images;
        $page = $this->page( 'Blocks', 'publish', '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $a ) ] ) . ' /-->' );
        $this->assertSame( [ (string) wp_get_attachment_url( $a ) ], $this->og_urls( $this->head_at( get_permalink( $page ) ) ), 'block image, and only it' );

        $record = self::factory()->post->create( [ 'post_type' => self::TYPE, 'post_title' => 'Field', 'post_status' => 'publish' ] );
        fabrement()->fields( $record )->set( 'photo', $b )->save();
        $this->assertSame( [ (string) wp_get_attachment_url( $b ) ], $this->og_urls( $this->head_at( get_permalink( $record ) ) ), 'field image, and only it' );

        $featured = $this->page( 'Featured', 'publish', '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $a ) ] ) . ' /-->' );
        set_post_thumbnail( $featured, $c );
        wp_update_post( [ 'ID' => $featured ] ); // as the editor saves it: Yoast rebuilds on save
        $urls = $this->og_urls( $this->head_at( get_permalink( $featured ) ) );
        $this->assertContains( (string) wp_get_attachment_url( $c ), $urls, 'the featured image is Yoast\'s own pick' );
        $this->assertNotContains( (string) wp_get_attachment_url( $a ), $urls, 'no fallback once the page has an image of its own' );
    }

    public function test_a_post_type_image_yoast_adds_after_us_is_not_hidden_by_a_block_image(): void {
        [ $a, , $c ] = $this->images;
        // What Yoast Premium does for "social image per content type": answers this filter.
        $type_image = static fn( $id, $subtype ) => $subtype === 'page' ? $c : $id;
        add_filter( 'Yoast\WP\SEO\open_graph_image_id_post', $type_image, 10, 2 );
        $page = $this->page( 'Typed', 'publish', '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $a ) ] ) . ' /-->' );
        $urls = $this->og_urls( $this->head_at( get_permalink( $page ) ) );
        remove_filter( 'Yoast\WP\SEO\open_graph_image_id_post', $type_image, 10 );

        $this->assertContains( (string) wp_get_attachment_url( $c ), $urls, 'the type image' );
        $this->assertNotContains( (string) wp_get_attachment_url( $a ), $urls, 'no block image over it' );

        // The same when the type image is known only by its URL.
        $type_url = static fn( $url, $subtype ) => $subtype === 'page' ? 'https://example.org/type.jpg' : $url;
        add_filter( 'Yoast\WP\SEO\open_graph_image_post', $type_url, 10, 2 );
        $urls = $this->og_urls( $this->head_at( get_permalink( $page ) ) );
        remove_filter( 'Yoast\WP\SEO\open_graph_image_post', $type_url, 10 );
        $this->assertSame( [ 'https://example.org/type.jpg' ], $urls );
    }

    public function test_without_a_presentation_the_viewed_singular_page_is_used(): void {
        [ $a ] = $this->images;
        $page = $this->page( 'Plain', 'publish', '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $a ) ] ) . ' /-->' );
        $this->go_to( get_permalink( $page ) );
        YoastIntegration::remember_presentation( null );
        // A caller that reached Yoast's generator around wpseo_frontend_presentation.
        $container = new class() {
            public array $ids = [];
            public function has_images(): bool { return $this->ids !== []; }
            public function add_image_by_id( $id ): void { $this->ids[] = (int) $id; }
        };
        YoastIntegration::open_graph_image( $container );
        $this->assertSame( [ $a ], $container->ids );

        $this->go_to( home_url( '/' ) );
        $none = new class() {
            public array $ids = [];
            public function has_images(): bool { return false; }
            public function add_image_by_id( $id ): void { $this->ids[] = (int) $id; }
        };
        YoastIntegration::open_graph_image( $none );
        $this->assertSame( [], $none->ids, 'not singular: nothing to guess' );
    }

    public function test_the_fallback_image_belongs_to_the_record_yoast_describes_not_the_page_viewed(): void {
        [ $a, $b ] = $this->images;
        $one = $this->page( 'One', 'publish', '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $a ) ] ) . ' /-->' );
        $two = $this->page( 'Two', 'publish', '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $b ) ] ) . ' /-->' );

        // get-seo runs in an MCP request: nothing is "singular" there.
        $this->go_to( home_url( '/' ) );
        YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class )->clear();
        $seo = (array) $this->tool( 'get-seo', [ 'contentId' => $one ] )['content'];
        $this->assertSame( (string) wp_get_attachment_url( $a ), (string) ( (array) $seo['effective'] )['ogImage'], 'get-seo shows what the page prints' );

        // Viewing one page while something builds Yoast's meta for another.
        $this->head_at( get_permalink( $one ) );
        $this->assertSame( (string) wp_get_attachment_url( $b ), Seo::bridge()->effective_post( $two )['ogImage'], 'the other record, its own image' );
    }

    public function test_sitemap_images_go_through_yoasts_url_filter_and_are_listed_once(): void {
        [ $a ] = $this->images;
        $cdn = static fn( $src ) => str_replace( home_url(), 'https://cdn.example.org', (string) $src );
        add_filter( 'wpseo_xml_sitemap_img_src', $cdn );
        $record = self::factory()->post->create( [
            'post_type'    => self::TYPE,
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $a ) ] ) . ' /-->',
        ] );
        set_post_thumbnail( $record, $a );
        $srcs = array_column( ( new \WPSEO_Sitemap_Image_Parser() )->get_images( get_post( $record ) ), 'src' );
        remove_filter( 'wpseo_xml_sitemap_img_src', $cdn );

        $this->assertContains( $cdn( wp_get_attachment_image_url( $a, 'full' ) ), $srcs );
        $this->assertSame( $srcs, array_values( array_unique( $srcs ) ), 'the featured image and the block image are one picture' );
        foreach ( $srcs as $src ) {
            $this->assertStringStartsWith( 'https://cdn.example.org', $src );
        }
    }

    public function test_sitemap_items_go_through_yoasts_item_filter(): void {
        [ $a, $b ] = $this->images;
        $record = self::factory()->post->create( [
            'post_type'    => self::TYPE,
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $a ) ] ) . ' /-->',
        ] );
        fabrement()->fields( $record )->set( 'photo', $b )->save();
        $drop_b = static fn( $item ) => ( is_array( $item ) && $item['src'] === wp_get_attachment_image_url( $b, 'full' ) ) ? false : ( (array) $item + [ 'title' => 'QA' ] );
        add_filter( 'wpseo_xml_sitemap_img', $drop_b );
        $items = ( new \WPSEO_Sitemap_Image_Parser() )->get_images( get_post( $record ) );
        remove_filter( 'wpseo_xml_sitemap_img', $drop_b );

        $this->assertSame( [ (string) wp_get_attachment_image_url( $a, 'full' ) ], array_column( $items, 'src' ), 'the filter dropped the field image' );
        $this->assertSame( 'QA', $items[0]['title'], 'and its changes to the block image are kept' );
    }

    public function test_the_sitemap_lists_block_and_field_images_once(): void {
        [ $a, $b ] = $this->images;
        $record = self::factory()->post->create( [
            'post_type'    => self::TYPE,
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $a ), 'again' => $this->media( $a ) ] ) . ' /-->',
        ] );
        fabrement()->fields( $record )->set( 'photo', $b )->save();

        $srcs = array_column( ( new \WPSEO_Sitemap_Image_Parser() )->get_images( get_post( $record ) ), 'src' );

        $this->assertContains( (string) wp_get_attachment_image_url( $a, 'full' ), $srcs );
        $this->assertContains( (string) wp_get_attachment_image_url( $b, 'full' ), $srcs );
        $this->assertSame( $srcs, array_values( array_unique( $srcs ) ), 'each image once' );
    }

    // ── query args ───────────────────────────────────────────────────────────────────────────

    public function test_query_args_the_site_registered_survive_clean_permalinks(): void {
        add_filter( 'query_vars', static fn( array $vars ): array => array_merge( $vars, [ 'qa_year' ] ) );
        $allowed = (array) apply_filters( 'Yoast\WP\SEO\allowlist_permalink_vars', [] );

        $this->assertContains( 'qa_year', $allowed );
        $this->assertNotContains( 'p', $allowed );
    }

    // ── the SEO tools against Yoast's storage ────────────────────────────────────────────────

    public function test_set_seo_on_a_record_is_what_yoast_prints(): void {
        $record = self::factory()->post->create( [ 'post_type' => self::TYPE, 'post_title' => 'Course', 'post_status' => 'publish' ] );
        $out    = $this->tool( 'set-seo', [ 'contentId' => $record, 'title' => 'QA SEO title', 'metaDesc' => 'QA description', 'noindex' => true ] );

        $this->assertFalse( is_wp_error( $out ), is_wp_error( $out ) ? $out->get_error_message() : '' );
        $this->assertSame( 'QA SEO title', $out['effective']->title );
        $this->assertSame( 'QA description', $out['effective']->description );
        $this->assertSame( 'QA SEO title', \WPSEO_Meta::get_value( 'title', $record ) );
        $this->assertStringContainsString( 'noindex', wp_json_encode( $out['effective']->robots ) );
    }

    public function test_set_seo_checks_canonical_and_images_for_a_record_and_a_term_alike(): void {
        [ $a ] = $this->images;
        $pdf    = self::factory()->attachment->create( [ 'post_mime_type' => 'application/pdf', 'post_title' => 'Doc' ] );
        $record = self::factory()->post->create( [ 'post_type' => self::TYPE, 'post_title' => 'Course', 'post_status' => 'publish' ] );
        $term   = self::factory()->category->create( [ 'name' => 'Rules' ] );
        $url    = (string) wp_get_attachment_image_url( $a, 'full' );

        foreach ( [ [ 'contentId' => $record ], [ 'termId' => $term ] ] as $target ) {
            $what = array_key_first( $target );
            $this->assertSame( 'bad_canonical', $this->tool( 'set-seo', $target + [ 'canonical' => '/relative' ] )->get_error_code(), $what );
            $this->assertSame( 'not_image', $this->tool( 'set-seo', $target + [ 'ogImageId' => $pdf ] )->get_error_code(), $what );
            $ok = $this->tool( 'set-seo', $target + [ 'ogImageId' => $a, 'canonical' => 'https://example.org/x' ] );
            $this->assertFalse( is_wp_error( $ok ), $what . ( is_wp_error( $ok ) ? ': ' . $ok->get_error_message() : '' ) );
        }

        $this->assertSame( $url, \WPSEO_Meta::get_value( 'opengraph-image', $record ), 'Yoast keeps the URL beside the id' );
        $this->assertSame( 'https://example.org/x', \WPSEO_Meta::get_value( 'canonical', $record ) );
        $this->assertSame( $url, \WPSEO_Taxonomy_Meta::get_term_meta( $term, 'category', 'opengraph-image' ) );
        $this->assertSame( 'https://example.org/x', \WPSEO_Taxonomy_Meta::get_term_meta( $term, 'category', 'canonical' ) );
    }

    public function test_a_backslash_survives_and_resending_the_same_value_is_not_a_failure(): void {
        $record = self::factory()->post->create( [ 'post_type' => self::TYPE, 'post_status' => 'publish' ] );
        $this->tool( 'set-seo', [ 'contentId' => $record, 'title' => 'Paths C:\\Temp \\ more' ] );
        $this->assertSame( 'Paths C:\\Temp \\ more', \WPSEO_Meta::get_value( 'title', $record ) );

        $again = $this->tool( 'set-seo', [ 'contentId' => $record, 'title' => 'Paths C:\\Temp \\ more' ] );
        $this->assertSame( [ 'title' ], $again['written'] );
        $this->assertSame( 'Paths C:\\Temp \\ more', \WPSEO_Meta::get_value( 'title', $record ), 'unchanged, not unslashed by a second write' );
    }

    public function test_a_default_share_image_does_not_hide_a_content_image_as_in_yoast_itself(): void {
        [ $a, $b ] = $this->images;
        \WPSEO_Options::set( 'og_default_image_id', $b );
        \WPSEO_Options::set( 'og_default_image', (string) wp_get_attachment_url( $b ) );
        $blocks = $this->page( 'Blocks', 'publish', '<!-- wp:fabrement/qa-hero ' . wp_json_encode( [ 'bg' => $this->media( $a ) ] ) . ' /-->' );
        $core   = $this->page( 'Core', 'publish', '<!-- wp:image {"id":' . $a . '} --><figure class="wp-block-image"><img src="' . esc_url( (string) wp_get_attachment_url( $a ) ) . '" class="wp-image-' . $a . '"/></figure><!-- /wp:image -->' );
        $none   = $this->page( 'Text only', 'publish', '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->' );

        $this->assertContains( (string) wp_get_attachment_url( $a ), $this->og_urls( $this->head_at( get_permalink( $core ) ) ), 'Yoast: the first content image beats the default' );
        $this->assertSame( [ (string) wp_get_attachment_url( $a ) ], $this->og_urls( $this->head_at( get_permalink( $blocks ) ) ), 'a Fabrement block image is a content image too, not added beside the default' );
        $this->assertContains( (string) wp_get_attachment_url( $b ), $this->og_urls( $this->head_at( get_permalink( $none ) ) ), 'no image at all: the default' );
    }

    public function test_primary_taxonomies_go_through_yoasts_filter_in_yoasts_shape(): void {
        $seen = null;
        $drop = static function ( $taxonomies ) use ( &$seen ) {
            $seen = $taxonomies;
            unset( $taxonomies['category'] );
            return $taxonomies;
        };
        add_filter( 'wpseo_primary_term_taxonomies', $drop );
        $post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        $term = self::factory()->category->create();

        $out = $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $term ], 'mode' => 'replace', 'primary' => $term ] );
        remove_filter( 'wpseo_primary_term_taxonomies', $drop );

        $this->assertInstanceOf( \WP_Taxonomy::class, $seen['category'] ?? null, 'objects keyed by name, as Yoast passes them' );
        $this->assertSame( 'no_primary_here', $out->get_error_code(), 'a taxonomy the site took out of Yoast\'s list is refused' );
    }

    public function test_term_seo_reaches_the_stored_indexable_on_its_next_read(): void {
        $term = self::factory()->category->create( [ 'name' => 'Indexed' ] );
        $repo = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
        $repo->find_by_id_and_type( $term, 'term' ); // an indexable exists before the write

        $this->tool( 'set-seo', [ 'termId' => $term, 'title' => 'Fresh term title' ] );

        $this->assertSame( 'Fresh term title', $repo->find_by_id_and_type( $term, 'term' )->title );
    }

    public function test_article_type_is_refused_where_yoast_would_print_no_article(): void {
        $record = self::factory()->post->create( [ 'post_type' => self::TYPE, 'post_status' => 'publish' ] );
        $this->assertSame( 'article_needs_author', $this->tool( 'set-seo', [ 'contentId' => $record, 'schemaArticleType' => 'Article' ] )->get_error_code() );
        $this->assertSame( 'article_needs_author', $this->tool( 'set-seo-defaults', [ 'postType' => self::TYPE, 'schemaArticleType' => 'Article' ] )->get_error_code() );

        $post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        $this->assertFalse( is_wp_error( $this->tool( 'set-seo', [ 'contentId' => $post, 'schemaArticleType' => 'NewsArticle' ] ) ), 'posts support author' );
    }

    public function test_term_seo_keeps_what_a_call_did_not_send_and_yoast_prints_it(): void {
        $this->assertTrue( Seo::bridge()->terms_supported(), 'this Yoast has the term store we write' );
        $term = self::factory()->category->create( [ 'name' => 'Negotiation' ] );

        $first = $this->tool( 'set-seo', [ 'termId' => $term, 'title' => 'QA term title', 'metaDesc' => 'First' ] );
        $this->assertFalse( is_wp_error( $first ), is_wp_error( $first ) ? $first->get_error_message() : '' );
        $second = $this->tool( 'set-seo', [ 'termId' => $term, 'metaDesc' => 'Second' ] );
        $this->assertSame( 'QA term title', $second['stored']->title );
        $this->assertSame( 'Second', $second['stored']->metaDesc );
        $this->assertSame( 'QA term title', \WPSEO_Taxonomy_Meta::get_term_meta( $term, 'category', 'title' ), 'in Yoast\'s own store' );

        $seo = $this->tool( 'get-seo', [ 'termId' => $term ] );
        $this->assertStringStartsWith( 'QA term title', (string) ( (array) ( (array) $seo['term'] )['effective'] )['title'] );

        $hidden = $this->tool( 'set-seo', [ 'termId' => $term, 'noindex' => true ] );
        $this->assertTrue( $hidden['stored']->noindex ?? null );
        $this->assertSame( 'noindex', \WPSEO_Taxonomy_Meta::get_term_meta( $term, 'category', 'noindex' ), 'in Yoast\'s own words' );
        $this->assertFalse( $this->tool( 'set-seo', [ 'termId' => $term, 'noindex' => false ] )['stored']->noindex ?? null, 'index, set explicitly' );
        $this->assertArrayNotHasKey( 'noindex', (array) $this->tool( 'set-seo', [ 'termId' => $term, 'noindex' => null ] )['stored'], 'null = back to the taxonomy\'s default, beside the fields it keeps' );
        $this->tool( 'set-seo', [ 'termId' => $term, 'canonical' => home_url( '/elsewhere/' ), 'breadcrumbTitle' => 'Short' ] );
        $cleared = (array) $this->tool( 'set-seo', [ 'termId' => $term, 'canonical' => null, 'breadcrumbTitle' => null ] )['stored'];
        $this->assertSame( [], array_intersect_key( $cleared, [ 'canonical' => 1, 'breadcrumbTitle' => 1 ] ), 'cleared, not kept from before' );
        $this->assertSame( 'QA term title', $cleared['title'] ?? null );

        $this->assertSame( 'not_for_terms', $this->tool( 'set-seo', [ 'termId' => $term, 'nofollow' => true ] )->get_error_code() );
        $this->assertSame( 'one_target', $this->tool( 'set-seo', [ 'termId' => $term, 'contentId' => 1, 'title' => 'x' ] )->get_error_code() );
    }

    public function test_a_term_value_yoast_throws_out_does_not_reach_its_row(): void {
        $term = self::factory()->category->create( [ 'name' => 'Sanitised' ] );
        $row  = static function () use ( $term ): array {
            global $wpdb;
            $all = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wpseo_taxonomy_meta' ) ) );
            return (array) ( $all['category'][ $term ] ?? [] );
        };
        $this->assertIsArray( $this->tool( 'set-seo', [ 'termId' => $term, 'metaDesc' => 'Kept' ] ) );

        $this->tool( 'set-seo', [ 'termId' => $term, 'title' => '<b></b>' ] );
        Seo::bridge()->write_term( \WP_Term::get_instance( $term ), [ 'canonical' => 'javascript:alert(1)' ] );

        $stored = $row();
        $this->assertStringNotContainsString( '<b>', (string) ( $stored['wpseo_title'] ?? '' ), 'what Yoast strips stays stripped' );
        $this->assertStringNotContainsString( 'javascript', (string) ( $stored['wpseo_canonical'] ?? '' ) );
        $this->assertSame( 'Kept', $stored['wpseo_desc'] ?? null );
    }

    public function test_primary_term_is_yoasts_and_must_stay_assigned(): void {
        $parent = self::factory()->category->create( [ 'name' => 'Parent' ] );
        $child  = self::factory()->category->create( [ 'name' => 'Child', 'parent' => $parent ] );
        $post   = self::factory()->post->create( [ 'post_status' => 'publish' ] );

        $set = $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $parent, $child ], 'mode' => 'replace', 'primary' => $parent ] );
        $this->assertFalse( is_wp_error( $set ), is_wp_error( $set ) ? $set->get_error_message() : '' );
        $this->assertSame( $parent, ( new \WPSEO_Primary_Term( 'category', $post ) )->get_primary_term(), 'Yoast reads it' );
        $this->assertSame( $parent, (int) ( (array) $this->tool( 'get-seo', [ 'contentId' => $post ] )['content']['primaryTerms'] )['category'] );

        $bad = $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $child ], 'mode' => 'replace', 'primary' => $parent ] );
        $this->assertSame( 'primary_not_assigned', $bad->get_error_code() );
        $tag = $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'post_tag', 'terms' => [ 'qa' ], 'mode' => 'replace', 'primary' => 'qa' ] );
        $this->assertSame( 'no_primary_here', $tag->get_error_code(), 'tags are flat' );

        $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $parent, $child ], 'mode' => 'replace', 'primary' => $parent ] );
        $clear = $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $parent, $child ], 'mode' => 'replace', 'primary' => 0 ] );
        $this->assertFalse( is_wp_error( $clear ), is_wp_error( $clear ) ? $clear->get_error_message() : '' );
        $this->assertSame( '', (string) get_post_meta( $post, '_yoast_wpseo_primary_category', true ), '0 clears it' );
        $this->assertEmpty( ( new \WPSEO_Primary_Term( 'category', $post ) )->get_primary_term(), 'Yoast has none' );
        $this->assertSame( 0, (int) ( (array) $this->tool( 'get-seo', [ 'contentId' => $post ] )['content']['primaryTerms'] )['category'] );
    }

    public function test_a_primary_term_is_checked_in_the_records_language(): void {
        $en   = self::factory()->category->create( [ 'name' => 'Parent EN' ] );
        $pl   = self::factory()->category->create( [ 'name' => 'Parent PL' ] );
        $post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        wp_set_object_terms( $post, [ $pl ], 'category' );
        update_post_meta( $post, '_yoast_wpseo_primary_category', (string) $pl );

        // What WPML does outside wp-admin: a record's terms read as their versions in the REQUEST's
        // language — the Polish post's Polish term reads as the English one, unless the read runs
        // in Polish.
        $lang   = 'en';
        $bridge = $this->createStub( \Fabrement\Modules\Translation\Contract\TranslationBridge::class );
        $bridge->method( 'is_active' )->willReturn( true );
        $bridge->method( 'languages' )->willReturn( [ [ 'code' => 'en', 'name' => 'en' ], [ 'code' => 'pl', 'name' => 'pl' ] ] );
        $bridge->method( 'default_language' )->willReturn( 'en' );
        $bridge->method( 'current_language' )->willReturnCallback( static function () use ( &$lang ) { return $lang; } );
        $bridge->method( 'version_facts' )->willReturnCallback( static fn( array $ids ) => array_fill_keys( array_map( 'intval', $ids ), [ 'language' => 'pl' ] ) );
        $bridge->method( 'in_locale' )->willReturnCallback( static fn( string $to, callable $fn ) => $fn() );
        $bridge->method( 'in_language' )->willReturnCallback( static function ( string $to, callable $fn ) use ( &$lang ) {
            $was  = $lang;
            $lang = $to === '' ? $lang : $to;
            try {
                return $fn();
            } finally {
                $lang = $was;
            }
        } );
        $map = static function ( $terms, $object_ids, $taxonomies ) use ( &$lang, $pl, $en ) {
            if ( $lang !== 'en' || ! is_array( $terms ) ) {
                return $terms;
            }
            return array_map( static fn( $t ) => ( $t instanceof \WP_Term && $t->term_id === $pl ) ? get_term( $en ) : $t, $terms );
        };
        add_filter( 'get_object_terms', $map, 10, 3 );
        \Fabrement\Modules\Translation\Translation::set( $bridge );
        \Fabrement\Modules\Translation\Translation::forget_languages();
        try {
            clean_object_term_cache( $post, 'post' );
            $this->assertFalse( has_term( $pl, 'category', $post ), 'the request (en) sees the English sibling' );
            clean_object_term_cache( $post, 'post' );
            $this->assertSame( $pl, \Fabrement\Modules\Seo\PrimaryTerm::get( $post, 'category' ), 'read in the record\'s own language' );
        } finally {
            \Fabrement\Modules\Translation\Translation::set( null );
            \Fabrement\Modules\Translation\Translation::forget_languages();
            remove_filter( 'get_object_terms', $map, 10 );
        }
    }

    public function test_site_social_profiles_and_default_image_reach_yoast(): void {
        [ $a ] = $this->images;
        $out   = $this->tool( 'set-seo-defaults', [ 'site' => true, 'socialProfiles' => [ 'https://www.instagram.com/qa' ], 'defaultOgImageId' => $a, 'twitterUsername' => '@qa_handle' ] );

        $this->assertFalse( is_wp_error( $out ), is_wp_error( $out ) ? $out->get_error_message() : '' );
        $this->assertSame( [ 'https://www.instagram.com/qa' ], array_values( (array) \WPSEO_Options::get( 'other_social_urls' ) ) );
        $this->assertSame( $a, (int) \WPSEO_Options::get( 'og_default_image_id' ) );
        $this->assertSame( 'qa_handle', \WPSEO_Options::get( 'twitter_site' ) );
        $this->assertSame( 'bad_url', $this->tool( 'set-seo-defaults', [ 'site' => true, 'socialProfiles' => [ 'not a url' ] ] )->get_error_code() );
    }

    // ── schema: our nodes as a piece of Yoast's graph ─────────────────────────────────────────

    /** @return array<string,mixed>|null The node of Yoast's graph for record `$id` with that @id. */
    private function schema_node( int $id, string $at_id ): ?array {
        foreach ( \Fabrement\Modules\Seo\Schema\SchemaOutput::preview( $id )['graph'] as $node ) {
            if ( is_array( $node ) && ( $node['@id'] ?? '' ) === $at_id ) {
                return $node;
            }
        }
        return null;
    }

    public function test_our_nodes_are_a_piece_of_yoasts_graph_beside_its_own(): void {
        $page = $this->page( 'Chair' );
        $url  = (string) get_permalink( $page );
        $out  = $this->tool( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Product', '@id' => '{{url}}#product', 'name' => '{{title}}', 'mainEntityOfPage' => [ '@id' => '{{url}}' ] ] ] ] );
        $this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );

        $product = $this->schema_node( $page, $url . '#product' );
        $this->assertSame( 'Chair', $product['name'] ?? null, 'ours is in the graph get-seo shows' );
        $this->assertSame( [ '@id' => $url ], $product['mainEntityOfPage'] ?? null );
        $webpage = $this->schema_node( $page, $url );
        $this->assertNotNull( $webpage, 'the WebPage of Yoast is still there' );
        $this->assertArrayHasKey( 'breadcrumb', $webpage );

        // The Schema API filters of Yoast reach our nodes as they reach its own.
        $seen = [];
        $spy  = static function ( $node ) use ( &$seen ) {
            $seen[] = $node['@type'] ?? '';
            return $node;
        };
        add_filter( 'wpseo_schema_fabrement', $spy );
        add_filter( 'wpseo_schema_needs_fabrement', '__return_false' );
        try {
            $this->assertNull( $this->schema_node( $page, $url . '#product' ), 'wpseo_schema_needs_fabrement turns them off' );
            remove_filter( 'wpseo_schema_needs_fabrement', '__return_false' );
            $this->assertNotNull( $this->schema_node( $page, $url . '#product' ) );
            $this->assertSame( [ 'Product' ], $seen );
        } finally {
            remove_filter( 'wpseo_schema_fabrement', $spy );
            remove_filter( 'wpseo_schema_needs_fabrement', '__return_false' );
        }
    }

    public function test_the_id_of_a_yoast_node_is_refused(): void {
        $page = $this->page( 'Chair' );
        $url  = (string) get_permalink( $page );
        $taken = [ '{{url}}', '{{ url }}', '{{url}}#breadcrumb', '{{url}}#primaryimage', '{{url}}#article', '{{site_url}}#website', '{{site_url}}#organization', '{{site_url}}#/schema/person/1', $url, $url . '#breadcrumb', home_url( '/' ) . '#organization' ];
        foreach ( $taken as $id ) {
            $out = $this->tool( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Product', '@id' => $id, 'name' => 'x' ] ] ] );
            $this->assertSame( 'yoast_node_id', is_wp_error( $out ) ? $out->get_error_code() : 'accepted', $id );
        }
        $this->assertSame( [], \Fabrement\Modules\Seo\Schema\SchemaStore::read_post( $page ), 'nothing saved' );
        foreach ( [ '{{url}}', home_url( '/' ) . '#organization', home_url( '/' ) . '#/schema/logo/image/' ] as $id ) {
            $type = $this->tool( 'set-schema', [ 'postType' => self::TYPE, 'graph' => [ [ '@type' => 'Course', '@id' => $id, 'name' => '{{title}}' ] ] ] );
            $this->assertSame( 'yoast_node_id', is_wp_error( $type ) ? $type->get_error_code() : 'accepted', 'the type template too: ' . $id );
        }

        // On a static front page the page URL is the site URL: {{url}}#organization is Yoast's.
        $front = $this->page( 'Home' );
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $front );
        foreach ( [ '{{url}}#organization', '{{site_url}}#breadcrumb' ] as $id ) {
            $out = $this->tool( 'set-schema', [ 'contentId' => $front, 'graph' => [ [ '@type' => 'Thing', '@id' => $id, 'name' => 'x' ] ] ] );
            $this->assertSame( 'yoast_node_id', is_wp_error( $out ) ? $out->get_error_code() : 'accepted', 'front page: ' . $id );
        }

        foreach ( [ '{{url}}#product', '{{url}}#/product/1', $url . '#product', '{{site_url}}#store' ] as $id ) {
            $out = $this->tool( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Product', '@id' => $id, 'name' => 'x' ] ] ] );
            $this->assertIsArray( $out, $id . ( is_wp_error( $out ) ? ': ' . $out->get_error_message() : '' ) );
        }
    }

    public function test_a_yoast_node_inside_ours_is_a_reference_only(): void {
        $page = $this->page( 'Chair' );
        $cases = [
            'organizer' => [ '@id' => '{{site_url}}#organization', 'name' => 'Another name', 'telephone' => '+1' ],
            'isPartOf'  => [ '@id' => '{{url}}', '@type' => 'WebPage' ],
            'performer' => [ [ '@type' => 'Person', 'name' => 'A' ], [ '@id' => '{{site_url}}#organization', 'logo' => 'x.png' ] ],
        ];
        foreach ( $cases as $property => $value ) {
            $out = $this->tool( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Event', '@id' => '{{url}}#event', 'name' => 'x', $property => $value ] ] ] );
            $this->assertSame( 'yoast_node_extended', is_wp_error( $out ) ? $out->get_error_code() : 'accepted', $property );
        }
        $this->assertStringContainsString( 'graph[0].performer[1]', $this->tool( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Event', '@id' => '{{url}}#event', 'name' => 'x', 'performer' => $cases['performer'] ] ] ] )->get_error_message() );

        $ok = $this->tool( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Event', '@id' => '{{url}}#event', 'name' => 'x', 'organizer' => [ '@id' => '{{site_url}}#organization' ], 'location' => [ '@type' => 'Place', '@id' => '{{url}}#place', 'name' => 'Hall' ] ] ] ] );
        $this->assertIsArray( $ok, 'a reference alone, and a nested node of our own, are fine' . ( is_wp_error( $ok ) ? ': ' . $ok->get_error_message() : '' ) );
    }

    public function test_a_stored_yoast_node_inside_ours_is_printed_as_a_reference(): void {
        $page = $this->page( 'Chair' );
        $url  = (string) get_permalink( $page );
        // Written past validation, as a graph saved by an older version would be.
        update_post_meta( $page, Keys::META_SCHEMA, wp_slash( (string) wp_json_encode( [
            [ '@type' => 'Event', '@id' => '{{url}}#event', 'name' => 'Talk', 'organizer' => [ '@id' => '{{site_url}}#organization', 'name' => 'Swapped' ] ],
        ] ) ) );
        $event = $this->schema_node( $page, $url . '#event' );
        $this->assertSame( [ '@id' => home_url( '/' ) . '#organization' ], $event['organizer'] ?? null );
        $this->assertStringNotContainsString( 'Swapped', (string) wp_json_encode( \Fabrement\Modules\Seo\Schema\SchemaOutput::preview( $page )['graph'] ) );
    }

    public function test_a_stored_node_with_a_yoast_id_is_left_out_and_yoasts_stays(): void {
        $page = $this->page( 'Chair' );
        $url  = (string) get_permalink( $page );
        // Written past validation, as a graph saved by an older version would be.
        update_post_meta( $page, Keys::META_SCHEMA, wp_slash( (string) wp_json_encode( [
            [ '@type' => 'Product', '@id' => '{{url}}', 'name' => 'Swapped' ],
            [ '@type' => 'Thing', '@id' => '{{url}}#thing', 'name' => 'Kept' ],
        ] ) ) );

        $webpage = $this->schema_node( $page, $url );
        $this->assertNotNull( $webpage );
        $this->assertNotSame( 'Swapped', $webpage['name'] ?? '', 'the WebPage of Yoast, not ours in its place' );
        $this->assertContains( 'WebPage', (array) $webpage['@type'] );
        $graph = \Fabrement\Modules\Seo\Schema\SchemaOutput::preview( $page )['graph'];
        $this->assertCount( 1, array_filter( $graph, static fn( $n ) => is_array( $n ) && ( $n['@id'] ?? '' ) === $url ), 'one node with the page id — ours is not printed beside it' );
        $this->assertStringNotContainsString( 'Swapped', (string) wp_json_encode( $graph ) );
        $this->assertSame( 'Kept', $this->schema_node( $page, $url . '#thing' )['name'] ?? null );

        // Under a custom canonical Yoast names #breadcrumb after the canonical, not the permalink.
        $canonical = home_url( '/elsewhere/' );
        $this->assertIsArray( $this->tool( 'set-seo', [ 'contentId' => $page, 'canonical' => $canonical ] ) );
        update_post_meta( $page, Keys::META_SCHEMA, wp_slash( (string) wp_json_encode( [
            [ '@type' => 'BreadcrumbList', '@id' => $canonical . '#breadcrumb', 'name' => 'Swapped' ],
        ] ) ) );
        $graph = \Fabrement\Modules\Seo\Schema\SchemaOutput::preview( $page )['graph'];
        $this->assertCount( 1, array_filter( $graph, static fn( $n ) => is_array( $n ) && ( $n['@id'] ?? '' ) === $canonical . '#breadcrumb' ) );
        $this->assertStringNotContainsString( 'Swapped', (string) wp_json_encode( $graph ) );
    }

    public function test_the_posts_page_takes_its_own_nodes_not_the_page_template(): void {
        $posts = $this->page( 'Blog' );
        $plain = $this->page( 'About' );
        update_option( 'page_for_posts', $posts );
        $this->assertTrue( \Fabrement\Modules\Seo\Schema\SchemaOutput::with_type( $posts ), 'a leftover setting while the front page lists posts: an ordinary page' );
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $plain );
        $this->assertFalse( \Fabrement\Modules\Seo\Schema\SchemaOutput::with_type( $posts ) );
        $this->assertTrue( \Fabrement\Modules\Seo\Schema\SchemaOutput::with_type( $plain ) );

        $this->assertIsArray( $this->tool( 'set-schema', [ 'postType' => 'page', 'graph' => [ [ '@type' => 'Thing', '@id' => '{{url}}#page-template', 'name' => 'Template' ] ] ] ) );
        $this->assertIsArray( $this->tool( 'set-schema', [ 'contentId' => $posts, 'graph' => [ [ '@type' => 'Blog', '@id' => '{{url}}#blog', 'name' => 'Own' ] ] ] ) );
        $url = (string) get_permalink( $posts );
        $this->assertSame( 'Own', $this->schema_node( $posts, $url . '#blog' )['name'] ?? null );
        $this->assertNull( $this->schema_node( $posts, $url . '#page-template' ), 'not the page template' );
        $webpage = (string) ( \Fabrement\Modules\Seo\Seo::bridge()->schema_values( $plain )['url'] ?? '' );
        $this->assertNotSame( '', $webpage, 'the WebPage id Yoast gives the front page' );
        $this->assertNotNull( $this->schema_node( $plain, $webpage . '#page-template' ), 'an ordinary page takes it, on that WebPage id' );
    }

    public function test_the_page_view_emits_the_nodes_of_the_record_yoast_describes(): void {
        $page = $this->page( 'Chair' );
        $this->assertIsArray( $this->tool( 'set-schema', [ 'contentId' => $page, 'graph' => [ [ '@type' => 'Product', '@id' => '{{url}}#product', 'name' => '{{title}}' ] ] ] ) );
        $ours = static function (): array {
            $graph = YoastSEO()->meta->for_current_page()->schema['@graph'] ?? [];
            return array_values( array_filter( $graph, static fn( $n ) => is_array( $n ) && strpos( (string) ( $n['@id'] ?? '' ), '#product' ) !== false ) );
        };

        $this->go_to( (string) get_permalink( $page ) );
        $forget = static fn() => YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class )->clear();
        $forget();
        $this->assertSame( 'Chair', $ours()[0]['name'] ?? null, 'the viewed record, from the real query' );

        $this->go_to( home_url( '/?s=chair' ) );
        $forget();
        $this->assertSame( [], $ours(), 'a search page describes no record' );
    }

    /** Yoast's primary-term row for a record, or null. */
    private function primary_row( int $post ): ?int {
        $row = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Primary_Term_Repository::class )->find_by_post_id_and_taxonomy( $post, 'category', false );
        return $row ? (int) $row->term_id : null;
    }

    /** The stored indexable's version, read raw: Yoast's repository upgrades an outdated one as it reads it. */
    private function indexable_version( int $post ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT version FROM {$wpdb->prefix}yoast_indexable WHERE object_id = %d AND object_type = 'post'", $post ) );
    }

    public function test_a_main_term_taken_off_leaves_yoasts_tables_and_its_breadcrumbs(): void {
        $parent = self::factory()->category->create( [ 'name' => 'Frontend' ] );
        $child  = self::factory()->category->create( [ 'name' => 'JavaScript', 'parent' => $parent ] );
        $post   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $parent, $child ], 'mode' => 'replace', 'primary' => $parent ] );
        \Fabrement\Modules\Seo\Integration\YoastPrimaryTerm::sync();
        YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class )->find_by_id_and_type( $post, 'post' );
        $this->assertSame( $parent, $this->primary_row( $post ), 'Yoast has the choice' );
        $this->assertGreaterThan( 0, $this->indexable_version( $post ), 'and an up-to-date record' );

        $out = $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $parent ], 'mode' => 'remove' ] );
        \Fabrement\Modules\Seo\Integration\YoastPrimaryTerm::sync(); // the end of the request

        $this->assertSame( 0, $out['primary'] );
        $this->assertNull( $this->primary_row( $post ), 'the row is gone: Yoast falls back to the deepest term' );
        $this->assertSame( 0, $this->indexable_version( $post ), 'the record is rebuilt, breadcrumbs included, on its next read' );
        $this->assertSame( 0, (int) ( (array) $this->tool( 'get-seo', [ 'contentId' => $post ] )['content']['primaryTerms'] )['category'] );
    }

    public function test_new_terms_and_a_new_main_term_in_one_call_keep_the_new_one(): void {
        $a    = self::factory()->category->create( [ 'name' => 'A' ] );
        $b    = self::factory()->category->create( [ 'name' => 'B' ] );
        $post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $a ], 'mode' => 'replace', 'primary' => $a ] );

        $out = $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $b ], 'mode' => 'replace', 'primary' => $b ] );
        \Fabrement\Modules\Seo\Integration\YoastPrimaryTerm::sync();

        $this->assertSame( $b, $out['primary'], 'the old one is dropped as its term comes off, then the new one is set' );
        $this->assertSame( $b, $this->primary_row( $post ) );
    }

    public function test_a_main_term_that_cannot_be_set_writes_nothing(): void {
        $a    = self::factory()->category->create( [ 'name' => 'Kept A' ] );
        $b    = self::factory()->category->create( [ 'name' => 'Kept B' ] );
        $post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $a, $b ], 'mode' => 'replace', 'primary' => $a ] );
        $before = wp_get_object_terms( $post, 'category', [ 'fields' => 'ids' ] );

        $this->assertSame( 'primary_not_assigned', $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $b ], 'mode' => 'replace', 'primary' => $a ] )->get_error_code() );
        $this->assertSame( 'primary_not_assigned', $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $a ], 'mode' => 'remove', 'primary' => $a ] )->get_error_code() );
        $this->assertSame( 'term_not_found', $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $b ], 'mode' => 'replace', 'primary' => 'No such category' ] )->get_error_code() );

        $this->assertEqualsCanonicalizing( $before, wp_get_object_terms( $post, 'category', [ 'fields' => 'ids' ] ), 'no call above touched the terms' );
        $this->assertSame( (string) $a, get_post_meta( $post, '_yoast_wpseo_primary_category', true ) );
    }

    public function test_a_save_that_does_not_change_the_terms_queues_nothing(): void {
        $post    = self::factory()->post->create( [ 'post_status' => 'publish' ] );
        $pending = new \ReflectionProperty( \Fabrement\Modules\Seo\Integration\YoastPrimaryTerm::class, 'pending' );
        $pending->setAccessible( true );
        \Fabrement\Modules\Seo\Integration\YoastPrimaryTerm::sync();

        wp_update_post( [ 'ID' => $post, 'post_title' => 'Renamed' ] ); // sets the same categories again
        $this->assertSame( [], $pending->getValue(), 'an unchanged set is not a change' );

        $this->tool( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ self::factory()->category->create() ], 'mode' => 'replace' ] );
        $this->assertArrayHasKey( $post, $pending->getValue(), 'a change set-post-terms made is' );
    }

    public function test_term_seo_is_the_term_asked_for_not_its_version_in_the_requests_language(): void {
        $en = self::factory()->category->create( [ 'name' => 'Alpha' ] );
        $pl = self::factory()->category->create( [ 'name' => 'Alfa' ] );
        // What WPML does outside wp-admin: get_term() answers with the term's version in the
        // REQUEST's language (en) — the Polish term reads as the English one.
        $adjust = static fn( $term ) => $term instanceof \WP_Term && $term->term_id === $pl ? \WP_Term::get_instance( $en ) : $term;
        add_filter( 'get_term', $adjust );
        try {
            $out = $this->tool( 'set-seo', [ 'termId' => $pl, 'title' => 'Tytuł PL' ] );
            $got = $this->tool( 'get-seo', [ 'termId' => $pl ] );
        } finally {
            remove_filter( 'get_term', $adjust );
        }

        $this->assertSame( $pl, $out['termId'] );
        $this->assertSame( 'Tytuł PL', \WPSEO_Taxonomy_Meta::get_term_meta( $pl, 'category', 'title' ), 'written on the Polish term' );
        $this->assertEmpty( \WPSEO_Taxonomy_Meta::get_term_meta( $en, 'category', 'title' ), 'the English one untouched' );
        $this->assertSame( $pl, ( (array) $got['term'] )['id'] );
        $this->assertSame( 'Tytuł PL', ( (array) ( (array) $got['term'] )['stored'] )['title'] );
    }
}
