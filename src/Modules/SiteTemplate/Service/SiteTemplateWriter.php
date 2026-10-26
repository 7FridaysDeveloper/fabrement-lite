<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;

/** Write the block frame and shared JSON source choices; never generate PHP. */
final class SiteTemplateWriter {
    public static function save( array $input ) {
        $id = (int) ( $input['id'] ?? 0 );
        $post = $id > 0 ? get_post( $id ) : null;
        if ( $id > 0 && ( ! $post || $post->post_type !== SiteTemplatePostType::POST_TYPE ) ) {
            return new \WP_Error( 'fabrement_st_not_found', 'Template not found.' );
        }
        $refusal = $id > 0 ? SiteTemplateTranslator::refusal_for_version( $id ) : null;
        if ( $refusal !== null ) { return $refusal; }
        $title = trim( (string) ( $input['title'] ?? '' ) );
        if ( $title === '' ) { $title = $post ? $post->post_title : ''; }
        if ( $title === '' ) { return new \WP_Error( 'fabrement_st_title', 'Template name is required.' ); }
        $content = $input['content'] ?? ( $post ? $post->post_content : '' );
        try {
            $source = ContentSourceConfig::validate( $input['contentSource'] ?? ContentSourceConfig::read( $id ), $content, $id );
            self::assert_slot( $content );
            $content = ContentSourceConfig::strip_block_rules( $content );
        } catch ( McpAbilityException $e ) { return $e->to_error(); }
        $data = [ 'post_type' => SiteTemplatePostType::POST_TYPE, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content ];
        if ( $id > 0 ) { $data['ID'] = $id; }
        $result = wp_insert_post( wp_slash( $data ), true );
        if ( is_wp_error( $result ) ) { return $result; }
        $created = $id === 0;
        $id = (int) $result;
        $saved_source = ContentSourceConfig::write( $id, $source );
        if ( is_wp_error( $saved_source ) ) {
            if ( $created ) {
                wp_delete_post( $id, true );
                return new \WP_Error( 'fabrement_st_manifest_write', 'The shared source rules could not be saved; the new template was removed. Check the templates storage directory and retry.', [ 'status' => 500 ] );
            }
            return $saved_source;
        }
        if ( isset( $input['contentWidth'] ) ) {
            update_post_meta( $id, Keys::META_TEMPLATE_CONTENT_WIDTH, SiteTemplatePostType::sanitize_content_width( $input['contentWidth'] ) );
        }
        SiteTemplateRegistry::flush();
        return SiteTemplateRegistry::find( $id )->to_summary( SiteTemplatePostType::type_defaults() )
            + [ 'editUrl' => get_edit_post_link( $id, 'raw' ) ];
    }

    /** A singular frame has one native page-content slot, including within groups. */
    public static function assert_slot( string $content ): void {
        $count = 0;
        $walk = static function ( array $blocks ) use ( &$walk, &$count ): void {
            foreach ( $blocks as $block ) {
                if ( in_array( $block['blockName'] ?? '', [ 'fabrement/content-source', 'core/post-content' ], true ) ) { ++$count; }
                if ( ! empty( $block['innerBlocks'] ) ) { $walk( $block['innerBlocks'] ); }
            }
        };
        $walk( parse_blocks( $content ) );
        if ( $count !== 1 ) { throw new McpAbilityException( 'A template needs exactly one Content Source (or core/post-content) block; found ' . esc_html( $count ) . '.' ); }
    }

    /** Native Gutenberg writes use the same checks, without changing untouched blocks. */
    public static function validate_rest( $prepared, $request ) {
        if ( is_wp_error( $prepared ) ) { return $prepared; }
        $id = (int) $request['id'];
        $refusal = $id > 0 ? SiteTemplateTranslator::refusal_for_version( $id ) : null;
        if ( $refusal !== null ) { return $refusal; }
        $old = $id > 0 ? BlockEntry::of_post( $id ) : [];
        try {
            if ( $request->has_param( 'contentSource' ) ) {
                ContentSourceConfig::validate( $request['contentSource'], $prepared->post_content ?? (string) get_post_field( 'post_content', $id ), $id );
            }
            if ( ! isset( $prepared->post_content ) ) { return $prepared; }
            self::assert_slot( $prepared->post_content );
            foreach ( parse_blocks( $prepared->post_content ) as $i => $block ) {
                if ( ( $block['blockName'] ?? null ) === null && trim( $block['innerHTML'] ?? '' ) === '' ) { continue; }
                $entry = BlockEntry::describe( $block );
                if ( in_array( $entry, $old, true ) ) { continue; }
                BlockEntry::serialize( $entry, $i, 'blocks', false, true, true );
            }
            $prepared->post_content = ContentSourceConfig::strip_block_rules( $prepared->post_content );
        } catch ( McpAbilityException $e ) { return $e->to_error(); }
        return $prepared;
    }

    /** Gutenberg edits this REST field separately from the translatable block frame. */
    public static function register_rest_fields(): void {
        register_rest_field( SiteTemplatePostType::POST_TYPE, 'contentWidth', [
            'get_callback'    => static fn( $object ) => SiteTemplatePostType::content_width( (int) $object['id'] ),
            'update_callback' => static function ( $value, $post ) {
                update_post_meta( (int) $post->ID, Keys::META_TEMPLATE_CONTENT_WIDTH, SiteTemplatePostType::sanitize_content_width( $value ) );
                SiteTemplateRegistry::flush();
                return true;
            },
            'schema'          => [ 'type' => 'string', 'enum' => [ 'full', 'constrained' ], 'context' => [ 'edit' ] ],
        ] );
        register_rest_field( SiteTemplatePostType::POST_TYPE, 'contentSource', [
            'get_callback' => static fn( $object ) => ContentSourceConfig::read( (int) $object['id'] ),
            'update_callback' => static function ( $value, $post ) {
                $result = ContentSourceConfig::write( (int) $post->ID, $value );
                return is_wp_error( $result ) ? $result : true;
            },
            'schema' => [ 'type' => 'object', 'context' => [ 'edit' ], 'additionalProperties' => false, 'required' => [ 'rules' ],
                'properties' => [ 'rules' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => false,
                    'required' => [ 'context', 'file' ], 'properties' => [ 'context' => [ 'type' => 'string' ], 'file' => [ 'type' => 'string' ] ],
                ] ] ],
            ],
        ] );
    }
}
