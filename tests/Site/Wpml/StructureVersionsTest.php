<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * What changes a record's structure reaches every language version whatever its status and
 * whoever runs it: a copied field written by site code on the front end reaches a draft
 * translation and a type that is not public, translate-content offers a version in the trash back
 * instead of copying again, and a reader of a type that is not public is shown its version.
 */
final class StructureVersionsTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private function as_visitor( string $body ) {
        $this->site_code( 'qa-structure-versions', "add_action( 'template_redirect', static function () {
            if ( ! isset( \$_GET['qa_structure'] ) ) {
                return;
            }
            wp_send_json( ( static function () { $body } )() );
        } );" );
        try {
            $page = $this->get( '/?qa_structure=1' );
        } finally {
            $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/qa-structure-versions.php' );
        }
        $this->assertSame( 200, $page['status'], $page['body'] );
        return json_decode( $page['body'], true );
    }

    private function value( int $id ) {
        return $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values']['code'] ?? null;
    }

    public function test_a_copied_field_saved_on_the_front_end_reaches_a_draft_translation(): void {
        $this->post_type( 'qa_sv_talk', [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
        $this->fields( 'qa_sv_talk', [ self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ] );
        $original = $this->record( 'qa_sv_talk', 'Talk' );
        $fr       = (int) $this->mcp( 'translate-content', [ 'contentId' => $original, 'language' => 'fr' ] )['contentId'];
        $this->assertSame( 'draft', $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $fr ] ) );

        $this->as_visitor( "fabrement()->fields( $original )->set( 'code', 'FRONT' )->save(); return true;" );
        $this->assertSame( 'FRONT', $this->value( $original ) );
        $this->assertSame( 'FRONT', $this->value( $fr ), 'the draft translation gets the copied value too' );
    }

    public function test_a_copied_field_of_a_type_that_is_not_public_reaches_its_translation(): void {
        $this->post_type( 'qa_sv_hidden', [ 'translationMode' => 'translate', 'public' => false, 'supports' => [ 'title', 'editor' ] ] );
        $this->fields( 'qa_sv_hidden', [ self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ] );
        [ $original, $fr ] = $this->site_runs( "
            \$bridge   = \Fabrement\Modules\Translation\Translation::bridge();
            \$original = (int) wp_insert_post( [ 'post_type' => 'qa_sv_hidden', 'post_status' => 'publish', 'post_title' => 'Hidden' ] );
            \$bridge->set_language( \$original, 'en', 'qa_sv_hidden' );
            \$fr = (int) wp_insert_post( [ 'post_type' => 'qa_sv_hidden', 'post_status' => 'publish', 'post_title' => 'Caché' ] );
            \$bridge->link_translation( \$fr, \$original, 'fr', 'qa_sv_hidden' );
            return [ \$original, \$fr ];" );

        $this->as_visitor( "fabrement()->fields( $original )->set( 'code', 'HIDDEN' )->save(); return true;" );
        $this->assertSame( 'HIDDEN', $this->value( $fr ) );
        $this->assertSame( [ 'id' => $fr, 'state' => 'translated' ], $this->as_visitor( "return [ 'id' => fabrement()->post( $original, lang: 'fr' )->id(), 'state' => 'translated' ];" ), 'a visitor reading it in French is shown the French version' );
    }

    public function test_translate_synced_pattern_says_what_to_do_with_a_trashed_version(): void {
        $pattern = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Trashed band', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];
        $fr      = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $pattern, 'language' => 'fr' ] )['patternId'];
        $this->site_runs( "wp_trash_post( $fr ); return true;" );
        $this->assertSame( 'trash', $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $fr ] ) );
        $patterns = $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_block'" );

        $refusal = $this->mcp_error( 'translate-synced-pattern', [ 'patternId' => $pattern, 'language' => 'fr' ] );
        $this->assertStringContainsString( "(pattern $fr), in the trash", $refusal );
        $this->assertStringContainsString( "delete-synced-pattern { patternId: $fr }", $refusal );
        $this->assertSame( $patterns, $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_block'" ), 'no second copy' );

        $this->mcp( 'delete-synced-pattern', [ 'patternId' => $fr ] );
        $again = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $pattern, 'language' => 'fr' ] )['patternId'];
        $this->assertNotSame( $fr, $again, 'deleted for good, a new version is made' );
    }

    public function test_translate_content_offers_a_trashed_version_back_instead_of_a_second_one(): void {
        $page = $this->record( 'page', 'Trashed version' );
        $fr   = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'fr' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'trash' ] );
        $pages = $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page'" );

        $refusal = $this->mcp_error( 'translate-content', [ 'contentId' => $page, 'language' => 'fr' ] );
        $this->assertStringContainsString( "($fr), in the trash", $refusal );
        $this->assertStringContainsString( 'status: "restore"', $refusal );
        $this->assertSame( $pages, $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page'" ), 'no second copy' );

        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'restore' ] );
        $this->assertSame( $fr, (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'fr' ] )['contentId'], 'restored, it is the French version again' );
    }

    private function kind_of( int $pattern ): string {
        return (string) $this->site_runs( "return \Fabrement\Modules\SiteTemplate\Service\SyncedPatterns::kind( $pattern );" );
    }

    public function test_a_new_kind_reaches_a_version_in_the_trash(): void {
        $pattern = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Kind band', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];
        $fr      = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $pattern, 'language' => 'fr' ] )['patternId'];
        $this->site_runs( "wp_trash_post( $fr ); return true;" );

        $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $pattern, 'kind' => 'footer', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph -->' ] ] ] );
        $this->site_runs( "wp_untrash_post( $fr ); return true;" );
        $this->assertSame( [ 'footer', 'footer' ], [ $this->kind_of( $pattern ), $this->kind_of( $fr ) ], 'restored, the version is the same kind as its original' );
    }

    public function test_a_version_names_its_original_even_when_the_original_is_in_the_trash(): void {
        $pattern = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Orphan band', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];
        $fr      = (int) $this->mcp( 'translate-synced-pattern', [ 'patternId' => $pattern, 'language' => 'fr' ] )['patternId'];
        $this->site_runs( "wp_trash_post( $pattern ); return true;" );

        $refusal = $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $fr, 'kind' => 'footer', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Bande</p><!-- /wp:paragraph -->' ] ] ] );
        $this->assertStringContainsString( "original (id $pattern)", $refusal );
    }

    public function test_a_template_given_to_a_record_reaches_its_translation_in_the_trash(): void {
        $this->post_type( 'qa_sv_story', [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
        $header   = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Frame head', 'kind' => 'header', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Head</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];
        $template = $this->mcp( 'save-template', self::frame( 'Frame', $header ) )['template'];
        $page     = $this->record( 'qa_sv_story', 'Framed' );
        $fr       = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'fr' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'trash' ] );

        $this->mcp( 'assign-template-pages', [ 'templateId' => (int) $template['id'], 'pageIds' => [ $page ], 'mode' => 'add' ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'restore' ] );
        $picked = fn( int $id ) => $this->site()->value( "SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key = '_wp_page_template'", [ $id ] );
        $this->assertNotSame( '', (string) $picked( $page ) );
        $this->assertSame( $picked( $page ), $picked( $fr ), 'restored, the translation wears the frame its original was given' );
    }
}
