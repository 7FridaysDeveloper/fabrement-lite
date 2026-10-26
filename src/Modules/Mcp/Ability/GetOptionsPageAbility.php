<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Schema\SchemaService;

final class GetOptionsPageAbility extends FieldsAbility {

    public function contract(): ?string {
        return 'options-contract';
    }

    public function name(): string {
        return 'fabrement/get-options-page';
    }

    public function label(): string {
        return 'Get options page';
    }

    public function description(): string {
        return 'One options page (wp-admin → "Site General Settings"): its page settings (title, menu label, description, position, '
            . 'autoload), its fields with their names, its tabs (layout) and its revision. revision 0 = no such page yet. Call it '
            . 'before save-options-page (you need the revision and the existing fields) and before theme code reads the page. '
            . 'Its values: get-field-values {options: slug}. Theme PHP: fabrement()->options(\'<slug>\')->get_text(\'<name>\'), get_date(\'<name>\')… — the reader of its type. '
            . '"problem" = the page\'s file is broken: nothing can be saved until the user fixes or removes uploads/fabrement-lite/fields/<file>; '
            . 'tell them, do not recreate the fields.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'slug' ],
            'properties'           => [ 'slug' => self::options_slug_property() ],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        return SchemaService::get( 'options', (string) ( $input['slug'] ?? '' ) );
    }
}
