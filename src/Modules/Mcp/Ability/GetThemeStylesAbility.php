<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ThemeStylesView;

/** Read-only: how the active theme draws a page by default (its merged theme.json styles). */
final class GetThemeStylesAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/get-theme-styles';
    }

    public function label(): string {
        return 'Get theme styles';
    }

    public function description(): string {
        return 'How the active theme draws a page by default, from its theme.json and the site\'s Global Styles: '
            . 'page text and background, headings h1–h6, links, buttons, captions, and each block\'s own defaults. '
            . 'Presets read as "slug (value)". Block style variations are in get-block-source.';
    }

    public function contract(): ?string {
        return 'block-discovery-contract';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'theme', 'layout', 'page', 'elements', 'blocks' ],
            'properties' => [
                'theme'    => [ 'type' => 'string' ],
                'layout'   => [ 'type' => 'object' ],
                'page'     => [ 'type' => 'object' ],
                'elements' => [ 'type' => 'object' ],
                'blocks'   => [ 'type' => 'object' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $styles = \WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data()['styles'] ?? [];
        $view   = ThemeStylesView::build( is_array( $styles ) ? $styles : [], wp_get_global_settings() );
        $out    = [ 'theme' => (string) wp_get_theme()->get( 'Name' ) ] + $view;
        foreach ( [ 'layout', 'page', 'elements', 'blocks' ] as $key ) {
            $out[ $key ] = (object) $out[ $key ];
        }
        return $out;
    }
}
