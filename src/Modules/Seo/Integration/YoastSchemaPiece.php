<?php

namespace Fabrement\Modules\Seo\Integration;

use Fabrement\Modules\Seo\Schema\SchemaOutput;
use Fabrement\Modules\Seo\Seo;
use Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece;

/**
 * The agent's schema.org nodes ({@see \Fabrement\Modules\Seo\Schema\SchemaStore}) as one piece of Yoast's graph — the way Yoast's
 * Schema API asks integrations to add to it (`wpseo_schema_graph_pieces`), as WP Recipe Maker and
 * The Events Calendar do.
 *
 * Yoast hands the piece its context, so the record is the one Yoast describes — the viewed record,
 * the posts page on the blog listing, or the record get-seo asked about — and the nodes go through Yoast's own `wpseo_schema_fabrement` filter; a site turns
 * them off with `wpseo_schema_needs_fabrement`.
 *
 * Nodes are ADDED, never swapped for Yoast's: one whose @id is a Yoast node's (the page URL — the
 * WebPage —, #breadcrumb, #organization…) is left out, and an object inside a node that names one is
 * cut down to the reference. set-schema refuses such an @id; this keeps
 * a graph stored before that out as well. Yoast builds the WebPage id from the permalink and the
 * #breadcrumb / #primaryimage / #article ones from the canonical, so both are checked.
 *
 * Being a piece, each node also passes Yoast's per-type filters (`wpseo_schema_<type>`), as its own
 * do — on a password-protected record Yoast's own `wpseo_schema_webpage` trims a node typed
 * WebPage.
 *
 * Loaded only inside Yoast's filter, where its parent class exists.
 */
final class YoastSchemaPiece extends Abstract_Schema_Piece {

    /** @var string */
    public $identifier = 'fabrement';

    /** @var list<array<string,mixed>>|null */
    private ?array $nodes = null;

    public function is_needed() {
        return $this->nodes() !== [];
    }

    /** @return list<array<string,mixed>> */
    public function generate() {
        return $this->nodes();
    }

    /** @return list<array<string,mixed>> */
    private function nodes(): array {
        if ( $this->nodes !== null ) {
            return $this->nodes;
        }
        $indexable = $this->context->indexable ?? null;
        if ( ! is_object( $indexable ) || ( $indexable->object_type ?? '' ) !== 'post' ) {
            return $this->nodes = [];
        }
        $seo       = Seo::bridge();
        $page      = (string) ( $this->context->main_schema_id ?? '' );
        $canonical = (string) ( $this->context->canonical ?? '' );
        $site      = (string) ( $this->context->site_url ?? '' );
        $id        = (int) $indexable->object_id;

        $yoasts = static fn( string $at ): bool => $seo->is_own_schema_id( $at, $page, $site ) || $seo->is_own_schema_id( $at, $canonical, $site );

        return $this->nodes = array_values(
            array_map(
                static fn( array $node ): array => self::references_only( $node, $yoasts, true ),
                array_filter(
                    SchemaOutput::nodes_for( $id, $seo->schema_values( $id, $this->context ) ),
                    static fn( array $node ): bool => ! $yoasts( (string) ( $node['@id'] ?? '' ) )
                )
            )
        );
    }

    /**
     * Inside a node, an object with a Yoast node's @id is cut down to that id: set-schema refuses
     * anything more, and a graph stored before it did must not add to Yoast's node either.
     *
     * @param array<mixed>           $node
     * @param callable(string): bool $yoasts
     * @return array<mixed>
     */
    private static function references_only( array $node, callable $yoasts, bool $top = false ): array {
        if ( ! $top && isset( $node['@id'] ) && is_string( $node['@id'] ) && $yoasts( $node['@id'] ) ) {
            return [ '@id' => $node['@id'] ];
        }
        foreach ( $node as $key => $value ) {
            if ( is_array( $value ) ) {
                $node[ $key ] = self::references_only( $value, $yoasts );
            }
        }
        return $node;
    }
}
