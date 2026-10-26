<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Fields\Translation\TranslationPolicy;
use Fabrement\Modules\Fields\Values\ValueCodec;
use InvalidArgumentException;
use Fabrement\Modules\Fields\Values\FieldValueError;

/**
 * A field that holds other fields: a group (one set of sub-fields) or a repeater (rows of them).
 * The children of one set — one group, one row — are handled here once; the two types differ in
 * how many sets there are and how each set is stored and identified.
 */
abstract class ContainerType extends FieldType {

    public function is_container(): bool {
        return true;
    }

    public function allows_required(): bool {
        return false;
    }

    public function check_default( $value, array $field, string $at ) {
        throw new SchemaError( esc_html( $at ), 'Group and repeater fields take no default.' );
    }

    // ---- one set of children, in each shape ------------------------------------------------

    /** Every child's empty value, retired ones included (what a new set starts from). */
    protected function empty_children( array $field ): array {
        $out = [];
        foreach ( $field['sub_fields'] as $child ) {
            $out[ $child['key'] ] = ValueCodec::empty_value( $child );
        }
        return $out;
    }

    /** @param array|null $previous The stored children, or null when this set is new. */
    protected function normalize_children( array $field, $value, string $at, ?array $previous ): array {
        $active = ValueCodec::active( $field['sub_fields'] );
        if ( ! is_array( $value ) || ( $value && array_values( $value ) === $value ) || array_diff( array_keys( $value ), array_keys( $active ) ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::SUB_FIELD_UNKNOWN );
        }
        $out = [];
        foreach ( $active as $key => $child ) {
            $out[ $key ] = ValueCodec::normalize(
                $child,
                array_key_exists( $key, $value ) ? $value[ $key ] : ValueCodec::empty_value( $child ),
                $at !== '' ? $at . '.' . $child['name'] : '',
                $previous !== null && array_key_exists( $key, $previous ) ? [ $previous[ $key ] ] : null
            );
        }
        return $out;
    }

    protected function public_children( array $field, array $values, bool $row_ids ): array {
        $out = [];
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            $out[ $child['name'] ] = ValueCodec::public_value( $child, array_key_exists( $key, $values ) ? $values[ $key ] : ValueCodec::empty_value( $child ), $row_ids );
        }
        return $out;
    }

    protected function editor_children( array $field, array $values ): array {
        $out = [];
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            $out[ $key ] = ValueCodec::editor_value( $child, array_key_exists( $key, $values ) ? $values[ $key ] : ValueCodec::empty_value( $child ) );
        }
        return $out;
    }

    /** A set sent by names → editor keys; a child left out keeps what is stored. */
    protected function children_from_public( array $field, $value, array $previous, string $at ): array {
        $active = ValueCodec::active( $field['sub_fields'] );
        $names = array_column( $active, 'name' );
        if ( ! is_array( $value ) || ( $value && array_values( $value ) === $value ) ) {
            throw new InvalidArgumentException( esc_html( $at !== '' ? $at : $field['label'] ) . ': Expected an object keyed by sub-field names (' . esc_html( implode( ', ', $names ) ) . ').' );
        }
        unset( $value[ ValueCodec::ROW_KEY ] );
        foreach ( array_keys( $value ) as $name ) {
            if ( ! in_array( $name, $names, true ) ) {
                throw new InvalidArgumentException( esc_html( $at !== '' ? $at . '.' : '' ) . esc_html( (string) $name ) . ': ' . ValueCodec::unknown( (string) $name, $names ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ValueCodec::unknown() escapes the names it quotes.
            }
        }
        $out = [];
        foreach ( $active as $key => $child ) {
            $out[ $key ] = array_key_exists( $child['name'], $value )
                ? ValueCodec::from_public( $child, $value[ $child['name'] ], $previous[ $key ] ?? null, $at !== '' ? $at . '.' . $child['name'] : '' )
                : ValueCodec::editor_value( $child, array_key_exists( $key, $previous ) ? $previous[ $key ] : ValueCodec::empty_value( $child ) );
        }
        return $out;
    }

    /** Retired children carried from the stored set; active ones recursed. */
    protected function retired_children( array $field, array $next, array $previous ): array {
        $out = [];
        foreach ( $field['sub_fields'] as $child ) {
            $key = $child['key'];
            if ( ! empty( $child['retired'] ) ) {
                if ( array_key_exists( $key, $previous ) ) {
                    $out[ $key ] = $previous[ $key ];
                }
                continue;
            }
            if ( array_key_exists( $key, $next ) ) {
                $out[ $key ] = ValueCodec::with_retired( $child, $next[ $key ], $previous[ $key ] ?? null );
            }
        }
        return $out;
    }

    /** A PUBLIC set (keyed by names) as fabrement()->fields() gives it. */
    protected function api_children( array $field, $value ): array {
        return $this->map_public_children( $field, $value, [ ValueCodec::class, 'api_value' ] );
    }

    /** A PUBLIC set (keyed by names) in the reader's language. */
    protected function reader_children( array $field, $value ): array {
        return $this->map_public_children( $field, $value, [ ValueCodec::class, 'reader_value' ] );
    }

    /** Visit only present public children, preserving null separately from absence. */
    private function map_public_children( array $field, $value, callable $map ): array {
        $out = is_array( $value ) ? $value : [];
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $child ) {
            if ( array_key_exists( $child['name'], $out ) ) {
                $out[ $child['name'] ] = $map( $child, $out[ $child['name'] ] );
            }
        }
        return $out;
    }

    protected function child_references( array $field, array $values, array &$posts, array &$media ): void {
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            FieldTypes::of( $child )->references( $child, $values[ $key ] ?? null, $posts, $media );
        }
    }

    // ---- translation of one set -------------------------------------------------------------

    /**
     * @param callable(string): ?string $child_path A child key → its storage path in the target.
     * @param array<string, true>|null  $present
     */
    protected function merge_children( array $field, array $source, array $target, bool $target_has, callable $child_path, ?array $present ): array {
        $out = [];
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            $path = $child_path( $key );
            $held = $target_has && array_key_exists( $key, $target )
                && ( $present === null || ( $path !== null && isset( $present[ $path ] ) ) );
            if ( $child['translation'] === 'ignore' && ! $held ) {
                continue;
            }
            if ( ! array_key_exists( $key, $source ) ) {
                if ( ! $held ) {
                    continue;
                }
                if ( ! TranslationPolicy::follows_original( $child ) ) {
                    $out[ $key ] = $target[ $key ];
                    continue;
                }
                // A missing copied leaf/list disappears. A group may still hold local children.
                if ( $child['type'] !== 'group' ) {
                    continue;
                }
            }
            $out[ $key ] = TranslationPolicy::merge(
                $child,
                array_key_exists( $key, $source ) ? $source[ $key ] : [],
                array_key_exists( $key, $target ) ? $target[ $key ] : ValueCodec::empty_value( $child ),
                $held,
                (string) $path,
                $present
            );
        }
        return $out;
    }

    /** @param array|null $current The set the translation holds, or null. */
    protected function assert_children( array $field, $submitted, $source, string $at, ?array $current ): void {
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            // A known stored null is a value, not the "current unknown" sentinel accepted by
            // assert_editable(). An unchanged stale copy must not block its sibling's edit.
            if ( $child['translation'] === 'copy' && $current !== null && array_key_exists( $key, $current )
                && $current[ $key ] === null && array_key_exists( $key, (array) $submitted ) && $submitted[ $key ] === null ) {
                continue;
            }
            TranslationPolicy::assert_editable( $child, $submitted[ $key ] ?? null, array_key_exists( $key, (array) $source ) ? $source[ $key ] : ValueCodec::empty_value( $child ), $at . '.' . $child['name'],
                $current !== null && array_key_exists( $key, $current ) ? $current[ $key ] : null );
        }
    }

    protected function original_children( array $field, array $values, $source ): array {
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            if ( array_key_exists( $key, $values ) ) {
                $values[ $key ] = TranslationPolicy::with_original( $child, $values[ $key ], is_array( $source ) && array_key_exists( $key, $source ) ? $source[ $key ] : ValueCodec::empty_value( $child ) );
            }
        }
        return $values;
    }

    public function locks( array $field, string $path, array &$locks ): void {
        $inner = TranslationPolicy::locks( $field['sub_fields'], $path );
        $locks['fields'] = array_merge( $locks['fields'], $inner['fields'] );
        $locks['rows'] = array_merge( $locks['rows'], $inner['rows'] );
    }

    public function word_locks( array $field, string $path, array &$out ): void {
        $out = array_merge( $out, TranslationPolicy::word_locks( $field['sub_fields'], $path ) );
    }

    protected function child_texts( array $field, $values, array $path, string $label ): array {
        $out = [];
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            $out += TranslationPolicy::texts( $child, array_key_exists( $key, (array) $values ) ? $values[ $key ] : ValueCodec::empty_value( $child ), $path, $label );
        }
        return $out;
    }

    protected function clear_child_texts( array $field, $values, ?array $source, array $job, array $path, array &$cleared ) {
        $held = $source !== null && is_array( $source[0] ) ? $source[0] : [];
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            if ( ! array_key_exists( $key, (array) $values ) ) {
                continue;
            }
            $has = array_key_exists( $key, $held );
            $next = TranslationPolicy::clear_texts( $child, $values[ $key ], $has ? [ $held[ $key ] ] : null, $job, $path, $cleared );
            if ( ! $has && ! FieldTypes::of( $child )->is_container() && wp_json_encode( $next ) !== wp_json_encode( $values[ $key ] ) ) {
                unset( $values[ $key ] );
            } else {
                $values[ $key ] = $next;
            }
        }
        return $values;
    }

    protected function apply_child_texts( array $field, $values, array $texts, array $path ) {
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            if ( ! array_key_exists( $key, (array) $values )
                && ! array_intersect_key( TranslationPolicy::texts( $child, ValueCodec::empty_value( $child ), $path ), $texts ) ) {
                continue;
            }
            $values[ $key ] = TranslationPolicy::apply_texts( $child, array_key_exists( $key, (array) $values ) ? $values[ $key ] : ValueCodec::empty_value( $child ), $texts, $path );
        }
        return $values;
    }
}
