<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

final class SharedFieldRoutingTest extends SiteTestCase {
    protected const SITE = 'wpml';

    /** @dataProvider writers */
    public function test_shared_changes_route_to_the_original_and_own_words_stay_local( string $entry ): void {
        $type = 'qa_route_' . $entry;
        $this->post_type( $type, [ 'translationMode' => 'translate' ] );
        $this->fields( $type, [ self::field( 'route_title', 'text' ), self::field( 'route_number', 'number', [ 'translation' => 'copy' ] ),
            self::field( 'route_rows', 'repeater', [ 'sub_fields' => [ self::field( 'title', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ] ] ),
        ] );
        $en = $this->record( $type, 'Original' );
        $this->mcp( 'set-field-values', [ 'postId' => $en, 'values' => [ 'route_title' => 'English', 'route_number' => 1, 'route_rows' => [ [ 'title' => 'EN row', 'code' => 'old' ] ] ] ] );
        $pl = (int) $this->mcp( 'translate-content', [ 'contentId' => $en, 'language' => 'pl', 'slug' => $type . '-pl' ] )['contentId'];
        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $en, 'language' => 'fr', 'slug' => $type . '-fr' ] )['contentId'];
        $row = $this->mcp( 'get-field-values', [ 'postId' => $pl ] )['values']['route_rows'][0]['_row'];
        $added = 'row_ffffffffffffffffffffffffffffffff';
        if ( $entry === 'php' ) {
            $this->assertTrue( $this->site_runs( "\$f = fabrement()->fields($pl)->set('route_title', 'Polski')->set('route_number', 2)->set('route_rows.$row.title', 'PL row')->set('route_rows.$row.code', 'new'); \$f->add_row('route_rows', ['title' => 'New row', 'code' => 'added']); return \$f->save();" ) );
        } elseif ( $entry === 'mcp' ) {
            $saved = $this->mcp( 'set-field-values', [ 'postId' => $pl, 'values' => [ 'route_title' => 'Polski', 'route_number' => 2,
                'route_rows' => [ [ '_row' => $row, 'title' => 'PL row', 'code' => 'new' ], [ 'title' => 'New row', 'code' => 'added' ] ],
            ] ] );
            $this->assertSame( $en, $saved['routed'][0]['postId'] );
        } else {
            $response = Http::request( $this->site(), 'PUT', $this->site()->url( '/wp-json/fabrement/v1/fields/post/' . $pl ), [
                'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ), 'Content-Type' => 'application/json',
            ], (string) json_encode( [ 'values' => [ 'fab_route_title' => 'Polski', 'fab_route_number' => 2,
                'fab_route_rows' => [ [ 'id' => $row, 'values' => [ 'title' => 'PL row', 'code' => 'new' ] ], [ 'id' => $added, 'values' => [ 'title' => 'New row', 'code' => 'added' ] ] ],
            ], 'intentVersion' => 1, 'intent' => [ 'fab_route_title' => true, 'fab_route_number' => true, 'fab_route_rows' => [ $row => [ 'title' => true, 'code' => true ], $added => true ] ] ] ) );
            $this->assertSame( 200, $response['status'], $response['body'] );
            $this->assertSame( $en, json_decode( $response['body'], true )['routed'][0]['postId'] );
        }
        foreach ( [ $en, $pl, $fr ] as $id ) {
            $values = $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values'];
            $this->assertSame( 2, $values['route_number'] );
            $this->assertSame( 'new', $values['route_rows'][0]['code'] );
            $this->assertSame( $id === $pl ? 'Polski' : 'English', $values['route_title'] );
            $this->assertSame( $id === $pl ? 'PL row' : 'EN row', $values['route_rows'][0]['title'] );
            $this->assertSame( $row, $values['route_rows'][0]['_row'] );
            $this->assertCount( 2, $values['route_rows'] );
            $this->assertSame( 'New row', $values['route_rows'][1]['title'], 'A new row routes its initial words too.' );
            $this->assertSame( 'added', $values['route_rows'][1]['code'] );
        }
        // A user allowed to edit the requested translation cannot write a forbidden original.
        $denied = $this->site_runs( "wp_set_current_user(1);
            add_filter('map_meta_cap', static function(\$caps, \$cap, \$user, \$args) { return \$cap === 'edit_post' && (int)(\$args[0] ?? 0) === $en ? ['do_not_allow'] : \$caps; }, 99, 4);
            \$request = new WP_REST_Request('PUT', '/fabrement/v1/fields/post/$pl');
            \$request->set_param('values', ['fab_route_number' => 9, 'fab_route_title' => 'Forbidden']);
            \$request->set_param('intent', ['fab_route_number' => true, 'fab_route_title' => true]); \$request->set_param('intentVersion', 1);
            \$response = rest_do_request(\$request); return [\$response->get_status(), \$response->get_data()['code'] ?? ''];" );
        $this->assertSame( [ 403, 'fields_forbidden' ], $denied );
        $this->assertSame( 'Polski', $this->mcp( 'get-field-values', [ 'postId' => $pl ] )['values']['route_title'] );
        $this->assertSame( 2, $this->mcp( 'get-field-values', [ 'postId' => $en ] )['values']['route_number'] );
    }

    public function test_a_later_failure_names_the_committed_original(): void {
        $type = 'qa_partial_failure';
        $this->post_type( $type, [ 'translationMode' => 'translate' ] );
        $this->fields( $type, [ self::field( 'pf_title', 'text' ), self::field( 'pf_number', 'number', [ 'translation' => 'copy' ] ) ] );
        $en = $this->record( $type, 'Partial failure' );
        $this->mcp( 'set-field-values', [ 'postId' => $en, 'values' => [ 'pf_title' => null, 'pf_number' => 7 ] ] );
        $pl = (int) $this->mcp( 'translate-content', [ 'contentId' => $en, 'language' => 'pl', 'slug' => 'qa-partial-failure-pl' ] )['contentId'];
        $result = $this->site_runs( "wp_set_current_user(1);
            add_action('fabrement_fields_saved', static function(\$type, \$id) {
                if (\$type !== 'post' || (int)\$id !== $en) { return; }
                \$path = \\Fabrement\\Modules\\Fields\\Schema\\SchemaRepository::dir() . '/post.qa_partial_failure.json';
                \$schema = json_decode(file_get_contents(\$path), true);
                \$schema['fields'][0]['required'] = true;
                file_put_contents(\$path, wp_json_encode(\$schema));
                \\Fabrement\\Modules\\Fields\\Schema\\SchemaRepository::flush();
            }, 99, 2);
            \$result = \\Fabrement\\Framework\\App::container()[\\Fabrement\\Modules\\Mcp\\Ability\\SetFieldValuesAbility::class]->execute(['postId' => $pl, 'values' => ['pf_number' => 9]]);
            return is_wp_error(\$result) ? [\$result->get_error_code(), \$result->get_error_message(), \$result->get_error_data()] : \$result;" );
        $this->assertSame( 'fields_partial_write', $result[0] );
        $this->assertStringNotContainsString( 'Nothing was saved', $result[1] );
        $this->assertSame( [ [ 'postId' => $en ] ], $result[2]['committed'] );
        $this->assertSame( [ 'postId' => $pl ], $result[2]['failed'] );
        $this->assertSame( [ '9' ], $this->site()->meta( $en, 'fab_pf_number' ) );
    }

    public static function writers(): array { return [ 'php' => [ 'php' ], 'mcp' => [ 'mcp' ], 'rest' => [ 'rest' ] ]; }
}
