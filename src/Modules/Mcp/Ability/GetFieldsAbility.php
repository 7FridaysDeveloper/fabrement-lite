<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Schema\SchemaService;

final class GetFieldsAbility extends FieldsAbility {

    public function name(): string {
        return 'fabrement/get-fields';
    }

    public function label(): string {
        return 'Get fields';
    }

    public function description(): string {
        return 'The custom fields defined for a post type (Fabrement fields): every field with its name and meta key (fab_<name>), '
            . 'retired ones marked "retired": true, the form layout and the revision. Call it before save-fields '
            . '(you need its revision and the existing fields) and before a template reads fields. revision 0 = no '
            . 'fields yet. Templates read values with fabrement()->fields($id)->get_text(\'<name>\'), get_date(\'<name>\')… — the reader of its type (readSnippet in list-post-type-fields), the record id passed explicitly, never get_post_meta. '
            . 'Site-wide settings: get-options-page. "problem" = the field file is broken: nothing can be saved until the user fixes or removes '
            . 'uploads/fabrement-lite/fields/<file>; tell them, do not recreate the fields.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'type', 'slug' ],
            'properties'           => self::owner_properties(),
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        return SchemaService::get( 'post', (string) ( $input['slug'] ?? '' ) );
    }
}
