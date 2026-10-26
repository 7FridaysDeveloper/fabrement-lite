<?php

namespace Fabrement\Modules\Fields\Schema;

/**
 * The form layout: tabs, order, width, repeater display. It never touches stored values, so it
 * only has to point at fields that exist. A field the layout leaves out is still shown, after
 * the laid-out ones, so a layout mistake can never hide a field.
 */
final class LayoutValidator {

    public const WIDTHS = [ '1/4', '1/3', '1/2', '2/3', '3/4', '1' ];
    public const DISPLAYS = [ 'block', 'row', 'table' ];

    /**
     * @param mixed $input  The layout as sent.
     * @param array $fields The full field tree it must fit.
     * @throws SchemaError
     */
    public static function normalize( $input, array $fields ): array {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), [ 'tabs' ] ) ) {
            throw new SchemaError( 'layout', 'A layout is {tabs: [...]}.' );
        }
        $tabs = $input['tabs'] ?? [];
        if ( ! is_array( $tabs ) || array_values( $tabs ) !== $tabs || count( $tabs ) > 20 ) {
            throw new SchemaError( 'layout.tabs', 'Expected a list of at most 20 tabs.' );
        }
        $active = [];
        $by_name = [];
        foreach ( $fields as $field ) {
            if ( empty( $field['retired'] ) ) {
                $active[ $field['key'] ] = $field;
                $by_name[ $field['name'] ] = $field['key'];
            }
        }
        $placed = [];
        $out = [];
        foreach ( $tabs as $t => $tab ) {
            $at = 'layout.tabs[' . $t . ']';
            if ( ! is_array( $tab ) || array_diff( array_keys( $tab ), [ 'label', 'items' ] ) ) {
                throw new SchemaError( esc_html( $at ), 'A tab is {label, items}.' );
            }
            $label = $tab['label'] ?? null;
            if ( ! is_string( $label ) || trim( $label ) === '' || strlen( $label ) > 100 ) {
                throw new SchemaError( esc_html( $at . '.label' ), 'Expected a tab label of at most 100 characters.' );
            }
            $items = $tab['items'] ?? null;
            if ( ! is_array( $items ) || array_values( $items ) !== $items ) {
                throw new SchemaError( esc_html( $at . '.items' ), 'Expected a list of items.' );
            }
            $normalized = [];
            foreach ( $items as $i => $item ) {
                $path = $at . '.items[' . $i . ']';
                if ( ! is_array( $item ) || array_diff( array_keys( $item ), [ 'field', 'width', 'display' ] ) ) {
                    throw new SchemaError( esc_html( $path ), 'An item is {field, width?, display?}.' );
                }
                // A key, or a name: new fields have no key until this same save generates one.
                $key = $item['field'] ?? null;
                if ( is_string( $key ) && ! isset( $active[ $key ] ) && isset( $by_name[ $key ] ) ) {
                    $key = $by_name[ $key ];
                }
                if ( ! is_string( $key ) || ! isset( $active[ $key ] ) ) {
                    throw new SchemaError( esc_html( $path . '.field' ), 'Point at the key or name of an active top-level field.' );
                }
                if ( isset( $placed[ $key ] ) ) {
                    throw new SchemaError( esc_html( $path . '.field' ), 'Field "' . esc_html( $key ) . '" is placed twice.' );
                }
                $placed[ $key ] = true;
                $entry = [ 'field' => $key ];
                if ( array_key_exists( 'width', $item ) ) {
                    if ( ! in_array( $item['width'], self::WIDTHS, true ) ) {
                        throw new SchemaError( esc_html( $path . '.width' ), 'Width is one of ' . esc_html( implode( ', ', self::WIDTHS ) ) . '.' );
                    }
                    $entry['width'] = $item['width'];
                }
                if ( array_key_exists( 'display', $item ) ) {
                    if ( $active[ $key ]['type'] !== 'repeater' || ! in_array( $item['display'], self::DISPLAYS, true ) ) {
                        throw new SchemaError( esc_html( $path . '.display' ), 'Only a repeater has a display: block, row or table.' );
                    }
                    $entry['display'] = $item['display'];
                }
                $normalized[] = $entry;
            }
            $out[] = [ 'label' => $label, 'items' => $normalized ];
        }
        return [ 'tabs' => $out ];
    }

    /** Drop items whose field is gone or retired; what a stored layout means after a field change. */
    public static function prune( array $layout, array $fields ): array {
        $active = [];
        foreach ( $fields as $field ) {
            if ( empty( $field['retired'] ) ) {
                $active[ $field['key'] ] = true;
            }
        }
        foreach ( $layout['tabs'] ?? [] as $t => $tab ) {
            $layout['tabs'][ $t ]['items'] = array_values( array_filter( $tab['items'], static fn( $item ) => isset( $active[ $item['field'] ] ) ) );
        }
        return $layout;
    }
}
