<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\StorageLayout;
use Fabrement\Modules\Fields\Values\ValueCodec;

/** One set of sub-fields, keyed by child key. Stored as a marker row plus one row per child. */
final class GroupType extends ContainerType {

    public function name(): string {
        return 'group';
    }

    public function empty_value( array $field ) {
        return $this->empty_children( $field );
    }

    /** Each active child's blank value (a group itself has no default to drop). */
    public function blank_value( array $field ) {
        $out = [];
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            $out[ $key ] = ValueCodec::blank_value( $child );
        }
        return $out;
    }

    public function normalize( array $field, $value, $previous, bool $kept, string $at ) {
        return $this->normalize_children( $field, $value, $at, is_array( $previous ) ? $previous : null );
    }

    public function flatten( array $field, $value, string $path, array &$meta ): void {
        $meta[ $path ] = '1';
        foreach ( $field['sub_fields'] as $child ) {
            if ( array_key_exists( $child['key'], $value ) ) {
                ValueCodec::flatten( $child, $value[ $child['key'] ], StorageLayout::child( $path, $child['key'] ), $meta );
            }
        }
    }

    public function inflate( array $field, $raw, string $path, array $meta, array &$present ) {
        $out = [];
        foreach ( $field['sub_fields'] as $child ) {
            $child_path = StorageLayout::child( $path, $child['key'] );
            if ( empty( $child['retired'] ) || array_key_exists( $child_path, $meta ) ) {
                $out[ $child['key'] ] = ValueCodec::inflate( $child, $child_path, $meta, $present );
            }
        }
        return $out;
    }

    public function public_value( array $field, $value, bool $row_ids ) {
        return $this->public_children( $field, is_array( $value ) ? $value : [], $row_ids );
    }

    public function from_public( array $field, $value, $previous, string $at ) {
        return $this->children_from_public( $field, $value, is_array( $previous ) ? $previous : [], $at );
    }

    public function editor_value( array $field, $value ) {
        return $this->editor_children( $field, is_array( $value ) ? $value : [] );
    }

    public function with_retired( array $field, $next, $previous ) {
        return $this->retired_children( $field, is_array( $next ) ? $next : [], is_array( $previous ) ? $previous : [] );
    }

    public function api_value( array $field, $value ) {
        $out = $this->api_children( $field, $value );
        foreach ( $out as $child ) {
            if ( $child !== null ) {
                return $out;
            }
        }
        return null;
    }

    public function reader_value( array $field, $value ) {
        return $this->reader_children( $field, $value );
    }

    public function references( array $field, $value, array &$posts, array &$media ): void {
        $this->child_references( $field, is_array( $value ) ? $value : [], $posts, $media );
    }

    // ---- translation: each child follows its own policy ------------------------------------

    public function merge( array $field, $source, $target, bool $target_has, string $path, ?array $present ) {
        return $this->merge_children( $field, is_array( $source ) ? $source : [], is_array( $target ) ? $target : [], $target_has,
            static fn( string $child ): string => StorageLayout::child( $path, $child ), $present );
    }

    public function assert_editable( array $field, $submitted, $source, string $at, $current ): void {
        $this->assert_children( $field, $submitted, $source, $at, is_array( $current ) ? $current : null );
    }

    public function with_original( array $field, $submitted, $source ) {
        return $this->original_children( $field, is_array( $submitted ) ? $submitted : [], $source );
    }

    public function texts( array $field, $value, array $path, string $label ): array {
        return $this->child_texts( $field, $value, $path, $label );
    }

    public function apply_texts( array $field, $value, array $texts, array $path ) {
        return $this->apply_child_texts( $field, $value, $texts, $path );
    }

    public function clear_texts( array $field, $value, ?array $source, array $job, array $path, array &$cleared ) {
        return is_array( $value ) ? $this->clear_child_texts( $field, $value, $source, $job, $path, $cleared ) : $value;
    }
}
