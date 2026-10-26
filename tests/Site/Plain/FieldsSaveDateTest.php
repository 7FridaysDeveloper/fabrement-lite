<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\McpClient;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * A record whose fields changed is a changed record: set-field-values saves it as its Update
 * button would — a new modified date, save_post for the plugins listening (here a cache plugin's
 * counter, as site code), Yoast's dateModified following — and values that did not change leave it
 * alone. An options page has no record to date.
 */
final class FieldsSaveDateTest extends SiteTestCase {

    private const OLD = '2020-01-01 00:00:00';

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->fields( 'page', [ self::field( 'tagline', 'text' ), self::field( 'price', 'number' ) ] );
            // Another plugin (a page cache, a search index) listens to save_post.
            $this->site_code( 'qa-save-post-counter', "add_action( 'save_post', static function ( \$id ): void {
                if ( wp_is_post_revision( \$id ) || wp_is_post_autosave( \$id ) ) { return; }
                update_option( 'qa_saves_' . \$id, (int) get_option( 'qa_saves_' . \$id, 0 ) + 1, false );
            } );" );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    private function saves( int $id ): int {
        return (int) $this->site()->option( 'qa_saves_' . $id );
    }

    public function test_php_without_wpml_still_saves_only_fields(): void {
        $page = $this->record( 'page', 'PHP without WPML' );
        $this->age( $page );
        $before = $this->row( $page );
        $saves = $this->saves( $page );
        $result = $this->site_runs( "\$saved = fabrement()->fields($page)->set('tagline', 'PHP words')->save(); return [\$saved, did_action('wpml_tm_save_post')];" );
        $this->assertSame( [ true, 0 ], $result );
        $this->assertSame( $before, $this->row( $page ) );
        $this->assertSame( $saves, $this->saves( $page ) );
        $this->assertSame( [ 'PHP words' ], $this->site()->meta( $page, 'fab_tagline' ) );
    }

    public function test_field_adapter_is_inert_without_a_translation_plugin(): void {
        $this->assertSame( [ false, false, false, 0, 0 ], $this->site_runs( '
            $bridge = \\Fabrement\\Modules\\Translation\\Translation::bridge();
            $bridge->register_field_hooks();
            return [ has_filter("wpml_tm_translation_job_data"), has_filter("wpml_tm_post_md5_content"),
                has_action("wpml_translation_update"), $bridge->pending_original(0), $bridge->duplicate_of(0) ];
        ' ) );
    }

    /** @return array{post_modified_gmt:?string, post_content:?string, post_status:?string} */
    private function row( int $id ): array {
        return $this->site()->rows( 'SELECT post_modified_gmt, post_content, post_status FROM wp_posts WHERE ID = ?', [ $id ] )[0];
    }

    /**
     * The record dated long ago, straight in the table: time cannot be waited for in a test, and
     * nothing a person does sets a past modified date. The site has no persistent object cache.
     */
    private function age( int $id ): void {
        $this->site()->db()->query( "UPDATE wp_posts SET post_modified = '" . self::OLD . "', post_modified_gmt = '" . self::OLD . "' WHERE ID = " . $id );
    }

    /** Yoast's WebPage node on the visitor's copy: its dateModified. */
    private function date_modified_seen( int $id ): ?string {
        $page = $this->get( $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'] );
        $this->assertSame( 200, $page['status'] );
        return self::node( self::json_ld( $page['body'] ), 'WebPage' )['dateModified'] ?? null;
    }

    private static function iso( string $gmt ): string {
        return str_replace( ' ', 'T', $gmt ) . '+00:00';
    }

    public function test_changed_values_save_the_record_once_and_the_same_values_do_not(): void {
        $page = $this->record( 'page', 'QA dated page' );
        // Yoast emits dateModified only after datePublished, even when both saves share a second.
        $this->site()->wp( 'post', 'update', (string) $page, '--post_content=<!-- wp:paragraph --><p>Body of the dated page</p><!-- /wp:paragraph -->',
            '--post_date=2019-01-01 00:00:00', '--post_date_gmt=2019-01-01 00:00:00', '--user=admin' );
        $content = $this->row( $page )['post_content'];
        $saves   = $this->saves( $page );

        $this->age( $page );
        $this->mcp( 'set-field-values', [ 'postId' => $page, 'values' => [ 'tagline' => 'We build sites', 'price' => 12 ] ] );
        $row = $this->row( $page );
        $this->assertNotSame( self::OLD, $row['post_modified_gmt'], 'dated now' );
        $this->assertSame( $saves + 1, $this->saves( $page ), 'saved once, as its Update button would' );
        $this->assertSame( $content, $row['post_content'], 'its content untouched' );
        $this->assertSame( 'publish', $row['post_status'] );
        $this->assertSame( self::iso( (string) $row['post_modified_gmt'] ), $this->date_modified_seen( $page ), 'Yoast followed the save' );
        $this->assertStringContainsString( 'Body of the dated page', $this->get( $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'] )['body'] );

        // The same values again: nothing changed, nothing saved.
        $this->age( $page );
        $this->mcp( 'set-field-values', [ 'postId' => $page, 'values' => [ 'tagline' => 'We build sites', 'price' => 12.0 ] ] );
        $this->assertSame( self::OLD, $this->row( $page )['post_modified_gmt'], 'the same values: the record is left alone' );
        $this->assertSame( $saves + 1, $this->saves( $page ) );

        // A cleared value is a change.
        $this->mcp( 'set-field-values', [ 'postId' => $page, 'values' => [ 'price' => null ] ] );
        $row = $this->row( $page );
        $this->assertSame( [ null ], $this->site()->meta( $page, 'fab_price' ), 'explicit clear retains SQL NULL' );
        $this->assertNotSame( self::OLD, $row['post_modified_gmt'], 'dated now' );
        $this->assertSame( $saves + 2, $this->saves( $page ) );
        $this->assertSame( self::iso( (string) $row['post_modified_gmt'] ), $this->date_modified_seen( $page ) );

        // A refused value saves nothing and dates nothing.
        $this->age( $page );
        $this->mcp_error( 'set-field-values', [ 'postId' => $page, 'values' => [ 'price' => 'soon' ] ] );
        $this->assertSame( self::OLD, $this->row( $page )['post_modified_gmt'] );
        $this->assertSame( $saves + 2, $this->saves( $page ) );
        $this->assertSame( [ 'We build sites' ], $this->site()->meta( $page, 'fab_tagline' ) );
    }

    public function test_an_administrator_without_unfiltered_html_still_saves_and_dates_the_record(): void {
        $this->site_code( 'qa-date-limited', "add_filter('map_meta_cap', static function(\$caps, \$cap, \$id) {
            \$user = \$cap === 'unfiltered_html' ? get_userdata((int) \$id) : false;
            return \$user && \$user->user_login === 'qa-date-limited' ? ['do_not_allow'] : \$caps;
        }, 10, 3);" );
        $id = (int) $this->site()->wp( 'user', 'create', 'qa-date-limited', 'qa-date-limited@example.org', '--role=administrator', '--porcelain' );
        $agent = new McpClient( $this->site(), 'qa-date-limited', trim( $this->site()->wp( 'user', 'application-password', 'create', (string) $id, 'qa', '--porcelain' ) ) );
        $this->assertFalse( $this->site_runs( "return user_can($id, 'unfiltered_html');" ) );
        $page = $this->record( 'page', 'QA restricted save date' );
        $saves = $this->saves( $page );
        $this->age( $page );
        $answer = $agent->call( 'set-field-values', [ 'postId' => $page, 'values' => [ 'tagline' => 'Changed' ] ] );
        $this->assertFalse( $answer['error'], $answer['text'] );
        $this->assertSame( [ 'Changed' ], $this->site()->meta( $page, 'fab_tagline' ) );
        $this->assertSame( $saves + 1, $this->saves( $page ) );
        $this->assertNotSame( self::OLD, $this->row( $page )['post_modified_gmt'] );
    }

    public function test_the_record_is_saved_also_for_an_administrator_without_unfiltered_html_and_its_content_survives(): void {
        // The site takes unfiltered_html from one administrator, as multisite or DISALLOW_UNFILTERED_HTML does.
        $this->site_code( 'qa-no-unfiltered-html', "add_filter( 'map_meta_cap', static function ( \$caps, \$cap, \$user_id ) {
            \$user = \$cap === 'unfiltered_html' ? get_userdata( (int) \$user_id ) : false;
            return \$user && \$user->user_login === 'qa-limited' ? [ 'do_not_allow' ] : \$caps;
        }, 10, 3 );" );
        $limited  = (int) $this->site()->wp( 'user', 'create', 'qa-limited', 'qa-limited@example.org', '--role=administrator', '--porcelain' );
        $agent    = new McpClient( $this->site(), 'qa-limited', trim( $this->site()->wp( 'user', 'application-password', 'create', (string) $limited, 'qa', '--porcelain' ) ) );

        // A page whose content an administrator who may post unfiltered HTML wrote: an embed.
        $page = $this->record( 'page', 'QA embed page' );
        $this->site()->wp( 'post', 'update', (string) $page, '--post_content=<!-- wp:html --><iframe src="https://www.youtube.com/embed/qa-embed"></iframe><!-- /wp:html -->', '--user=admin' );
        $content = $this->row( $page )['post_content'];
        $this->assertStringContainsString( '<iframe', (string) $content );
        $saves = $this->saves( $page );
        $this->age( $page );

        $answer = $agent->call( 'set-field-values', [ 'postId' => $page, 'values' => [ 'tagline' => 'Changed' ] ] );
        $this->assertFalse( $answer['error'], $answer['text'] );
        $this->assertSame( $saves + 1, $this->saves( $page ), 'saved like its Update button: WPML, SEO and caches follow' );
        $this->assertNotSame( self::OLD, $this->row( $page )['post_modified_gmt'] );
        $this->assertSame( [ 'Changed' ], $this->site()->meta( $page, 'fab_tagline' ) );
        $this->assertSame( $content, $this->row( $page )['post_content'], 'saving the fields does not run the content through kses' );
        $this->assertStringContainsString( 'youtube.com/embed/qa-embed', $this->get( $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'] )['body'], 'the visitor still gets the embed' );
    }

    /** The site's own code writes values only, as ACF's update_field(): no save, no new date. */
    public function test_the_sites_code_writing_values_leaves_the_record_and_its_date(): void {
        $page  = $this->record( 'page', 'QA code page' );
        $saves = $this->saves( $page );
        $this->age( $page );
        $this->assertTrue( $this->site_runs( "return fabrement()->fields( $page )->set( 'tagline', 'From an import' )->save();" ) );
        $this->assertSame( [ 'From an import' ], $this->site()->meta( $page, 'fab_tagline' ) );
        $this->assertSame( self::OLD, $this->row( $page )['post_modified_gmt'], 'its date stays: the code saves the record itself when it should' );
        $this->assertSame( $saves, $this->saves( $page ), 'no save_post' );
    }

    public function test_an_options_page_has_no_record_to_date(): void {
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-save-date', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA' ], 'fields' => [ self::field( 'phone', 'text' ) ] ] );
        $posts = $this->site()->value( 'SELECT MAX(post_modified_gmt) FROM wp_posts' );

        $this->assertSame( '+32 2 000', $this->mcp( 'set-field-values', [ 'options' => 'qa-save-date', 'values' => [ 'phone' => '+32 2 000' ] ] )['values']['phone'] );
        $this->assertSame( '+32 2 000', $this->site_runs( "return fabrement_get_option( 'phone', 'qa-save-date' );" ) );
        $this->assertSame( '+32 2 000', $this->site()->option( 'fabrement_options_qa-save-date' )['fab_phone'] );
        $this->assertSame( $posts, $this->site()->value( 'SELECT MAX(post_modified_gmt) FROM wp_posts' ), 'no record was saved for it' );

        $this->mcp( 'set-field-values', [ 'options' => 'qa-save-date', 'values' => [ 'phone' => '+32 2 111' ] ] );
        $this->assertSame( '+32 2 111', $this->site_runs( "return fabrement_get_option( 'phone', 'qa-save-date' );" ), 'changed on the next read' );
    }
}
