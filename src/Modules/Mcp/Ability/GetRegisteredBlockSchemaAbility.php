<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\Mcp\Support\RegisteredBlocks;

/** The schema of one block registered on this site; the tool keeps its get-block-source name. */
final class GetRegisteredBlockSchemaAbility extends AbstractAbility {
    public function name(): string { return 'fabrement/get-block-source'; }
    public function label(): string { return 'Get installed block schema'; }
    public function contract(): ?string { return 'block-discovery-contract'; }
    public function description(): string {
        return 'Read a server-registered block by exact name: WordPress attributes, supports, writeMode, themeSettings (the theme.json presets and switches for the features this block supports, its styles and variations) and ACF fields/limitations when applicable. It returns the schema, not PHP/JS source files. Follow writeMode; a render callback alone does not prove an attribute-only save contract.';
    }
    public function annotations(): array { return [ 'readOnlyHint' => true ]; }
    public function input_schema(): array {
        return [ 'type' => 'object', 'required' => [ 'name' ], 'additionalProperties' => false,
            'properties' => [ 'name' => [ 'type' => 'string', 'description' => 'Exact registered name from list-blocks, e.g. core/paragraph or acf/hero.' ] ] ];
    }
    public function output_schema(): array {
        return [ 'type' => 'object', 'required' => [ 'name', 'attributes', 'supports', 'writeMode' ],
            'properties' => [ 'name' => [ 'type' => 'string' ], 'attributes' => [ 'type' => 'object' ],
                'supports' => [ 'type' => 'object' ], 'writeMode' => [ 'type' => 'string', 'enum' => [ 'attributes', 'markup', 'unsupported' ] ],
                'themeSettings' => [ 'type' => 'object', 'description' => 'Only the features this block supports: color, typography, spacing, dimensions, border, shadow, layout, align, styles, variations. Presets are {slug, name, value}; use the slug.' ],
                'guide' => [ 'type' => 'string', 'description' => 'Notes for this block: markup the editor accepts, how it behaves, pitfalls. Read it before writing the block.' ],
                'acf' => [ 'type' => 'object' ] ] ];
    }
    public function execute( array $input ) {
        if ( ! is_string( $input['name'] ?? null ) || count( $input ) !== 1 ) {
            return new \WP_Error( 'invalid_block', 'Pass only the registered block name.' );
        }
        try { return RegisteredBlocks::describe( $input['name'] ); }
        catch ( McpAbilityException $error ) { return $error->to_error(); }
    }
}
