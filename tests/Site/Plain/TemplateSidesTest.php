<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\TemplateSides;

/** Template frames are ordinary installed blocks, saved through MCP or Gutenberg's native REST. */
final class TemplateSidesTest extends SiteTestCase {
    use TemplateSides;

    private function read( int $id ): array {
        return $this->mcp( 'get-template', [ 'templateId' => $id ] )['template'];
    }

    private function raw( int $id ): string {
        return (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $id ] );
    }

    private function rest( string $method, string $path, ?array $body = null, string $user = 'admin', ?string $password = null ): array {
        $headers = [ 'Authorization' => 'Basic ' . base64_encode( $user . ':' . ( $password ?? $this->site()->password ) ) ];
        return Http::request( $this->site(), $method, $this->site()->url( '/wp-json/wp/v2/' . $path ), $headers + [ 'Content-Type' => 'application/json' ], $body === null ? null : (string) json_encode( $body ) );
    }

    public function test_header_page_content_and_footer_render_once_in_order(): void {
        $header = $this->own_global_block( 'header', 'Working header' );
        $footer = $this->own_global_block( 'footer', 'Working footer' );
        $saved = $this->mcp( 'save-template', self::frame( 'Working', $header, $footer ) )['template'];
        $id = (int) $saved['id'];
        $page = $this->record( 'page', 'Working page' );
        $this->mcp( 'save-content', [ 'mode' => 'replace', 'contentId' => $page, 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p class="qa-page-body">Page body</p><!-- /wp:paragraph -->' ] ] ] );
        $this->mcp( 'assign-template-pages', [ 'templateId' => $id, 'pageIds' => [ $page ], 'mode' => 'add' ] );
        $body = $this->get( $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'] )['body'];
        $positions = [];
        foreach ( [ 'qa-own-working-header', 'qa-page-body', 'qa-own-working-footer' ] as $mark ) {
            $this->assertSame( 1, substr_count( $body, $mark ) );
            $positions[] = strpos( $body, $mark );
        }
        $this->assertLessThan( $positions[1], $positions[0] );
        $this->assertLessThan( $positions[2], $positions[1] );
        $this->assertSame( '', $saved['problem'] );
        $this->assertContains( $id, array_column( $this->mcp( 'get-synced-pattern', [ 'patternId' => $header ] )['templates'], 'id' ) );
    }

    public function test_rename_and_width_changes_preserve_the_complete_frame(): void {
        $header = $this->own_global_block( 'header', 'Rename header' );
        $footer = $this->own_global_block( 'footer', 'Rename footer' );
        $frame = self::frame( 'Rename', $header, $footer );
        array_splice( $frame['blocks'], 1, 0, [ [ 'markup' => '<!-- wp:paragraph --><p>Notice</p><!-- /wp:paragraph -->' ] ] );
        $id = (int) $this->mcp( 'save-template', $frame )['template']['id'];
        $before = $this->raw( $id );
        $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $id, 'title' => 'Renamed', 'contentWidth' => 'constrained' ] );
        $this->assertSame( $before, $this->raw( $id ) );
        $this->assertSame( 'constrained', $this->read( $id )['contentWidth'] );
        $rest = $this->rest( 'POST', 'fabrement_template/' . $id, [ 'title' => 'Editor renamed' ] );
        $this->assertSame( 200, $rest['status'], $rest['body'] );
        $this->assertSame( $before, $this->raw( $id ) );
        $this->assertSame( 'Editor renamed', $this->read( $id )['title'] );
    }

    public function test_hash_checked_edits_change_one_block_and_refusals_are_atomic(): void {
        $header = $this->own_global_block( 'header', 'Atomic header' );
        $id = (int) $this->mcp( 'save-template', self::frame( 'Atomic', $header ) )['template']['id'];
        $read = $this->read( $id );
        $edit = [ 'op' => 'insert', 'at' => 1, 'block' => [ 'markup' => '<!-- wp:paragraph --><p>Notice</p><!-- /wp:paragraph -->' ] ];
        $this->assertStringContainsString( 'changed since you read it', $this->mcp_error( 'save-template', [ 'mode' => 'edit', 'templateId' => $id, 'expectedHash' => 'stale', 'title' => 'Refused', 'edits' => [ $edit ] ] ) );
        $this->assertSame( $read['hash'], $this->read( $id )['hash'] );
        $this->assertSame( 'Atomic', $this->read( $id )['title'] );
        $this->mcp( 'save-template', [ 'mode' => 'edit', 'templateId' => $id, 'expectedHash' => $read['hash'], 'edits' => [ $edit ] ] );
        $this->assertSame( [ 'core/block', 'core/paragraph', 'fabrement/content-source' ], $this->read( $id )['outline'] );
        $before = $this->raw( $id );
        foreach ( [ [], [ [ 'name' => 'fabrement/content-source' ], [ 'name' => 'fabrement/content-source' ] ] ] as $blocks ) {
            $this->mcp_error( 'save-template', [ 'mode' => 'replace', 'templateId' => $id, 'title' => 'Refused', 'blocks' => $blocks ] );
            $this->assertSame( $before, $this->raw( $id ) );
            $this->assertSame( 'Atomic', $this->read( $id )['title'] );
        }
    }

    public function test_sides_can_be_removed_without_removing_other_blocks(): void {
        $header = $this->own_global_block( 'header', 'Removed header' );
        $footer = $this->own_global_block( 'footer', 'Removed footer' );
        $id = (int) $this->mcp( 'save-template', self::frame( 'Removed sides', $header, $footer ) )['template']['id'];
        $read = $this->read( $id );
        $this->mcp( 'save-template', [ 'mode' => 'edit', 'templateId' => $id, 'expectedHash' => $read['hash'], 'edits' => [ [ 'op' => 'remove', 'index' => 0, 'name' => 'core/block' ] ] ] );
        $this->assertSame( [ 'fabrement/content-source', 'core/block' ], $this->read( $id )['outline'] );
        $this->assertSame( $footer, $this->side_of( $id, 'footer' ) );
        $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $id, 'blocks' => self::frame( '' )['blocks'] ] );
        $this->assertSame( [ 'fabrement/content-source' ], $this->read( $id )['outline'] );
        $this->mcp( 'delete-synced-pattern', [ 'patternId' => $header ] );
        $this->mcp( 'delete-synced-pattern', [ 'patternId' => $footer ] );
    }

    public function test_new_missing_draft_and_password_protected_refs_are_refused_atomically(): void {
        $good = $this->own_global_block( 'header', 'Refs good' );
        $draft = $this->own_global_block( 'header', 'Refs draft' );
        $locked = $this->own_global_block( 'header', 'Refs locked' );
        $this->site_runs( 'wp_update_post( [ "ID" => ' . $draft . ', "post_status" => "draft" ] ); wp_update_post( [ "ID" => ' . $locked . ', "post_password" => "secret" ] ); return true;' );
        $id = (int) $this->mcp( 'save-template', self::frame( 'Refs', $good ) )['template']['id'];
        $before = $this->raw( $id );
        foreach ( [ 999999, $draft, $locked, $id ] as $ref ) {
            $this->mcp_error( 'save-template', [ 'mode' => 'replace', 'templateId' => $id, 'title' => 'Refused', 'blocks' => self::frame( '', $ref )['blocks'] ] );
            $this->assertSame( $before, $this->raw( $id ) );
            $this->assertSame( 'Refs', $this->read( $id )['title'] );
        }
    }

    public function test_native_editor_saves_blocks_and_refuses_invalid_frames(): void {
        $header = $this->own_global_block( 'header', 'Native header' );
        $id = (int) $this->mcp( 'save-template', self::frame( 'Native', $header ) )['template']['id'];
        $content = $this->raw( $id ) . '<!-- wp:paragraph --><p>Native notice</p><!-- /wp:paragraph -->';
        $saved = $this->rest( 'POST', 'fabrement_template/' . $id, [ 'content' => $content ] );
        $this->assertSame( 200, $saved['status'], $saved['body'] );
        $this->assertStringContainsString( 'Native notice', $this->raw( $id ) );
        $before = $this->raw( $id );
        $refused = $this->rest( 'POST', 'fabrement_template/' . $id, [ 'title' => 'Refused', 'content' => '<!-- wp:paragraph --><p>No slot</p><!-- /wp:paragraph -->' ] );
        $this->assertGreaterThanOrEqual( 400, $refused['status'], $refused['body'] );
        $this->assertSame( $before, $this->raw( $id ) );
        $this->assertSame( 'Native', $this->read( $id )['title'] );
    }

    public function test_native_pattern_delete_explains_usage_and_free_patterns_delete(): void {
        $header = $this->own_global_block( 'header', 'Kept header' );
        $id = (int) $this->mcp( 'save-template', self::frame( 'Kept', $header ) )['template']['id'];
        foreach ( [ '', '?force=true' ] as $query ) {
            $refused = $this->rest( 'DELETE', 'blocks/' . $header . $query );
            $this->assertSame( 409, $refused['status'], $refused['body'] );
            $data = json_decode( $refused['body'], true );
            $this->assertSame( 'fabrement_global_block_in_use', $data['code'] );
            $this->assertStringContainsString( 'Kept', $data['message'] );
        }
        $free = $this->own_global_block( 'header', 'Free header' );
        $gone = $this->rest( 'DELETE', 'blocks/' . $free . '?force=true' );
        $this->assertSame( 200, $gone['status'], $gone['body'] );
        $this->assertSame( $header, $this->side_of( $id, 'header' ) );
    }

    public function test_native_template_writes_require_an_administrator(): void {
        $id = (int) $this->mcp( 'save-template', self::frame( 'Protected' ) )['template']['id'];
        $password = (string) $this->site_runs( '$id = wp_insert_user( [ "user_login" => "qa_template_editor", "user_pass" => wp_generate_password(), "user_email" => "qa_template_editor@example.org", "role" => "editor" ] ); return WP_Application_Passwords::create_new_application_password( $id, [ "name" => "qa" ] )[0];' );
        $refused = $this->rest( 'POST', 'fabrement_template/' . $id, [ 'title' => 'Taken' ], 'qa_template_editor', $password );
        $this->assertSame( 403, $refused['status'], $refused['body'] );
        $this->assertSame( 'Protected', $this->read( $id )['title'] );
    }

    public function test_pattern_status_changes_invalidate_readiness_in_the_same_request(): void {
        $header = $this->own_global_block( 'header', 'Fresh header' );
        $id = (int) $this->mcp( 'save-template', self::frame( 'Fresh', $header ) )['template']['id'];
        $registry = '\\Fabrement\\Modules\\SiteTemplate\\Service\\SiteTemplateRegistry';
        $result = $this->site_runs( '$out = [ ' . $registry . '::find( ' . $id . ' )->problem() === "" ]; wp_update_post( [ "ID" => ' . $header . ', "post_status" => "draft" ] ); $out[] = ' . $registry . '::find( ' . $id . ' )->problem() === ""; wp_update_post( [ "ID" => ' . $header . ', "post_status" => "publish" ] ); $out[] = ' . $registry . '::find( ' . $id . ' )->problem() === ""; return $out;' );
        $this->assertSame( [ true, false, true ], $result );
    }

    public function test_frame_has_no_duplicate_side_metadata_and_discovery_queries_are_bounded(): void {
        $queries = fn(): int => (int) $this->site_runs( 'global $wpdb; wp_cache_flush(); \Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry::flush(); $before = $wpdb->num_queries; \Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry::originals(); return $wpdb->num_queries - $before;' );
        $before = $queries();
        foreach ( [ 'One', 'Two', 'Three' ] as $name ) {
            $header = $this->own_global_block( 'header', 'Metadata ' . $name );
            $id = (int) $this->mcp( 'save-template', self::frame( 'Metadata ' . $name, $header ) )['template']['id'];
            $keys = array_column( $this->site()->rows( 'SELECT meta_key FROM wp_postmeta WHERE post_id = ?', [ $id ] ), 'meta_key' );
            foreach ( [ '_fabrement_st_header_id', '_fabrement_st_footer_id', '_fabrement_st_header_pattern', '_fabrement_st_footer_pattern' ] as $key ) {
                $this->assertNotContains( $key, $keys );
            }
            $this->assertSame( $header, $this->read( $id )['blocks'][0]['attributes']['ref'] );
        }
        $this->assertSame( $before, $queries(), 'adding templates must not add per-template discovery queries' );
        $this->assertLessThanOrEqual( 8, $before );
    }
}
