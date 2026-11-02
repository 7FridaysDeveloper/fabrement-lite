<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\McpClient;
use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\TemplateSides;

/** Template frames are ordinary installed blocks, saved in wp-admin (the Templates screen or Gutenberg's native REST) or over MCP. */
final class TemplateSidesTest extends SiteTestCase {
    use TemplateSides;

    private function read( int $id ): array {
        return $this->template_row( $id );
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
        $id = $this->new_template( 'Working', $header, $footer );
        $page = $this->record( 'page', 'Working page' );
        $this->mcp( 'save-content', [ 'mode' => 'replace', 'contentId' => $page, 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p class="qa-page-body">Page body</p><!-- /wp:paragraph -->' ] ] ] );
        $this->assign_template( $id, [ $page ] );
        $body = $this->get( $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'] )['body'];
        $positions = [];
        foreach ( [ 'qa-own-working-header', 'qa-page-body', 'qa-own-working-footer' ] as $mark ) {
            $this->assertSame( 1, substr_count( $body, $mark ) );
            $positions[] = strpos( $body, $mark );
        }
        $this->assertLessThan( $positions[1], $positions[0] );
        $this->assertLessThan( $positions[2], $positions[1] );
        $this->assertSame( '', $this->read( $id )['problem'] );
        $read = $this->mcp( 'get-synced-pattern', [ 'patternId' => $header ] );
        $this->assertSame( [ [ $id ], 0 ], [ array_column( $read['templates'], 'id' ), $read['templatesHidden'] ] );
    }

    public function test_rename_and_width_changes_preserve_the_complete_frame(): void {
        $header = $this->own_global_block( 'header', 'Rename header' );
        $footer = $this->own_global_block( 'footer', 'Rename footer' );
        $id = $this->new_template( 'Rename', $header, $footer, '<!-- wp:paragraph --><p>Notice</p><!-- /wp:paragraph -->' );
        $before = $this->raw( $id );
        $this->save_template( [ 'id' => $id, 'title' => 'Renamed', 'contentWidth' => 'constrained' ] );
        $this->assertSame( $before, $this->raw( $id ) );
        $this->assertSame( 'constrained', $this->read( $id )['contentWidth'] );
        $rest = $this->rest( 'POST', 'fabrement_template/' . $id, [ 'title' => 'Editor renamed' ] );
        $this->assertSame( 200, $rest['status'], $rest['body'] );
        $this->assertSame( $before, $this->raw( $id ) );
        $this->assertSame( 'Editor renamed', $this->read( $id )['title'] );
    }

    /**
     * A missing global block is refused, as everywhere. wp-admin does not restrict people further:
     * a frame placing a draft or locked one is saved, and the template says what is wrong.
     */
    public function test_a_missing_ref_is_refused_and_draft_and_password_protected_refs_are_saved_and_said(): void {
        $good = $this->own_global_block( 'header', 'Refs good' );
        $draft = $this->own_global_block( 'header', 'Refs draft' );
        $locked = $this->own_global_block( 'header', 'Refs locked' );
        $this->site_runs( 'wp_update_post( [ "ID" => ' . $draft . ', "post_status" => "draft" ] ); wp_update_post( [ "ID" => ' . $locked . ', "post_password" => "secret" ] ); return true;' );
        $id = $this->new_template( 'Refs', $good );
        $this->assertSame( '', $this->read( $id )['problem'] );

        $before  = $this->raw( $id );
        $refused = $this->rest( 'POST', 'fabrement_template/' . $id, [ 'title' => 'Refused', 'content' => self::frame( 999999 ) ] );
        $this->assertGreaterThanOrEqual( 400, $refused['status'], $refused['body'] );
        $this->assertStringContainsString( 'synced pattern 999999 does not exist', $refused['body'] );
        $this->assertSame( $before, $this->raw( $id ) );
        $this->assertSame( 'Refs', $this->read( $id )['title'] );

        foreach ( [ $draft => 'is not published (draft)', $locked => 'is password-protected' ] as $ref => $said ) {
            $saved = $this->rest( 'POST', 'fabrement_template/' . $id, [ 'content' => self::frame( $ref ) ] );
            $this->assertSame( 200, $saved['status'], $saved['body'] );
            $this->assertStringContainsString( '"ref":' . $ref, $this->raw( $id ) );
            $this->assertStringContainsString( $said, $this->read( $id )['problem'] );
        }
    }

    public function test_native_editor_saves_blocks_and_refuses_invalid_frames(): void {
        $header = $this->own_global_block( 'header', 'Native header' );
        $id = $this->new_template( 'Native', $header );
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
        $id = $this->new_template( 'Kept', $header );
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

    public function test_native_template_writes_require_editing_others_pages(): void {
        $id = $this->new_template( 'Protected' );
        // An Author can edit posts but not everyone's pages, so not a template.
        $password = (string) $this->site_runs( '$id = wp_insert_user( [ "user_login" => "qa_template_author", "user_pass" => wp_generate_password(), "user_email" => "qa_template_author@example.org", "role" => "author" ] ); return WP_Application_Passwords::create_new_application_password( $id, [ "name" => "qa" ] )[0];' );
        $refused = $this->rest( 'POST', 'fabrement_template/' . $id, [ 'title' => 'Taken' ], 'qa_template_author', $password );
        $this->assertSame( 403, $refused['status'], $refused['body'] );
        $this->assertSame( 'Protected', $this->read( $id )['title'] );
    }

    /** An Editor saves templates in wp-admin but cannot connect; the Administrator's session, capped to an Editor's caps, lists and saves them. */
    public function test_an_editor_saves_templates_in_wp_admin_and_an_administrator_over_mcp(): void {
        $existing = $this->new_template( 'Before the editor' );
        $user     = (int) $this->site()->wp( 'user', 'create', 'qa-editor', 'qa-editor@example.org', '--role=editor', '--porcelain' );
        $password = trim( $this->site()->wp( 'user', 'application-password', 'create', (string) $user, 'qa', '--porcelain' ) );
        $renamed  = $this->rest( 'POST', 'fabrement_template/' . $existing, [ 'title' => 'Editor renamed' ], 'qa-editor', $password );
        $this->assertSame( 200, $renamed['status'], $renamed['body'] );
        $this->assertSame( 'Editor renamed', $this->read( $existing )['title'] );
        $refused = ( new McpClient( $this->site(), $this->site()->access_token( 'qa-editor' ) ) )->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'qa', 'version' => '1' ] ] );
        $this->assertSame( 403, $refused['_http']['status'], 'an Editor cannot connect' );

        $this->assertContains( $existing, array_column( $this->mcp( 'list-templates' )['templates'], 'id' ) );
        $saved = $this->mcp( 'save-template', [ 'mode' => 'create', 'title' => 'Administrator made', 'blocks' => [ [ 'name' => 'fabrement/content-source' ] ] ] )['template'];
        $this->assertSame( 'Administrator made', $this->read( (int) $saved['id'] )['title'] );
    }

    public function test_a_post_type_default_is_not_deleted_over_mcp_even_with_force(): void {
        $id = $this->new_template( 'Site default' );
        $this->default_template( $id, 'post' );

        $refusal = $this->mcp_error( 'delete-template', [ 'templateId' => $id, 'force' => true ] );
        $this->assertStringContainsString( 'the default for post type "post"', $refusal );
        $this->assertStringContainsString( 'wp-admin', $refusal );
        // The adapter passes on the message alone; the code, from the tool itself.
        $this->assertSame( 'template_assigned_site_wide', $this->site_runs( 'return ( new \Fabrement\Modules\Mcp\Ability\DeleteTemplateAbility() )->execute( [ "templateId" => ' . $id . ', "force" => true ] )->get_error_code();' ) );
        $this->assertSame( 'Site default', $this->read( $id )['title'] );
    }

    public function test_pattern_status_changes_invalidate_readiness_in_the_same_request(): void {
        $header = $this->own_global_block( 'header', 'Fresh header' );
        $id = $this->new_template( 'Fresh', $header );
        $registry = '\\Fabrement\\Modules\\SiteTemplate\\Service\\SiteTemplateRegistry';
        $result = $this->site_runs( '$out = [ ' . $registry . '::find( ' . $id . ' )->problem() === "" ]; wp_update_post( [ "ID" => ' . $header . ', "post_status" => "draft" ] ); $out[] = ' . $registry . '::find( ' . $id . ' )->problem() === ""; wp_update_post( [ "ID" => ' . $header . ', "post_status" => "publish" ] ); $out[] = ' . $registry . '::find( ' . $id . ' )->problem() === ""; return $out;' );
        $this->assertSame( [ true, false, true ], $result );
    }

    public function test_frame_has_no_duplicate_side_metadata_and_discovery_queries_are_bounded(): void {
        $queries = fn(): int => (int) $this->site_runs( 'global $wpdb; wp_cache_flush(); \Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry::flush(); $before = $wpdb->num_queries; \Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry::originals(); return $wpdb->num_queries - $before;' );
        $before = $queries();
        foreach ( [ 'One', 'Two', 'Three' ] as $name ) {
            $header = $this->own_global_block( 'header', 'Metadata ' . $name );
            $id = $this->new_template( 'Metadata ' . $name, $header );
            $keys = array_column( $this->site()->rows( 'SELECT meta_key FROM wp_postmeta WHERE post_id = ?', [ $id ] ), 'meta_key' );
            foreach ( [ '_fabrement_st_header_id', '_fabrement_st_footer_id', '_fabrement_st_header_pattern', '_fabrement_st_footer_pattern' ] as $key ) {
                $this->assertNotContains( $key, $keys );
            }
            $this->assertSame( $header, $this->side_of( $id, 'header' ) );
        }
        $this->assertSame( $before, $queries(), 'adding templates must not add per-template discovery queries' );
        $this->assertLessThanOrEqual( 8, $before );
    }
}
