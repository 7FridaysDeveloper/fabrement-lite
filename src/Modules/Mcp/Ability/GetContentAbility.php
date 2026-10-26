<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\Mcp\Support\ContentEdits;
use Fabrement\Modules\Mcp\Support\ContentGuard;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\Storage\TextEdits;

/**
 * One record in full — what list-content says about it PLUS its body as save-content takes it.
 *
 * save-content {contentId, mode:"replace", blocks} rewrites a page outright, and nothing
 * returned what the page held: an agent asked to change one word on an existing page could only
 * append, or replace blind. This is the read half of that pair, for any public type.
 */
final class GetContentAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/get-content';
    }

    public function label(): string {
        return 'Get content';
    }

    public function description(): string {
        return 'Read ONE page/post/custom record in full: everything list-content reports plus slug, template, excerpt, '
            . 'featuredImage and `blocks` — its body as save-content takes it ({name, attributes} or '
            . '{markup}), `outline` (block name per index) and `hash`. The step before editing a page: '
            . 'get-content → save-content {contentId, mode:"edit", edits, expectedHash: hash}.';
    }

    public function contract(): ?string {
        return 'content-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'contentId' ],
            'properties'           => [
                'contentId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Record id from list-content' . self::multilingual_text( ' — a language version by ITS own id' ) . '.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'contentId', 'title', 'postType', 'status', 'url', 'blocks' ],
            'properties' => [
                'contentId'     => [ 'type' => 'integer' ],
                'title'         => [ 'type' => 'string' ],
                'postType'      => [ 'type' => 'string' ],
                'status'        => [ 'type' => 'string' ],
                'url'           => [ 'type' => 'string' ],
                'editUrl'       => [ 'type' => 'string' ],
                'modified'      => [ 'type' => 'string' ],
                'terms'         => [ 'type' => 'object' ],
                'slug'          => [ 'type' => 'string', 'description' => 'URL slug (post_name). Empty on a draft that was given none.' ],
                'slugWhenPublished' => [ 'type' => 'string', 'description' => 'Only while `slug` is empty — a draft that was given none: the slug WordPress will make from its title when it is published. A forecast, not a fact: it follows the title, and another record may take it first. Send `slug` to fix the address.' ],
                'parent'        => [ 'type' => 'integer', 'description' => 'Parent record id; 0 = top level.' ],
                'menuOrder'     => [ 'type' => 'integer' ],
                'template'      => [ 'type' => 'string', 'description' => 'Site template slug in use, "" = the default for its type — as list-template-pages reports it; assign-template-pages changes it.' ],
                'excerpt'       => [ 'type' => 'string' ],
                'featuredImage' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer' ], 'url' => [ 'type' => 'string' ] ],
                ],
                'blocks'        => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'object' ],
                    'description' => 'In order, as save-content takes them back: {name, attributes} for a block written by its attributes (ACF, a global block), {markup} for a block saved as HTML.',
                ],
                'outline'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Block name at each index of `blocks` — the `index` + `name` of a save-content edit.' ],
                'hash'          => [ 'type' => 'string', 'description' => 'expectedHash for save-content mode "edit".' ],
            ] + self::multilingual( [
                'language'         => [ 'type' => 'string' ],
                'originalId'       => [ 'type' => 'integer' ],
                'translations'     => [ 'type' => 'object' ],
                'missingLanguages' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ] ),
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $id = (int) ( $input['contentId'] ?? 0 );
        // The listing already knows how to describe a record — its address in its own language,
        // its public terms, its language group. One row of it, rather than a second copy of that.
        $listed = ( new ListContentAbility() )->execute( [ 'id' => $id, 'postType' => 'any' ] );
        if ( is_wp_error( $listed ) ) {
            return $listed;
        }
        $row = $listed['items'][0] ?? null;
        if ( $row === null ) {
            return ( new McpAbilityException( ContentGuard::not_found_message( $id ) ) )->to_error();
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return ( new McpAbilityException( sprintf( 'Content %d is not yours to read for editing.', $id ) ) )->to_error();
        }

        $thumb = (int) get_post_thumbnail_id( $id );
        $out   = [ 'contentId' => $id ] + $row;
        unset( $out['id'] );
        $tpl                  = (string) get_post_meta( $id, '_wp_page_template', true );
        $out['slug']          = (string) get_post_field( 'post_name', $id );
        $out                 += SaveContentAbility::slug_when_published( $id );
        $out['template']      = $tpl === 'default' ? '' : $tpl;
        $out['excerpt']       = (string) get_post_field( 'post_excerpt', $id );
        $out['featuredImage'] = [
            'id'  => $thumb,
            'url' => $thumb > 0 ? (string) wp_get_attachment_image_url( $thumb, 'large' ) : '',
        ];
        $out['blocks'] = BlockEntry::of_post( $id );
        // What save-content {mode:"edit"} addresses: the block at each index, and the body's hash.
        $out['outline'] = ContentEdits::outline( $out['blocks'] );
        $out['hash']    = TextEdits::hash( $out['blocks'] );

        return $out;
    }
}
