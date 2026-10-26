<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/** The container's real routes preserve the editor's methods, access checks and input contract. */
final class FieldRoutesTest extends SiteTestCase {
    /** @dataProvider owners */
    public function test_editor_routes_validate_access_and_intent_and_save_once( string $owner ): void {
        if ( $owner === 'post' ) {
            $this->fields( 'page', [ self::field( 'route_note', 'text' ) ] );
            $id = $this->record( 'page', 'QA field route' );
            $path = '/wp-json/fabrement/v1/fields/post/' . $id;
            $stored = fn() => $this->site()->meta( $id, 'fab_route_note' )[0] ?? null;
        } else {
            $this->mcp( 'save-options-page', [ 'slug' => 'qa-route', 'expectedRevision' => 0, 'page' => [ 'title' => 'Route' ], 'fields' => [ self::field( 'route_note', 'text' ) ] ] );
            $path = '/wp-json/fabrement/v1/fields/options/qa-route';
            $stored = fn() => $this->site()->option( 'fabrement_options_qa-route' )['fab_route_note'] ?? null;
        }
        $send = function ( string $method, ?array $body = null, bool $signed_in = true ) use ( $path ): array {
            $headers = [ 'Content-Type' => 'application/json' ];
            if ( $signed_in ) {
                $headers['Authorization'] = 'Basic ' . base64_encode( 'admin:' . $this->site()->password );
            }
            return Http::request( $this->site(), $method, $this->site()->url( $path ), $headers, $body === null ? null : (string) json_encode( $body ) );
        };
        foreach ( [ 'GET', 'PUT' ] as $method ) {
            $denied = $send( $method, null, false );
            $this->assertSame( 401, $denied['status'], $denied['body'] );
            $this->assertSame( 'fields_forbidden', json_decode( $denied['body'], true )['code'] );
        }
        $this->assertSame( 200, $send( 'GET' )['status'] );
        foreach ( [
            [ 'values' => [ 'fab_route_note' => 'Invalid' ] ],
            [ 'values' => [ 'fab_route_note' => 'Invalid' ], 'intent' => [ 'fab_route_note' => true ] ],
            [ 'values' => [ 'fab_route_note' => 'Invalid' ], 'intent' => [ 'fab_route_note' => true ], 'intentVersion' => 2 ],
        ] as $body ) {
            $invalid = $send( 'PUT', $body );
            $this->assertSame( 400, $invalid['status'], $invalid['body'] );
        }
        $this->assertNull( $stored() );

        $this->site_code( 'qa-count-field-routes', "add_action('fabrement_fields_saved', static function() { update_option('qa_field_route_saves', 1 + (int) get_option('qa_field_route_saves', 0)); });" );
        $before = (int) $this->site()->option( 'qa_field_route_saves' );
        $saved = $send( 'PUT', [ 'values' => [ 'fab_route_note' => 'Saved once' ], 'intent' => [ 'fab_route_note' => true ], 'intentVersion' => 1 ] );
        $this->assertSame( 200, $saved['status'], $saved['body'] );
        $this->assertSame( 'Saved once', json_decode( $saved['body'], true )['values']['fab_route_note'] );
        $this->assertSame( 'Saved once', $stored() );
        $this->assertSame( $before + 1, (int) $this->site()->option( 'qa_field_route_saves' ) );
        $this->assertSame( 404, $send( 'POST' )['status'] );
    }

    public static function owners(): array {
        return [ 'record' => [ 'post' ], 'options' => [ 'options' ] ];
    }
}
