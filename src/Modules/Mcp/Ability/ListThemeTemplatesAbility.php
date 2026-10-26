<?php
namespace Fabrement\Modules\Mcp\Ability;

/** Existing theme files only. No source-code read/write or uploads. */
final class ListThemeTemplatesAbility extends AbstractAbility {
    public function name(): string { return 'fabrement/list-theme-templates'; }
    public function label(): string { return 'List theme PHP templates'; }
    public function description(): string { return 'List existing PHP files under the active/parent theme templates/ directory for Content Source rules. Returns relative paths and declared view kinds, never PHP source. Files are provided by the theme developer; Lite cannot create or edit them.'; }
    public function contract(): ?string { return 'template-contract'; }
    public function annotations(): array { return [ 'readOnlyHint' => true ]; }
    public function input_schema(): array { return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ]; }
    public function output_schema(): array { return [ 'type' => 'object', 'properties' => [ 'files' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ] ], 'required' => [ 'files' ] ]; }
    public function execute( array $input ) { return [ 'files' => \Fabrement\Modules\ContentSource\ThemeTemplateFiles::all() ]; }
}
