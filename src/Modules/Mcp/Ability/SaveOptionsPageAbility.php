<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Schema\SchemaService;

final class SaveOptionsPageAbility extends FieldsAbility {

    public function contract(): ?string {
        return 'options-contract';
    }

    public function name(): string {
        return 'fabrement/save-options-page';
    }

    public function label(): string {
        return 'Save options page';
    }

    public function description(): string {
        return 'Create or change an options page — site-wide settings such as contacts, social links, a header announcement or '
            . 'the footer, shown in wp-admin under "Site General Settings": its page settings, its fields and its tabs (a section you leave out '
            . 'stays as it is). Read get-options-page first and send its revision as expectedRevision; 0 with a new slug creates the '
            . 'page and needs page.title. "fields" is the complete active list, as in save-fields. Values are filled on the page, '
            . 'or with set-field-values {options: slug}. Everything is checked before writing; an error names the input to fix.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'slug', 'expectedRevision' ],
            'properties'           => [
                'slug'             => self::options_slug_property(),
                'expectedRevision' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'revision from get-options-page; 0 creates a new page.' ],
                'page'             => self::page_property(),
                'fields'           => self::fields_property(),
                'layout'           => self::layout_property(),
            ] + self::multilingual( [
                'force'            => [ 'type' => 'boolean', 'description' => 'Only for a change that is refused as destructive and that the user agreed to: a field set to translation "copy" from another policy, which replaces what each language version holds for it. Never send it otherwise.' ],
            ] ),
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false ];
    }

    public function execute( array $input ) {
        return SchemaService::save( 'options', (string) ( $input['slug'] ?? '' ), $input );
    }
}
