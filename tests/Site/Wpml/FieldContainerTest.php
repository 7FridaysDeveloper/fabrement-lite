<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/** A host's container override reaches both HTTP entries without changing trusted PHP rules. */
final class FieldContainerTest extends SiteTestCase {
    protected const SITE = 'wpml';

    public function test_external_routing_rules_are_injected_and_site_php_remains_standard(): void {
        $this->post_type( 'qa_di_fields', [ 'translationMode' => 'translate' ] );
        $this->fields( 'qa_di_fields', [ self::field( 'di_price', 'number', [ 'translation' => 'copy' ] ), self::field( 'di_text', 'text' ) ] );
        $en = $this->record( 'qa_di_fields', 'Original' );
        $this->mcp( 'set-field-values', [ 'postId' => $en, 'values' => [ 'di_price' => 1, 'di_text' => 'English' ] ] );
        $pl = (int) $this->mcp( 'translate-content', [ 'contentId' => $en, 'language' => 'pl', 'slug' => 'di-pl' ] )['contentId'];
        $this->site_code( 'qa-field-rules', <<<'PHP'
add_action('plugins_loaded', static function() {
    \Fabrement\Framework\App::container()[\Fabrement\Modules\Fields\Write\WriteRules::class] = static fn() => new class extends \Fabrement\Modules\Fields\Write\WriteRules {
        public function original(\Fabrement\Modules\Fields\Values\FieldObject $target, \Fabrement\Modules\Fields\Values\FieldObject $original, array $paths, \Fabrement\Modules\Fields\Write\WriteMode $mode): \Fabrement\Modules\Fields\Write\WriteDecision {
            return \Fabrement\Modules\Fields\Write\WriteDecision::refuse('Edit shared values on the original.');
        }
    };
}, 11);
PHP
        );
        $this->assertStringContainsString( 'Edit shared values on the original.', $this->mcp_error( 'set-field-values', [ 'postId' => $pl, 'values' => [ 'di_price' => 2, 'di_text' => 'Must not save' ] ] ) );
        $response = Http::request( $this->site(), 'PUT', $this->site()->url( '/wp-json/fabrement/v1/fields/post/' . $pl ), [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ), 'Content-Type' => 'application/json',
        ], (string) json_encode( [ 'values' => [ 'fab_di_price' => 2, 'fab_di_text' => 'Must not save' ], 'intent' => [ 'fab_di_price' => true, 'fab_di_text' => true ], 'intentVersion' => 1 ] ) );
        $this->assertSame( 403, $response['status'], $response['body'] );
        $this->assertSame( 'fields_routing_refused', json_decode( $response['body'], true )['code'] );
        foreach ( [ $en, $pl ] as $id ) {
            $this->assertSame( [ '1' ], $this->site()->meta( $id, 'fab_di_price' ) );
            $this->assertSame( [ 'English' ], $this->site()->meta( $id, 'fab_di_text' ) );
        }
        // Only shared changes are governed by routing rules; local words still pass normal guards.
        $this->mcp( 'set-field-values', [ 'postId' => $pl, 'values' => [ 'di_text' => 'Polski' ] ] );
        $this->assertTrue( $this->site_runs( "return fabrement()->fields($pl)->set_number('di_price', 3)->save();" ) );
        $this->assertSame( [ '3' ], $this->site()->meta( $en, 'fab_di_price' ) );
        $this->assertSame( [ '3' ], $this->site()->meta( $pl, 'fab_di_price' ) );
        $this->assertSame( [ 'Polski' ], $this->site()->meta( $pl, 'fab_di_text' ) );
    }
}
