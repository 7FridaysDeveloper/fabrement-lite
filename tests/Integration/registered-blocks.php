<?php
/** wp eval-file tests/Integration/registered-blocks.php — in-memory fixtures, no post/database writes. */
use Fabrement\Modules\Mcp\Support\AcfBlockData;
use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\Mcp\Support\ContentEdits;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\Mcp\Support\RegisteredBlocks;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
$passed = 0;
$assert = static function ( bool $condition, string $message ) use ( &$passed ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$passed;
};
$refuses = static function ( callable $operation, string $label ) use ( $assert ) {
    try { $operation(); } catch ( McpAbilityException $e ) { $assert( true, $label ); return; }
    $assert( false, 'Did not refuse: ' . $label );
};
$registered = [];
$groups = [];
try {
    $name = 'fabrement/test-registry-card';
    if ( WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) { throw new RuntimeException( 'Fixture collision.' ); }
    register_block_type( $name, [ 'title' => 'Test registry card', 'attributes' => [ 'count' => [ 'type' => 'integer' ], 'title' => [ 'type' => 'string' ] ], 'render_callback' => static fn() => '' ] );
    $registered[] = $name;
    $assert( in_array( $name, array_column( RegisteredBlocks::summaries(), 'name' ), true ), 'Registry discovery' );
    $assert( RegisteredBlocks::describe( 'core/paragraph' )['writeMode'] === 'markup', 'Core markup guidance' );
    $refuses( static fn() => BlockEntry::serialize( [ 'name' => $name, 'attributes' => [ 'count' => 'bad' ] ], 0 ), 'type' );
    $entry = [ 'name' => $name, 'attributes' => [ 'count' => 2, 'title' => '" --> <b>text</b> & Україна' ] ];
    $parsed = parse_blocks( BlockEntry::serialize( $entry, 0 )['markup'] );
    $assert( count( $parsed ) === 1 && $parsed[0]['attrs'] === $entry['attributes'], 'Comment-safe round trip' );
    $assert( ! AcfBlockData::is_block( $name ), 'Not all dynamic blocks are ACF' );

    // A PHP callback may depend on the HTML produced by a client's save() implementation.
    $hybrid_name = 'fabrement-test/hybrid-card';
    if ( WP_Block_Type_Registry::get_instance()->is_registered( $hybrid_name ) ) { throw new RuntimeException( 'Hybrid fixture collision.' ); }
    register_block_type( $hybrid_name, [
        'title' => 'Hybrid card',
        'attributes' => [ 'title' => [ 'type' => 'string', 'source' => 'html', 'selector' => 'h3' ] ],
        'render_callback' => static fn( $attrs, $content ) => $content,
    ] );
    $registered[] = $hybrid_name;
    $assert( RegisteredBlocks::describe( $hybrid_name )['writeMode'] === 'markup', 'Hybrid schema requires markup' );
    $summaries = array_column( RegisteredBlocks::summaries(), null, 'name' );
    $assert( $summaries[ $hybrid_name ]['writeMode'] === 'markup', 'Hybrid discovery agrees with schema' );
    $hybrid_attrs = [ 'name' => $hybrid_name, 'attributes' => [ 'title' => 'Preserve this heading' ] ];
    $refuses( static fn() => BlockEntry::serialize( $hybrid_attrs, 0 ), 'Hybrid attribute-only write refused' );
    $refuses( static fn() => ContentEdits::apply( [], [ [ 'op' => 'insert', 'at' => 0, 'block' => $hybrid_attrs ] ] ), 'Hybrid attribute-only insert refused' );
    $hybrid_markup = '<!-- wp:' . $hybrid_name . ' --><h3>Preserve this heading</h3><!-- /wp:' . $hybrid_name . ' -->';
    $hybrid_entry = BlockEntry::describe( parse_blocks( $hybrid_markup )[0] );
    $assert( ( $hybrid_entry['markup'] ?? null ) === $hybrid_markup, 'Hybrid read preserves saved HTML' );
    $assert( do_blocks( BlockEntry::serialize( $hybrid_entry, 0 )['markup'] ) === '<h3>Preserve this heading</h3>', 'Hybrid full markup renders correctly' );
    $refuses( static fn() => ContentEdits::apply( [ $hybrid_entry ], [ [ 'op' => 'replace', 'index' => 0, 'name' => $hybrid_name, 'block' => $hybrid_attrs ] ] ), 'Hybrid attribute-only replacement refused' );
    $unknown_empty = BlockEntry::describe( parse_blocks( '<!-- wp:' . $hybrid_name . ' /-->' )[0] );
    $assert( isset( $unknown_empty['markup'] ), 'Empty unknown dynamic block still uses markup' );
    // No source attribute is not proof of save() === null either (e.g. an InnerBlocks wrapper).
    $hybrid_type = WP_Block_Type_Registry::get_instance()->get_registered( $hybrid_name );
    unset( $hybrid_type->attributes['title']['source'], $hybrid_type->attributes['title']['selector'] );
    $assert( RegisteredBlocks::describe( $hybrid_name )['writeMode'] === 'markup', 'Unknown callback without sourced attributes still needs markup' );

    if ( function_exists( 'acf_register_block_type' ) ) {
        foreach ( [ 1, 2, 3 ] as $version ) {
            $short = 'fabrement-test-acf-v' . $version;
            $acf_name = 'acf/' . $short;
            if ( AcfBlockData::is_block( $acf_name ) ) { throw new RuntimeException( 'ACF fixture collision.' ); }
            acf_register_block_type( [ 'name' => $short, 'title' => 'Test ACF', 'acf_block_version' => $version, 'render_callback' => static function () {
                $group = get_field( 'details' );
                echo esc_html( (string) ( $group['heading'] ?? 'missing' ) );
                foreach ( (array) get_field( 'rows' ) as $row ) { echo esc_html( $row['label'] ?? '' ); }
            } ] );
            $registered[] = $acf_name;
            $group_key = 'group_' . $short;
            $groups[] = $group_key;
            acf_add_local_field_group( [ 'key' => $group_key, 'title' => 'Test', 'fields' => [
                [ 'key' => 'field_' . $short . '_details', 'name' => 'details', 'label' => 'Details', 'type' => 'group', 'sub_fields' => [
                    [ 'key' => 'field_' . $short . '_heading', 'name' => 'heading', 'label' => 'Heading', 'type' => 'text', 'required' => 1 ],
                ] ],
                [ 'key' => 'field_' . $short . '_rows', 'name' => 'rows', 'label' => 'Rows', 'type' => 'repeater', 'sub_fields' => [
                    [ 'key' => 'field_' . $short . '_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => 1 ],
                ] ],
                [ 'key' => 'field_' . $short . '_choice', 'name' => 'choice', 'label' => 'Choice', 'type' => 'select', 'choices' => [ 'a' => 'A', 'b' => 'B' ] ],
            ], 'location' => [ [ [ 'param' => 'block', 'operator' => '==', 'value' => $acf_name ] ] ] ] );
            $data = [ 'details_heading' => 'Group works', 'rows' => 2, 'rows_0_label' => 'Row one', 'rows_1_label' => 'Row two', 'choice' => 'a' ];
            $schema = RegisteredBlocks::describe( $acf_name );
            $assert( $schema['acf']['blockVersion'] === $version && $schema['acf']['writable'], 'ACF version ' . $version );
            $native_schema = ( new \Fabrement\Modules\Mcp\Ability\GetRegisteredBlockSchemaAbility() )->execute( [ 'name' => $acf_name ] );
            $assert( is_array( $native_schema ) && $native_schema['acf']['blockVersion'] === $version && $native_schema['acf']['writable'] && ! empty( $native_schema['acf']['fields'] ), 'Lite ACF schema v' . $version );
            $native_list = ( new \Fabrement\Modules\Mcp\Ability\ListRegisteredBlocksAbility() )->execute( [ 'source' => 'acf', 'search' => $short ] );
            $assert( array_column( $native_list['blocks'], 'name' ) === [ $acf_name ], 'Lite ACF discovery v' . $version );
            $entry = [ 'name' => $acf_name, 'attributes' => [ 'data' => $data ] ];
            $serialized = BlockEntry::serialize( $entry, 0 );
            $html = do_blocks( $serialized['markup'] );
            $assert( str_contains( $html, 'Group works' ) && str_contains( $html, 'Row two' ), 'ACF group and repeater render v' . $version );
            $normalized = BlockEntry::describe( parse_blocks( $serialized['markup'] )[0] );
            $legacy = $normalized;
            $legacy['attributes']['id'] = 'block_existing_123';
            $assert( str_contains( BlockEntry::serialize( $legacy, 0 )['markup'], 'block_existing_123' ), 'Legacy ACF id preserved' );
            $edited = ContentEdits::apply( [ $normalized ], [ [ 'op' => 'update', 'index' => 0, 'name' => $acf_name, 'attributes' => [ 'data' => [ 'details_heading' => 'Edited group' ] ] ] ] );
            $assert( $edited[0]['attributes']['data']['rows_1_label'] === 'Row two', 'Patch preserves siblings' );
            $smaller = ContentEdits::apply( $edited, [ [ 'op' => 'update', 'index' => 0, 'name' => $acf_name, 'attributes' => [ 'data' => [ 'rows' => 1 ] ] ] ] );
            $assert( ! isset( $smaller[0]['attributes']['data']['_rows_1_label'] ), 'Shrink prunes references' );
            // Gutenberg/ACF saves a cleared repeater as '', not necessarily integer zero.
            $empty_data = $data;
            $empty_data['rows'] = '';
            unset( $empty_data['rows_0_label'], $empty_data['rows_1_label'] );
            $empty_entry = [ 'name' => $acf_name, 'attributes' => [ 'data' => $empty_data ] ];
            $empty_saved = BlockEntry::serialize( $empty_entry, 0 );
            $assert( parse_blocks( $empty_saved['markup'] )[0]['attrs']['data']['rows'] === '', 'ACF empty-string repeater round trip' );
            $neighbors = [
                [ 'markup' => '<!-- wp:paragraph --><p>Keep core content</p><!-- /wp:paragraph -->' ],
                $empty_entry,
                $hybrid_entry,
                [ 'markup' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Keep nested content</p><!-- /wp:paragraph --></div><!-- /wp:group -->' ],
            ];
            $empty_edited = ContentEdits::apply( $neighbors, [ [ 'op' => 'update', 'index' => 1, 'name' => $acf_name, 'attributes' => [ 'data' => [ 'details_heading' => 'Edited with empty repeater' ] ] ] ] );
            $assert( $empty_edited[1]['attributes']['data']['rows'] === '' && $empty_edited[1]['attributes']['data']['details_heading'] === 'Edited with empty repeater', 'Patch succeeds after Gutenberg clears repeater' );
            foreach ( [ 0, 2, 3 ] as $neighbor ) {
                $assert( $empty_edited[ $neighbor ] === $neighbors[ $neighbor ], 'ACF patch preserves neighbor ' . $neighbor );
                $assert( BlockEntry::serialize( $empty_edited[ $neighbor ], $neighbor, 'blocks', true, false )['markup'] === $neighbors[ $neighbor ]['markup'], 'Neighbor serialization preserves HTML ' . $neighbor );
            }
            $cleared = ContentEdits::apply( [ $normalized ], [ [ 'op' => 'update', 'index' => 0, 'name' => $acf_name, 'attributes' => [ 'data' => [ 'rows' => '' ] ] ] ] );
            $assert( count( preg_grep( '/^_?rows_\d+_/', array_keys( $cleared[0]['attributes']['data'] ) ) ) === 0, 'Empty string clears all old repeater rows and references' );
            foreach ( [ -1, 501, false, 'bad', [] ] as $invalid_count ) {
                $refuses( static fn() => AcfBlockData::validate( $acf_name, array_replace( $empty_data, [ 'rows' => $invalid_count ] ), 'test' ), 'Invalid repeater count still refused' );
            }
            foreach ( [ [ 'rows_0_label' => '' ], [ 'details_heading' => '' ], [ 'choice' => 'invalid' ], [ 'unknown' => 1 ] ] as $invalid ) {
                $refuses( static fn() => AcfBlockData::validate( $acf_name, array_replace( $data, $invalid ), 'test' ), 'Nested/choice validation v' . $version );
            }
            $nested_markup = '<!-- wp:group --><div class="wp-block-group">' . $serialized['markup'] . '</div><!-- /wp:group -->';
            $assert( str_contains( BlockEntry::serialize( [ 'markup' => $nested_markup ], 0 )['markup'], $acf_name ), 'Nested ACF markup' );
            $validation = acf_get_instance( 'acf_validation' );
            $old_errors = $validation->errors;
            $validation->errors = [ [ 'input' => 'unrelated', 'message' => 'Keep this' ] ];
            try { AcfBlockData::validate( $acf_name, $data, 'test' ); $assert( $validation->errors[0]['message'] === 'Keep this', 'Validation state isolated' ); }
            finally { $validation->errors = $old_errors; }
        }
        acf_register_block_type( [ 'name' => 'fabrement-test-meta', 'title' => 'Test post meta', 'use_post_meta' => true, 'render_callback' => static fn() => '' ] );
        $registered[] = 'acf/fabrement-test-meta';
        $assert( ! RegisteredBlocks::describe( 'acf/fabrement-test-meta' )['acf']['writable'], 'Post meta limitation exposed' );
        $refuses( static fn() => BlockEntry::serialize( [ 'name' => 'acf/fabrement-test-meta' ], 0 ), 'Post meta refused' );
    } else { WP_CLI::warning( 'ACF not active: only native registry checks ran.' ); }
    WP_CLI::success( $passed . ' registered-block assertions passed; fixtures are request-local.' );
} finally {
    foreach ( $registered as $name ) {
        unregister_block_type( $name );
        if ( function_exists( 'acf_get_store' ) && AcfBlockData::is_block( $name ) ) { acf_get_store( 'block-types' )->remove( $name ); }
    }
    foreach ( $groups as $key ) { acf_remove_local_field_group( $key ); }
}
