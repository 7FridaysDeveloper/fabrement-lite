<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The block editor's preview of a block (REST block-renderer, with the edited post's id) on a
 * French record: fabrement() shows French, as the page will.
 */
final class FabEditorPreviewTest extends SiteTestCase {

    protected const SITE = 'wpml';

    public function test_the_block_editor_preview_of_a_french_record_is_in_french(): void {
        $en = $this->record( 'page', 'Probe editor page' );
        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $en, 'language' => 'fr', 'slug' => 'probe-editor-page-fr' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        $this->site()->write( '/var/www/html/wp-content/mu-plugins/qa-probe-block.php', "<?php\nadd_action( 'init', static function () {\n register_block_type( 'qa/probe', [ 'api_version' => 3, 'render_callback' => static function () {\n  return wp_json_encode( [ 'fab' => fabrement()->language(), 'wpml' => apply_filters( 'wpml_current_language', null ), 'post' => fabrement()->post( $en )->id(), 'url' => fabrement()->post( $en )->url(), 'queried' => get_queried_object_id() ] );\n } ] );\n} );\n" );
        $auth = [ 'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ) ];
        $out  = [ 'en' => $en, 'fr' => $fr ];
        foreach ( [
            'fr post, no lang'   => "/wp-json/wp/v2/block-renderer/qa/probe?context=edit&post_id=$fr",
            'fr post, lang=fr'   => "/wp-json/wp/v2/block-renderer/qa/probe?context=edit&post_id=$fr&lang=fr",
            'en post, no lang'   => "/wp-json/wp/v2/block-renderer/qa/probe?context=edit&post_id=$en",
        ] as $case => $path ) {
            $r = Http::request( $this->site(), 'GET', $this->site()->url( $path ), $auth );
            $out[ $case ] = [ $r['status'], json_decode( $r['body'], true )['rendered'] ?? $r['body'] ];
        }
        $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/qa-probe-block.php' );
        $fr = $out['fr'];
        foreach ( [ 'fr post, no lang', 'fr post, lang=fr' ] as $case ) {
            $this->assertSame( 200, $out[ $case ][0], $case );
            $seen = json_decode( $out[ $case ][1], true );
            $this->assertSame( [ 'fr', $fr, $fr ], [ $seen['fab'], $seen['post'], $seen['queried'] ], $case );
            $this->assertStringContainsString( '/fr/', $seen['url'], $case );
        }
        $seen = json_decode( $out['en post, no lang'][1], true );
        $this->assertSame( [ 'en', $out['en'] ], [ $seen['fab'], $seen['post'] ] );
    }
}
