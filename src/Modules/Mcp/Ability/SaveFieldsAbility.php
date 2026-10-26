<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Schema\SchemaService;

final class SaveFieldsAbility extends FieldsAbility {

    public function name(): string {
        return 'fabrement/save-fields';
    }

    public function label(): string {
        return 'Save fields';
    }

    public function description(): string {
        return 'Define the custom fields of a post type (fields, the form layout, or both; a section you leave out '
            . 'stays as it is). Editors then fill the values in the "Fabrement fields" box of the block editor (saved with Update); '
            . 'set-field-values sets them too. Site-wide settings are options pages: save-options-page. '
            . 'Read get-fields first and send its revision as expectedRevision. "fields" '
            . 'is the complete active list: start from get-fields, change it, send it all (without its "retired": true fields). Everything is checked '
            . 'before writing: on any error nothing is saved and the error starts with the path of the input to fix '
            . '(e.g. "fields[2].sub_fields[0].name: ..."). Returns the stored fields.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'type', 'slug', 'expectedRevision' ],
            'properties'           => self::owner_properties() + [
                'expectedRevision' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'revision from get-fields; 0 for a post type without fields.' ],
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
        return SchemaService::save( 'post', (string) ( $input['slug'] ?? '' ), $input );
    }
}
