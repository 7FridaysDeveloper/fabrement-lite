<?php

namespace Fabrement\Modules\Mcp\Support;

/** ACF's stored flat data format; deliberately independent from Fabrement custom fields. */
final class AcfBlockData {
    private const SCALARS = [ 'text', 'textarea', 'wysiwyg', 'number', 'range', 'true_false', 'select', 'checkbox', 'radio', 'button_group', 'image', 'file', 'gallery', 'link', 'url', 'email', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'post_object', 'relationship', 'user', 'taxonomy' ];
    private const DISPLAY = [ 'tab', 'accordion', 'message' ];

    public static function is_block( string $name ): bool {
        return function_exists( 'acf_get_block_type' ) && (bool) acf_get_block_type( $name );
    }

    private static function fields( string $name ): array {
        return function_exists( 'acf_get_block_fields' ) ? acf_get_block_fields( [ 'name' => $name ] ) : [];
    }

    public static function schema( string $name ): array {
        $block = acf_get_block_type( $name );
        $reasons = [];
        if ( ! empty( $block['use_post_meta'] ) || ! empty( $block['usePostMeta'] ) ) {
            $reasons[] = 'usePostMeta stores values outside block content; content writes are not supported for this block.';
        }
        $fields = self::describe_fields( self::fields( $name ), $reasons );
        return [
            'blockVersion' => (int) ( $block['acf_block_version'] ?? 1 ),
            'writable' => $reasons === [], 'limitations' => array_values( array_unique( $reasons ) ), 'fields' => $fields,
            'storage' => 'attributes.data: flat ACF field names, _name field-key references, repeater count plus name_0_subfield keys. Missing references are filled automatically. In op:update, data is patched by key; omitted fields stay. To clear a repeater set its count to 0. To replace all data, replace the block.',
        ];
    }

    private static function describe_fields( array $fields, array &$reasons, int $depth = 0 ): array {
        if ( $depth > 12 ) { $reasons[] = 'Field nesting exceeds 12 levels.'; return []; }
        $out = [];
        foreach ( $fields as $field ) {
            if ( in_array( $field['type'], self::DISPLAY, true ) ) { continue; }
            if ( ! in_array( $field['type'], array_merge( self::SCALARS, [ 'group', 'repeater' ] ), true ) ) {
                $reasons[] = 'Unsupported field type: ' . $field['type'] . ' (' . $field['name'] . ').';
            }
            if ( ! empty( $field['conditional_logic'] ) ) { $reasons[] = 'Conditional field validation is not supported: ' . $field['name'] . '.'; }
            $row = array_intersect_key( $field, array_flip( [ 'key', 'name', 'label', 'type', 'required', 'default_value', 'choices', 'multiple', 'allow_null', 'min', 'max', 'step', 'maxlength', 'return_format', 'conditional_logic', 'post_type', 'taxonomy', 'field_type', 'save_terms' ] ) );
            if ( ! empty( $field['save_terms'] ) ) { $reasons[] = 'Taxonomy save_terms side effects are not supported: ' . $field['name'] . '.'; }
            if ( isset( $field['sub_fields'] ) ) { $row['sub_fields'] = self::describe_fields( $field['sub_fields'], $reasons, $depth + 1 ); }
            $out[] = $row;
        }
        return $out;
    }

    public static function merge( string $name, array $old, array $patch ): array {
        $data = array_replace( $old, $patch );
        $budget = 5000;
        // Reducing a repeater removes only rows beyond the new count, including nested data/references.
        self::prune_rows( self::fields( $name ), $data, $patch, '', 0, $budget );
        return $data;
    }

    private static function prune_rows( array $fields, array &$data, array $patch, string $prefix, int $depth, int &$budget ): void {
        if ( $depth > 12 ) { return; }
        foreach ( $fields as $field ) {
            if ( --$budget < 0 ) { throw new McpAbilityException( 'ACF data exceeds 5000 field values.' ); }
            $path = $prefix . $field['name'];
            if ( $field['type'] === 'group' ) { self::prune_rows( $field['sub_fields'] ?? [], $data, $patch, $path . '_', $depth + 1, $budget ); }
            if ( $field['type'] !== 'repeater' ) { continue; }
            $count = $data[ $path ] ?? 0;
            if ( ! self::count_value( $count ) ) { continue; }
            if ( array_key_exists( $path, $patch ) ) {
                foreach ( array_keys( $data ) as $key ) {
                    if ( preg_match( '/^_?' . preg_quote( $path, '/' ) . '_(\d+)_/', $key, $match ) && (int) $match[1] >= (int) $count ) { unset( $data[ $key ] ); }
                }
            }
            for ( $i = 0; $i < (int) $count; $i++ ) { self::prune_rows( $field['sub_fields'] ?? [], $data, $patch, $path . '_' . $i . '_', $depth + 1, $budget ); }
        }
    }

    private static function count_value( $value ): bool {
        // ACF's update_value() stores an empty repeater as '', including editor saves.
        if ( $value === '' ) { return true; }
        return ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 0 && (int) $value <= 500;
    }

    public static function validate( string $name, array $data, string $where ): array {
        $schema = self::schema( $name );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the block's own limitation sentences, returned to the MCP client as JSON text; never printed as HTML.
        if ( ! $schema['writable'] ) { throw new McpAbilityException( implode( ' ', $schema['limitations'] ), [], 'unsupported_acf_block' ); }
        $used = [];
        $budget = 5000;
        $fields = self::fields( $name );
        $values = self::decode( $fields, $data, $used, '', $budget );
        $unknown = array_diff( array_keys( $data ), array_keys( $used ) );
        if ( $unknown ) { throw new McpAbilityException( esc_html( $where ) . ': unknown ACF data keys: ' . esc_html( implode( ', ', $unknown ) ), [], 'unknown_acf_field' ); }
        // ACF's validators also run the site's custom acf/validate_value hooks. Preserve their global state.
        $validation = acf_get_instance( 'acf_validation' );
        $previous = $validation->errors;
        try {
            acf_reset_validation_errors();
            foreach ( $fields as $field ) {
                if ( in_array( $field['type'], self::DISPLAY, true ) ) { continue; }
                acf_validate_value( $values[ $field['key'] ] ?? null, $field, $where . '.data.' . $field['name'] );
            }
            $errors = acf_get_validation_errors();
            if ( $errors ) { throw new McpAbilityException( $where . ': ' . implode( '; ', array_column( $errors, 'message' ) ), [ 'errors' => $errors ], 'invalid_acf_data' ); }
        } finally { $validation->errors = $previous; }
        return $data;
    }

    /** Rebuild ACF form values keyed by field key, without loading or writing post meta. */
    private static function decode( array $fields, array &$data, array &$used, string $prefix, int &$budget, int $depth = 0 ): array {
        if ( $depth > 12 ) { throw new McpAbilityException( 'ACF field nesting exceeds 12 levels.' ); }
        $values = [];
        foreach ( $fields as $field ) {
            if ( --$budget < 0 ) { throw new McpAbilityException( 'ACF data exceeds 5000 field values.' ); }
            if ( in_array( $field['type'], self::DISPLAY, true ) ) { continue; }
            $path = $prefix . $field['name'];
            $used[ $path ] = true;
            $used[ '_' . $path ] = true;
            if ( isset( $data[ '_' . $path ] ) && $data[ '_' . $path ] !== $field['key'] ) {
                throw new McpAbilityException( 'Incorrect ACF field-key reference for ' . esc_html( $path ) . '.', [], 'invalid_acf_data' );
            }
            if ( array_key_exists( $path, $data ) ) { $data[ '_' . $path ] = $field['key']; }
            $value = $data[ $path ] ?? ( $field['default_value'] ?? null );
            if ( $field['type'] === 'group' ) {
                if ( isset( $data[ $path ] ) && $data[ $path ] !== '' ) { throw new McpAbilityException( esc_html( $path ) . ': use flat group_subfield keys.' ); }
                $value = self::decode( $field['sub_fields'] ?? [], $data, $used, $path . '_', $budget, $depth + 1 );
                $data[ $path ] = '';
                $data[ '_' . $path ] = $field['key'];
            } elseif ( $field['type'] === 'repeater' ) {
                $count = $data[ $path ] ?? 0;
                if ( ! self::count_value( $count ) ) { throw new McpAbilityException( esc_html( $path ) . ': repeater count must be an integer from 0 to 500 or an empty string.', [], 'invalid_acf_data' ); }
                if ( (int) $count < (int) ( $field['min'] ?? 0 ) || ( ! empty( $field['max'] ) && (int) $count > (int) $field['max'] ) ) { throw new McpAbilityException( esc_html( $path ) . ': repeater count is outside min/max.', [], 'invalid_acf_data' ); }
                $value = [];
                for ( $i = 0; $i < (int) $count; $i++ ) { $value[] = self::decode( $field['sub_fields'] ?? [], $data, $used, $path . '_' . $i . '_', $budget, $depth + 1 ); }
            } else { self::scalar( $field, $value, $path ); }
            $values[ $field['key'] ] = $value;
        }
        return $values;
    }

    private static function scalar( array $field, $value, string $path ): void {
        if ( $value === null || $value === '' || $value === false ) { return; } // Required is checked by ACF.
        $type = $field['type'];
        $valid = true;
        if ( in_array( $type, [ 'number', 'range' ], true ) ) { $valid = is_numeric( $value ) && ! is_bool( $value ); }
        elseif ( $type === 'true_false' ) { $valid = in_array( $value, [ true, false, 0, 1, '0', '1' ], true ); }
        elseif ( in_array( $type, [ 'select', 'checkbox', 'radio', 'button_group' ], true ) ) {
            $multi = $type === 'checkbox' || ! empty( $field['multiple'] );
            $valid = $multi ? is_array( $value ) : is_scalar( $value );
            foreach ( (array) $value as $choice ) { $valid = $valid && ( is_string( $choice ) || is_int( $choice ) ) && array_key_exists( $choice, $field['choices'] ?? [] ); }
        } elseif ( $type === 'link' ) {
            $valid = is_array( $value ) && isset( $value['url'] ) && is_string( $value['url'] ) && ( $value['url'] === '' || esc_url_raw( $value['url'] ) !== '' );
            foreach ( [ 'title', 'target' ] as $key ) { if ( isset( $value[ $key ] ) && ! is_string( $value[ $key ] ) ) { $valid = false; } }
        } elseif ( in_array( $type, [ 'image', 'file', 'gallery', 'post_object', 'relationship', 'user', 'taxonomy' ], true ) ) {
            $multi = in_array( $type, [ 'gallery', 'relationship' ], true ) || ! empty( $field['multiple'] ) || ( $type === 'taxonomy' && in_array( $field['field_type'] ?? '', [ 'checkbox', 'multi_select' ], true ) );
            $valid = $multi ? is_array( $value ) : ! is_array( $value );
            foreach ( (array) $value as $ref ) { $valid = $valid && ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) && (int) $ref >= 0; }
        } else { $valid = is_string( $value ); }
        if ( ! $valid ) { throw new McpAbilityException( esc_html( $path ) . ': invalid ' . esc_html( $type ) . ' value.', [], 'invalid_acf_data' ); }
    }
}
