<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\Http;
use Fabrement\Tests\Site\Support\SiteTestCase;

/** The real PHP, MCP and REST entries agree on sparse values and atomic validation. */
final class SharedFieldWriterTest extends SiteTestCase {
    /** @dataProvider writers */
    public function test_removed_choices_have_one_contract_for_posts_and_options( string $entry, string $owner ): void {
        $slug = 'qa-choice-' . $entry . '-' . $owner;
        $type = 'qa_choice_' . $entry;
        $city = self::field( 'city', 'select', [ 'choices' => [ [ 'value' => 'lviv', 'label' => 'Lviv' ], [ 'value' => 'kyiv', 'label' => 'Kyiv' ] ] ] );
        $fields = [ $city, self::field( 'group', 'group', [ 'sub_fields' => [ $city, self::field( 'word', 'text' ) ] ] ),
            self::field( 'rows', 'repeater', [ 'sub_fields' => [ $city ] ] ),
            array_replace( $city, [ 'name' => 'required_city', 'required' => true ] ) ];
        if ( $owner === 'post' ) {
            $this->post_type( $type );
            $this->fields( $type, $fields );
            $id = $this->record( $type, $slug );
            $target = [ 'postId' => $id ];
            $api = "fabrement()->fields($id)";
            $path = '/wp-json/fabrement/v1/fields/post/' . $id;
        } else {
            $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => 0, 'page' => [ 'title' => $slug ], 'fields' => $fields ] );
            $target = [ 'options' => $slug ];
            $api = "fabrement()->options('$slug')";
            $path = '/wp-json/fabrement/v1/fields/options/' . $slug;
        }
        $before = $this->mcp( 'set-field-values', $target + [ 'values' => [ 'city' => 'lviv', 'required_city' => 'lviv',
            'group' => [ 'city' => 'lviv', 'word' => 'Keep' ], 'rows' => [ [ 'city' => 'lviv' ], [ 'city' => 'lviv' ] ] ] ] )['values'];
        $document = $owner === 'post' ? $this->mcp( 'get-fields', [ 'type' => 'post', 'slug' => $type ] )
            : $this->mcp( 'get-options-page', [ 'slug' => $slug ] );
        $remove = static function ( array $fields ) use ( &$remove ): array {
            foreach ( $fields as &$field ) {
                if ( $field['type'] === 'select' ) {
                    $field['choices'] = [ [ 'value' => 'kyiv', 'label' => 'Kyiv' ] ];
                } elseif ( isset( $field['sub_fields'] ) ) {
                    $field['sub_fields'] = $remove( $field['sub_fields'] );
                }
            }
            return $fields;
        };
        $this->mcp( $owner === 'post' ? 'save-fields' : 'save-options-page', [
            'slug' => $owner === 'post' ? $type : $slug, 'expectedRevision' => $document['revision'], 'fields' => $remove( $document['fields'] ),
        ] + ( $owner === 'post' ? [ 'type' => 'post' ] : [] ) );
        $read = $this->mcp( 'get-field-values', $target );
        $this->assertNull( $read['values']['city'] );
        $this->assertNull( $read['values']['group']['city'] );
        $this->assertNull( $read['values']['rows'][0]['city'] );
        $this->assertTrue( $read['stored']['city'] );
        $this->assertNull( $this->site_runs( "return {$api}->get_select('city');" ) );
        $this->assertStringContainsString( 'required_city', $this->mcp_error( 'set-field-values', $target + [ 'values' => [ 'city' => 'lviv' ] ] ) );
        $a = $before['rows'][0]['_row'];
        $b = $before['rows'][1]['_row'];
        if ( $entry === 'php' ) {
            $this->site_runs( "{$api}->set('city','lviv')->set('group.city','lviv')->set('rows.$a.city','lviv')->move_row('rows','$b',before:'$a')->set('required_city','kyiv')->save();" );
        } elseif ( $entry === 'mcp' ) {
            $this->mcp( 'set-field-values', $target + [ 'values' => [ 'city' => 'lviv', 'group' => [ 'city' => 'lviv' ],
                'rows' => [ [ '_row' => $b ], [ '_row' => $a, 'city' => 'lviv' ] ], 'required_city' => 'kyiv' ] ] );
        } else {
            $this->rest( $path, [ 'values' => [ 'fab_city' => 'lviv', 'fab_group' => [ 'city' => 'lviv' ],
                'fab_rows' => [ [ 'id' => $b, 'values' => [] ], [ 'id' => $a, 'values' => [ 'city' => 'lviv' ] ] ], 'fab_required_city' => 'kyiv' ],
                'intentVersion' => 1, 'intent' => [ 'fab_city' => true, 'fab_group' => [ 'city' => true ],
                    'fab_rows' => [ $a => [ 'city' => true ] ], 'fab_required_city' => true ] ] );
        }
        foreach ( [ 'fab_city' => null, 'fab_group_city' => null, 'fab_rows_1_city' => null, 'fab_rows_0_city' => 'lviv',
            'fab_group_word' => 'Keep', 'fab_required_city' => 'kyiv', 'fab_rows_0__rid' => $b, 'fab_rows_1__rid' => $a ] as $key => $expected ) {
            if ( $owner === 'post' ) {
                $this->assertSame( [ $expected ], $this->site()->meta( $id, $key ), $key );
            } else {
                $raw = $this->site()->option( 'fabrement_options_' . $slug );
                $this->assertArrayHasKey( $key, $raw );
                $this->assertSame( $expected, $raw[ $key ], $key );
            }
        }
    }

    /** @dataProvider writers */
    public function test_partial_clear_default_and_validation_through_each_entry( string $entry, string $owner ): void {
        $slug = 'qa-core-' . $entry . '-' . $owner;
        $type = 'qa_core_' . $entry;
        $fields = [ self::field( 'core_title', 'text', [ 'required' => true ] ),
            self::field( 'core_group', 'group', [ 'sub_fields' => [ self::field( 'note', 'text' ), self::field( 'amount', 'number', [ 'default' => 7 ] ) ] ] ),
            self::field( 'core_rows', 'repeater', [ 'sub_fields' => [ self::field( 'note', 'text' ), self::field( 'amount', 'number', [ 'default' => 7 ] ) ] ] ),
        ];
        if ( $owner === 'post' ) {
            $this->post_type( $type );
            $this->fields( $type, $fields );
            $id = $this->record( $type, $slug );
            $target = [ 'postId' => $id ];
            $api = "fabrement()->fields($id)";
            $path = '/wp-json/fabrement/v1/fields/post/' . $id;
        } else {
            $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => 0, 'page' => [ 'title' => $slug ], 'fields' => $fields ] );
            $target = [ 'options' => $slug ];
            $api = "fabrement()->options('$slug')";
            $path = '/wp-json/fabrement/v1/fields/options/' . $slug;
        }
        $before = $this->mcp( 'set-field-values', $target + [ 'values' => [ 'core_title' => 'Required',
            'core_group' => [ 'note' => 'Before' ], 'core_rows' => [ [ 'note' => 'A' ], [ 'note' => 'B' ] ],
        ] ] )['values'];
        // A child added to an existing schema has a displayed default but no stored value.
        $missing = [ 'fab_core_group_amount', 'fab_core_rows_0_amount', 'fab_core_rows_1_amount' ];
        if ( $owner === 'post' ) {
            $this->site_runs( 'foreach (' . var_export( $missing, true ) . " as \$key) { delete_post_meta($id, \$key); }" );
        } else {
            $this->site_runs( "\$map = get_option('fabrement_options_$slug'); foreach (" . var_export( $missing, true ) . " as \$key) { unset(\$map[\$key]); } update_option('fabrement_options_$slug', \$map);" );
        }
        $a = $before['core_rows'][0]['_row'];
        $b = $before['core_rows'][1]['_row'];
        if ( $entry === 'php' ) {
            $this->assertTrue( $this->site_runs( "\$f = $api; \$f->set('core_group.note', 'Changed')->set('core_rows.$a.note', 'Changed A')->move_row('core_rows', '$b', before: '$a'); return \$f->save();" ) );
        } elseif ( $entry === 'mcp' ) {
            $this->mcp( 'set-field-values', $target + [ 'values' => [ 'core_group' => [ 'note' => 'Changed' ],
                'core_rows' => [ [ '_row' => $b ], [ '_row' => $a, 'note' => 'Changed A' ] ],
            ] ] );
        } else {
            $this->rest( $path, [ 'values' => [ 'fab_core_group' => [ 'note' => 'Changed', 'amount' => 7 ],
                'fab_core_rows' => [ [ 'id' => $b, 'values' => [ 'note' => 'B', 'amount' => 7 ] ], [ 'id' => $a, 'values' => [ 'note' => 'Changed A', 'amount' => 7 ] ] ],
            ], 'intentVersion' => 1, 'intent' => [ 'fab_core_group' => [ 'note' => true ], 'fab_core_rows' => [ $a => [ 'note' => true ] ] ] ] );
        }
        foreach ( $missing as $key ) {
            if ( $owner === 'post' ) {
                $this->assertFalse( $this->site()->has_meta( $id, $key ), $entry . '/' . $key );
            } else {
                $this->assertArrayNotHasKey( $key, $this->site()->option( 'fabrement_options_' . $slug ) );
            }
        }
        $read = $this->mcp( 'get-field-values', $target )['values'];
        $this->assertSame( [ $b, $a ], array_column( $read['core_rows'], '_row' ) );
        $this->assertSame( [ 'B', 'Changed A' ], array_column( $read['core_rows'], 'note' ) );
        $this->assertSame( 'Changed', $read['core_group']['note'] );

        if ( $entry === 'php' ) {
            $this->site_runs( "{$api}->clear('core_group')->save();" );
        } elseif ( $entry === 'mcp' ) {
            $this->mcp( 'set-field-values', $target + [ 'values' => [ 'core_group' => null ] ] );
        } else {
            $this->rest( $path, [ 'delete' => [ 'fab_core_group' ], 'intent' => [], 'intentVersion' => 1 ] );
        }
        foreach ( [ 'fab_core_group_note', 'fab_core_group_amount' ] as $key ) {
            if ( $owner === 'post' ) {
                $this->assertSame( [ null ], $this->site()->meta( $id, $key ) );
            } else {
                $map = $this->site()->option( 'fabrement_options_' . $slug );
                $this->assertArrayHasKey( $key, $map );
                $this->assertNull( $map[ $key ] );
            }
        }
        $held = $this->mcp( 'get-field-values', $target )['values'];
        if ( $entry === 'php' ) {
            $error = $this->site_runs( "try { \$f = $api; \$f->set('core_group.note', 'Must not save')->clear('core_title')->save(); return ''; } catch (\\Fabrement\\Api\\FieldsException \$e) { return \$e->getMessage(); }" );
            $this->assertStringContainsString( 'required', strtolower( $error ) );
        } elseif ( $entry === 'mcp' ) {
            $this->assertStringContainsString( 'required', strtolower( $this->mcp_error( 'set-field-values', $target + [ 'values' => [ 'core_group' => [ 'note' => 'Must not save' ], 'core_title' => null ] ] ) ) );
        } else {
            $this->rest( $path, [ 'values' => [ 'fab_core_group' => [ 'note' => 'Must not save' ], 'fab_core_title' => null ],
                'intent' => [ 'fab_core_group' => [ 'note' => true ], 'fab_core_title' => true ], 'intentVersion' => 1 ], 400 );
        }
        $this->assertSame( $held, $this->mcp( 'get-field-values', $target )['values'] );
    }

    private function rest( string $path, array $payload, int $status = 200 ): array {
        $result = Http::request( $this->site(), 'PUT', $this->site()->url( $path ), [
            'Authorization' => 'Basic ' . base64_encode( 'admin:' . $this->site()->password ), 'Content-Type' => 'application/json',
        ], (string) json_encode( $payload ) );
        $this->assertSame( $status, $result['status'], $result['body'] );
        return json_decode( $result['body'], true );
    }

    public static function writers(): array {
        return [ 'php post' => [ 'php', 'post' ], 'mcp post' => [ 'mcp', 'post' ], 'rest post' => [ 'rest', 'post' ],
            'php options' => [ 'php', 'options' ], 'mcp options' => [ 'mcp', 'options' ], 'rest options' => [ 'rest', 'options' ] ];
    }
}
