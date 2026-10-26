<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Fields\Schema\StorageLayout;
use Fabrement\Modules\Fields\Values\ValueCodec;
use InvalidArgumentException;
use Fabrement\Modules\Fields\Values\FieldValueError;

/**
 * Rows of sub-fields: [{id: row_…, values: {…}}]. A row's identity (`__rid`) keeps it joined to
 * its translations wherever it moves; stored as a row count plus, per row, its id and children.
 */
final class RepeaterType extends ContainerType {

    private const MAX_ROWS = 500;

    public function name(): string {
        return 'repeater';
    }

    protected function check_bounds( array $raw, array &$field, string $at ): void {
        foreach ( [ 'min', 'max' ] as $bound ) {
            if ( ! array_key_exists( $bound, $raw ) ) {
                continue;
            }
            $n = $raw[ $bound ];
            if ( ! is_int( $n ) || $n < 0 || $n > self::MAX_ROWS ) {
                throw new SchemaError( esc_html( $at . '.' . $bound ), 'Invalid ' . esc_html( $bound ) . ' for a repeater field.' );
            }
            $field[ $bound ] = $n;
        }
        if ( isset( $field['min'], $field['max'] ) && $field['min'] > $field['max'] ) {
            throw new SchemaError( esc_html( $at ), 'min is greater than max.' );
        }
    }

    public function empty_value( array $field ) {
        return [];
    }

    public function counted_items( $value ): ?array {
        return is_array( $value ) ? array_column( $value, 'id' ) : [];
    }

    public function normalize( array $field, $value, $previous, bool $kept, string $at ) {
        $rows_before = is_array( $previous ) ? array_column( $previous, 'values', 'id' ) : [];
        if ( ! is_array( $value ) || array_values( $value ) !== $value || count( $value ) > self::MAX_ROWS ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::ROWS_SHAPE );
        }
        $seen = [];
        foreach ( $value as $i => $row ) {
            if ( ! is_array( $row ) || array_diff( array_keys( $row ), [ 'id', 'values' ] )
                || ! is_string( $row['id'] ?? null ) || preg_match( StorageLayout::ROW_ID_RE, $row['id'] ) !== 1 || isset( $seen[ $row['id'] ] ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
                throw new FieldValueError( FieldValueError::ROW_ID );
            }
            $seen[ $row['id'] ] = true;
            $value[ $i ] = [
                'id'     => $row['id'],
                'values' => $this->normalize_children( $field, $row['values'] ?? [], $at !== '' ? $at . '[' . $i . ']' : '',
                    isset( $rows_before[ $row['id'] ] ) && is_array( $rows_before[ $row['id'] ] ) ? $rows_before[ $row['id'] ] : null ),
            ];
        }
        return $value;
    }

    public function flatten( array $field, $value, string $path, array &$meta ): void {
        $meta[ $path ] = $value === [] ? null : (string) count( $value );
        foreach ( array_values( $value ) as $i => $row ) {
            $meta[ StorageLayout::row_id( $path, $i ) ] = $row['id'];
            foreach ( $field['sub_fields'] as $child ) {
                if ( array_key_exists( $child['key'], $row['values'] ) ) {
                    ValueCodec::flatten( $child, $row['values'][ $child['key'] ], StorageLayout::row_child( $path, $i, $child['key'] ), $meta );
                }
            }
        }
    }

    public function inflate( array $field, $raw, string $path, array $meta, array &$present ) {
        $rows = [];
        $seen = [];
        $count = min( self::MAX_ROWS, max( 0, (int) $raw ) );
        for ( $i = 0; $i < $count; $i++ ) {
            $id = $meta[ StorageLayout::row_id( $path, $i ) ] ?? null;
            // A missing or repeated identity (foreign write, legacy data) falls back to a stable derived one.
            if ( ! is_string( $id ) || preg_match( StorageLayout::ROW_ID_RE, $id ) !== 1 || isset( $seen[ $id ] ) ) {
                $id = StorageLayout::derived_row_id( $path, $i );
            }
            $seen[ $id ] = true;
            $values = [];
            foreach ( $field['sub_fields'] as $child ) {
                $child_path = StorageLayout::row_child( $path, $i, $child['key'] );
                if ( empty( $child['retired'] ) || array_key_exists( $child_path, $meta ) ) {
                    $values[ $child['key'] ] = ValueCodec::inflate( $child, $child_path, $meta, $present );
                }
            }
            $rows[] = [ 'id' => $id, 'values' => $values ];
        }
        return $rows;
    }

    public function public_value( array $field, $value, bool $row_ids ) {
        return array_map(
            fn( array $row ) => ( $row_ids ? [ ValueCodec::ROW_KEY => $row['id'] ] : [] ) + $this->public_children( $field, $row['values'], $row_ids ),
            is_array( $value ) ? $value : []
        );
    }

    public function editor_value( array $field, $value ) {
        return array_map( fn( array $row ) => [ 'id' => $row['id'], 'values' => $this->editor_children( $field, $row['values'] ) ], is_array( $value ) ? $value : [] );
    }

    /** @see ValueCodec::from_public() for which stored row a sent row is. */
    public function from_public( array $field, $value, $previous, string $at ) {
        $where = $at !== '' ? $at : $field['label'];
        if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
            throw new InvalidArgumentException( esc_html( $where ) . ': Expected a list of rows, each an object keyed by sub-field names.' );
        }
        $before = is_array( $previous ) ? array_values( $previous ) : [];
        $stored = array_column( $before, 'values', 'id' );
        $used = [];
        $rows = [];
        foreach ( $value as $i => $row ) {
            $row_at = $at !== '' ? $at . '[' . $i . ']' : '';
            $id = is_array( $row ) ? ( $row[ ValueCodec::ROW_KEY ] ?? null ) : null;
            if ( $id !== null && ( ! is_string( $id ) || ! isset( $stored[ $id ] ) ) ) {
                throw new InvalidArgumentException( esc_html( $row_at !== '' ? $row_at : $where ) . '._row: Not a row of this field. Copy _row from what you read, or leave it out for a new row.' );
            }
            if ( $id !== null && isset( $used[ $id ] ) ) {
                throw new InvalidArgumentException( esc_html( $row_at !== '' ? $row_at : $where ) . '._row: The same row is sent twice; a copied row is a new one, without _row.' );
            }
            // No position is guessed: a row is the stored one only by its _row. Rows of which none
            // carries one are all new: they replace the stored rows, whose translations go with them.
            $id = $id ?? StorageLayout::new_row_id();
            $used[ $id ] = true;
            $rows[] = [ 'id' => $id, 'values' => $this->children_from_public( $field, $row, $stored[ $id ] ?? [], $row_at ) ];
        }
        return $rows;
    }

    /** Retired children carried by row identity. */
    public function with_retired( array $field, $next, $previous ) {
        $old = [];
        foreach ( is_array( $previous ) ? $previous : [] as $row ) {
            $old[ $row['id'] ] = $row['values'];
        }
        return array_map(
            fn( array $row ): array => [ 'id' => $row['id'], 'values' => $this->retired_children( $field, $row['values'], $old[ $row['id'] ] ?? [] ) ],
            $next
        );
    }

    public function api_value( array $field, $value ) {
        return array_map( fn( $row ) => is_array( $row ) ? $this->api_children( $field, $row ) : $row, is_array( $value ) ? $value : [] );
    }

    /** Public rows (no identity) in the reader's language. */
    public function reader_value( array $field, $value ) {
        return array_map( fn( $row ) => is_array( $row ) ? $this->reader_children( $field, $row ) : $row, is_array( $value ) ? $value : [] );
    }

    public function references( array $field, $value, array &$posts, array &$media ): void {
        foreach ( is_array( $value ) ? $value : [] as $row ) {
            $this->child_references( $field, $row['values'] ?? [], $posts, $media );
        }
    }

    // ---- translation: rows follow the original (copy, translate); children their own policy ----

    public function follows_original( array $field ): bool {
        return in_array( $field['translation'], [ 'copy', 'translate' ], true );
    }

    public function merge( array $field, $source, $target, bool $target_has, string $path, ?array $present ) {
        // Rows are matched by identity; a row's children sit under ITS index in the target.
        $target_rows = [];
        $target_index = [];
        foreach ( array_values( is_array( $target ) ? $target : [] ) as $i => $row ) {
            $target_rows[ $row['id'] ] = $row['values'];
            $target_index[ $row['id'] ] = $i;
        }
        return array_map( function ( array $row ) use ( $field, $target_rows, $target_index, $target_has, $path, $present ): array {
            $has = $target_has && isset( $target_rows[ $row['id'] ] );
            $index = $target_index[ $row['id'] ] ?? null;
            $child_path = static fn( string $child ): ?string => $index === null ? null : StorageLayout::row_child( $path, $index, $child );
            return [ 'id' => $row['id'], 'values' => $this->merge_children( $field, $row['values'], $target_rows[ $row['id'] ] ?? [], $has, $child_path, $present ) ];
        }, is_array( $source ) ? $source : [] );
    }

    public function assert_editable( array $field, $submitted, $source, string $at, $current ): void {
        $rows = [];
        foreach ( is_array( $source ) ? $source : [] as $row ) {
            $rows[ $row['id'] ] = $row['values'];
        }
        $held = [];
        foreach ( is_array( $current ) ? $current : [] as $row ) {
            $held[ $row['id'] ] = $row['values'];
        }
        // The rows the translation holds, sent back as they are, are no change of its own even when
        // they are stale (a sync that failed): with_original() gives them the original's rows.
        $sent = array_column( (array) $submitted, 'id' );
        if ( $this->follows_original( $field ) && $sent !== array_keys( $rows ) && ( $current === null || $sent !== array_keys( $held ) ) ) {
            throw new InvalidArgumentException( esc_html( $at ) . ': ' . esc_html( $field['label'] ) . ': Rows follow the original language: add, remove or reorder them on the original.' );
        }
        foreach ( (array) $submitted as $i => $row ) {
            if ( isset( $rows[ $row['id'] ] ) ) {
                $this->assert_children( $field, $row['values'], $rows[ $row['id'] ], $at . '[' . $i . ']', $held[ $row['id'] ] ?? null );
            }
        }
    }

    public function with_original( array $field, $submitted, $source ) {
        $rows = [];
        foreach ( is_array( $source ) ? $source : [] as $row ) {
            $rows[ $row['id'] ] = $row['values'];
        }
        $submitted = is_array( $submitted ) ? $submitted : [];
        // Stale rows (a sync that failed) heal here as the sync would have healed them: the
        // original's rows in its order; each keeps the words sent for it, a new one starts from the
        // original, one the original removed goes.
        if ( $this->follows_original( $field ) && array_column( $submitted, 'id' ) !== array_keys( $rows ) ) {
            $sent = array_column( $submitted, 'values', 'id' );
            return array_map( function ( string $id ) use ( $field, $rows, $sent ): array {
                return [ 'id' => $id, 'values' => isset( $sent[ $id ] ) ? $this->original_children( $field, $sent[ $id ], $rows[ $id ] ) : $rows[ $id ] ];
            }, array_map( 'strval', array_keys( $rows ) ) );
        }
        return array_map( function ( array $row ) use ( $field, $rows ): array {
            if ( isset( $rows[ $row['id'] ] ) ) {
                $row['values'] = $this->original_children( $field, $row['values'], $rows[ $row['id'] ] );
            }
            return $row;
        }, $submitted );
    }

    public function locks( array $field, string $path, array &$locks ): void {
        if ( $this->follows_original( $field ) ) {
            $locks['rows'][] = $path;
        }
        parent::locks( $field, $path, $locks );
    }

    public function texts( array $field, $value, array $path, string $label ): array {
        $out = [];
        foreach ( (array) $value as $i => $row ) {
            $out += $this->child_texts( $field, $row['values'], array_merge( $path, [ $row['id'] ] ), $label . ' [' . ( $i + 1 ) . ']' );
        }
        return $out;
    }

    public function apply_texts( array $field, $value, array $texts, array $path ) {
        foreach ( $value as $i => $row ) {
            $value[ $i ]['values'] = $this->apply_child_texts( $field, $row['values'], $texts, array_merge( $path, [ $row['id'] ] ) );
        }
        return $value;
    }

    public function clear_texts( array $field, $value, ?array $source, array $job, array $path, array &$cleared ) {
        $held = $source !== null && is_array( $source[0] ) ? array_column( $source[0], 'values', 'id' ) : [];
        foreach ( (array) $value as $i => $row ) {
            if ( array_key_exists( $row['id'], $held ) ) {
                $value[ $i ]['values'] = $this->clear_child_texts( $field, $row['values'], [ $held[ $row['id'] ] ], $job, array_merge( $path, [ $row['id'] ] ), $cleared );
            }
        }
        return $value;
    }
}
