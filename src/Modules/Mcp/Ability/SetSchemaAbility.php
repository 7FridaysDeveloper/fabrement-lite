<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ContentGuard;

use Fabrement\Modules\Seo\Schema\SchemaOutput;
use Fabrement\Modules\Seo\Schema\SchemaStore;
use Fabrement\Modules\Seo\Seo;
use WP_Error;

/**
 * Custom structured data — the nodes Yoast cannot know about: a FAQPage's questions, a Product,
 * an Event, a Course, a Book.
 *
 * ONE record's pieces. Added as a piece of the SEO plugin's graph; refused without one — structured
 * data is that plugin's output.
 */
final class SetSchemaAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/set-schema';
    }

    public function label(): string {
        return 'Set structured data (schema.org)';
    }

    public function description(): string {
        return 'Attach schema.org JSON-LD nodes to ONE record {contentId, graph}. '
            . 'The node type is not an argument: it goes INSIDE `graph`, a list of nodes without @context — '
            . 'graph: [{"@type": "Event", "name": "{{title}}", …}]. Strings may use {{url}}, {{site_url}}, {{title}}, {{excerpt}}, {{featured_image}}, '
            . '{{date_published}}, {{date_modified}} (Yoast\'s own values for the page), {{meta:<key>}} (a meta '
            . 'value), {{term:<taxonomy>}} (a public one)… Unknown tokens are refused; a protected meta key (leading "_") '
            . 'too; on a password-protected record excerpt, meta and terms resolve empty. '
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
            'required'             => [ 'contentId', 'graph' ],
            'properties'           => [
                'contentId' => [ 'type' => 'integer', 'minimum' => 1 ],
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
                'scope'     => [ 'type' => 'string', 'enum' => [ 'content' ] ],
                'contentId' => [ 'type' => 'integer' ],
                'pieces'    => [ 'type' => 'integer', 'description' => 'Nodes now stored for the record (0 = removed).' ],
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
                    'description' => 'Saved, but thinner than it looks: a property Google needs is missing, a token resolved EMPTY on the preview record (so it was dropped there). Absent when there is nothing to say.',
                ],
            ],
        ];
    }

    /**
     * The stored pieces against what they resolved to on the preview record: a templated
     * property that came out empty was dropped there — the silent loss of a guessed field.
     *
     * @param list<array<string,mixed>> $graph What was just stored for the record.
     * @return list<string>
     */
    private static function empty_on_preview( array $graph, int $record ): array {
        if ( $record <= 0 || $graph === [] ) {
            return [];
        }
        $resolved = SchemaStore::resolve_for_post( $record );
        $out      = [];
        foreach ( array_values( $graph ) as $i => $piece ) {
            $node = $resolved[ $i ] ?? [];
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
        if ( empty( $input['contentId'] ) ) {
            return new WP_Error( 'one_scope', 'Give contentId.' );
        }
        $graph = is_array( $input['graph'] ?? null ) ? array_values( $input['graph'] ) : [];

        $id   = (int) $input['contentId'];
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post || in_array( $post->post_type, [ 'revision', 'attachment', 'nav_menu_item' ], true ) ) {
            return ContentGuard::not_found( $id );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You may not edit content %d.', $id ) );
        }
        // Yoast's Schema tab, under the rule set-seo follows.
        if ( Seo::bridge()->not_allowed( [ 'schema' ] ) !== [] ) {
            return new WP_Error( 'forbidden', 'You may not set structured data: Yoast lets only users with wpseo_edit_advanced_metadata change it.' );
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
        return self::with_warnings(
            $out,
            array_merge( SchemaStore::warnings( $graph ), self::empty_on_preview( $graph, $id ) )
        );
    }

}
