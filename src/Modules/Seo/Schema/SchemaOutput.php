<?php

namespace Fabrement\Modules\Seo\Schema;

use Fabrement\Modules\Seo\Seo;

/**
 * The stored graph pieces ({@see SchemaStore}) as they go on a page: a piece of the SEO plugin's
 * graph (Yoast: {@see \Fabrement\Modules\Seo\Integration\YoastSchemaPiece}, added through its
 * Schema API). Without an SEO plugin nothing is printed.
 *
 * A record gets the full graph: its post type's template plus its own nodes. The posts page gets
 * ONLY its own nodes, never the page template: the template says what a page IS, and the blog
 * index is a list, not that page.
 */
final class SchemaOutput {

    /**
     * The nodes record `$post_id` emits. Not cached: a page asks once (the plugin builds its graph
     * once), and get-seo right after set-schema must see the new graph.
     *
     * @param array<string,string>|null $values The page tokens, when the caller has the plugin's page context.
     * @return list<array<string, mixed>>
     */
    public static function nodes_for( int $post_id, ?array $values = null ): array {
        return SchemaStore::resolve_for_post( $post_id, self::with_type( $post_id ), $values );
    }

    /** Whether the record takes its post type's template: not the posts page. */
    public static function with_type( int $post_id ): bool {
        return ! ( get_option( 'show_on_front' ) === 'page' && $post_id === (int) get_option( 'page_for_posts' ) );
    }

    /**
     * What a given post will emit — for get-seo and set-schema, so the agent checks the graph
     * without opening the page: the SEO plugin's graph for that record, ours inside it.
     *
     * @param array<string,mixed>|null $effective Already computed with schema, when the caller also needs SEO.
     * @return array{source: string, graph: list<array<string, mixed>>}
     */
    public static function preview( int $post_id, ?array $effective = null ): array {
        $seo = Seo::bridge();
        if ( ! $seo->is_active() ) {
            return [ 'source' => 'none', 'graph' => [] ];
        }
        $graph = ( $effective ?? $seo->effective_post( $post_id ) )['schema']['@graph'] ?? [];
        return [ 'source' => 'yoast+fabrement', 'graph' => is_array( $graph ) ? $graph : [] ];
    }
}
