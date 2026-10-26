<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\McpClient;
use Fabrement\Tests\Site\Support\SeoPage;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Fabrement plugged into the real Yoast SEO, seen as a crawler sees it: the share picture Yoast
 * falls back to (a Fabrement block's or field's image, never over one a person chose), the images
 * of the sitemap, the query args that survive Yoast's URL cleanup, and the SEO tools writing into
 * Yoast's own storage for records, terms and the site — every write read back from the database
 * and from the page. Pages built of Fabrement blocks are imported with WP-CLI (a block needs the
 * block backend to be made through the tools; an import is how such content also arrives).
 */
final class YoastTest extends SiteTestCase {

    private const TYPE = 'qa_ycourse';

    /** @var array<string,array{id:int,url:string}> */
    private static array $images = [];

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            foreach ( [ 'a', 'b', 'c' ] as $name ) {
                self::$images[ $name ] = $this->upload( 'qa-yoast-' . $name . '.png' );
            }
            $this->post_type( self::TYPE, [ 'singular' => 'Course', 'supports' => [ 'title', 'editor' ] ] );
            $this->fields( self::TYPE, [
                self::field( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => 'For beginners' ] ], 'default' => 'beginner' ] ),
                self::field( 'photo', 'image' ),
            ] );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready  = false;
        self::$images = [];
        parent::tearDownAfterClass();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** @return array{id:int,url:string} */
    private function upload( string $filename ): array {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $out = $this->mcp( 'upload-media', [ 'base64' => $png, 'filename' => $filename, 'altText' => 'A test image, ' . $filename . '.' ] );
        return [ 'id' => (int) $out['id'], 'url' => (string) $out['url'] ];
    }

    private function organization_logo(): string {
        $logo = $this->head( '/' )->node_of_type( 'Organization' )['logo'] ?? [];
        return (string) ( $logo['url'] ?? $logo['contentUrl'] ?? '' );
    }

    private function img( string $name ): array {
        return self::$images[ $name ];
    }

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    private function head( string $url ): SeoPage {
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'], $url );
        return SeoPage::of( $page['body'] );
    }

    /** A Fabrement block holding an image, as the editor serialises it. */
    private static function hero( array ...$images ): string {
        $attrs = [ 'bg' => [ 'id' => $images[0]['id'], 'url' => $images[0]['url'] ] ];
        foreach ( array_slice( $images, 1 ) as $i => $image ) {
            $attrs[ 'more' . $i ] = [ 'id' => $image['id'], 'url' => $image['url'] ];
        }
        return '<!-- wp:fabrement/qa-hero ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
    }

    /** A record whose body arrived by import (WP-CLI, as the administrator). @return int its id */
    private function imported( string $type, string $title, string $content ): int {
        return (int) trim( $this->site()->wp( 'post', 'create', '--post_type=' . $type, '--post_status=publish', '--post_title=' . $title, '--post_content=' . $content, '--user=admin', '--porcelain' ) );
    }

    private function remove_site_code( string $name ): void {
        $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/' . $name . '.php' );
    }

    private function yoast( int $id, string $key ): array {
        return $this->site()->meta( $id, '_yoast_wpseo_' . $key );
    }

    /** @return array<string,mixed> Yoast's stored SEO of a term, read straight. */
    private function term_row( int $term ): array {
        return (array) ( $this->site()->option( 'wpseo_taxonomy_meta' )['category'][ $term ] ?? [] );
    }

    // ── the share picture ────────────────────────────────────────────────────────────────────

    public function test_og_image_falls_back_to_a_block_image_then_a_field_image_never_over_a_chosen_one(): void {
        [ $a, $b, $c ] = [ $this->img( 'a' ), $this->img( 'b' ), $this->img( 'c' ) ];

        $page = $this->imported( 'page', 'Blocks', self::hero( $a ) );
        $this->assertSame( [ $a['url'] ], $this->head( $this->url( $page ) )->og_images(), 'block image, and only it' );

        $record = $this->record( self::TYPE, 'Field' );
        $this->assertSame( [], $this->head( $this->url( $record ) )->og_images(), 'nothing to show yet' );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'photo' => $b['id'] ] ] );
        $this->assertSame( [ $b['url'] ], $this->head( $this->url( $record ) )->og_images(), 'field image, and only it' );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'photo' => $c['id'] ] ] );
        $this->assertSame( [ $c['url'] ], $this->head( $this->url( $record ) )->og_images(), 'the field changed: read again on the next view' );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'photo' => null ] ] );
        $this->assertSame( [], $this->head( $this->url( $record ) )->og_images(), 'cleared: gone' );

        $featured = $this->imported( 'page', 'Featured', self::hero( $a ) );
        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $featured, 'blocks' => [], 'featuredImage' => $c['id'] ] );
        $urls = $this->head( $this->url( $featured ) )->og_images();
        $this->assertContains( $c['url'], $urls, 'the featured image is Yoast\'s own pick' );
        $this->assertNotContains( $a['url'], $urls, 'no fallback once the page has an image of its own' );

        $chosen = $this->imported( 'page', 'Chosen', self::hero( $a ) );
        $this->mcp( 'set-seo', [ 'contentId' => $chosen, 'ogImageId' => $b['id'] ] );
        $this->assertSame( [ $b['url'] ], $this->head( $this->url( $chosen ) )->og_images(), 'the agent\'s choice, alone' );
    }

    public function test_a_type_image_yoast_adds_after_us_is_not_hidden_by_a_block_image(): void {
        [ $a, $c ] = [ $this->img( 'a' ), $this->img( 'c' ) ];
        $page = $this->imported( 'page', 'Typed', self::hero( $a ) );
        // What Yoast Premium does for "social image per content type": answers this filter.
        $this->site_code( 'qa-type-image', "add_filter( 'Yoast\\WP\\SEO\\open_graph_image_id_post', static fn( \$id, \$subtype ) => \$subtype === 'page' ? {$c['id']} : \$id, 10, 2 );" );
        $urls = $this->head( $this->url( $page ) )->og_images();
        $this->remove_site_code( 'qa-type-image' );
        $this->assertContains( $c['url'], $urls, 'the type image' );
        $this->assertNotContains( $a['url'], $urls, 'no block image over it' );

        // The same when the type image is known only by its URL.
        $this->site_code( 'qa-type-image-url', "add_filter( 'Yoast\\WP\\SEO\\open_graph_image_post', static fn( \$url, \$subtype ) => \$subtype === 'page' ? 'https://example.org/type.jpg' : \$url, 10, 2 );" );
        $urls = $this->head( $this->url( $page ) )->og_images();
        $this->remove_site_code( 'qa-type-image-url' );
        $this->assertSame( [ 'https://example.org/type.jpg' ], $urls );

        $this->assertSame( [ $a['url'] ], $this->head( $this->url( $page ) )->og_images(), 'the rule gone: the block image again' );
    }

    public function test_get_seo_shows_each_record_its_own_picture(): void {
        [ $a, $b ] = [ $this->img( 'a' ), $this->img( 'b' ) ];
        $one = $this->imported( 'page', 'One', self::hero( $a ) );
        $two = $this->imported( 'page', 'Two', self::hero( $b ) );
        // get-seo runs in an MCP request: nothing is "singular" there.
        $this->assertSame( $a['url'], $this->mcp( 'get-seo', [ 'contentId' => $one ] )['content']['effective']['ogImage'], 'get-seo shows what the page prints' );
        $this->assertSame( $b['url'], $this->mcp( 'get-seo', [ 'contentId' => $two ] )['content']['effective']['ogImage'] );
        $this->assertSame( [ $b['url'] ], $this->head( $this->url( $two ) )->og_images() );
    }

    public function test_a_default_share_image_does_not_hide_a_content_image_and_site_profiles_reach_yoast(): void {
        [ $a, $b ] = [ $this->img( 'a' ), $this->img( 'b' ) ];
        $out = $this->mcp( 'set-seo-defaults', [ 'site' => true, 'defaultOgImageId' => $b['id'], 'socialProfiles' => [ 'https://www.instagram.com/qa' ], 'twitterUsername' => '@qa_handle' ] );
        $this->assertSame( 'site', $out['scope'] );
        $this->assertEqualsCanonicalizing( [ 'defaultOgImageId', 'socialProfiles', 'twitterUsername' ], $out['written'] );
        $social = $this->site()->option( 'wpseo_social' );
        $this->assertSame( $b['id'], (int) $social['og_default_image_id'] );
        $this->assertSame( $b['url'], $social['og_default_image'], 'Yoast keeps the URL beside the id' );
        $this->assertSame( [ 'https://www.instagram.com/qa' ], array_values( (array) $social['other_social_urls'] ) );
        $this->assertSame( 'qa_handle', $social['twitter_site'] );

        $blocks = $this->imported( 'page', 'Blocks default', self::hero( $a ) );
        $core   = $this->record( 'page', 'Core default' );
        $this->mcp( 'save-content', [ 'mode' => 'replace', 'contentId' => $core, 'blocks' => [ [ 'markup' => '<!-- wp:image {"id":' . $a['id'] . '} --><figure class="wp-block-image"><img src="' . $a['url'] . '" alt="" class="wp-image-' . $a['id'] . '"/></figure><!-- /wp:image -->' ] ] ] );
        $text = $this->record( 'page', 'Text default' );
        $this->mcp( 'save-content', [ 'mode' => 'replace', 'contentId' => $text, 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->' ] ] ] );

        $this->assertContains( $a['url'], $this->head( $this->url( $core ) )->og_images(), 'Yoast: the first content image beats the default' );
        $this->assertSame( [ $a['url'] ], $this->head( $this->url( $blocks ) )->og_images(), 'a Fabrement block image is a content image too, not added beside the default' );
        $this->assertContains( $b['url'], $this->head( $this->url( $text ) )->og_images(), 'no image at all: the default' );
        $this->assertSame( '@qa_handle', $this->head( $this->url( $text ) )->meta( 'twitter:site' ) );

        $this->assertStringContainsString( 'is not an absolute http(s) URL', $this->mcp_error( 'set-seo-defaults', [ 'site' => true, 'socialProfiles' => [ 'not a url' ] ] ) );
        $this->assertSame( $social, $this->site()->option( 'wpseo_social' ), 'nothing written' );

        $this->mcp( 'set-seo-defaults', [ 'site' => true, 'defaultOgImageId' => null ] );
        $this->assertSame( [], $this->head( $this->url( $text ) )->og_images(), 'the default taken away' );
        $this->assertSame( '', $this->site()->option( 'wpseo_social' )['og_default_image'] );
    }

    public function test_the_company_logo_is_kept_with_its_address_and_a_missing_one_is_named(): void {
        $logo = $this->img( 'a' );
        $out  = $this->mcp( 'set-seo-defaults', [ 'site' => true, 'companyOrPerson' => 'company', 'companyName' => 'QA Company', 'companyLogoId' => $logo['id'] ] );
        $this->assertSame( [], $out['warnings'] );
        $this->assertNotContains( 'companyLogo', $out['written'] );
        $this->assertArrayNotHasKey( 'companyLogo', (array) $out['defaults'] );
        $this->assertSame( $logo['id'], ( (array) $out['defaults'] )['companyLogoId'] ?? null );
        $titles = $this->site()->option( 'wpseo_titles' );
        $this->assertSame( $logo['id'], (int) $titles['company_logo_id'] );
        $this->assertSame( $logo['url'], $titles['company_logo'] );
        $this->assertStringContainsString( pathinfo( $logo['url'], PATHINFO_FILENAME ), $this->organization_logo() );

        $other = $this->img( 'b' );
        $this->mcp( 'set-seo-defaults', [ 'site' => true, 'companyLogoId' => $other['id'] ] );
        $this->assertStringContainsString( pathinfo( $other['url'], PATHINFO_FILENAME ), $this->organization_logo(), 'a new logo replaces the one Yoast printed before' );

        $out    = $this->mcp( 'set-seo-defaults', [ 'site' => true, 'companyLogoId' => null ] );
        $titles = $this->site()->option( 'wpseo_titles' );
        $this->assertSame( '', $titles['company_logo'] );
        $this->assertSame( 0, (int) $titles['company_logo_id'], 'the id goes with the address' );
        $this->assertCount( 1, $out['warnings'] );
        $this->assertStringStartsWith( 'companyOrPerson is "company" but the Organization has no logo', $out['warnings'][0] );
        $this->assertNull( $this->head( '/' )->node_of_type( 'Organization' ) );

        $this->site_runs( 'update_option( "site_logo", ' . $logo['id'] . ' ); return true;' );
        $out = $this->mcp( 'set-seo-defaults', [ 'site' => true, 'companyName' => 'QA Company' ] );
        $this->assertSame( [ 'The Organization takes its logo from the site logo (Site Identity / the Site Logo block) (no companyLogoId) — set companyLogoId only for a different logo.' ], $out['warnings'] );
        $this->assertSame( 'QA Company', $this->head( '/' )->node_of_type( 'Organization' )['name'] ?? null, 'Yoast prints it with the site logo' );
        $this->assertStringContainsString( pathinfo( $logo['url'], PATHINFO_FILENAME ), $this->organization_logo(), 'the site logo, not the company logo printed before' );
        $out = $this->mcp( 'set-seo-defaults', [ 'site' => true, 'companyName' => null, 'websiteName' => 'QA Website' ] );
        $this->assertContains( 'The Organization takes its name from the website name (websiteName) (no companyName) — set companyName if the company is called otherwise.', $out['warnings'] );
        $out = $this->mcp( 'set-seo-defaults', [ 'site' => true, 'websiteName' => null ] );
        $this->assertContains( 'The Organization takes its name from the site title (Settings → General) (no companyName) — set companyName if the company is called otherwise.', $out['warnings'] );
        $this->site_runs( 'delete_option( "site_logo" ); return true;' );

        $admin = (int) $this->site_runs( 'return get_current_user_id() ?: (int) get_users( [ "role" => "administrator", "fields" => "ID", "number" => 1 ] )[0];' );
        $this->assertSame( [], $this->mcp( 'set-seo-defaults', [ 'site' => true, 'companyOrPerson' => 'person', 'personUserId' => $admin ] )['warnings'] );
        $out = $this->mcp( 'set-seo-defaults', [ 'site' => true, 'personUserId' => null ] );
        $this->assertFalse( (bool) $this->site()->option( 'wpseo_titles' )['company_or_person_user_id'] );
        $this->assertStringStartsWith( 'companyOrPerson is "person" but personUserId is not an existing user', $out['warnings'][0] ?? '' );
    }

    public function test_the_not_found_page_is_noindex_without_a_canonical_and_out_of_the_sitemap(): void {
        $page = $this->record( 'page', 'Lost and found' );
        $url  = $this->url( $page );
        $this->mcp( 'update-site-settings', [ 'notFoundPage' => $page ] );

        $missing = $this->get( '/qa-no-such-address/' );
        $this->assertSame( 404, $missing['status'] );
        $this->assertStringContainsString( 'Lost and found', $missing['body'] );
        $this->assertTrue( SeoPage::of( $missing['body'] )->noindex() );
        $this->assertSame( '', SeoPage::of( $missing['body'] )->canonical() );

        $this->assertTrue( $this->head( $url )->noindex(), 'opened at its own address too' );
        $this->assertNotContains( $url, SeoPage::sitemap_urls( $this->get( '/page-sitemap.xml' )['body'] ) );

        $this->mcp( 'update-site-settings', [ 'notFoundPage' => 0 ] );
        $this->assertFalse( $this->head( $url )->noindex() );
    }

    // ── the sitemap ──────────────────────────────────────────────────────────────────────────

    public function test_sitemap_images_are_listed_once_and_go_through_yoasts_filters(): void {
        [ $a, $b ] = [ $this->img( 'a' ), $this->img( 'b' ) ];
        $record = $this->imported( self::TYPE, 'Mapped', self::hero( $a, $a ) );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'photo' => $b['id'] ] ] );
        $url = $this->url( $record );

        $images = SeoPage::sitemap_images( $this->get( '/' . self::TYPE . '-sitemap.xml' )['body'] )[ $url ] ?? null;
        $this->assertNotNull( $images, 'the record is in its type\'s sitemap' );
        $this->assertContains( $a['url'], $images );
        $this->assertContains( $b['url'], $images );
        $this->assertSame( $images, array_values( array_unique( $images ) ), 'each image once' );

        // A post with a featured image that is also its block image: one picture.
        $post = $this->imported( 'post', 'Featured post', self::hero( $a ) );
        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $post, 'blocks' => [], 'featuredImage' => $a['id'] ] );
        // A CDN plugin rewriting Yoast's image addresses rewrites ours too.
        $cdn = 'https://cdn.example.org';
        $this->site_code( 'qa-cdn', "add_filter( 'wpseo_xml_sitemap_img_src', static fn( \$src ) => str_replace( home_url(), '$cdn', (string) \$src ) );" );
        $srcs = SeoPage::sitemap_images( $this->get( '/post-sitemap.xml' )['body'] )[ $this->url( $post ) ] ?? [];
        $this->remove_site_code( 'qa-cdn' );
        $home = rtrim( $this->site()->url( '/' ), '/' );
        $this->assertContains( str_replace( $home, $cdn, $a['url'] ), $srcs );
        $this->assertSame( $srcs, array_values( array_unique( $srcs ) ), 'the featured image and the block image are one picture' );
        foreach ( $srcs as $src ) {
            $this->assertStringStartsWith( $cdn, $src );
        }

        // A plugin dropping one image through Yoast's item filter drops ours as it drops its own.
        $this->site_code( 'qa-drop-image', "add_filter( 'wpseo_xml_sitemap_img', static fn( \$item ) => ( is_array( \$item ) && \$item['src'] === '{$b['url']}' ) ? false : \$item );" );
        $images = SeoPage::sitemap_images( $this->get( '/' . self::TYPE . '-sitemap.xml' )['body'] )[ $url ] ?? [];
        $this->remove_site_code( 'qa-drop-image' );
        $this->assertSame( [ $a['url'] ], $images, 'the filter dropped the field image' );
    }

    /**
     * A block keeps an image at its block.json default (a default is not saved): WordPress renders
     * it, so the sitemap and the share picture count it. A saved value replaces the default; a
     * default naming a deleted attachment adds nothing.
     */
    public function test_an_image_a_block_keeps_at_its_default_reaches_the_sitemap_and_og_image(): void {
        [ $a, $b, $c ] = [ $this->img( 'a' ), $this->img( 'b' ), $this->img( 'c' ) ];
        $gone = $this->upload( 'qa-yoast-gone.png' );
        $this->site()->wp( 'post', 'delete', (string) $gone['id'], '--force' );
        $media = static fn( array $image ): array => [ 'id' => $image['id'], 'url' => $image['url'] ];
        $root  = '/var/www/html/wp-content/fabrement/blocks/';
        $this->qa_block( $root . 'QaDefaults-00000000-0000-4000-8000-0000000000b1/build', 'fabrement/qa-defaults', [
            'poster' => [ 'type' => 'object', 'default' => $media( $a ) ],
            'logos'  => [ 'type' => 'array', 'default' => [ $media( $c ) ] ],
            'title'  => [ 'type' => 'string', 'default' => 'Hello' ],
        ] );
        $this->qa_block( $root . 'QaGone-00000000-0000-4000-8000-0000000000b2/build', 'fabrement/qa-gone', [
            'poster' => [ 'type' => 'object', 'default' => $media( $gone ) ],
        ] );
        try {
            $kept     = $this->imported( 'page', 'Defaults kept', '<!-- wp:fabrement/qa-defaults /-->' );
            $replaced = $this->imported( 'page', 'Default replaced', '<!-- wp:fabrement/qa-defaults ' . json_encode( [ 'poster' => $media( $b ) ], JSON_UNESCAPED_SLASHES ) . ' /-->' );
            $missing  = $this->imported( 'page', 'Default gone', '<!-- wp:fabrement/qa-gone /-->' );

            $sitemap = SeoPage::sitemap_images( $this->get( '/page-sitemap.xml' )['body'] );
            $this->assertSame( [ $a['url'], $c['url'] ], $sitemap[ $this->url( $kept ) ] ?? null, 'both defaults, each once, in block order' );
            $this->assertSame( [ $a['url'] ], $this->head( $this->url( $kept ) )->og_images(), 'the default poster is the share picture' );

            $this->assertSame( [ $b['url'], $c['url'] ], $sitemap[ $this->url( $replaced ) ] ?? null, 'the saved poster, not its default; the kept logos default stays' );
            $this->assertSame( [ $b['url'] ], $this->head( $this->url( $replaced ) )->og_images() );

            $this->assertSame( [], $sitemap[ $this->url( $missing ) ] ?? null, 'a default naming a deleted image adds nothing' );
            $this->assertSame( [], $this->head( $this->url( $missing ) )->og_images() );
            $this->assertNotContains( $gone['url'], array_merge( ...array_values( $sitemap ) ) );
        } finally {
            $this->site()->exec( 'rm', '-rf', $root . 'QaDefaults-00000000-0000-4000-8000-0000000000b1', $root . 'QaGone-00000000-0000-4000-8000-0000000000b2' );
        }
    }

    /** A Fabrement block on disk as its deploy leaves it, with these block.json attributes. */
    private function qa_block( string $dir, string $name, array $attributes ): void {
        $this->site()->exec( 'mkdir', '-p', $dir );
        $this->site()->write( $dir . '/block.json', (string) json_encode( [
            'apiVersion' => 3,
            'name'       => $name,
            'title'      => $name,
            'category'   => 'design',
            'render'     => 'file:./render.php',
            'attributes' => $attributes,
            'supports'   => [ 'html' => false ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        $this->site()->write( $dir . '/render.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n?>\n<div class=\"qa-defaults\">Block</div>\n" );
        $this->site()->exec( 'chown', '-R', 'www-data:www-data', '/var/www/html/wp-content/fabrement' );
    }

    public function test_a_record_behind_a_password_shows_no_picture_of_its_blocks_or_fields(): void {
        $b      = $this->img( 'b' );
        $record = $this->record( self::TYPE, 'Locked picture' );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'photo' => $b['id'] ] ] );
        $this->assertSame( [ $b['url'] ], $this->head( $this->url( $record ) )->og_images() );

        $this->site()->wp( 'post', 'update', (string) $record, '--post_password=x' );
        $this->assertNotContains( $b['url'], $this->head( $this->url( $record ) )->og_images() );
    }

    // ── query args ───────────────────────────────────────────────────────────────────────────

    public function test_query_args_the_site_registered_survive_yoasts_url_cleanup(): void {
        $page = $this->url( $this->record( 'page', 'Filters' ) );
        $this->site_code( 'qa-query-var', "add_filter( 'query_vars', static fn( array \$vars ): array => array_merge( \$vars, [ 'qa_year' ] ) );" );
        $this->site()->wp( 'option', 'patch', 'update', 'wpseo', 'clean_permalinks', 'true', '--format=json' );
        try {
            $kept = $this->get( $page . '?qa_year=2026' );
            $this->assertSame( 200, $kept['status'], 'an argument the site reads is kept' );
            $junk = $this->get( $page . '?qa_junk=1' );
            $this->assertSame( 301, $junk['status'], 'Yoast cleans what nobody registered' );
            $this->assertSame( $page, $junk['headers']['location'] ?? '' );
            $both = $this->get( $page . '?qa_year=2026&qa_junk=1' );
            $this->assertSame( 301, $both['status'] );
            $this->assertStringContainsString( 'qa_year=2026', $both['headers']['location'] ?? '' );
            $this->assertStringNotContainsString( 'qa_junk', $both['headers']['location'] ?? '' );
        } finally {
            $this->site()->wp( 'option', 'patch', 'update', 'wpseo', 'clean_permalinks', 'false', '--format=json' );
            $this->remove_site_code( 'qa-query-var' );
        }
    }

    // ── the SEO tools against Yoast's storage ────────────────────────────────────────────────

    public function test_set_seo_on_a_record_is_what_yoast_stores_and_prints(): void {
        $record = $this->record( self::TYPE, 'Course' );
        $out    = $this->mcp( 'set-seo', [ 'contentId' => $record, 'title' => 'QA SEO title', 'metaDesc' => 'QA description', 'noindex' => true ] );
        $this->assertSame( 'QA SEO title', $out['effective']['title'] );
        $this->assertSame( 'QA description', $out['effective']['description'] );
        $this->assertStringContainsString( 'noindex', json_encode( $out['effective']['robots'] ) );
        $this->assertSame( [ 'QA SEO title' ], $this->yoast( $record, 'title' ) );
        $this->assertSame( [ 'QA description' ], $this->yoast( $record, 'metadesc' ) );

        $page = $this->head( $this->url( $record ) );
        $this->assertSame( 'QA SEO title', $page->title() );
        $this->assertSame( 'QA description', $page->description() );
        $this->assertTrue( $page->noindex() );

        $this->mcp( 'set-seo', [ 'contentId' => $record, 'title' => null, 'noindex' => null ] );
        $this->assertSame( [], $this->yoast( $record, 'title' ), 'cleared' );
        $this->assertStringStartsWith( 'Course', $this->head( $this->url( $record ) )->title(), 'back to the template' );
        $this->assertSame( 'QA description', $this->head( $this->url( $record ) )->description(), 'what was not sent stays' );
    }

    public function test_every_record_field_reaches_yoast_and_the_page_and_clears(): void {
        $record = $this->record( self::TYPE, 'Every field' );
        $b      = $this->img( 'b' );
        $out    = $this->mcp( 'set-seo', [
            'contentId'          => $record,
            'nofollow'           => true,
            'robotsAdvanced'     => [ 'noarchive', 'nosnippet', 'noimageindex' ],
            'cornerstone'        => true,
            'focusKeyword'       => 'every field',
            'breadcrumbTitle'    => 'Short crumb',
            'ogDescription'      => 'Shared description',
            'twitterTitle'       => 'X title',
            'twitterDescription' => 'X description',
            'twitterImageId'     => $b['id'],
        ] );
        $this->assertEqualsCanonicalizing( [ 'nofollow', 'robotsAdvanced', 'cornerstone', 'focusKeyword', 'breadcrumbTitle', 'ogDescription', 'twitterTitle', 'twitterDescription', 'twitterImageId' ], $out['written'] );
        $this->assertSame( [ '1' ], $this->yoast( $record, 'meta-robots-nofollow' ) );
        $this->assertSame( [ 'noimageindex,noarchive,nosnippet' ], $this->yoast( $record, 'meta-robots-adv' ), 'in the order Yoast keeps' );
        $this->assertSame( [ '1' ], $this->yoast( $record, 'is_cornerstone' ) );
        $this->assertSame( [ 'every field' ], $this->yoast( $record, 'focuskw' ) );
        $this->assertSame( [ 'Short crumb' ], $this->yoast( $record, 'bctitle' ) );
        $this->assertSame( [ $b['url'] ], $this->yoast( $record, 'twitter-image' ), 'the address beside the id' );

        $stored = (array) $this->mcp( 'get-seo', [ 'contentId' => $record ] )['content']['stored'];
        $this->assertTrue( $stored['nofollow'] );
        $this->assertEqualsCanonicalizing( [ 'noarchive', 'nosnippet', 'noimageindex' ], $stored['robotsAdvanced'] );
        $this->assertTrue( $stored['cornerstone'] );
        $this->assertSame( $b['id'], $stored['twitterImageId'] );

        $page   = $this->head( $this->url( $record ) );
        $robots = $page->robots();
        foreach ( [ 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ] as $directive ) {
            $this->assertStringContainsString( $directive, $robots );
        }
        $this->assertSame( 'Shared description', $page->meta( 'og:description' ) );
        $this->assertSame( 'X title', $page->meta( 'twitter:title' ) );
        $this->assertSame( 'X description', $page->meta( 'twitter:description' ) );
        $this->assertSame( $b['url'], $page->meta( 'twitter:image' ) );

        $this->mcp( 'set-seo', [
            'contentId' => $record, 'nofollow' => false, 'robotsAdvanced' => [], 'cornerstone' => false, 'focusKeyword' => null,
            'breadcrumbTitle' => null, 'ogDescription' => null, 'twitterTitle' => null, 'twitterDescription' => null, 'twitterImageId' => null,
        ] );
        foreach ( [ 'meta-robots-nofollow', 'meta-robots-adv', 'is_cornerstone', 'focuskw', 'bctitle', 'opengraph-description', 'twitter-title', 'twitter-description', 'twitter-image', 'twitter-image-id' ] as $key ) {
            $this->assertSame( [], $this->yoast( $record, $key ), $key . ' cleared' );
        }
        $page = $this->head( $this->url( $record ) );
        $this->assertStringNotContainsString( 'nofollow', $page->robots() );
        $this->assertSame( '', $page->meta( 'twitter:title' ) );
        $this->assertNotSame( 'Shared description', $page->meta( 'og:description' ) );
    }

    public function test_canonical_and_images_are_checked_for_a_record_and_a_term_alike(): void {
        $a      = $this->img( 'a' );
        $pdf    = (int) $this->mcp( 'upload-media', [ 'base64' => base64_encode( '%PDF-1.4 ' . str_repeat( 'x', 64 ) ), 'filename' => 'qa-doc.pdf' ] )['id'];
        $record = $this->record( self::TYPE, 'Checked' );
        $term   = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Rules' ] )['termId'];

        foreach ( [ [ 'contentId' => $record ], [ 'termId' => $term ] ] as $target ) {
            $what   = array_key_first( $target );
            $before = [ $this->site()->rows( 'SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = ? ORDER BY meta_id', [ $record ] ), $this->term_row( $term ) ];
            $this->assertSame( 'canonical must be an absolute http(s) URL.', $this->mcp_error( 'set-seo', $target + [ 'canonical' => '/relative' ] ), $what );
            $this->assertStringContainsString( 'is not an image attachment', $this->mcp_error( 'set-seo', $target + [ 'ogImageId' => $pdf ] ), $what );
            $this->assertSame( $before, [ $this->site()->rows( 'SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = ? ORDER BY meta_id', [ $record ] ), $this->term_row( $term ) ], $what . ': nothing written' );
            $out = $this->mcp( 'set-seo', $target + [ 'ogImageId' => $a['id'], 'canonical' => 'https://example.org/x' ] );
            $this->assertSame( [ 'ogImageId', 'canonical' ], $out['written'], $what . ': the fields sent, not the address kept beside the id' );
            $this->assertSame( $a['id'], ( (array) $out['stored'] )['ogImageId'] ?? null, $what );
            $this->assertArrayNotHasKey( 'ogImage', (array) $out['stored'], $what . ': what is read is what is written' );
        }

        $this->assertSame( [ $a['url'] ], $this->yoast( $record, 'opengraph-image' ), 'Yoast keeps the URL beside the id' );
        $this->assertSame( [ 'https://example.org/x' ], $this->yoast( $record, 'canonical' ) );
        $this->assertSame( $a['url'], $this->term_row( $term )['wpseo_opengraph-image'] ?? null );
        $this->assertSame( 'https://example.org/x', $this->term_row( $term )['wpseo_canonical'] ?? null );

        $page = $this->head( $this->url( $record ) );
        $this->assertSame( 'https://example.org/x', $page->canonical() );
        $this->assertSame( [ $a['url'] ], $page->og_images() );
    }

    public function test_a_backslash_survives_and_resending_the_same_value_is_not_a_failure(): void {
        $record = $this->record( self::TYPE, 'Paths' );
        $this->mcp( 'set-seo', [ 'contentId' => $record, 'title' => 'Paths C:\\Temp \\ more' ] );
        $this->assertSame( [ 'Paths C:\\Temp \\ more' ], $this->yoast( $record, 'title' ) );

        $again = $this->mcp( 'set-seo', [ 'contentId' => $record, 'title' => 'Paths C:\\Temp \\ more' ] );
        $this->assertSame( [ 'title' ], $again['written'] );
        $this->assertSame( [ 'Paths C:\\Temp \\ more' ], $this->yoast( $record, 'title' ), 'unchanged, not unslashed by a second write' );
        $this->assertSame( 'Paths C:\\Temp \\ more', $this->head( $this->url( $record ) )->title() );
    }

    public function test_term_seo_lives_in_yoasts_store_and_reaches_the_term_page_on_its_next_view(): void {
        $term = $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Negotiation' ] );
        $id   = (int) $term['termId'];
        $post = $this->record( 'post', 'Filed under negotiation' );
        $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $id ], 'mode' => 'replace' ] );
        $this->assertStringStartsWith( 'Negotiation', $this->head( $term['url'] )->title(), 'Yoast has its record of the term now' );

        $this->mcp( 'set-seo', [ 'termId' => $id, 'title' => 'QA term title', 'metaDesc' => 'First' ] );
        $second = $this->mcp( 'set-seo', [ 'termId' => $id, 'metaDesc' => 'Second' ] );
        $this->assertSame( 'QA term title', $second['stored']['title'], 'kept what the call did not send' );
        $this->assertSame( 'Second', $second['stored']['metaDesc'] );
        $this->assertSame( 'QA term title', $this->term_row( $id )['wpseo_title'] ?? null, 'in Yoast\'s own store' );
        $this->assertStringStartsWith( 'QA term title', (string) $this->mcp( 'get-seo', [ 'termId' => $id ] )['term']['effective']['title'] );

        $page = $this->head( $term['url'] );
        $this->assertSame( 'QA term title', $page->title(), 'the stored record of the term is fresh on its next read' );
        $this->assertSame( 'Second', $page->description() );

        $hidden = $this->mcp( 'set-seo', [ 'termId' => $id, 'noindex' => true ] );
        $this->assertTrue( $hidden['stored']['noindex'] ?? null );
        $this->assertSame( 'noindex', $this->term_row( $id )['wpseo_noindex'] ?? null, 'in Yoast\'s own words' );
        $this->assertTrue( $this->head( $term['url'] )->noindex() );
        $this->assertFalse( $this->mcp( 'set-seo', [ 'termId' => $id, 'noindex' => false ] )['stored']['noindex'] ?? null, 'index, set explicitly' );
        $this->assertFalse( $this->head( $term['url'] )->noindex() );
        $this->assertArrayNotHasKey( 'noindex', (array) $this->mcp( 'set-seo', [ 'termId' => $id, 'noindex' => null ] )['stored'], 'null = back to the taxonomy\'s default' );

        $this->mcp( 'set-seo', [ 'termId' => $id, 'canonical' => $this->site()->url( '/elsewhere/' ), 'breadcrumbTitle' => 'Short' ] );
        $this->assertSame( $this->site()->url( '/elsewhere/' ), $this->head( $term['url'] )->canonical() );
        $cleared = (array) $this->mcp( 'set-seo', [ 'termId' => $id, 'canonical' => null, 'breadcrumbTitle' => null ] )['stored'];
        $this->assertSame( [], array_intersect_key( $cleared, [ 'canonical' => 1, 'breadcrumbTitle' => 1 ] ), 'cleared, not kept from before' );
        $this->assertSame( 'QA term title', $cleared['title'] ?? null );
        $this->assertSame( $term['url'], $this->head( $term['url'] )->canonical() );

        $before = $this->term_row( $id );
        $this->assertStringContainsString( 'cannot be set on a term', $this->mcp_error( 'set-seo', [ 'termId' => $id, 'nofollow' => true ] ) );
        $this->assertStringStartsWith( 'Give exactly one of', $this->mcp_error( 'set-seo', [ 'termId' => $id, 'contentId' => $post, 'title' => 'x' ] ) );
        $this->assertSame( $before, $this->term_row( $id ), 'nothing written' );
    }

    public function test_a_term_value_yoast_throws_out_does_not_reach_its_row(): void {
        $id = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Sanitised' ] )['termId'];
        $this->mcp( 'set-seo', [ 'termId' => $id, 'metaDesc' => 'Kept' ] );
        $before = $this->term_row( $id );
        $this->assertStringContainsString( 'Nothing was written', $this->mcp_error( 'set-seo', [ 'termId' => $id, 'title' => '<b></b>' ] ), 'what Yoast would strip is refused' );

        $this->assertSame( $before, $this->term_row( $id ) );
        $this->assertSame( 'Kept', $before['wpseo_desc'] ?? null );
    }

    public function test_an_article_type_is_refused_where_yoast_would_print_no_article(): void {
        $record = $this->record( self::TYPE, 'No author' );
        $this->assertStringContainsString( 'support "author"', $this->mcp_error( 'set-seo', [ 'contentId' => $record, 'schemaArticleType' => 'Article' ] ) );
        $this->assertStringContainsString( 'support "author"', $this->mcp_error( 'set-seo-defaults', [ 'postType' => self::TYPE, 'schemaArticleType' => 'Article' ] ) );
        $this->assertSame( [], $this->yoast( $record, 'schema_article_type' ) );

        $post = $this->record( 'post', 'With author' );
        $this->mcp( 'set-seo', [ 'contentId' => $post, 'schemaArticleType' => 'NewsArticle' ] );
        $this->assertNotNull( $this->head( $this->url( $post ) )->node_of_type( 'NewsArticle' ), 'posts support author: Yoast prints it' );
    }

    // ── the editor's analysis route ──────────────────────────────────────────────────────────

    private function analysis( string $auth, int $post, string $content ): array {
        return Http::request( $this->site(), 'POST', $this->site()->url( '/wp-json/fabrement/v1/seo/analysis-text' ), [
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ], (string) json_encode( [ 'postId' => $post, 'content' => $content ] ) );
    }

    public function test_the_analysis_route_answers_only_someone_who_may_edit_the_record(): void {
        $post  = $this->record( 'page', 'Analysed' );
        $admin = 'Basic ' . base64_encode( 'admin:' . $this->site()->password );

        $answer = $this->analysis( $admin, $post, '<!-- wp:paragraph --><p>Core words</p><!-- /wp:paragraph -->' );
        $this->assertSame( 200, $answer['status'] );
        $this->assertSame( '', json_decode( $answer['body'], true )['html'] ?? null, 'core text is already in what the analyser reads' );

        $user     = (int) $this->site()->wp( 'user', 'create', 'qa-contributor', 'qa-contributor@example.org', '--role=contributor', '--porcelain' );
        $password = trim( $this->site()->wp( 'user', 'application-password', 'create', (string) $user, 'qa', '--porcelain' ) );
        $this->assertSame( 403, $this->analysis( 'Basic ' . base64_encode( 'qa-contributor:' . $password ), $post, '<!-- wp:fabrement/x /-->' )['status'] );
        $this->assertSame( 403, ( new McpClient( $this->site(), 'qa-contributor', $password ) )->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'qa', 'version' => '1' ] ] )['_http']['status'], 'nor is the MCP server theirs' );
    }

    /** Last in this class: Yoast is switched off for good (the next class restores the snapshot). */
    public function test_without_yoast_the_analysis_route_is_not_there(): void {
        $post = $this->record( 'page', 'Unanalysed' );
        $this->site()->wp( 'plugin', 'deactivate', 'wordpress-seo' );
        $this->assertSame( 404, $this->analysis( 'Basic ' . base64_encode( 'admin:' . $this->site()->password ), $post, '<!-- wp:fabrement/x /-->' )['status'] );
    }
}
