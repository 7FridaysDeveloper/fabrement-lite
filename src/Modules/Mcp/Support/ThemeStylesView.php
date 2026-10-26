<?php

namespace Fabrement\Modules\Mcp\Support;

/**
 * How the theme draws a page by default, from the merged theme.json "styles" (theme + the user's
 * Global Styles): the page, the elements (headings, links, buttons…) and the blocks' own defaults.
 * Block style variations are left out (get-block-source lists them per block), and so is raw CSS.
 * Preset references read as "<slug> (<value>)". Pure: the caller passes the theme.json data.
 */
final class ThemeStylesView {

    private const PRESETS = [
        'color'       => [ 'color', 'palette', 'color' ],
        'gradient'    => [ 'color', 'gradients', 'gradient' ],
        'font-size'   => [ 'typography', 'fontSizes', 'size' ],
        'font-family' => [ 'typography', 'fontFamilies', 'fontFamily' ],
        'spacing'     => [ 'spacing', 'spacingSizes', 'size' ],
        'shadow'      => [ 'shadow', 'presets', 'shadow' ],
    ];

    /**
     * @param array $styles   theme.json "styles", merged.
     * @param array $settings Global settings (wp_get_global_settings()).
     */
    public static function build( array $styles, array $settings ): array {
        $lookup = self::lookup( $settings );
        $page   = array_diff_key( $styles, array_flip( [ 'elements', 'blocks', 'variations', 'css' ] ) );

        $blocks = [];
        foreach ( (array) ( $styles['blocks'] ?? [] ) as $name => $block ) {
            $own = self::clean( array_diff_key( (array) $block, array_flip( [ 'variations', 'css' ] ) ) );
            if ( $own !== [] ) {
                $blocks[ (string) $name ] = $own;
            }
        }
        ksort( $blocks );

        return self::resolve( [
            'layout'   => array_intersect_key( (array) ( $settings['layout'] ?? [] ), array_flip( [ 'contentSize', 'wideSize' ] ) ),
            'page'     => self::clean( $page ),
            'elements' => self::clean( (array) ( $styles['elements'] ?? [] ) ),
            'blocks'   => $blocks,
        ], $lookup );
    }

    /** Drops raw CSS at any depth and empty branches. */
    private static function clean( array $tree ): array {
        $out = [];
        foreach ( $tree as $key => $value ) {
            if ( $key === 'css' ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $value = self::clean( $value );
                if ( $value === [] ) {
                    continue;
                }
            }
            $out[ $key ] = $value;
        }
        return $out;
    }

    /** "type/slug" => value, from every origin in the order default, theme, custom; the later one wins. */
    private static function lookup( array $settings ): array {
        $map = [];
        foreach ( self::PRESETS as $type => [ $group, $key, $field ] ) {
            $presets = (array) ( $settings[ $group ][ $key ] ?? [] );
            // Keyed by origin (default, theme, custom), or one plain list.
            $lists = isset( $presets[0] ) ? [ $presets ] : array_values( array_intersect_key( array_replace( array_flip( [ 'default', 'theme', 'custom' ] ), $presets ), $presets ) );
            foreach ( $lists as $list ) {
                foreach ( (array) $list as $preset ) {
                    if ( is_array( $preset ) && isset( $preset['slug'] ) && is_scalar( $preset[ $field ] ?? null ) ) {
                        $map[ $type . '/' . $preset['slug'] ] = (string) $preset[ $field ];
                    }
                }
            }
        }
        return $map;
    }

    private static function resolve( $value, array $lookup ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[ $key ] = self::resolve( $item, $lookup );
            }
            return $value;
        }
        if ( ! is_string( $value ) ) {
            return $value;
        }
        $named = static function ( string $type, string $slug ) use ( $lookup ): string {
            $found = $lookup[ $type . '/' . $slug ] ?? null;
            return $found === null ? $slug : $slug . ' (' . $found . ')';
        };
        $value = (string) preg_replace_callback( '/var\(--wp--preset--([a-z-]+?)--([a-z0-9-]+)\)/', static fn( $m ) => $named( $m[1], $m[2] ), $value );
        return (string) preg_replace_callback( '/var:preset\|([a-z-]+)\|([a-z0-9-]+)/', static fn( $m ) => $named( $m[1], $m[2] ), $value );
    }
}
