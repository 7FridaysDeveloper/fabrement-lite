<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Schema\SchemaService;

final class ListOptionsPagesAbility extends FieldsAbility {

    public function contract(): ?string {
        return 'options-contract';
    }

    public function name(): string {
        return 'fabrement/list-options-pages';
    }

    public function label(): string {
        return 'List options pages';
    }

    public function description(): string {
        return 'The options pages of the site (wp-admin → "Site General Settings"): site-wide settings such as a phone, an address, '
            . 'social links or footer text, kept once for the whole site' . self::multilingual_text( ' (one set per language)' ) . '. Each: slug, title, menuTitle, '
            . 'description, position, autoload, revision and how many active fields it has. Look here before creating one: a setting '
            . 'that fits an existing page belongs there. Read or change a page\'s fields and tabs with get-options-page / save-options-page '
            . '{slug}; its values with get-field-values / set-field-values {options: slug}; templates read them '
            . 'with fabrement()->options(\'<slug>\')->get_text(\'<name>\'), get_date(\'<name>\')… — the reader of its type.';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        return [ 'pages' => SchemaService::options_pages() ];
    }
}
