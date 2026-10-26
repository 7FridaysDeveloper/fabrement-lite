<?php

namespace Fabrement\Modules\Fields\Write;

use Fabrement\Modules\Fields\Types\FieldTypes;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueSelection;
use InvalidArgumentException;

/** Submitted values and their intent, kept separately from defaults and stored presence. */
final class WriteRequest {
    public array $values;
    public array $paths;
    public array $clears;
    public array $selection;

    public function __construct( array $values = [], array $paths = [], array $clears = [], array $selection = [] ) {
        $this->values = $values;
        $this->paths = $paths;
        $this->clears = $clears;
        $this->selection = $selection;
    }

    public static function from_public( FieldObject $object, array $input ): self {
        $active = ValueCodec::active( $object->all_fields() );
        $by_name = array_column( $active, null, 'name' );
        $shown = $object->write_values( $active );
        $request = new self();
        foreach ( $input as $name => $value ) {
            $name = (string) $name;
            if ( ! isset( $by_name[ $name ] ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ValueCodec::unknown() escapes the names it quotes.
                throw new InvalidArgumentException( esc_html( $name ) . ': ' . ValueCodec::unknown( $name, array_keys( $by_name ) ) );
            }
            $field = $by_name[ $name ];
            $key = $field['key'];
            $editor = ValueCodec::from_public( $field, $value, ValueCodec::editor_value( $field, $shown['values'][ $key ] ), $name );
            $request->values[ $key ] = $editor;
            $request->paths[ $key ] = $name;
            $request->selection[ $key ] = ValueSelection::from_public( $field, $value, $editor );
            $clears = self::public_clears( $field, $value, $editor );
            if ( $clears ) {
                $request->clears[ $key ] = $clears;
            }
        }
        return $request;
    }

    /** Old REST delete means explicit clear now; it must never restore a schema default. */
    public static function from_editor( FieldObject $object, array $values, array $deletes, array $intent ): self {
        $active = ValueCodec::active( $object->all_fields() );
        foreach ( $deletes as $key ) {
            if ( ! isset( $active[ $key ] ) || array_key_exists( $key, $values ) ) {
                throw new InvalidArgumentException( 'Unknown, retired or conflicting field "' . esc_html( $key ) . '".' );
            }
            $values[ $key ] = null;
            $intent[ $key ] = true;
        }
        $request = new self( $values );
        foreach ( $values as $key => $value ) {
            if ( ! isset( $active[ $key ] ) ) {
                throw new InvalidArgumentException( 'Unknown, retired or conflicting field "' . esc_html( $key ) . '".' );
            }
            $field = $active[ $key ];
            $request->paths[ $key ] = $field['name'];
            if ( ! array_key_exists( $key, $intent ) ) {
                throw new InvalidArgumentException( 'intent: Missing submitted field "' . esc_html( $key ) . '".' );
            }
            self::validate_selection( $field, $value, $intent[ $key ] );
            $request->selection[ $key ] = $intent[ $key ];
            $clears = self::editor_clears( $field, $value, [], $request->selection[ $key ] );
            if ( $clears ) {
                $request->clears[ $key ] = $clears;
            }
        }
        if ( array_diff_key( $intent, $values ) ) {
            throw new InvalidArgumentException( 'intent: A selected field has no submitted value.' );
        }
        return $request;
    }

    /** Exact paths before public containers expand. Atomic link arrays are not traversed. */
    public static function public_clears( array $field, $input, $editor, array $prefix = [] ): array {
        if ( $input === null ) {
            return [ $prefix ];
        }
        if ( ! FieldTypes::of( $field )->is_container() || ! is_array( $input ) ) {
            return [];
        }
        $out = [];
        $sets = $field['type'] === 'repeater' ? $input : [ $input ];
        foreach ( $sets as $index => $children ) {
            $row_prefix = $field['type'] === 'repeater' ? array_merge( $prefix, [ $editor[ $index ]['id'] ] ) : $prefix;
            $next = $field['type'] === 'repeater' ? $editor[ $index ]['values'] : $editor;
            foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                if ( array_key_exists( $child['name'], $children ) ) {
                    $out = array_merge( $out, self::public_clears( $child, $children[ $child['name'] ], $next[ $key ], array_merge( $row_prefix, [ $child['name'] ] ) ) );
                }
            }
        }
        return $out;
    }

    private static function editor_clears( array $field, $input, array $prefix = [], $selection = true ): array {
        if ( $selection === false ) {
            return [];
        }
        if ( $input === null || ( $selection === true && ! FieldTypes::of( $field )->is_container() && FieldTypes::of( $field )->is_empty( $input ) ) ) {
            return [ $prefix ];
        }
        if ( ! FieldTypes::of( $field )->is_container() || ! is_array( $input ) ) {
            return [];
        }
        $out = [];
        $sets = $field['type'] === 'repeater' ? $input : [ [ 'values' => $input ] ];
        foreach ( $sets as $row ) {
            if ( ! is_array( $row ) || ! is_array( $row['values'] ?? null ) ) {
                continue; // Normalization reports malformed rows, without PHP notices here.
            }
            $row_prefix = $field['type'] === 'repeater' ? array_merge( $prefix, [ $row['id'] ?? '' ] ) : $prefix;
            $children = $selection === true ? true : ( $field['type'] === 'repeater' ? ( $selection[ $row['id'] ?? '' ] ?? [] ) : $selection );
            foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                if ( array_key_exists( $key, $row['values'] ) ) {
                    $out = array_merge( $out, self::editor_clears( $child, $row['values'][ $key ], array_merge( $row_prefix, [ $child['name'] ] ), $children === true ? true : ( $children[ $key ] ?? false ) ) );
                }
            }
        }
        return $out;
    }

    private static function validate_selection( array $field, $value, $selected ): void {
        if ( $selected === true ) {
            return;
        }
        if ( ! is_array( $selected ) || ! is_array( $value ) || ! FieldTypes::of( $field )->is_container() ) {
            throw new InvalidArgumentException( 'intent: Invalid selection for ' . esc_html( $field['name'] ) . '.' );
        }
        $children = ValueCodec::active( $field['sub_fields'] );
        if ( $field['type'] === 'repeater' ) {
            foreach ( $value as $row ) {
                if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || ! is_array( $row['values'] ?? null ) ) {
                    throw new InvalidArgumentException( 'intent: Invalid repeater row.' );
                }
            }
        }
        $sets = $field['type'] === 'repeater' ? array_column( $value, 'values', 'id' ) : [ '' => $value ];
        $selections = $field['type'] === 'repeater' ? $selected : [ '' => $selected ];
        foreach ( $selections as $id => $selection ) {
            if ( ! array_key_exists( $id, $sets ) || ( $selection !== true && ! is_array( $selection ) ) ) {
                throw new InvalidArgumentException( 'intent: Unknown row or invalid child selection.' );
            }
            if ( $selection === true ) {
                continue;
            }
            foreach ( $selection as $key => $child_selection ) {
                if ( ! isset( $children[ $key ] ) || ! array_key_exists( $key, $sets[ $id ] ) ) {
                    throw new InvalidArgumentException( 'intent: Unknown or omitted child field.' );
                }
                self::validate_selection( $children[ $key ], $sets[ $id ][ $key ], $child_selection );
            }
        }
    }

    public function with_values( array $values, ?array $selection = null ): self {
        $request = new self( $values, array_intersect_key( $this->paths, $values ), array_intersect_key( $this->clears, $values ),
            array_intersect_key( $selection ?? $this->selection, $values ) );
        return $request;
    }
    /** Does this operation assign the cleared public path? Row movement selects no children. */
    public static function selects_path( array $field, $selection, array $segments ): bool {
        if ( $selection === true || $selection === false || $segments === [] ) {
            return $selection === true;
        }
        if ( $field['type'] === 'repeater' ) {
            $selection = $selection[ array_shift( $segments ) ] ?? false;
            if ( $selection === true || $selection === false ) {
                return $selection;
            }
        }
        $child = self::child( $field, array_shift( $segments ), $field['name'] );
        return self::selects_path( $child, $selection[ $child['key'] ] ?? false, $segments );
    }

    /**
     * Project one caller-cleared path onto the current editor tree. Whole groups expand to
     * blank children, and row indexes become stable ids. A later replacement or row removal
     * drops only the affected intent; unrelated explicitly cleared siblings remain cleared.
     *
     * @return list<list<string|int>>
     */
    public static function effective_clears( array $field, $value, array $segments, array $prefix = [] ): array {
        if ( $field['type'] === 'group' ) {
            $name = $segments !== [] ? array_shift( $segments ) : null;
            $out = [];
            foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                if ( ( $name === null || $name === $child['name'] ) && array_key_exists( $key, (array) $value ) ) {
                    $out = array_merge( $out, self::effective_clears( $child, $value[ $key ], $segments, array_merge( $prefix, [ $child['name'] ] ) ) );
                }
            }
            return $out;
        }
        if ( $field['type'] === 'repeater' && $segments !== [] ) {
            $selector = array_shift( $segments );
            $name = $segments !== [] ? array_shift( $segments ) : null;
            foreach ( (array) $value as $index => $row ) {
                if ( $selector !== $index && $selector !== $row['id'] ) {
                    continue;
                }
                $out = [];
                foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                    if ( ( $name === null || $name === $child['name'] ) && array_key_exists( $key, $row['values'] ) ) {
                        $out = array_merge( $out, self::effective_clears( $child, $row['values'][ $key ], $segments, array_merge( $prefix, [ $row['id'], $child['name'] ] ) ) );
                    }
                }
                return $out;
            }
            return [];
        }
        return $segments === [] && FieldTypes::of( $field )->is_empty( $value ) ? [ $prefix ] : [];
    }

    public static function raw_clears( $value ): array {
        if ( $value === null ) {
            return [ [] ];
        }
        $paths = [];
        if ( is_array( $value ) ) {
            foreach ( $value as $segment => $child ) {
                if ( $segment === ValueCodec::ROW_KEY ) {
                    continue;
                }
                if ( is_int( $segment ) && is_array( $child ) && isset( $child[ ValueCodec::ROW_KEY ] ) ) {
                    $segment = $child[ ValueCodec::ROW_KEY ];
                }
                foreach ( self::raw_clears( $child ) as $path ) {
                    $paths[] = array_merge( [ $segment ], $path );
                }
            }
        }
        return $paths;
    }

    private static function child( array $field, string $name, string $at ): array {
        foreach ( ValueCodec::active( $field['sub_fields'] ?? [] ) as $child ) {
            if ( $child['name'] === $name ) {
                return $child;
            }
        }
        throw new InvalidArgumentException( esc_html( $at . '.' . $name ) . ': Unknown child field.' );
    }
}
