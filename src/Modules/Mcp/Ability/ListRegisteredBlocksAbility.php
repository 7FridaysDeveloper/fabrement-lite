<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\RegisteredBlocks;

/** Lite discovery has no cloud catalog or generated-block records. */
final class ListRegisteredBlocksAbility extends AbstractAbility {
    public function name(): string { return 'fabrement/list-blocks'; }
    public function label(): string { return 'List installed blocks'; }
    public function contract(): ?string { return 'block-discovery-contract'; }
    public function description(): string {
        return 'Search server-registered Gutenberg blocks on this site. source: all (default), core, acf, registered (other providers). Read get-block-source {name} for the schema and writeMode. Paged.';
    }
    public function annotations(): array { return [ 'readOnlyHint' => true ]; }
    public function input_schema(): array {
        return [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [
            'source' => [ 'type' => 'string', 'enum' => [ 'all', 'core', 'acf', 'registered' ] ],
            'search' => [ 'type' => 'string', 'description' => 'Substring in name, title or description.' ],
            'page' => [ 'type' => 'integer', 'minimum' => 1 ],
            'limit' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Default 10; capped at 25.' ],
        ] ];
    }
    public function output_schema(): array {
        return [ 'type' => 'object', 'required' => [ 'blocks', 'total', 'page', 'limit', 'pages' ], 'properties' => [
            'blocks' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
            'total' => [ 'type' => 'integer' ], 'page' => [ 'type' => 'integer' ],
            'limit' => [ 'type' => 'integer' ], 'pages' => [ 'type' => 'integer' ],
        ] ];
    }
    public function execute( array $input ) {
        $source = $input['source'] ?? 'all';
        if ( ! in_array( $source, [ 'all', 'core', 'acf', 'registered' ], true ) ) {
            return new \WP_Error( 'invalid_source', 'Use all, core, acf or registered.' );
        }
        $search = trim( (string) ( $input['search'] ?? '' ) );
        $rows = array_values( array_filter( RegisteredBlocks::summaries(), static function ( array $row ) use ( $source, $search ): bool {
            return ( $source === 'all' || $row['source'] === $source )
                && ( $search === '' || stripos( $row['name'] . ' ' . $row['title'] . ' ' . $row['description'], $search ) !== false );
        } ) );
        $page = max( 1, (int) ( $input['page'] ?? 1 ) );
        $limit = max( 1, min( 25, (int) ( $input['limit'] ?? 10 ) ) );
        return [ 'blocks' => array_slice( $rows, ( $page - 1 ) * $limit, $limit ),
            'total' => count( $rows ), 'page' => $page, 'limit' => $limit,
            'pages' => (int) ceil( count( $rows ) / $limit ) ];
    }
}
