<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Seo\Schema\SchemaStore;
use WP_Error;
use WP_UnitTestCase;

/**
 * What a schema.org template may read, on real WordPress with Yoast: the page's own values as Yoast
 * has them, protected meta keys refused on write and never printed, and a password-protected record
 * giving away nothing behind its password. Without an SEO plugin set-schema is refused.
 */
final class SchemaTokensTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        if ( ! defined( 'WPSEO_VERSION' ) && $this->getName() !== 'test_without_an_seo_plugin_nothing_is_written' ) {
            $this->markTestSkipped( 'Structured data is the SEO plugin\'s output: Yoast SEO is not loaded in this run.' );
        }
    }

    public function test_without_an_seo_plugin_nothing_is_written(): void {
        if ( defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Yoast SEO is loaded in this run.' );
        }
        $id  = $this->record();
        $out = $this->set_schema( [ 'contentId' => $id, 'graph' => $this->template() ] );

        $this->assertSame( 'yoast_inactive', $out instanceof WP_Error ? $out->get_error_code() : 'accepted' );
        $this->assertSame( [], SchemaStore::read_post( $id ) );
    }

    private function set_schema( array $input ) {
        return wp_get_ability( 'fabrement/set-schema' )->execute( $input );
    }

    /** One node reading everything a template can read about a record. */
    private function template(): array {
        return [ [
            '@type'       => 'Product',
            'name'        => '{{title}}',
            'description' => '{{excerpt}}',
            'sku'         => '{{meta:sku}}',
            'category'    => '{{term:category}}',
        ] ];
    }

    private function record( array $args = [] ): int {
        $id = self::factory()->post->create(
            $args + [
                'post_title'   => 'Chair',
                'post_excerpt' => 'A good chair',
                'post_content' => '<!-- wp:paragraph --><p>Secret body</p><!-- /wp:paragraph -->',
                'post_status'  => 'publish',
            ]
        );
        update_post_meta( $id, 'sku', 'CH-1' ); // another plugin's field
        if ( defined( 'WPSEO_VERSION' ) ) {
            // Its description as Yoast has it — set as set-seo does, index included.
            \Fabrement\Modules\Seo\Seo::bridge()->write_post( $id, [ 'metaDesc' => 'A good chair' ] );
            \Fabrement\Modules\Seo\Seo::bridge()->reindex_post( $id );
        }
        update_post_meta( $id, '_edit_last', '1' );
        wp_set_object_terms( $id, 'Furniture', 'category' );

        return $id;
    }

    public function test_a_protected_meta_key_is_refused_and_nothing_is_saved(): void {
        $id = $this->record();

        foreach ( [ '_edit_last', '_wp_page_template', '_yoast_wpseo_title' ] as $key ) {
            $out = $this->set_schema( [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', 'name' => '{{meta:' . $key . '}}' ] ] ] );
            $this->assertInstanceOf( WP_Error::class, $out, $key );
            $this->assertSame( 'protected_meta', $out->get_error_code(), $key );
        }
        $this->assertSame( [], SchemaStore::read_post( $id ) );

        $type = $this->set_schema( [ 'postType' => 'post', 'graph' => [ [ '@type' => 'Thing', 'name' => '{{meta:_edit_last}}' ] ] ] );
        $this->assertSame( 'protected_meta', $type->get_error_code() );
        $this->assertSame( [], SchemaStore::read_type( 'post' ) );
    }

    public function test_the_page_body_is_not_a_token(): void {
        $out = $this->set_schema( [ 'contentId' => $this->record(), 'graph' => [ [ '@type' => 'Article', 'articleBody' => '{{content_text}}' ] ] ] );

        $this->assertInstanceOf( WP_Error::class, $out );
        $this->assertSame( 'unknown_token', $out->get_error_code() );
    }

    public function test_a_fabrement_field_is_a_field_token_not_raw_meta(): void {
        $out = $this->set_schema( [ 'contentId' => $this->record(), 'graph' => [ [ '@type' => 'Product', 'sku' => '{{meta:fab_price}}' ] ] ] );

        $this->assertInstanceOf( WP_Error::class, $out );
        $this->assertSame( 'meta_is_a_field', $out->get_error_code() );
        $this->assertStringContainsString( '{{field:price}}', $out->get_error_message() );
    }

    public function test_the_page_values_are_yoasts_and_removed_tokens_are_refused(): void {
        $id  = $this->record();
        $out = $this->set_schema( [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', 'url' => '{{url}}', 'isPartOf' => [ '@id' => '{{site_url}}#website' ] ] ] ] );
        $this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
        $values = \Fabrement\Modules\Seo\Seo::bridge()->schema_values( $id );
        $node   = SchemaStore::resolve_for_post( $id )[0];
        $this->assertSame( $values['url'], $node['url'], 'Yoast\'s WebPage id' );
        $this->assertSame( $values['site_url'] . '#website', $node['isPartOf']['@id'] );

        foreach ( [ 'site_name', 'author_name', 'author_url', 'post_id', 'post_type', 'featured_image_id' ] as $gone ) {
            $refused = $this->set_schema( [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', 'name' => '{{' . $gone . '}}' ] ] ] );
            $this->assertSame( 'unknown_token', $refused instanceof WP_Error ? $refused->get_error_code() : 'accepted', $gone );
        }
        register_taxonomy( 'qa_private_tax', 'post', [ 'public' => false ] );
        $private = $this->set_schema( [ 'contentId' => $id, 'graph' => [ [ '@type' => 'Thing', 'name' => '{{term:qa_private_tax}}' ] ] ] );
        $this->assertSame( 'taxonomy_not_public', $private instanceof WP_Error ? $private->get_error_code() : 'accepted' );
        unregister_taxonomy( 'qa_private_tax' );
    }

    public function test_a_field_key_is_accepted_and_resolved(): void {
        $id  = $this->record();
        $out = $this->set_schema( [ 'contentId' => $id, 'graph' => $this->template() ] );

        $this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
        $node = SchemaStore::resolve_for_post( $id )[0];
        $this->assertSame( 'Chair', $node['name'] );
        $this->assertSame( 'A good chair', $node['description'] );
        $this->assertSame( 'CH-1', $node['sku'] );
        $this->assertSame( 'Furniture', $node['category'] );
    }

    public function test_a_protected_key_stored_before_the_check_still_prints_nothing(): void {
        $id = $this->record();
        // Written past validation, as a graph saved by an older version would be.
        update_post_meta( $id, Keys::META_SCHEMA, wp_slash( wp_json_encode( [ [ '@type' => 'Thing', 'name' => 'Kept', 'identifier' => '{{meta:_edit_last}}' ] ] ) ) );

        $node = SchemaStore::resolve_for_post( $id )[0];

        $this->assertSame( 'Kept', $node['name'] );
        $this->assertArrayNotHasKey( 'identifier', $node );
    }

    public function test_a_password_protected_record_gives_away_nothing_behind_the_password(): void {
        $id = $this->record( [ 'post_password' => 'letmein' ] );
        $this->set_schema( [ 'contentId' => $id, 'graph' => $this->template() ] );

        $node = SchemaStore::resolve_for_post( $id )[0];

        // The title and address are public on a protected page anyway.
        $this->assertSame( 'Chair', $node['name'] );
        foreach ( [ 'description', 'sku', 'category' ] as $gone ) {
            $this->assertArrayNotHasKey( $gone, $node, $gone );
        }
        $this->assertStringNotContainsString( 'Secret body', (string) wp_json_encode( $node ) );
        $this->assertStringNotContainsString( 'protected post', (string) wp_json_encode( $node ), 'no "There is no excerpt…" sentence' );
    }

    public function test_the_type_template_follows_the_same_rules(): void {
        $open   = $this->record();
        $locked = $this->record( [ 'post_password' => 'letmein' ] );
        $this->set_schema( [ 'postType' => 'post', 'graph' => $this->template() ] );

        $this->assertSame( 'CH-1', SchemaStore::resolve_for_post( $open )[0]['sku'] );
        $this->assertArrayNotHasKey( 'sku', SchemaStore::resolve_for_post( $locked )[0] );
    }
}
