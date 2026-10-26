<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\Browser;
use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/** PHP field saves reach WPML's status and the actual admin language column without saving posts. */
final class PhpTranslationStatusTest extends SiteTestCase {
    protected const SITE = 'wpml';

    private function php_save( string $body ): array {
        return $this->site_runs( '$saved = (static function () {' . $body . '})(); return [ $saved, did_action("save_post"), did_action("wpml_tm_save_post") ];' );
    }

    private function row( int $id ): array {
        return $this->site()->rows( 'SELECT post_title, post_content, post_status, post_modified, post_modified_gmt FROM wp_posts WHERE ID = ?', [ $id ] )[0];
    }

    private function column( Browser $browser, int $id ): string {
        $response = $browser->get( $this->site()->url( '/wp-admin/edit.php?post_type=page&lang=en' ) );
        $this->assertSame( 200, $response['status'] );
        $this->assertSame( 1, preg_match( '#<tr\b[^>]*id="post-' . $id . '"[^>]*>(.*?)</tr>#s', $response['body'], $row ) );
        return html_entity_decode( $row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /** @dataProvider editors */
    public function test_php_changes_update_the_status_and_visible_icon( string $editor ): void {
        $this->fields( 'page', [ self::field( 'tagline', 'text', [ 'required' => true ] ),
            self::field( 'price', 'number' ), self::field( 'once', 'text', [ 'translation' => 'copy_once' ] ),
            self::field( 'local', 'text', [ 'translation' => 'ignore' ] ) ] );
        $page = $this->record( 'page', 'QA PHP status ' . $editor );
        $this->mcp( 'set-field-values', [ 'postId' => $page, 'values' => [ 'tagline' => 'Original words', 'price' => 10 ] ] );
        $wpml = new WpmlSite( $this->site() );
        $wpml->use_editor( $page, 'native' );
        $pl = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'pl' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $pl, 'status' => 'publish' ] );
        $this->mcp( 'set-field-values', [ 'postId' => $pl, 'values' => [ 'tagline' => 'Polskie słowa' ] ] );
        $wpml->mark_complete( $page, $pl );
        $wpml->use_editor( $page, $editor );
        $browser = new Browser( $this->site() );
        $browser->login( 'admin', 'admin' );
        $this->assertStringContainsString( 'Edit the Polish translation', $this->column( $browser, $page ) );
        $before = [ $this->row( $page ), $this->row( $pl ) ];

        $this->assertSame( [ false, 0, 0 ], $this->php_save( "return fabrement()->fields($page)->set('tagline', 'Original words')->save();" ) );
        $this->assertSame( [ true, 0, 0 ], $this->php_save( "return fabrement()->fields($page)->set('price', 20)->set('once', 'Changed copy once')->set('local', 'Source note')->save();" ) );
        $this->assertSame( 0, $wpml->needs_update( $pl ) );
        $this->assertSame( 20, $this->mcp( 'get-field-values', [ 'postId' => $pl ] )['values']['price'] );
        $this->assertStringNotContainsString( 'otgs-ico-refresh', $this->column( $browser, $page ) );

        $this->assertSame( [ true, 0, 1 ], $this->php_save( "return fabrement()->fields($page)->set('tagline', 'Changed original words')->save();" ) );
        $this->assertSame( 1, $wpml->needs_update( $pl ) );
        $this->assertSame( 'Polskie słowa', $this->mcp( 'get-field-values', [ 'postId' => $pl ] )['values']['tagline'] );
        $column = $this->column( $browser, $page );
        $this->assertStringContainsString( 'otgs-ico-refresh', $column );
        $this->assertStringContainsString( 'aria-label="Update Polish translation"', $column );
        $this->assertStringContainsString( 'title="Update Polish translation"', $column );
        if ( $editor === 'native' ) {
            $this->assertSame( 1, preg_match( '#<a\b[^>]*href="([^"]+)"[^>]*>\s*<i\b[^>]*aria-label="Update Polish translation"#', $column, $link ) );
            // WPML 5 routes the icon through its task dispatcher before choosing the editor.
            $response = $browser->get( $this->site()->url( '/wp-admin/' . $link[1] ) );
            $this->assertSame( 302, $response['status'] );
            $this->assertMatchesRegularExpression( '#post\.php\?[^\s]*\bpost=' . $pl . '(?:&|$)#', $response['headers']['location'] ?? '',
                'The update icon redirects to the manually edited translation.' );
        }
        $this->assertSame( $before, [ $this->row( $page ), $this->row( $pl ) ], 'A notification changes no post content, status or dates.' );
        $this->assertSame( [ 'refused', 0, 0 ], $this->php_save( "try { fabrement()->fields($page)->clear('tagline')->save(); return 'accepted'; } catch (\\Fabrement\\Api\\FieldsException \$e) { return 'refused'; }" ) );
        // PHP changes on a translation must not tell WPML its outstanding words are complete.
        $this->assertSame( [ true, 0, 0 ], $this->php_save( "return fabrement()->fields($pl)->set('local', 'Local note')->save();" ) );
        $this->assertSame( 1, $wpml->needs_update( $pl ) );

        if ( $editor === 'native' ) {
            $this->assertSame( [ true, 0, 0 ], $this->php_save( "return fabrement()->fields($pl)->set('tagline', 'Tekst z importu')->save();" ) );
            $this->assertSame( 1, $wpml->needs_update( $pl ), 'A PHP write alone does not confirm translation completion.' );
            $this->mcp( 'set-field-values', [ 'postId' => $pl, 'values' => [ 'tagline' => 'Zmienione polskie słowa' ] ] );
            $this->assertSame( 0, $wpml->needs_update( $pl ) );
            $this->assertStringNotContainsString( 'otgs-ico-refresh', $this->column( $browser, $page ) );
            $this->assertStringContainsString( 'Edit the Polish translation', $this->column( $browser, $page ) );
            // Leave a real native-editor example for visual verification after the test suite.
            $this->assertSame( [ true, 0, 1 ], $this->php_save( "return fabrement()->fields($page)->set('tagline', 'Another source edit')->save();" ) );
        }
    }

    public static function editors(): array {
        return [ 'manual WordPress editor' => [ 'native' ], 'WPML editor' => [ 'wpml' ] ];
    }

    public function test_nested_words_and_routed_new_rows_notify_the_actual_original(): void {
        $children = [ self::field( 'word', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ];
        $this->fields( 'page', [ self::field( 'group', 'group', [ 'sub_fields' => $children ] ),
            self::field( 'rows', 'repeater', [ 'sub_fields' => $children ] ) ] );
        $page = $this->record( 'page', 'PHP nested translation status' );
        $before = $this->mcp( 'set-field-values', [ 'postId' => $page, 'values' => [ 'group' => [ 'word' => 'Group', 'code' => 'A' ],
            'rows' => [ [ 'word' => 'One', 'code' => 'A' ], [ 'word' => 'Two', 'code' => 'B' ] ] ] ] )['values'];
        $wpml = new WpmlSite( $this->site() );
        $wpml->use_editor( $page, 'native' );
        $pl = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'pl' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $pl, 'status' => 'publish' ] );
        $wpml->mark_complete( $page, $pl );
        $a = $before['rows'][0]['_row'];
        $b = $before['rows'][1]['_row'];
        $this->assertSame( [ true, 0, 0 ], $this->php_save( "return fabrement()->fields($pl)->set('group.code','Routed copy')->move_row('rows','$b',before:'$a')->save();" ) );
        $this->assertSame( 0, $wpml->needs_update( $pl ) );
        $this->assertSame( 'Routed copy', $this->mcp( 'get-field-values', [ 'postId' => $page ] )['values']['group']['code'] );
        $this->assertSame( [ true, 0, 1 ], $this->php_save( "return fabrement()->fields($page)->set('group.word','New group words')->save();" ) );
        $this->assertSame( 1, $wpml->needs_update( $pl ) );
        $wpml->mark_complete( $page, $pl );
        $this->assertSame( [ true, 0, 1 ], $this->php_save( "\$f=fabrement()->fields($pl); \$f->add_row('rows',['word'=>'New row words','code'=>'C']); return \$f->save();" ) );
        $this->assertSame( 1, $wpml->needs_update( $pl ), 'New words routed from PL mark PL for translation, not complete.' );
        $this->assertSame( 'New row words', $this->mcp( 'get-field-values', [ 'postId' => $page ] )['values']['rows'][2]['word'] );
    }

    public function test_options_saves_do_not_emit_post_translation_notifications(): void {
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-status', 'expectedRevision' => 0,
            'page' => [ 'title' => 'Status options' ], 'fields' => [ self::field( 'text', 'text' ) ] ] );
        $this->assertSame( [ true, 0, 0 ], $this->php_save( "return fabrement()->options('qa-status')->set('text', 'New options')->save();" ) );
    }
}
