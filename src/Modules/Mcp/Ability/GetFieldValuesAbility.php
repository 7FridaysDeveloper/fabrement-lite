<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Values\FieldRead;

final class GetFieldValuesAbility extends FieldsAbility {

    public function name(): string {
        return 'fabrement/get-field-values';
    }

    public function label(): string {
        return 'Get field values';
    }

    public function description(): string {
        return 'The Fabrement field values of one record or of an options page' . self::multilingual_text( ' (in one language)' ) . ', keyed by field name, in the shape set-field-values '
            . 'accepts; each repeater row carries "_row", its identity, to send back unchanged. "stored" says whether THIS '
            . 'record' . self::multilingual_text( '/language' ) . ' holds its own saved value (false = no stored value; showing its default or empty value). '
            . self::multilingual_text( 'For options, versionExists says whether the requested language\'s values row exists, even if empty; reading never creates it or substitutes another language. ' )
            . self::multilingual_text( 'A pending record translation may preview its initial seed before those values have been stored. ' )
            . 'Empty values are null for every field type, including lists and groups with no populated sub-fields. '
            . 'A saved null is explicitly empty and never replaced by the default; numeric 0 and boolean false remain values.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => self::target_properties(),
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $object = self::target( $input );
        return is_wp_error( $object ) ? $object : FieldRead::public_values( $object );
    }
}
