<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\LocalSkillDocs;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\Translation\Translation;

/**
 * Read-only: one bundled instruction document's full text, by id.
 */
final class GetSkillDocAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/get-skill-doc';
    }

    public function label(): string {
        return 'Get skill doc';
    }

    public function description(): string {
        return 'Read one instruction document bundled with this plugin, by id. Every tool that has rules names its contract in a "Rules:" line - read '
            . 'that doc before the first call and follow its references when relevant to the task. '
            . 'Fetch several docs as parallel calls, one id each.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'id' ],
            'properties'           => [
                'id' => [
                    'type'        => 'string',
                    'pattern'     => '^[a-z0-9][a-z0-9-]*$',
                    'maxLength'   => 100,
                    'description' => 'Document id, e.g. "content-contract", "block-discovery-contract" or "fields-contract".',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'id', 'content' ],
            'properties' => [
                'id'      => [ 'type' => 'string' ],
                'content' => [ 'type' => 'string' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        if ( ! is_string( $input['id'] ?? null ) ) {
            return ToolResult::error( 'invalid_skill_id', 'Expected a document id string.', [ 'status' => 400 ] );
        }
        try {
            return LocalSkillDocs::read( strtolower( trim( $input['id'] ) ), Translation::is_multilingual() );
        } catch ( McpAbilityException $error ) {
            return $error->to_error();
        }
    }
}
