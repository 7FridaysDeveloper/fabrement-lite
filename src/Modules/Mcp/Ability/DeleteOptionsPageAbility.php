<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Schema\SchemaService;

/** Destructive: the destructive capability, and clients ask before calling it (DestructiveAbility). */
final class DeleteOptionsPageAbility extends DestructiveAbility {

    public function contract(): ?string {
        return 'options-contract';
    }

    public function name(): string {
        return 'fabrement/delete-options-page';
    }

    public function label(): string {
        return 'Delete options page';
    }

    public function description(): string {
        return 'DESTRUCTIVE, cannot be undone: remove an options page AND every value stored on it, in every language. '
            . 'Its menu entry, fields and tabs are gone; a page created again under the same slug starts empty. '
            . 'Theme templates that still read it get nothing — check what reads it first (fabrement()->options(\'<slug>\') in theme PHP). '
            . 'To drop only some fields, save-options-page without them instead (their values are kept). '
            . 'Ask the user and name the page before calling this.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'slug', 'expectedRevision' ],
            'properties'           => [
                'slug'             => [ 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,31}$', 'description' => 'The options page slug (list-options-pages); a-z, 0-9, _ and -, no "__".' ],
                'expectedRevision' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The page\'s revision from get-options-page or list-options-pages.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute( array $input ) {
        return SchemaService::delete_options_page( (string) ( $input['slug'] ?? '' ), (int) ( $input['expectedRevision'] ?? 0 ) );
    }
}
