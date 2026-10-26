<?php

namespace Fabrement\Modules\Mcp\Support;

/**
 * What the site offers for ONE block: the theme.json presets and switches behind each feature the
 * block supports, its registered styles and its variations. A feature the block does not support
 * is left out, so the answer names only what can be set on it.
 */
final class BlockThemeSettings {

    /** Typography controls: the supports key (stable, experimental) and the theme.json switch. */
    private const TYPOGRAPHY = [
        'fontSize'       => [ [ 'fontSize' ], 'customFontSize' ],
        'lineHeight'     => [ [ 'lineHeight' ], 'lineHeight' ],
        'textAlign'      => [ [ 'textAlign' ], 'textAlign' ],
        'fontFamily'     => [ [ 'fontFamily', '__experimentalFontFamily' ], null ],
        'fontWeight'     => [ [ 'fontWeight', '__experimentalFontWeight' ], 'fontWeight' ],
        'fontStyle'      => [ [ 'fontStyle', '__experimentalFontStyle' ], 'fontStyle' ],
        'textTransform'  => [ [ 'textTransform', '__experimentalTextTransform' ], 'textTransform' ],
        'textDecoration' => [ [ 'textDecoration', '__experimentalTextDecoration' ], 'textDecoration' ],
        'letterSpacing'  => [ [ 'letterSpacing', '__experimentalLetterSpacing' ], 'letterSpacing' ],
    ];

    /** @return array<string,mixed> Empty when the block has nothing to set. */
    public static function for_block( \WP_Block_Type $type ): array {
        $supports = (array) $type->supports;
        // Site-wide settings, with what theme.json sets for this block alone laid over them.
        $settings = array_replace_recursive( wp_get_global_settings(), wp_get_global_settings( [], [ 'block_name' => $type->name ] ) );

        $out = array_filter( [
            'color'      => self::color( $supports, $settings ),
            'typography' => self::typography( $supports, $settings ),
            'spacing'    => self::spacing( $supports, $settings ),
            'dimensions' => self::dimensions( $supports, $settings ),
            'border'     => self::border( $supports, $settings ),
            'shadow'     => self::shadow( $supports, $settings ),
            'layout'     => self::layout( $supports, $settings ),
            'align'      => self::align( $supports ),
            'styles'     => self::styles( $type ),
            'variations' => self::variations( $type ),
        ] );
        return $out;
    }

    private static function color( array $supports, array $settings ): array {
        $color = $supports['color'] ?? null;
        if ( $color === null || $color === false ) {
            return [];
        }
        $color = is_array( $color ) ? $color : [];
        $set   = (array) ( $settings['color'] ?? [] );
        $out   = [
            // Text and background are on unless the block switches them off; the rest are opt-in.
            'text'       => ( $color['text'] ?? true ) !== false && ! empty( $set['text'] ),
            'background' => ( $color['background'] ?? true ) !== false && ! empty( $set['background'] ),
            'link'       => ! empty( $color['link'] ) && ! empty( $set['link'] ),
            'gradients'  => ! empty( $color['gradients'] ),
            'custom'     => ! empty( $set['custom'] ),
            'palette'    => self::presets( $set['palette'] ?? [], [ 'slug', 'name', 'color' ], ! empty( $set['defaultPalette'] ) ),
        ];
        if ( $out['gradients'] ) {
            $out['customGradient']  = ! empty( $set['customGradient'] );
            $out['gradientPresets'] = self::presets( $set['gradients'] ?? [], [ 'slug', 'name', 'gradient' ], ! empty( $set['defaultGradients'] ) );
        }
        return $out;
    }

    private static function typography( array $supports, array $settings ): array {
        $typography = $supports['typography'] ?? null;
        if ( ! is_array( $typography ) ) {
            return [];
        }
        $set = (array) ( $settings['typography'] ?? [] );
        $out = [];
        foreach ( self::TYPOGRAPHY as $control => [ $keys, $switch ] ) {
            $supported = false;
            foreach ( $keys as $key ) {
                $supported = $supported || ! empty( $typography[ $key ] );
            }
            if ( $supported ) {
                // fontSize is always offered through presets; customFontSize says whether any size may be typed.
                $out[ $control ] = $control === 'fontSize' || $switch === null || ! empty( $set[ $switch ] );
            }
        }
        if ( $out === [] ) {
            return [];
        }
        if ( ! empty( $out['fontSize'] ) ) {
            $out['customFontSize'] = ! empty( $set['customFontSize'] );
            $out['fontSizes']      = self::presets( $set['fontSizes'] ?? [], [ 'slug', 'name', 'size' ], ! empty( $set['defaultFontSizes'] ) );
        }
        if ( ! empty( $out['fontFamily'] ) ) {
            $out['fontFamilies'] = self::presets( $set['fontFamilies'] ?? [], [ 'slug', 'name', 'fontFamily' ], true );
        }
        return $out;
    }

    private static function spacing( array $supports, array $settings ): array {
        $spacing = $supports['spacing'] ?? null;
        if ( ! is_array( $spacing ) ) {
            return [];
        }
        $set = (array) ( $settings['spacing'] ?? [] );
        $out = [];
        foreach ( [ 'padding', 'margin', 'blockGap' ] as $side ) {
            if ( ! empty( $spacing[ $side ] ) && ! empty( $set[ $side ] ) ) {
                // true, or the list of sides / axes the block allows.
                $out[ $side ] = is_array( $spacing[ $side ] ) ? array_values( array_filter( $spacing[ $side ], 'is_string' ) ) ?: true : true;
            }
        }
        if ( $out === [] ) {
            return [];
        }
        $out['units']             = array_values( (array) ( $set['units'] ?? [] ) );
        $out['customSpacingSize'] = ! empty( $set['customSpacingSize'] );
        $out['spacingSizes']      = self::presets( $set['spacingSizes'] ?? [], [ 'slug', 'name', 'size' ], ! empty( $set['defaultSpacingSizes'] ) );
        return $out;
    }

    private static function dimensions( array $supports, array $settings ): array {
        $dimensions = $supports['dimensions'] ?? null;
        if ( ! is_array( $dimensions ) ) {
            return [];
        }
        $set = (array) ( $settings['dimensions'] ?? [] );
        $out = [];
        foreach ( [ 'minHeight', 'height', 'width', 'minWidth', 'aspectRatio' ] as $control ) {
            if ( ! empty( $dimensions[ $control ] ) && ! empty( $set[ $control ] ) ) {
                $out[ $control ] = true;
            }
        }
        if ( ! empty( $out['aspectRatio'] ) ) {
            $out['aspectRatios'] = self::presets( $set['aspectRatios'] ?? [], [ 'slug', 'name', 'ratio' ], ! empty( $set['defaultAspectRatios'] ) );
        }
        if ( ! empty( $out['width'] ) || ! empty( $out['height'] ) ) {
            $sizes = self::presets( $set['dimensionSizes'] ?? [], [ 'slug', 'name', 'size' ], true );
            if ( $sizes !== [] ) {
                $out['sizes'] = $sizes;
            }
        }
        return $out;
    }

    private static function border( array $supports, array $settings ): array {
        $border = $supports['border'] ?? $supports['__experimentalBorder'] ?? null;
        if ( ! is_array( $border ) ) {
            return [];
        }
        $set = (array) ( $settings['border'] ?? [] );
        $out = [];
        foreach ( [ 'color', 'radius', 'style', 'width' ] as $control ) {
            if ( ! empty( $border[ $control ] ) && ! empty( $set[ $control ] ) ) {
                $out[ $control ] = true;
            }
        }
        return $out;
    }

    private static function shadow( array $supports, array $settings ): array {
        if ( empty( $supports['shadow'] ) ) {
            return [];
        }
        $set = (array) ( $settings['shadow'] ?? [] );
        return [ 'presets' => self::presets( $set['presets'] ?? [], [ 'slug', 'name', 'shadow' ], ! empty( $set['defaultPresets'] ) ) ];
    }

    private static function layout( array $supports, array $settings ): array {
        $layout = $supports['layout'] ?? $supports['__experimentalLayout'] ?? null;
        if ( $layout === null || $layout === false ) {
            return [];
        }
        $set = (array) ( $settings['layout'] ?? [] );
        $out = array_filter( [
            'contentSize' => (string) ( $set['contentSize'] ?? '' ),
            'wideSize'    => (string) ( $set['wideSize'] ?? '' ),
        ] );
        if ( is_array( $layout ) && isset( $layout['default']['type'] ) ) {
            $out['defaultType'] = (string) $layout['default']['type'];
        }
        return $out;
    }

    /** @return list<string> */
    private static function align( array $supports ): array {
        $align = $supports['align'] ?? false;
        if ( $align === false ) {
            return [];
        }
        $all = is_array( $align ) ? array_values( array_filter( $align, 'is_string' ) ) : [ 'left', 'center', 'right', 'wide', 'full' ];
        // alignWide:false takes the two wide alignments away from a block that allows "all".
        return ( $supports['alignWide'] ?? true ) === false ? array_values( array_diff( $all, [ 'wide', 'full' ] ) ) : $all;
    }

    /**
     * The block's style variants: what to put in className, and what the theme draws for each.
     *
     * @return list<array{name:string,label:string,className:string,isDefault?:bool,sets?:array<string,mixed>}>
     */
    private static function styles( \WP_Block_Type $type ): array {
        $styles = [];
        foreach ( (array) ( $type->styles ?? [] ) as $style ) {
            if ( is_array( $style ) && isset( $style['name'] ) ) {
                $styles[ (string) $style['name'] ] = $style;
            }
        }
        foreach ( \WP_Block_Styles_Registry::get_instance()->get_registered_styles_for_block( $type->name ) as $name => $style ) {
            $styles[ (string) $name ] = (array) $style;
        }
        if ( $styles === [] ) {
            return [];
        }
        // What theme.json draws for a variant of this block; a variant drawn by a stylesheet has none.
        $drawn = \WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data()['styles']['blocks'][ $type->name ]['variations'] ?? [];
        $out   = [];
        foreach ( $styles as $name => $style ) {
            $row = [ 'name' => $name, 'label' => (string) ( $style['label'] ?? $name ), 'className' => 'is-style-' . $name ];
            if ( ! empty( $style['isDefault'] ) || ! empty( $style['is_default'] ) ) {
                $row['isDefault'] = true;
            }
            // The variant's own look only: what it does to blocks nested in it is left out.
            $sets = array_diff_key( (array) ( $drawn[ $name ] ?? [] ), array_flip( [ 'blocks', 'elements', 'css', 'variations' ] ) );
            if ( $sets !== [] ) {
                $row['sets'] = $sets;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array{name:string,title:string,attributes:object}> */
    private static function variations( \WP_Block_Type $type ): array {
        $out = [];
        foreach ( $type->get_variations() as $variation ) {
            if ( is_array( $variation ) && isset( $variation['name'] ) ) {
                $out[] = [
                    'name'       => (string) $variation['name'],
                    'title'      => (string) ( $variation['title'] ?? $variation['name'] ),
                    'attributes' => (object) ( $variation['attributes'] ?? [] ),
                ];
            }
        }
        return $out;
    }

    /**
     * Presets of every origin as one list; a later origin (theme, then custom) replaces a slug.
     *
     * @param mixed        $by_origin {default?:[], theme?:[], custom?:[]}
     * @param list<string> $keys
     * @return list<array<string,string>>
     */
    private static function presets( $by_origin, array $keys, bool $with_default ): array {
        $found = [];
        foreach ( [ 'default', 'theme', 'custom' ] as $origin ) {
            if ( $origin === 'default' && ! $with_default ) {
                continue;
            }
            foreach ( (array) ( is_array( $by_origin ) ? ( $by_origin[ $origin ] ?? [] ) : [] ) as $preset ) {
                if ( is_array( $preset ) && isset( $preset['slug'] ) ) {
                    $row = [];
                    foreach ( $keys as $key ) {
                        if ( isset( $preset[ $key ] ) && is_scalar( $preset[ $key ] ) ) {
                            $row[ $key ] = (string) $preset[ $key ];
                        }
                    }
                    $found[ (string) $preset['slug'] ] = $row;
                }
            }
        }
        return array_values( $found );
    }
}
