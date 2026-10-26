<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\FieldRules;
use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\McpClient;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Words (text, textarea, wysiwyg, a link's label) are saved as ACF saves them: as typed for someone
 * who may post unfiltered HTML, through wp_kses_post for anyone else; escaping is the printing
 * code's. Who writes: the administrator's agent over MCP; an administrator without unfiltered_html
 * (multisite, DISALLOW_UNFILTERED_HTML) over MCP; an author through the fields box's route (MCP is
 * the administrator's — it needs manage_options). What a visitor reads: the post's <title> built
 * from %%fab_note%% and the JSON-LD built from {{field:…}} — words without tags.
 *
 * The cleaning by the writer's rights belongs to the fields box's route and the MCP tools only: the
 * site's own code (fabrement()->fields()->set(), as ACF's update_field) keeps words as written whoever
 * runs it — that case lives in FieldsPhpApiTest.
 */
final class FieldWordsTest extends SiteTestCase {

    private static bool $ready = false;

    /** @var array{id:int,login:string,password:string}|null */
    private static ?array $author = null;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->fields( 'post', [
                self::field( 'note', 'text' ),
                self::field( 'about', 'textarea' ),
                self::field( 'body', 'wysiwyg' ),
                self::field( 'cta', 'link' ),
                self::field( 'box', 'group', [ 'sub_fields' => [ self::field( 'lead', 'text' ), self::field( 'tail', 'text' ) ] ] ),
            ] );
            $this->mcp( 'set-seo-defaults', [ 'postType' => 'post', 'titleTemplate' => '%%fab_note%%' ] );
            $this->mcp( 'set-schema', [ 'postType' => 'post', 'graph' => [ [
                '@type'               => 'CreativeWork',
                '@id'                 => '{{url}}#qa-words',
                'name'                => '{{field:note}}',
                'alternativeHeadline' => '{{field:cta.label}}',
            ] ] ] );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready  = false;
        self::$author = null;
        parent::tearDownAfterClass();
    }

    private function values( int $post ): array {
        return $this->mcp( 'get-field-values', [ 'postId' => $post ] )['values'];
    }

    private function url( int $post ): string {
        return $this->mcp( 'get-content', [ 'contentId' => $post ] )['url'];
    }

    /** @return array{title:string, raw:string, node:?array} What a visitor's copy of the post says. */
    private function visit( int $post ): array {
        $page = $this->get( $this->url( $post ) );
        $this->assertSame( 200, $page['status'] );
        return [ 'title' => FieldRules::title( $page['body'] ), 'raw' => FieldRules::raw_title( $page['body'] ), 'node' => self::node( self::json_ld( $page['body'] ), 'CreativeWork' ) ];
    }

    /** An author, made by the host with WP-CLI, with an application password for the editor's REST calls. */
    private function author(): array {
        if ( self::$author === null ) {
            $id = (int) $this->site()->wp( 'user', 'create', 'qa-author', 'qa-author@example.org', '--role=author', '--porcelain' );
            self::$author = [ 'id' => $id, 'login' => 'qa-author', 'password' => trim( $this->site()->wp( 'user', 'application-password', 'create', (string) $id, 'qa', '--porcelain' ) ) ];
        }
        return self::$author;
    }

    /** @return array{status:int, data:mixed} A REST call as the author's editor sends it. */
    private function as_author( string $method, string $path, ?array $body = null ): array {
        $author = $this->author();
        $answer = Http::request( $this->site(), $method, $this->site()->url( $path ), [
            'Authorization' => 'Basic ' . base64_encode( $author['login'] . ':' . $author['password'] ),
            'Content-Type'  => 'application/json',
        ], $body === null ? null : (string) json_encode( $body ) );
        return [ 'status' => $answer['status'], 'data' => json_decode( $answer['body'], true ) ];
    }

    /**
     * The fields box as the author uses it: the form opens with the record's values, some are
     * changed, and the changed fields are sent back with their intent. @return array The values the
     * form shows after the save.
     */
    private function box_saves( int $post, array $changes ): array {
        $form = $this->as_author( 'GET', '/wp-json/fabrement/v1/fields/post/' . $post );
        $this->assertSame( 200, $form['status'], (string) json_encode( $form['data'] ) );
        $values = $form['data']['values'];
        foreach ( $changes as $key => $value ) {
            $values[ $key ] = is_array( $value ) && is_array( $values[ $key ] ?? null ) ? array_merge( $values[ $key ], $value ) : $value;
        }
        $saved = $this->as_author( 'PUT', '/wp-json/fabrement/v1/fields/post/' . $post, [ 'values' => array_intersect_key( $values, $changes ), 'intent' => array_fill_keys( array_keys( $changes ), true ), 'intentVersion' => 1 ] );
        $this->assertSame( 200, $saved['status'], (string) json_encode( $saved['data'] ) );
        return $saved['data']['values'];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_administrators_words_are_kept_as_typed_and_reach_seo_and_schema_without_tags(): void {
        $post  = $this->record( 'post', 'QA typed words' );
        $video = '<p>Watch</p><iframe src="https://www.youtube.com/embed/x"></iframe>';
        $saved = $this->mcp( 'set-field-values', [ 'postId' => $post, 'values' => [
            'note'  => 'Children < 12 free, press <Enter>, see ?q=a%20b',
            'about' => "Line one\nPrice < 100 €",
            'body'  => $video,
            'cta'   => [ 'url' => '/contacts', 'label' => 'Tickets < 20 €' ],
        ] ] )['values'];
        $this->assertSame( 'Children < 12 free, press <Enter>, see ?q=a%20b', $saved['note'] );
        $this->assertSame( "Line one\nPrice < 100 €", $saved['about'] );
        $this->assertSame( $video, $saved['body'], 'an embed stays, as in post content' );
        $this->assertSame( 'Tickets < 20 €', $saved['cta']['label'] );
        $this->assertSame( [ 'Children < 12 free, press <Enter>, see ?q=a%20b' ], $this->site()->meta( $post, 'fab_note' ), 'stored as typed' );
        $this->assertSame( [ $video ], $this->site()->meta( $post, 'fab_body' ) );
        $this->assertSame( 'Children < 12 free, press <Enter>, see ?q=a%20b', $this->values( $post )['note'], 'read back as stored' );

        // Markup in the words: the visitor's title and the graph read words, tags left out.
        $this->mcp( 'set-field-values', [ 'postId' => $post, 'values' => [ 'note' => 'Kids <12 <b>free</b> x<5', 'cta' => [ 'url' => '/x', 'label' => '<i>Tickets</i> & more' ] ] ] );
        $this->assertSame( [ 'Kids <12 <b>free</b> x<5' ], $this->site()->meta( $post, 'fab_note' ) );
        $seen = $this->visit( $post );
        $this->assertSame( 'Kids <12 free x<5', $seen['title'], 'a "<" that opens no tag is a word' );
        $this->assertStringNotContainsString( '<b>', $seen['raw'], 'no markup in the head' );
        $this->assertNotNull( $seen['node'], 'the post carries the type\'s node' );
        $this->assertSame( 'Kids <12 free x<5', $seen['node']['name'] );
        $this->assertSame( 'Tickets & more', $seen['node']['alternativeHeadline'] );

        // Changed again: the next visitor reads the new words.
        $this->mcp( 'set-field-values', [ 'postId' => $post, 'values' => [ 'note' => 'Adults only' ] ] );
        $seen = $this->visit( $post );
        $this->assertSame( 'Adults only', $seen['title'] );
        $this->assertSame( 'Adults only', $seen['node']['name'] );
    }

    public function test_an_author_saving_through_the_fields_box_gets_what_kses_lets_through(): void {
        $author = $this->author();
        $this->assertContains(
            ( new McpClient( $this->site(), $author['login'], $author['password'] ) )->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'qa', 'version' => '1' ] ] )['_http']['status'],
            [ 401, 403 ], 'the MCP server is the administrator\'s: an author writes through the fields box'
        );

        $made = $this->as_author( 'POST', '/wp-json/wp/v2/posts', [ 'title' => 'QA author words', 'status' => 'publish' ] );
        $this->assertSame( 201, $made['status'], (string) json_encode( $made['data'] ) );
        $post = (int) $made['data']['id'];

        $this->box_saves( $post, [
            'fab_note'  => 'Children <12 & Tom <script>alert(1)</script><b onclick="x()">free</b>',
            'fab_about' => "Price <100 €\nA & B\n",
            'fab_body'  => '<p onclick="x()">Text</p><script>alert(1)</script><iframe src="https://evil.example"></iframe>',
            'fab_cta'   => [ 'url' => '/contacts', 'label' => '<img src=x onerror=alert(1)>Go' ],
        ] );

        // As ACF saves them: a "<" that opens no tag and "&" kept as entities, safe markup kept, the rest gone.
        $this->assertSame( [ 'Children &lt;12 &amp; Tom alert(1)<b>free</b>' ], $this->site()->meta( $post, 'fab_note' ) );
        $this->assertSame( [ "Price &lt;100 €\nA &amp; B\n" ], $this->site()->meta( $post, 'fab_about' ), 'spaces and line breaks stay' );
        $values = $this->values( $post );
        $this->assertStringNotContainsString( '<script', $values['body'] );
        $this->assertStringNotContainsString( '<iframe', $values['body'] );
        $this->assertStringNotContainsString( 'onclick', $values['body'] );
        $this->assertStringContainsString( 'Text', $values['body'] );
        $this->assertStringNotContainsString( 'onerror', $values['cta']['label'] );
        $this->assertStringContainsString( 'Go', $values['cta']['label'] );

        $seen = $this->visit( $post );
        $this->assertSame( 'Children <12 & Tom alert(1)free', $seen['title'], 'what a reader sees' );
        $this->assertSame( 'Children <12 & Tom alert(1)free', $seen['node']['name'], 'schema text has no entities' );
        $this->assertStringNotContainsString( '<script', $seen['raw'] );

        // Markup that rebuilds itself or is left open never becomes a tag — in the database or the head.
        foreach ( [ '<<b>img src=x onerror=alert(1)>', '<<</b>svg onload=alert(1)>', '<scr<b>ipt>alert(1)</scr<b>ipt>', 'Hello <img src=x onerror=alert(1)', '<a href="x<y" onmouseover=alert(1)>t</a>', '<<<b>/b>/script>' ] as $attack ) {
            $this->box_saves( $post, [ 'fab_note' => $attack ] );
            $stored = (string) ( $this->site()->meta( $post, 'fab_note' )[0] ?? '' );
            $this->assertDoesNotMatchRegularExpression( '@<\s*(img|svg|script)\b|<[a-z][^>]*\son[a-z]+\s*=@i', $stored, $attack . ' → ' . $stored );
            $this->assertDoesNotMatchRegularExpression( '@<\s*(img|svg|script)\b|<[a-z][^>]*\son[a-z]+\s*=@i', $this->visit( $post )['raw'], $attack . ' in the title' );
        }
    }

    public function test_the_administrator_saving_through_the_fields_box_keeps_the_words_as_typed(): void {
        $post = $this->record( 'post', 'QA admin box words' );
        $send = fn( string $method, ?array $body = null ): array => Http::request( $this->site(), $method, $this->site()->url( '/wp-json/fabrement/v1/fields/post/' . $post ), [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ),
            'Content-Type'  => 'application/json',
        ], $body === null ? null : (string) json_encode( $body ) );
        $form   = json_decode( $send( 'GET' )['body'], true );
        $values = (array) ( $form['values'] ?? [] );
        $video  = '<p>Watch</p><iframe src="https://www.youtube.com/embed/x"></iframe>';
        $values['fab_note'] = 'Kids <12';
        $values['fab_body'] = $video;
        $saved = $send( 'PUT', [ 'values' => [ 'fab_note' => $values['fab_note'], 'fab_body' => $values['fab_body'] ], 'intent' => [ 'fab_note' => true, 'fab_body' => true ], 'intentVersion' => 1 ] );
        $this->assertSame( 200, $saved['status'], $saved['body'] );
        $this->assertSame( [ 'Kids <12' ], $this->site()->meta( $post, 'fab_note' ), 'unfiltered_html: as typed, as in post content' );
        $this->assertSame( [ $video ], $this->site()->meta( $post, 'fab_body' ), 'the embed stays' );
        $this->assertSame( 'Kids <12', $this->visit( $post )['title'] );
    }

    public function test_an_administrator_without_unfiltered_html_is_held_to_kses_over_mcp_too(): void {
        // The site takes unfiltered_html from one administrator, as multisite or DISALLOW_UNFILTERED_HTML does.
        $this->site_code( 'qa-no-unfiltered-html', "add_filter( 'map_meta_cap', static function ( \$caps, \$cap, \$user_id ) {
            \$user = \$cap === 'unfiltered_html' ? get_userdata( (int) \$user_id ) : false;
            return \$user && \$user->user_login === 'qa-limited' ? [ 'do_not_allow' ] : \$caps;
        }, 10, 3 );" );
        $limited  = (int) $this->site()->wp( 'user', 'create', 'qa-limited', 'qa-limited@example.org', '--role=administrator', '--porcelain' );
        $password = trim( $this->site()->wp( 'user', 'application-password', 'create', (string) $limited, 'qa', '--porcelain' ) );
        $agent    = new McpClient( $this->site(), 'qa-limited', $password );
        $this->assertSame( [ false, true ], $this->site_runs( "return [ user_can( $limited, 'unfiltered_html' ), user_can( $limited, 'manage_options' ) ];" ), 'an administrator still: MCP lets them in' );

        $post   = $this->record( 'post', 'QA limited admin' );
        $answer = $agent->call( 'set-field-values', [ 'postId' => $post, 'values' => [
            'note' => '<script>alert(1)</script><b onclick="x()">free</b> <12',
            'body' => '<p>Watch</p><iframe src="https://www.youtube.com/embed/x"></iframe>',
        ] ] );
        $this->assertFalse( $answer['error'], $answer['text'] );
        $this->assertSame( 'alert(1)<b>free</b> &lt;12', $answer['data']['values']['note'], 'the answer shows what was kept' );
        $this->assertSame( [ 'alert(1)<b>free</b> &lt;12' ], $this->site()->meta( $post, 'fab_note' ) );
        $this->assertStringNotContainsString( '<iframe', (string) $this->site()->meta( $post, 'fab_body' )[0] );
        $this->assertSame( 'alert(1)free <12', $this->visit( $post )['title'] );

        // The administrator who may post unfiltered HTML writes the same words: kept as typed.
        $this->mcp( 'set-field-values', [ 'postId' => $post, 'values' => [ 'note' => '<b onclick="x()">free</b> <12' ] ] );
        $this->assertSame( [ '<b onclick="x()">free</b> <12' ], $this->site()->meta( $post, 'fab_note' ) );
        $this->assertSame( 'free <12', $this->visit( $post )['title'] );
    }

    public function test_words_nobody_touched_are_kept_as_stored_when_the_form_comes_back_whole(): void {
        $post = $this->record( 'post', 'QA untouched words' );
        $this->mcp( 'set-field-values', [ 'postId' => $post, 'values' => [ 'note' => '<b>Bold</b> <12', 'box' => [ 'lead' => '<b>New</b> <12', 'tail' => 'x' ] ] ] );
        // The host hands the post to the author.
        $this->site()->wp( 'post', 'update', (string) $post, '--post_author=' . $this->author()['id'] );

        // The fields box sends the whole form: a changed group with its untouched sub-field, the untouched note.
        $shown = $this->box_saves( $post, [ 'fab_box' => [ 'tail' => 'changed' ] ] );
        $this->assertSame( '<b>New</b> <12', $shown['fab_box']['lead'] ?? null );
        $this->assertSame( [ '<b>New</b> <12' ], $this->site()->meta( $post, 'fab_box_lead' ), 'not cleaned again: kept byte for byte' );
        $this->assertSame( [ 'changed' ], $this->site()->meta( $post, 'fab_box_tail' ) );
        $this->assertSame( [ '<b>Bold</b> <12' ], $this->site()->meta( $post, 'fab_note' ), 'a field the author did not touch' );
        $this->assertSame( 'Bold <12', $this->visit( $post )['title'] );

        // What the author changes is theirs, and goes through kses.
        $this->box_saves( $post, [ 'fab_box' => [ 'lead' => '<b>Newer</b> <12' ] ] );
        $this->assertSame( [ '<b>Newer</b> &lt;12' ], $this->site()->meta( $post, 'fab_box_lead' ) );
        $this->assertSame( [ 'changed' ], $this->site()->meta( $post, 'fab_box_tail' ) );
    }
}
