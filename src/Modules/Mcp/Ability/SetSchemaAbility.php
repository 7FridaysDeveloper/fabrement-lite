<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ContentGuard;

use Fabrement\Modules\Seo\Schema\SchemaOutput;
use Fabrement\Modules\Seo\Schema\SchemaStore;
use Fabrement\Modules\Seo\Seo;
use WP_Error;

/**
 * Custom structured data — the nodes Yoast cannot know about: a FAQPage's questions, a Product,
 * an Event, a Course, a Book for every record of the `book` type.
 *
 * Two scopes: ONE record (pieces literal) or a POST TYPE (pieces are a template with {{tokens}}
 * resolved per record at render time). Added as a piece of the SEO plugin's graph; refused without
 * one — structured data is that plugin's output.
 */
final class SetSchemaAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/set-schema';
    }

    public function label(): string {
        return 'Set structured data (schema.org)';
    }

    public function description(): string {
        return 'Attach schema.org JSON-LD nodes to ONE record {contentId, graph} or to EVERY record of a post type {postType, graph}. '
            . 'The node type is not an argument: it goes INSIDE `graph`, a list of nodes without @context — '
            . 'graph: [{"@type": "Event", "name": "{{title}}", …}]. Pieces of a post-type graph are a template with {{url}}, {{site_url}}, {{title}}, {{excerpt}}, {{featured_image}}, '
            . '{{date_published}}, {{date_modified}} (Yoast\'s own values for the page), {{field:<name>}} (a Fabrement field as '
            . 'the page shows it: default applied, image → URL, date-time → ISO with offset; group.sub, .label, '
            . '.date, .title/.url/.fields.<name> of a picked record), {{option:<page>.<field>}}, {{meta:<key>}} (other '
            . 'plugins\' fields), {{term:<taxonomy>}} (a public one)… Unknown fields are refused; a protected meta key (leading "_") '
            . 'too; on a password-protected record excerpt, fields, meta and terms resolve empty. '
            . '`graph: []` removes them. The @id of a Yoast node (the page URL, #organization…) is refused: nodes '
            . 'are added to Yoast\'s graph, never swapped for its own. Refused when Yoast is not active. Returns the resolved preview and '
            . '`warnings` — read both, then verify on the live page source.';
    }

    public function contract(): ?string {
        return 'seo-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'graph' ],
            'properties'           => [
                'contentId' => [ 'type' => 'integer', 'minimum' => 1 ],
                'postType'  => [ 'type' => 'string' ],
                'graph'     => [
                    'type'     => 'array',
                    'maxItems' => SchemaStore::MAX_PIECES,
                    'items'    => [ 'type' => 'object', 'description' => 'One schema.org node: {"@type": "FAQPage", "mainEntity": [...]}.' ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'scope', 'pieces' ],
            'properties' => [
                'scope'     => [ 'type' => 'string', 'enum' => [ 'content', 'postType' ] ],
                'contentId' => [ 'type' => 'integer' ],
                'postType'  => [ 'type' => 'string' ],
                'pieces'    => [ 'type' => 'integer', 'description' => 'Nodes now stored for the scope (0 = removed).' ],
                'preview'   => [
                    'type'       => 'object',
                    'properties' => [
                        'contentId' => [ 'type' => 'integer' ],
                        'source'    => [ 'type' => 'string' ],
                        'graph'     => [ 'type' => 'array' ],
                    ],
                ],
                'note'      => [ 'type' => 'string' ],
                'warnings'  => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'string' ],
                    'description' => 'Saved, but thinner than it looks: a property Google needs is missing, a token resolved EMPTY on the preview record (so it was dropped there), or a raw {{meta:fab_…}} where {{field:…}} fits. Absent when there is nothing to say.',
                ],
            ],
        ];
    }

    /**
     * The stored pieces against what they resolved to on the preview record: a templated
     * property that came out empty was dropped there — the silent loss of a guessed field.
     *
     * @param list<array<string,mixed>> $graph    What was just stored for the scope.
     * @param int                       $offset   Where those pieces start in the record's resolved
     *                                            list (after the type template, for a record's own).
     * @return list<string>
     */
    private static function empty_on_preview( array $graph, int $record, int $offset, bool $with_type ): array {
        if ( $record <= 0 || $graph === [] ) {
            return [];
        }
        $resolved = SchemaStore::resolve_for_post( $record, $with_type );
        $out      = [];
        foreach ( array_values( $graph ) as $i => $piece ) {
            $node = $resolved[ $offset + $i ] ?? [];
            foreach ( $piece as $key => $value ) {
                if ( strpos( (string) $key, '@' ) === 0 || isset( $node[ $key ] ) ) {
                    continue;
                }
                if ( strpos( (string) wp_json_encode( $value ), '{{' ) !== false ) {
                    $out[] = sprintf( 'graph[%d].%s resolved empty on record %d and was dropped there — the field is unset on that record, or the token names the wrong thing.', $i, $key, $record );
                }
            }
        }
        return $out;
    }

    /** @param list<string> $warnings */
    private static function with_warnings( array $out, array $warnings ): array {
        if ( $warnings !== [] ) {
            $out['warnings'] = array_values( array_unique( $warnings ) );
        }
        return $out;
    }

    public function annotations(): array {
        return [ 'destructiveHint' => true ];
    }

    public function execute( array $input ) {
        if ( ! Seo::bridge()->is_active() ) {
            return new WP_Error( 'yoast_inactive', 'Structured data is printed by the SEO plugin, and Yoast SEO is not active — nothing would reach the page. Ask the site admin to install and activate it (get-seo shows the state).' );
        }
        $has_content = ! empty( $input['contentId'] );
        $has_type    = ! empty( $input['postType'] );
        if ( $has_content === $has_type ) {
            return new WP_Error( 'one_scope', 'Give exactly one of contentId or postType.' );
        }
        $graph = is_array( $input['graph'] ?? null ) ? array_values( $input['graph'] ) : [];

        if ( $has_content ) {
            $id   = (int) $input['contentId'];
            $post = get_post( $id );
            if ( ! $post instanceof \WP_Post || in_array( $post->post_type, [ 'revision', 'attachment', 'nav_menu_item' ], true ) ) {
                return ContentGuard::not_found( $id );
            }
            if ( ! current_user_can( 'edit_post', $id ) ) {
                return new WP_Error( 'forbidden', sprintf( 'You may not edit content %d.', $id ) );
            }
            $r = SchemaStore::write_post( $id, $graph );
            if ( $r instanceof WP_Error ) {
                return $r;
            }
            $out = [
                'scope'     => 'content',
                'contentId' => $id,
                'pieces'    => count( SchemaStore::read_post( $id ) ),
                'preview'   => array_merge( [ 'contentId' => $id ], SchemaOutput::preview( $id ) ),
            ];
            if ( $post->post_status !== 'publish' ) {
                $out['note'] = 'Record is not published — nothing is emitted to visitors until it is.';
            }
            // The record's own pieces come after its type's template — unless it is the posts
            // page, which never takes the template (SchemaOutput::with_type).
            $with_type = SchemaOutput::with_type( $id );
            $offset    = $with_type ? count( SchemaStore::read_type( (string) $post->post_type ) ) : 0;
            return self::with_warnings(
                $out,
                array_merge( SchemaStore::warnings( $graph ), self::empty_on_preview( $graph, $id, $offset, $with_type ) )
            );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'A post-type-wide schema needs the manage_options capability.' );
        }
        $slug   = (string) $input['postType'];
        $object = get_post_type_object( $slug );
        if ( ! $object ) {
            return new WP_Error( 'unknown_post_type', sprintf( 'Post type "%s" is not registered — see list-post-types.', $slug ) );
        }
        if ( ! $object->public ) {
            return new WP_Error( 'not_public', sprintf( 'Post type "%s" has no public single view, so schema on it is never emitted.', $slug ) );
        }
        $r = SchemaStore::write_type( $slug, $graph );
        if ( $r instanceof WP_Error ) {
            return $r;
        }
        $out = [
            'scope'    => 'postType',
            'postType' => $slug,
            'pieces'   => count( SchemaStore::read_type( $slug ) ),
        ];
        // A published record first; else any draft — the tokens resolve the same either way, and
        // an agent that just created three drafts wants to see its template filled in NOW.
        $sample = get_posts( [ 'post_type' => $slug, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ] );
        $from_draft = false;
        if ( $sample === [] ) {
            $sample     = get_posts( [ 'post_type' => $slug, 'post_status' => [ 'draft', 'pending', 'private', 'future' ], 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ] );
            $from_draft = $sample !== [];
        }
        if ( $sample !== [] ) {
            $out['preview'] = array_merge( [ 'contentId' => (int) $sample[0] ], SchemaOutput::preview( (int) $sample[0] ) );
            if ( $from_draft ) {
                $out['note'] = sprintf( 'Preview built from an unpublished "%s" record — visitors see the graph once a record is published.', $slug );
            }
        } else {
            $out['note'] = sprintf( 'No "%s" record yet, so there is nothing to preview — the template applies as records appear.', $slug );
        }
        return self::with_warnings(
            $out,
            array_merge( SchemaStore::warnings( $graph ), self::empty_on_preview( $graph, (int) ( $sample[0] ?? 0 ), 0, true ) )
        );
    }

}
