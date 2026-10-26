<?php

namespace Fabrement\Modules\Fields\Values;

use Fabrement\Modules\Fields\Schema\StorageLayout;
use Fabrement\Modules\Fields\Types\FieldTypes;

/** Submitted fields by schema key and row ID; true selects an entire subtree. */
final class ValueSelection {
    /** Overlay selected children on the current value; never turn omitted siblings into edits. */
    public static function project( array $field, $value, $before, $selected ) {
        if ( $selected === false ) {
            return $before;
        }
        if ( $selected === true || ! is_array( $value ) || ! FieldTypes::of( $field )->is_container() ) {
            return $value;
        }
        if ( $field['type'] === 'group' ) {
            foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                $old = array_key_exists( $key, (array) $before ) ? $before[ $key ] : ValueCodec::empty_value( $child );
                $value[ $key ] = self::project( $child, array_key_exists( $key, $value ) ? $value[ $key ] : ValueCodec::empty_value( $child ), $old, $selected[ $key ] ?? false );
            }
            return $value;
        }
        $old = array_column( (array) $before, 'values', 'id' );
        foreach ( $value as &$row ) {
            if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || ! is_array( $row['values'] ?? null ) ) {
                continue;
            }
            if ( isset( $old[ $row['id'] ] ) && ( $selected[ $row['id'] ] ?? [] ) !== true ) {
                $group = array_replace( $field, [ 'type' => 'group' ] );
                $row['values'] = self::project( $group, $row['values'], $old[ $row['id'] ], $selected[ $row['id'] ] ?? [] );
            }
        }
        unset( $row );
        return $value;
    }

    public static function from_public( array $field, $input, $editor ) {
        // An explicitly assigned empty repeater replaces the whole list. In contrast, an
        // empty selection from move_row/delete_row selects no child values.
        if ( $input === null || ! FieldTypes::of( $field )->is_container() || ( $field['type'] === 'repeater' && $input === [] ) ) {
            return true;
        }
        if ( $field['type'] === 'group' ) {
            return self::children( $field, $input, $editor );
        }
        $out = [];
        foreach ( $editor as $index => $row ) {
            $raw = $input[ $index ];
            $out[ $row['id'] ] = isset( $raw[ ValueCodec::ROW_KEY ] )
                ? self::children( $field, $raw, $row['values'] ) : true;
        }
        return $out;
    }

    private static function children( array $field, array $input, array $editor ): array {
        $out = [];
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            if ( array_key_exists( $child['name'], $input ) ) {
                $out[ $key ] = self::from_public( $child, $input[ $child['name'] ], $editor[ $key ] );
            }
        }
        return $out;
    }

    public static function merge( $before, $next ) {
        if ( $before === true || $next === true ) {
            return true;
        }
        foreach ( $next as $key => $selected ) {
            $before[ $key ] = array_key_exists( $key, $before ) ? self::merge( $before[ $key ], $selected ) : $selected;
        }
        return $before;
    }

    public static function missing( array $field, $value, $selected, string $path, array $present, bool $defaults_only = false ): bool {
        if ( $selected === false ) {
            return false;
        }
        if ( ! FieldTypes::of( $field )->is_container() ) {
            return ! isset( $present[ $path ] ) && ( ! $defaults_only || ( array_key_exists( 'default', $field )
                && ValueCodec::same( $field['type'], $value, ValueCodec::editor_value( $field, $field['default'] ) ) ) );
        }
        $sets = $field['type'] === 'repeater' ? $value : [ [ 'id' => '', 'values' => $value ] ];
        foreach ( $sets as $index => $row ) {
            $children = $selected === true ? true : ( $field['type'] === 'repeater' ? ( $selected[ $row['id'] ] ?? [] ) : $selected );
            $prefix = $field['type'] === 'repeater' ? $path . '_' . $index : $path;
            foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                if ( self::missing( $child, $row['values'][ $key ], $children === true ? true : ( $children[ $key ] ?? false ), StorageLayout::child( $prefix, $key ), $present, $defaults_only ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function preserve( array $field, $value, $selected, string $path, string $old_path, array $meta, array &$insert ): void {
        if ( $selected === true ) {
            return;
        }
        if ( $selected === false ) {
            if ( ! FieldTypes::of( $field )->is_container() ) {
                unset( $insert[ $path ] );
                if ( array_key_exists( $old_path, $meta ) ) {
                    $insert[ $path ] = $meta[ $old_path ];
                }
                return;
            }
            $pattern = StorageLayout::owned_pattern( array_replace( $field, [ 'key' => $path ] ) );
            foreach ( array_keys( $insert ) as $key ) {
                if ( preg_match( $pattern, $key ) === 1 ) {
                    unset( $insert[ $key ] );
                }
            }
            $pattern = StorageLayout::owned_pattern( array_replace( $field, [ 'key' => $old_path ] ) );
            foreach ( $meta as $key => $raw ) {
                if ( preg_match( $pattern, $key ) === 1 ) {
                    $insert[ $path . substr( $key, strlen( $old_path ) ) ] = $raw;
                }
            }
            return;
        }
        if ( $field['type'] === 'group' ) {
            self::preserve_children( $field, $value, $selected, $path, $old_path, $meta, $insert );
            return;
        }
        $previous = ValueCodec::inflate( $field, $old_path, $meta );
        $indexes = array_flip( array_column( $previous, 'id' ) );
        foreach ( $value as $index => $row ) {
            if ( ( $selected[ $row['id'] ] ?? [] ) !== true ) {
                $held = isset( $indexes[ $row['id'] ] );
                // A displayed inherited row is not a newly authored row. When this destination
                // has no copy yet, its omitted children stay absent instead of being materialized.
                self::preserve_children( $field, $row['values'], $selected[ $row['id'] ] ?? [], $path . '_' . $index,
                    $held ? $old_path . '_' . $indexes[ $row['id'] ] : $path . '_' . $index, $held ? $meta : [], $insert );
            }
        }
    }

    private static function preserve_children( array $field, array $values, array $selected, string $path, string $old_path, array $meta, array &$insert ): void {
        foreach ( $field['sub_fields'] as $child ) {
            $key = $child['key'];
            self::preserve( $child, $values[ $key ] ?? null, $selected[ $key ] ?? false, StorageLayout::child( $path, $key ), StorageLayout::child( $old_path, $key ), $meta, $insert );
        }
    }
}
