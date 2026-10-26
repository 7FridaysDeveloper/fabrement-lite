<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/** Partial edits preserve sparse storage through schema changes and row reordering. */
final class FieldPartialWriteTest extends SiteTestCase {
    /** @dataProvider writers */
    public function test_existing_children_stay_absent_and_new_rows_receive_defaults( string $writer ): void {
        $type = 'qa_partial_' . $writer;
        $slug = 'qa-partial-' . $writer;
        $this->post_type( $type );
        $children = [ self::field( 'title', 'text' ), self::field( 'details', 'group', [
            'sub_fields' => [ self::field( 'note', 'text' ) ],
        ] ) ];
        $fields = [ self::field( 'partial_group', 'group', [ 'sub_fields' => $children ] ),
            self::field( 'partial_rows', 'repeater', [ 'sub_fields' => $children ] ),
        ];
        $this->fields( $type, $fields );
        $options = $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => 0,
            'page' => [ 'title' => 'Partial writes' ], 'fields' => $fields,
        ] );
        $id = $this->record( $type, 'Partial writes' );
        $targets = [ 'post' => [ 'postId' => $id ], 'options' => [ 'options' => $slug ] ];
        $before = [];
        foreach ( $targets as $owner => $target ) {
            $before[ $owner ] = $this->mcp( 'set-field-values', $target + [ 'values' => [
                'partial_group' => [ 'title' => 'Group', 'details' => [ 'note' => 'Group note' ] ],
                'partial_rows' => [
                    [ 'title' => 'A', 'details' => [ 'note' => 'Note A' ] ],
                    [ 'title' => 'B', 'details' => [ 'note' => 'Note B' ] ],
                ],
            ] ] )['values'];
        }

        $fields = $options['fields'];
        foreach ( $fields as &$field ) {
            $field['sub_fields'][] = self::field( 'amount', 'number', [ 'default' => 7 ] );
            $field['sub_fields'][1]['sub_fields'][] = self::field( 'count', 'number', [ 'default' => 11 ] );
        }
        unset( $field );
        $this->fields( $type, $fields );
        $options = $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => $options['revision'], 'fields' => $fields ] );

        foreach ( $targets as $owner => $target ) {
            $a = $before[ $owner ]['partial_rows'][0]['_row'];
            $b = $before[ $owner ]['partial_rows'][1]['_row'];
            if ( $writer === 'mcp' ) {
                $this->mcp( 'set-field-values', $target + [ 'values' => [
                    'partial_group' => [ 'title' => 'Changed' ],
                    'partial_rows' => [
                        [ '_row' => $b ],
                        [ '_row' => $a, 'title' => 'Changed A', 'details' => [ 'note' => 'Changed note' ] ],
                        [ 'title' => 'New', 'details' => [ 'count' => null ] ],
                    ],
                ] ] );
            } else {
                $api = $owner === 'post' ? "fabrement()->fields($id)" : "fabrement()->options('$slug')";
                $this->site_runs( "\$f = $api; \$f->set_text('partial_group.title', 'Changed');
                    \$f->update_row('partial_rows', '$a', ['title' => 'Changed A', 'details' => ['note' => 'Changed note']]);
                    \$f->move_row('partial_rows', '$b', before: '$a');
                    \$f->add_row('partial_rows', ['title' => 'New', 'details' => ['count' => null]]);
                    return \$f->save();" );
            }
            $map = $owner === 'options' ? $this->site()->option( 'fabrement_options_' . $slug ) : [];
            foreach ( [ 'fab_partial_group_amount', 'fab_partial_group_details_count',
                'fab_partial_rows_0_amount', 'fab_partial_rows_1_amount',
                'fab_partial_rows_0_details_count', 'fab_partial_rows_1_details_count',
            ] as $key ) {
                if ( $owner === 'post' ) {
                    $this->assertFalse( $this->site()->has_meta( $id, $key ), "$writer/$key stays absent" );
                } else {
                    $this->assertArrayNotHasKey( $key, $map, "$writer/$key stays absent" );
                }
            }
            if ( $owner === 'post' ) {
                $this->assertSame( [ '7' ], $this->site()->meta( $id, 'fab_partial_rows_2_amount' ) );
                $this->assertSame( [ null ], $this->site()->meta( $id, 'fab_partial_rows_2_details_count' ) );
            } else {
                $this->assertSame( '7', $map['fab_partial_rows_2_amount'] );
                $this->assertArrayHasKey( 'fab_partial_rows_2_details_count', $map );
                $this->assertNull( $map['fab_partial_rows_2_details_count'] );
            }
            $read = $this->mcp( 'get-field-values', $target )['values'];
            $this->assertSame( [ $b, $a ], array_slice( array_column( $read['partial_rows'], '_row' ), 0, 2 ) );
            $this->assertSame( [ 'B', 'Changed A', 'New' ], array_column( $read['partial_rows'], 'title' ) );
            $this->assertSame( 'Group note', $read['partial_group']['details']['note'] );
            $this->assertSame( 'Note B', $read['partial_rows'][0]['details']['note'] );
            $this->assertSame( 'Changed note', $read['partial_rows'][1]['details']['note'] );
            if ( $writer === 'mcp' ) {
                $this->mcp( 'set-field-values', $target + [ 'values' => [ 'partial_group' => [ 'amount' => 7 ] ] ] );
            } else {
                $this->site_runs( "{$api}->set_number('partial_group.amount', 7)->save();" );
            }
        }

        $fields = $options['fields'];
        foreach ( $fields as &$field ) {
            $field['sub_fields'][2]['default'] = 10;
            $field['sub_fields'][1]['sub_fields'][1]['default'] = 12;
        }
        unset( $field );
        $this->fields( $type, $fields );
        $this->mcp( 'save-options-page', [ 'slug' => $slug, 'expectedRevision' => $options['revision'], 'fields' => $fields ] );
        foreach ( $targets as $target ) {
            $read = $this->mcp( 'get-field-values', $target )['values'];
            $this->assertSame( 7, $read['partial_group']['amount'], 'An explicitly submitted default stays stored after the schema default changes.' );
            $this->assertSame( 12, $read['partial_group']['details']['count'] );
            $this->assertSame( [ 10, 10, 7 ], array_column( $read['partial_rows'], 'amount' ) );
            $this->assertSame( [ 12, 12, null ], array_map( static fn( array $row ) => $row['details']['count'] ?? null, $read['partial_rows'] ) );
        }
    }

    public static function writers(): array {
        return [ 'mcp' => [ 'mcp' ], 'php' => [ 'php' ] ];
    }
}
