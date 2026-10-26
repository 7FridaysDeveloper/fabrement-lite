<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\TemplateSides;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * A template's sides on a site with languages. A language version is a template of its own with
 * its own sides; every version places the ORIGINAL global block, and a reader is handed that
 * global block's version in their language — so what is checked here is what the database holds
 * after each action, and what the template says when a language is left without its header.
 */
final class TemplateSidesLanguagesTest extends SiteTestCase {

    use TemplateSides;

    protected const SITE = 'wpml';

    private function wpml(): WpmlSite {
        return new WpmlSite( $this->site() );
    }

    /** @return array{0:int,1:int} An English template and the own header global block it places. */
    private function template( string $title ): array {
        $header = $this->own_global_block( 'header', $title . ' header' );
        $saved  = $this->mcp( 'save-template', self::frame( $title, $header ) );
        return [ (int) $saved['template']['id'], $header ];
    }

    private function title_of( int $post ): string {
        return (string) $this->site()->value( 'SELECT post_title FROM wp_posts WHERE ID = ?', [ $post ] );
    }

    /** A template as get-template gives it (a version is read by its own id). */
    private function read( int $template ): array {
        return (array) $this->mcp( 'get-template', [ 'templateId' => $template ] )['template'];
    }

    public function test_a_version_is_changed_by_its_own_id_and_keeps_its_title(): void {
        [ $template, $header ] = $this->template( 'Versioned' );
        $footer                = $this->own_global_block( 'footer', 'Versioned footer' );
        $opened                = $this->mcp( 'translate-template', [ 'templateId' => $template, 'language' => 'fr' ] );
        $version               = (int) $opened['templateId'];
        $this->assertSame( 'Versioned (fr)', $opened['title'], 'a new version is named after its original, with its language' );
        $this->assertSame( 'Versioned (fr)', $this->title_of( $version ) );
        $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $version, 'title' => 'Modèle' ] );

        // "Change blocks" on the version's row: only the side that changed, no title.
        $saved = $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $version, 'blocks' => self::frame( '', $header, $footer )['blocks'] ] );

        $this->assertSame( 'Modèle', $this->title_of( $version ), 'the version keeps its own name' );
        $this->assertSame( $footer, $this->side_of( $version, 'footer' ) );
        $this->assertSame( $header, $this->side_of( $version, 'header' ) );
        $this->assertSame( 0, $this->side_of( $template, 'footer' ), 'the original is another template: untouched' );
        $this->assertSame( 'Versioned', $this->title_of( $template ) );

        // The version drops its header; the original keeps it.
        $saved = $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $version, 'blocks' => self::frame( '', 0, $footer )['blocks'] ] );
        $this->assertSame( 0, $this->side_of( $version, 'header' ) );
        $this->assertSame( $header, $this->side_of( $template, 'header' ) );
        $content = (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $version ] );
        $this->assertStringNotContainsString( '"ref":' . $header . '}', $content );
        $this->assertSame( 1, substr_count( $content, '"ref":' . $footer . '}' ) );

        $this->assertSame( $footer, $this->read( $version )['blocks'][1]['attributes']['ref'] );
    }

    public function test_a_language_version_of_a_header_that_is_not_published_is_the_patterns_business_not_the_templates(): void {
        [ $template, $header ] = $this->template( 'Draft language' );
        $french                = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $header, 'language' => 'fr' ] )['patternId'];
        $this->assertSame( '', $this->read( $template )['problem'] );

        // The French version is set to draft in the Patterns screen: French pages get no header.
        $this->site_runs( 'wp_update_post( [ "ID" => ' . $french . ', "post_status" => "draft" ] ); return true;' );

        // A language version of a global block is the pattern's business: get-synced-pattern says it.
        $this->assertSame( '', $this->read( $template )['problem'] );

        $this->site_runs( 'wp_update_post( [ "ID" => ' . $french . ', "post_status" => "publish" ] ); return true;' );
        $this->assertSame( '', $this->read( $template )['problem'] );
    }

    public function test_a_copy_made_with_wpmls_own_duplicate_places_the_same_sides(): void {
        [ $template, $header ] = $this->template( 'Duplicated' );

        $copy = $this->wpml()->duplicate( $template, 'pl' );

        $this->assertGreaterThan( 0, $copy );
        $this->assertSame( 'pl', $this->wpml()->language( $copy, 'fabrement_template' ) );
        $this->assertSame( $header, $this->side_of( $copy, 'header' ) );
        $this->assertSame( 1, preg_match_all( '#"ref":' . $header . '[,}]#', (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $copy ] ) ), 'its content places the header' );
        $read = $this->read( $copy );
        $this->assertSame( '', $read['problem'] );
        $this->assertSame( [ 'name' => 'core/block', 'attributes' => [ 'ref' => $header ] ], $read['blocks'][0] );
    }

    // ── what a reader of each language gets ──────────────────────────────────────────────────

    /**
     * A page in English and its French version, both wearing the template.
     *
     * @return array{en:string,fr:string} Their addresses.
     */
    private function pages( int $template, string $name ): array {
        $page = $this->record( 'page', $name );
        $this->mcp( 'assign-template-pages', [ 'templateId' => $template, 'pageIds' => [ $page ], 'mode' => 'add' ] );
        $french = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'fr', 'slug' => strtolower( str_replace( ' ', '-', $name ) ) . '-fr' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $french, 'status' => 'publish' ] );
        return [
            'en' => (string) $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'],
            'fr' => (string) $this->mcp( 'get-content', [ 'contentId' => $french ] )['url'],
        ];
    }

    /** A paragraph global block's body with a mark to find it by on a page. */
    private static function words( string $mark ): array {
        return [ [ 'markup' => '<!-- wp:paragraph --><p class="' . $mark . '">Words</p><!-- /wp:paragraph -->' ] ];
    }

    public function test_each_language_reads_its_version_of_the_header_and_the_original_where_it_has_none(): void {
        $header   = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Read header', 'kind' => 'header', 'blocks' => self::words( 'qa-read-en' ) ] )['patternId'];
        $template = (int) $this->mcp( 'save-template', self::frame( 'Read', $header ) )['template']['id'];
        $urls     = $this->pages( $template, 'Read page' );

        // No French version of the header yet: French readers get the original's words.
        $this->assertSame( 1, substr_count( $this->get( $urls['en'] )['body'], 'qa-read-en' ) );
        $this->assertSame( 1, substr_count( $this->get( $urls['fr'] )['body'], 'qa-read-en' ), 'no version: the original' );

        // A French version with its own words; the template is not touched.
        $french = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $header, 'language' => 'fr' ] )['patternId'];
        $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $french, 'blocks' => self::words( 'qa-read-fr' ) ] );
        $this->assertSame( $header, $this->side_of( $template, 'header' ), 'the template still places the original' );

        $en = $this->get( $urls['en'] )['body'];
        $fr = $this->get( $urls['fr'] )['body'];
        $this->assertSame( [ 1, 0 ], [ substr_count( $en, 'qa-read-en' ), substr_count( $en, 'qa-read-fr' ) ], 'English: the original' );
        $this->assertSame( [ 0, 1 ], [ substr_count( $fr, 'qa-read-en' ), substr_count( $fr, 'qa-read-fr' ) ], 'French: its version' );
        $this->assertSame( '', $this->read( $template )['problem'] );

        // The French version goes to draft: what the problem sentence says is what a French reader gets.
        $this->site_runs( 'wp_update_post( [ "ID" => ' . $french . ', "post_status" => "draft" ] ); return true;' );
        $fr = $this->get( $urls['fr'] );
        $this->assertSame( 200, $fr['status'] );
        $this->assertSame( [ 0, 0 ], [ substr_count( $fr['body'], 'qa-read-en' ), substr_count( $fr['body'], 'qa-read-fr' ) ], 'French pages show no header' );
        $this->assertSame( 1, substr_count( $this->get( $urls['en'] )['body'], 'qa-read-en' ), 'English is as it was' );

        // The French version is deleted (it is not what the template places, so nothing guards it): the original again.
        $auth    = [ 'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ) ];
        $deleted = \Fabrement\Tests\Site\Support\Http::request( $this->site(), 'DELETE', $this->site()->url( '/wp-json/wp/v2/blocks/' . $french . '?force=true' ), $auth );
        $this->assertSame( 200, $deleted['status'], $deleted['body'] );
        $this->assertNull( $this->site()->value( 'SELECT ID FROM wp_posts WHERE ID = ?', [ $french ] ) );
        $this->assertSame( 1, substr_count( $this->get( $urls['fr'] )['body'], 'qa-read-en' ) );
        $this->assertSame( '', $this->read( $template )['problem'] );
        $this->assertSame( 'publish', $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $header ] ), 'the original is untouched' );
    }

    public function test_a_language_version_of_a_template_with_its_own_sides_and_what_its_readers_get(): void {
        $header   = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Own sides header', 'kind' => 'header', 'blocks' => self::words( 'qa-sides-header' ) ] )['patternId'];
        $footer   = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Own sides French footer', 'kind' => 'footer', 'blocks' => self::words( 'qa-sides-fr-footer' ) ] )['patternId'];
        $template = (int) $this->mcp( 'save-template', self::frame( 'Own sides', $header ) )['template']['id'];
        $version  = (int) $this->mcp( 'translate-template', [ 'templateId' => $template, 'language' => 'fr' ] )['templateId'];
        $urls     = $this->pages( $template, 'Own sides page' );
        $marks    = fn( string $url ): array => [ substr_count( $this->get( $url )['body'], 'qa-sides-header' ), substr_count( $this->get( $url )['body'], 'qa-sides-fr-footer' ) ];

        // The French version gets a footer of its own.
        $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $version, 'blocks' => self::frame( '', $header, $footer )['blocks'] ] );
        $this->assertSame( [ 1, 0 ], $marks( $urls['en'] ), 'English: the original template' );
        $this->assertSame( [ 1, 1 ], $marks( $urls['fr'] ), 'French: its own version, with the footer' );

        // That footer goes to draft: French pages keep their version, with nothing in the footer's place.
        $this->site_runs( 'wp_update_post( [ "ID" => ' . $footer . ', "post_status" => "draft" ] ); return true;' );
        $this->assertSame( '', $this->read( $template )['problem'] );
        $this->assertStringContainsString( 'The global block "Own sides French footer" is not published (draft)', $this->read( $version )['problem'] );
        $this->assertSame( [ 1, 0 ], $marks( $urls['fr'] ), 'the header, and an empty place for the footer' );
        $this->assertSame( $footer, $this->side_of( $version, 'footer' ), 'the version still names its footer' );

        $this->site_runs( 'wp_update_post( [ "ID" => ' . $footer . ', "post_status" => "publish" ] ); return true;' );
        $this->assertSame( [ 1, 1 ], $marks( $urls['fr'] ) );

        // The version drops the header: French pages have none, English pages keep theirs.
        $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $version, 'blocks' => self::frame( '', 0, $footer )['blocks'] ] );
        $this->assertSame( [ 0, 1 ], $marks( $urls['fr'] ) );
        $this->assertSame( [ 1, 0 ], $marks( $urls['en'] ) );
        $this->assertSame( $header, $this->side_of( $template, 'header' ) );
    }

    // ── deleting ─────────────────────────────────────────────────────────────────────────────

    public function test_a_version_is_deleted_alone_and_the_original_takes_its_versions_with_it(): void {
        [ $template, $header ] = $this->template( 'Deleted group' );
        $french  = (int) $this->mcp( 'translate-template', [ 'templateId' => $template, 'language' => 'fr' ] )['templateId'];
        $polish  = (int) $this->mcp( 'translate-template', [ 'templateId' => $template, 'language' => 'pl' ] )['templateId'];
        $key     = (string) ( $this->site()->meta( $template, '_fabrement_st_key' )[0] ?? '' );
        $file    = '/var/www/html/wp-content/uploads/fabrement-lite/templates/' . $key . '.json';
        $has_file = fn(): bool => trim( $this->site()->exec( 'sh', '-c', 'test -f ' . escapeshellarg( $file ) . ' && echo yes || echo no' ) ) === 'yes';
        $rows    = fn(): array => array_map( 'intval', array_column( $this->site()->rows( 'SELECT ID FROM wp_posts WHERE ID IN ( ?, ?, ? ) ORDER BY ID', [ $template, $french, $polish ] ), 'ID' ) );
        $this->assertNotSame( '', $key );
        $this->assertSame( [ $key ], $this->site()->meta( $french, '_fabrement_st_key' ), 'the versions share the original\'s rules file' );
        $this->assertTrue( $has_file() );
        $this->assertSame( [ 'en' => $template, 'fr' => $french, 'pl' => $polish ], $this->wpml()->group( $template, 'fabrement_template' ) );

        // The header is placed by all three: still guarded.
        $this->assertStringContainsString( 'It cannot be deleted while they place it', $this->mcp_error( 'delete-synced-pattern', [ 'patternId' => $header ] ) );

        // One version goes alone.
        $deleted = $this->mcp( 'delete-template', [ 'templateId' => $french ] );
        $this->assertTrue( $deleted['deleted'] );
        $this->assertSame( [ $template, $polish ], $rows() );
        $this->assertSame( [ 'en' => $template, 'pl' => $polish ], $this->wpml()->group( $template, 'fabrement_template' ), 'WPML holds no row of the deleted version' );
        $this->assertTrue( $has_file(), 'the rules file is the group\'s: it stays' );
        $this->assertSame( $header, $this->side_of( $polish, 'header' ) );

        // The original takes the rest with it.
        $gone = $this->mcp( 'delete-template', [ 'templateId' => $template ] );
        $this->assertTrue( $gone['deleted'] );
        $this->assertSame( [ 'pl' => $polish ], $gone['deletedTranslations'] );
        $this->assertSame( [], $rows(), 'no template of the group is left, in the trash either' );
        $this->assertSame( [], $this->site()->rows( 'SELECT meta_id FROM wp_postmeta WHERE post_id IN ( ?, ?, ? )', [ $template, $french, $polish ] ) );
        $this->assertSame( [], $this->site()->rows( 'SELECT translation_id FROM wp_icl_translations WHERE element_type = "post_fabrement_template" AND element_id IN ( ?, ?, ? )', [ $template, $french, $polish ] ) );
        $this->assertFalse( $has_file(), 'the rules file goes with the last of them' );

        // What they placed stays, and is free to go.
        $this->assertSame( 'publish', $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $header ] ) );
        $this->mcp( 'delete-synced-pattern', [ 'patternId' => $header ] );
        $this->assertNull( $this->site()->value( 'SELECT ID FROM wp_posts WHERE ID = ?', [ $header ] ) );
    }

    public function test_a_version_is_given_to_nothing_and_the_refusal_names_the_original(): void {
        [ $template ] = $this->template( 'Not assignable' );
        $version      = (int) $this->mcp( 'translate-template', [ 'templateId' => $template, 'language' => 'fr' ] )['templateId'];
        $page         = $this->record( 'page', 'Not assignable page' );
        $pick         = $this->site()->meta( $page, '_wp_page_template' );

        foreach ( [
            'assign-template-pages'   => [ 'templateId' => $version, 'pageIds' => [ $page ], 'mode' => 'add' ],
            'set-default-template'    => [ 'templateId' => $version, 'postType' => 'page' ],
            'assign-template-archive' => [ 'templateId' => $version, 'context' => 'blog-home' ],
        ] as $tool => $input ) {
            $refusal = $this->mcp_error( $tool, $input );
            $this->assertStringContainsString( 'is a language version', $refusal, $tool );
            $this->assertStringContainsString( '(' . $template . ')', $refusal, $tool . ' names the original to use' );
        }
        $this->assertSame( $pick, $this->site()->meta( $page, '_wp_page_template' ), 'nothing was written' );
        $this->assertSame( [], $this->read( $template )['defaultFor'] );
    }

    public function test_an_agent_places_the_original_of_a_global_block_and_is_told_which_it_is(): void {
        [ $template, $header ] = $this->template( 'Original only' );
        $french                = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $header, 'language' => 'fr' ] )['patternId'];
        $slot                  = [ 'name' => 'fabrement/content-source' ];

        // The version's id is refused, in a new template and in an edit, with the original named.
        $refusal = $this->mcp_error( 'save-template', [ 'mode' => 'create', 'title' => 'Original only French', 'blocks' => [ [ 'name' => 'core/block', 'attributes' => [ 'ref' => $french ] ], $slot ] ] );
        $this->assertStringContainsString( 'is a language version', $refusal );
        $this->assertStringContainsString( '{"ref": ' . $header . '}', $refusal );
        $read    = $this->read( $template );
        $refusal = $this->mcp_error( 'save-template', [ 'mode' => 'edit', 'templateId' => $template, 'expectedHash' => $read['hash'], 'edits' => [
            [ 'op' => 'insert', 'at' => 0, 'block' => [ 'name' => 'core/block', 'attributes' => [ 'ref' => $french ] ] ],
        ] ] );
        $this->assertStringContainsString( 'is a language version', $refusal );
        $this->assertStringNotContainsString( '"ref":' . $french, (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $template ] ), 'nothing was written' );

        // A page is the same: the original of a section, never its version — by name and as markup.
        $section = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Original only section', 'blocks' => self::words( 'qa-original-only-section' ) ] )['patternId'];
        $version = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $section, 'language' => 'fr' ] )['patternId'];
        $page    = $this->record( 'page', 'Original only page' );
        foreach ( [ [ 'name' => 'core/block', 'attributes' => [ 'ref' => $version ] ], [ 'markup' => '<!-- wp:block {"ref":' . $version . '} /-->' ] ] as $entry ) {
            $refusal = $this->mcp_error( 'save-content', [ 'contentId' => $page, 'mode' => 'replace', 'blocks' => [ $entry ] ] );
            $this->assertStringContainsString( 'is a language version', $refusal );
            $this->assertStringContainsString( '{"ref": ' . $section . '}', $refusal );
        }
        $this->mcp( 'save-content', [ 'contentId' => $page, 'mode' => 'replace', 'blocks' => [ [ 'name' => 'core/block', 'attributes' => [ 'ref' => $section ] ] ] ] );
        $this->assertStringContainsString( '"ref":' . $section . '}', (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $page ] ) );

        // Saving the version answers the markup that places the original.
        $saved = $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $french, 'blocks' => self::words( 'qa-original-only-fr' ) ] );
        $this->assertSame( '<!-- wp:block {"ref":' . $header . '} /-->', $saved['placement'] );
        $this->assertContains( $template, array_column( $this->mcp( 'get-synced-pattern', [ 'patternId' => $header ] )['templates'], 'id' ), 'and the template is found by the original' );
    }

    public function test_a_new_global_block_is_made_in_the_language_asked_and_only_when_said_to_be_a_new_original(): void {
        $body = self::words( 'qa-language-of-new' );

        $default = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'New in default', 'kind' => 'header', 'blocks' => $body ] )['patternId'];
        $this->assertSame( 'en', $this->wpml()->language( $default, 'wp_block' ) );

        // Another language is a new original only when the caller says so.
        $refusal = $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'New in French', 'kind' => 'header', 'language' => 'fr', 'blocks' => $body ] );
        $this->assertStringContainsString( 'translate-synced-pattern', $refusal );
        $this->assertStringContainsString( 'newOriginal: true', $refusal );
        $this->assertNull( $this->site()->value( 'SELECT ID FROM wp_posts WHERE post_type = "wp_block" AND post_title = "New in French"' ), 'nothing was made' );

        $french = $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'New in French', 'kind' => 'header', 'language' => 'fr', 'newOriginal' => true, 'blocks' => $body ] );
        $id     = (int) $french['patternId'];
        $this->assertSame( 'fr', $this->wpml()->language( $id, 'wp_block' ) );
        $read = $this->mcp( 'get-synced-pattern', [ 'patternId' => $id ] );
        $this->assertSame( [ 'fr', true ], [ $read['language'], $read['isOriginal'] ] );
        $this->assertSame( '<!-- wp:block {"ref":' . $id . '} /-->', $french['placement'], 'it is its own original, placed by its own id' );

        // Not a language of the site, and not on an existing one.
        $this->assertStringContainsString( 'is not a language on this site', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'New in Klingon', 'language' => 'xx', 'newOriginal' => true, 'blocks' => $body ] ) );
        $this->assertStringContainsString( '`language` is for CREATE', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $default, 'language' => 'fr', 'newOriginal' => true, 'blocks' => $body ] ) );

        // A version of a placed global block is not made a draft either: its language's pages would show nothing there.
        $this->mcp( 'save-template', self::frame( 'Language of new', $default ) );
        $version = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $default, 'language' => 'fr' ] )['patternId'];
        $this->assertStringContainsString( 'As a draft it would draw nothing there', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $version, 'status' => 'draft' ] ) );
    }

    public function test_the_frames_own_words_are_the_template_versions_and_each_language_reads_its_own(): void {
        [ $template, $header ] = $this->template( 'Own words' );
        $read                  = $this->read( $template );
        $this->mcp( 'save-template', [ 'mode' => 'edit', 'templateId' => $template, 'expectedHash' => $read['hash'], 'edits' => [
            [ 'op' => 'insert', 'at' => 1, 'block' => [ 'markup' => '<!-- wp:paragraph --><p class="qa-own-words">Notice in English</p><!-- /wp:paragraph -->' ] ],
        ] ] );
        $urls = $this->pages( $template, 'Own words page' );

        // No French version of the template yet: French readers get the original's words.
        $this->assertStringContainsString( 'Notice in English', $this->get( $urls['fr'] )['body'] );

        $opened = $this->mcp( 'translate-template', [ 'templateId' => $template, 'language' => 'fr' ] );
        $this->mcp( 'save-template', [ 'mode' => 'edit', 'templateId' => (int) $opened['templateId'], 'expectedHash' => $opened['hash'], 'edits' => [
            [ 'op' => 'text', 'index' => 1, 'name' => 'core/paragraph', 'old' => 'Notice in English', 'new' => 'Avis en français' ],
        ] ] );

        $fr = $this->get( $urls['fr'] )['body'];
        $en = $this->get( $urls['en'] )['body'];
        $this->assertStringContainsString( 'Avis en français', $fr );
        $this->assertStringNotContainsString( 'Notice in English', $fr );
        $this->assertStringContainsString( 'Notice in English', $en );
        $this->assertStringNotContainsString( 'Avis en français', $en );
        $this->assertSame( $header, $this->side_of( (int) $opened['templateId'], 'header' ), 'and the version places the same header' );
    }

    public function test_the_templates_placing_a_global_block_say_their_language_and_which_are_versions_of_one(): void {
        [ $template, $header ] = $this->template( 'Counted' );
        $version               = (int) $this->mcp( 'translate-template', [ 'templateId' => $template, 'language' => 'fr' ] )['templateId'];

        $rows = array_column( $this->mcp( 'get-synced-pattern', [ 'patternId' => $header ] )['templates'], null, 'id' );
        $this->assertSame( [ 'id' => $template, 'title' => 'Counted', 'language' => 'en' ], $rows[ $template ], 'the original has no originalId' );
        $this->assertSame( [ 'id' => $version, 'title' => 'Counted (fr)', 'language' => 'fr', 'originalId' => $template ], $rows[ $version ] );

        $listed = array_column( $this->mcp( 'list-synced-patterns', [ 'kind' => 'header', 'search' => 'Counted' ] )['patterns'], null, 'id' );
        $this->assertSame( $template, array_column( $listed[ $header ]['templates'], 'originalId', 'id' )[ $version ] );

        $refusal = $this->mcp_error( 'delete-synced-pattern', [ 'patternId' => $header ] );
        $this->assertStringContainsString( $version . ' "Counted (fr)" (the fr version of ' . $template . ')', $refusal );
    }

    public function test_a_versions_save_leaves_the_shared_rules_file_named_after_the_original(): void {
        [ $template ] = $this->template( 'Named file' );
        $key          = (string) $this->site()->meta( $template, '_fabrement_st_key' )[0];
        $title        = fn(): string => (string) $this->site_runs( 'return json_decode( (string) file_get_contents( wp_upload_dir()["basedir"] . "/fabrement-lite/templates/' . $key . '.json" ), true )["title"] ?? "";' );
        $this->assertSame( 'Named file', $title() );

        $opened = $this->mcp( 'translate-template', [ 'templateId' => $template, 'language' => 'fr' ] );
        $this->mcp( 'save-template', [ 'mode' => 'edit', 'templateId' => (int) $opened['templateId'], 'expectedHash' => $opened['hash'], 'edits' => [
            [ 'op' => 'insert', 'at' => 1, 'block' => [ 'markup' => '<!-- wp:paragraph --><p>Avis</p><!-- /wp:paragraph -->' ] ],
        ] ] );
        $this->assertSame( 'Named file', $title(), 'the version\'s own name ("Named file (fr)") is not written into it' );

        // The original renamed, the file follows.
        $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $template, 'title' => 'Named file anew' ] );
        $this->assertSame( 'Named file anew', $title() );
    }

    public function test_an_update_cannot_swap_a_placed_global_block_for_a_language_version(): void {
        $section = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Update only original', 'blocks' => self::words( 'qa-update-original' ) ] )['patternId'];
        $version = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $section, 'language' => 'fr' ] )['patternId'];
        $page    = $this->record( 'page', 'Update only original page' );
        $this->mcp( 'save-content', [ 'contentId' => $page, 'mode' => 'replace', 'blocks' => [ [ 'name' => 'core/block', 'attributes' => [ 'ref' => $section ] ] ] ] );

        $refusal = $this->mcp_error( 'save-content', [ 'contentId' => $page, 'mode' => 'edit', 'edits' => [ [ 'op' => 'update', 'index' => 0, 'name' => 'core/block', 'attributes' => [ 'ref' => $version ] ] ] ] );
        $this->assertStringContainsString( 'is a language version', $refusal );
        $this->assertStringContainsString( '{"ref": ' . $section . '}', $refusal );
        $this->assertStringContainsString( '"ref":' . $section . '}', (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $page ] ) );
    }

    public function test_a_language_version_of_a_global_block_answers_the_places_its_original_stands(): void {
        [ $template, $header ] = $this->template( 'Shown through' );
        $version               = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $header, 'language' => 'fr' ] )['patternId'];

        $read = $this->mcp( 'get-synced-pattern', [ 'patternId' => $version ] );
        $this->assertSame( $header, $read['shownThrough'] );
        $this->assertSame( [ $template ], array_column( $read['usedBy'], 'id' ), 'not "used by nothing"' );
        $this->assertSame( [ $template ], array_column( $read['templates'], 'id' ) );

        $saved = $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $version, 'blocks' => self::words( 'qa-shown-through-fr' ) ] );
        $this->assertSame( [ $header, [ $template ] ], [ $saved['shownThrough'], array_column( $saved['usedBy'], 'id' ) ] );

        // The original says nothing of the kind.
        $this->assertArrayNotHasKey( 'shownThrough', $this->mcp( 'get-synced-pattern', [ 'patternId' => $header ] ) );
    }

    public function test_source_rules_edited_in_a_translation_are_shared_with_the_original(): void {
        [ $original ] = $this->template( 'Shared sources' );
        $version = (int) $this->mcp( 'translate-template', [ 'templateId' => $original, 'language' => 'fr' ] )['templateId'];
        $file = (string) $this->site_runs( '$dir = get_stylesheet_directory() . "/templates"; wp_mkdir_p( $dir ); $file = $dir . "/qa-shared-source.php"; if ( file_exists( $file ) ) { throw new \\RuntimeException( "Fixture already exists" ); } file_put_contents( $file, "<?php\n/**\n * Fabrement: singular:page\n */\n?><p class=\"qa-shared-source\">Shared theme file</p>" ); return $file;' );
        try {
            $rules = [ [ 'context' => 'singular:page', 'file' => 'qa-shared-source.php' ] ];
            $before = (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $original ] );
            $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $version, 'contentSource' => [ 'rules' => $rules ] ] );
            $this->assertSame( $rules, $this->read( $original )['contentSource']['rules'] );
            $this->assertSame( $rules, $this->read( $version )['contentSource']['rules'] );
            $this->assertSame( $this->site()->meta( $original, '_fabrement_st_key' ), $this->site()->meta( $version, '_fabrement_st_key' ) );
            $this->assertSame( $before, (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $original ] ), 'shared choices do not rewrite the original blocks' );
            foreach ( [ $original, $version ] as $id ) {
                $this->assertStringNotContainsString( 'qa-shared-source.php', (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $id ] ), 'the rules live only in the shared JSON manifest' );
            }
            $urls = $this->pages( $original, 'Shared source page' );
            foreach ( $urls as $url ) {
                $this->assertSame( 1, substr_count( $this->get( $url )['body'], 'qa-shared-source' ) );
            }
            $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $original, 'contentSource' => [ 'rules' => [] ] ] );
            $this->assertSame( [], $this->read( $version )['contentSource']['rules'] );
        } finally {
            $this->site()->exec( 'rm', '-f', '--', $file );
        }
    }
}
