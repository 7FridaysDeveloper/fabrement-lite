<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\McpClient;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Options pages as an agent makes and fills them on a real site: the page, its one wp_options row,
 * the PHP API the site's code reads it with, autoload, who may write, and deleting it.
 */
final class OptionsPagesTest extends SiteTestCase {

    private static function fields_of_page(): array {
        return [
            self::field( 'phone', 'text', [ 'translation' => 'copy' ] ),
            self::field( 'slogan', 'text' ),
            self::field( 'since', 'number', [ 'format' => 'integer', 'translation' => 'copy_once' ] ),
            self::field( 'steps', 'repeater', [ 'max' => 5, 'sub_fields' => [ self::field( 'title', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ] ] ),
        ];
    }

    private function create( string $slug ): array {
        return $this->mcp( 'save-options-page', [
            'slug'   => $slug, 'expectedRevision' => 0,
            'page'   => [ 'title' => 'QA options', 'menuTitle' => 'QA', 'description' => 'Test page', 'position' => 5 ],
            'fields' => self::fields_of_page(),
            'layout' => [ 'tabs' => [
                [ 'label' => 'Contacts', 'items' => [ [ 'field' => 'phone', 'width' => '1/2' ], [ 'field' => 'slogan' ] ] ],
                [ 'label' => 'Steps', 'items' => [ [ 'field' => 'steps' ] ] ],
            ] ],
        ] );
    }

    private function set( string $slug, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'options' => $slug, 'values' => $values ] );
    }

    /** @return array{value:mixed, autoload:string}|null The page's row, as WordPress stores it. */
    private function row( string $slug ): ?array {
        $row = $this->site()->rows( 'SELECT option_value, autoload FROM wp_options WHERE option_name = ?', [ 'fabrement_options_' . $slug ] )[0] ?? null;
        return $row ? [ 'value' => unserialize( (string) $row['option_value'], [ 'allowed_classes' => false ] ), 'autoload' => (string) $row['autoload'] ] : null;
    }

    private function revision( string $slug ): int {
        return (int) $this->mcp( 'get-options-page', [ 'slug' => $slug ] )['revision'];
    }

    public function test_a_new_page_needs_a_title(): void {
        $this->assertStringStartsWith( 'page', $this->mcp_error( 'save-options-page', [ 'slug' => 'qa-untitled', 'expectedRevision' => 0, 'fields' => self::fields_of_page() ] ) );
        $this->assertSame( '0', $this->site()->php( 'echo (int) is_file( wp_upload_dir()["basedir"] . "/fabrement-lite/fields/options.qa-untitled.json" );' ) );
    }

    public function test_the_page_is_created_with_its_tabs_listed_and_read_back(): void {
        $saved = $this->create( 'qa-made' );
        $this->assertSame( 'QA options', $saved['page']['title'] );
        $this->assertCount( 2, $saved['layout']['tabs'] );
        $this->assertSame( '1', $this->site()->php( 'echo (int) is_file( wp_upload_dir()["basedir"] . "/fabrement-lite/fields/options.qa-made.json" );' ) );

        $listed = array_column( $this->mcp( 'list-options-pages' )['pages'], null, 'slug' );
        $this->assertSame( 'QA', $listed['qa-made']['menuTitle'] );
        $this->assertSame( 4, $listed['qa-made']['fields'] );
        $read = $this->mcp( 'get-options-page', [ 'slug' => 'qa-made' ] );
        $this->assertTrue( $read['exists'] );
        $this->assertSame( 5, $read['page']['position'] );
        $this->assertSame( 1, $read['revision'] );
    }

    public function test_a_page_setting_changes_alone(): void {
        $this->create( 'qa-renamed' );
        $renamed = $this->mcp( 'save-options-page', [ 'slug' => 'qa-renamed', 'expectedRevision' => 1, 'page' => [ 'title' => 'QA settings' ] ] );
        $this->assertSame( 'QA settings', $renamed['page']['title'] );
        $this->assertSame( 'QA', $renamed['page']['menuTitle'] );
        $this->assertCount( 4, $renamed['fields'] );
    }

    public function test_save_fields_takes_no_page_settings_and_no_options_page(): void {
        $this->create( 'qa-not-fields' );
        $this->mcp_error( 'save-fields', [ 'type' => 'post', 'slug' => 'post', 'expectedRevision' => 0, 'page' => [ 'title' => 'x' ] ] );
        $this->mcp_error( 'save-fields', [ 'type' => 'options', 'slug' => 'qa-not-fields', 'expectedRevision' => 1, 'page' => [ 'title' => 'x' ] ] );
        $this->assertSame( 'QA options', $this->mcp( 'get-options-page', [ 'slug' => 'qa-not-fields' ] )['page']['title'] );
    }

    public function test_the_values_are_one_autoloaded_row_read_by_the_sites_code(): void {
        $this->create( 'qa-values' );
        $answer = $this->set( 'qa-values', [ 'phone' => '+380 44 000', 'slogan' => 'Hello', 'since' => 2001, 'steps' => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ] ] ] );
        $this->assertSame( '+380 44 000', $answer['values']['phone'] );

        $row = $this->row( 'qa-values' );
        $this->assertSame( 'on', $row['autoload'] );
        $this->assertSame( '+380 44 000', $row['value']['fab_phone'], 'laid out like meta' );
        $this->assertSame( '2', $row['value']['fab_steps'] );
        $this->assertArrayHasKey( 'fab_steps_0__rid', $row['value'] );

        $this->assertSame( [ '+380 44 000', 'Two', 2001, null, null, true, 'Hi', '' ], $this->site_runs( "return [
            fabrement_get_option( 'phone', 'qa-values' ),
            fabrement_get_options( 'qa-values' )['steps'][1]['title'],
            fabrement_get_field( 'since', 'options:qa-values' ),
            fabrement_get_option( 'nothing', 'qa-values' ),
            fabrement_get_option( 'phone', 'no-such-page' ),
            fabrement()->options( 'qa-values' )->set( 'slogan', 'Hi' )->save(),
            fabrement_get_option( 'slogan', 'qa-values' ),
            fabrement_get_option_label( 'steps.code', 'qa-values', 'b' ),
        ];" ) );
    }

    public function test_a_bad_value_is_refused_as_on_a_record(): void {
        $this->create( 'qa-bad' );
        $this->set( 'qa-bad', [ 'since' => 2001 ] );
        $before = $this->row( 'qa-bad' );
        $this->mcp_error( 'set-field-values', [ 'options' => 'qa-bad', 'values' => [ 'since' => 'soon' ] ] );
        $this->assertSame( $before, $this->row( 'qa-bad' ) );
    }

    public function test_autoload_is_the_pages_choice_and_a_save_keeps_it(): void {
        $this->create( 'qa-autoload' );
        $this->set( 'qa-autoload', [ 'slogan' => 'Hello' ] );
        $this->assertFalse( $this->mcp( 'save-options-page', [ 'slug' => 'qa-autoload', 'expectedRevision' => 1, 'page' => [ 'autoload' => false ] ] )['page']['autoload'] );
        $this->assertSame( 'off', $this->row( 'qa-autoload' )['autoload'] );
        $this->set( 'qa-autoload', [ 'slogan' => 'Quiet' ] );
        $this->assertSame( 'off', $this->row( 'qa-autoload' )['autoload'], 'a save keeps it off' );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-autoload', 'expectedRevision' => 2, 'page' => [ 'autoload' => true ] ] );
        $this->assertSame( 'on', $this->row( 'qa-autoload' )['autoload'] );
        $this->assertStringStartsWith( 'page.autoload', $this->mcp_error( 'save-options-page', [ 'slug' => 'qa-autoload', 'expectedRevision' => 3, 'page' => [ 'autoload' => 'yes' ] ] ) );
    }

    public function test_an_editor_gets_no_mcp_session(): void {
        $this->create( 'qa-editor' );
        $this->set( 'qa-editor', [ 'slogan' => 'Hello' ] );
        $editor   = (int) $this->site()->wp( 'user', 'create', 'qa-editor', 'qa-editor@example.org', '--role=editor', '--porcelain' );
        $password = trim( $this->site()->wp( 'user', 'application-password', 'create', (string) $editor, 'qa', '--porcelain' ) );
        $answer   = ( new McpClient( $this->site(), 'qa-editor', $password ) )->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'qa', 'version' => '1' ] ] );
        $this->assertSame( 403, $answer['_http']['status'], 'the MCP server is the administrator\'s' );
        $this->assertSame( 'Hello', $this->row( 'qa-editor' )['value']['fab_slogan'] );
    }

    public function test_a_record_field_without_post_types_takes_a_public_record_only(): void {
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-pick', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA' ], 'fields' => [ self::field( 'pick', 'post_object' ) ] ] );
        $pattern = (int) $this->site_runs( "return wp_insert_post( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'QA pattern', 'post_content' => '' ] );" );
        $page    = $this->record( 'page', 'Picked page' );
        $this->assertStringContainsString( 'public post type', $this->mcp_error( 'set-field-values', [ 'options' => 'qa-pick', 'values' => [ 'pick' => $pattern ] ] ) );
        $this->assertSame( $page, $this->set( 'qa-pick', [ 'pick' => $page ] )['values']['pick'] );
    }

    public function test_deleting_the_page_deletes_its_values_and_the_slug_starts_empty(): void {
        $this->create( 'qa-deleted' );
        $this->set( 'qa-deleted', [ 'phone' => '+380 44 000' ] );
        $tool = array_column( $this->client()->tools(), null, 'name' )['fabrement-delete-options-page'];
        $this->assertTrue( $tool['annotations']['destructiveHint'] ?? false, 'marked destructive for the agent' );

        $this->assertGreaterThanOrEqual( 1, $this->mcp( 'delete-options-page', [ 'slug' => 'qa-deleted', 'expectedRevision' => $this->revision( 'qa-deleted' ) ] )['valueRowsDeleted'] );
        $this->assertNull( $this->row( 'qa-deleted' ) );
        $this->assertNull( $this->site_runs( "return fabrement_get_option( 'phone', 'qa-deleted' );" ) );

        $this->mcp( 'save-options-page', [ 'slug' => 'qa-deleted', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA again' ], 'fields' => [ self::fields_of_page()[0] ] ] );
        $this->assertSame( '', $this->site_runs( "return fabrement_get_option( 'phone', 'qa-deleted' );" ) );
    }
}
