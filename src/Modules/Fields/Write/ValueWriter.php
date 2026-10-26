<?php

namespace Fabrement\Modules\Fields\Write;

use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\FieldMessages;
use Fabrement\Modules\Fields\Values\FieldValueError;
use Fabrement\Modules\Fields\Values\ChoiceValues;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueRepository;
use Fabrement\Modules\Fields\Values\ValueSelection;
use Fabrement\Modules\Fields\Translation\OptionVersions;
use InvalidArgumentException;
use WP_Error;

/** One object's validation and atomic persistence; no user or translation routing policy. */
final class ValueWriter {
    public function normalize( FieldObject $object, WriteRequest $request ): WriteRequest {
        $fields = ValueCodec::active( $object->all_fields() );
        $shown = $object->write_values( $fields );
        $values = [];
        foreach ( $request->values as $key => $value ) {
            if ( ! isset( $fields[ $key ] ) ) {
                throw new InvalidArgumentException( 'Unknown, retired or conflicting field "' . esc_html( $key ) . '".' );
            }
            $before = ValueCodec::editor_value( $fields[ $key ], $shown['values'][ $key ] );
            $value = ValueSelection::project( $fields[ $key ], $value, $before, $request->selection[ $key ] ?? true );
            $values[ $key ] = ValueCodec::normalize( $fields[ $key ], $value, $request->paths[ $key ] ?? '',
                $shown['exists'][ $key ] ? [ $before ] : null );
        }
        return $request->with_values( $values );
    }

    public function clear_removed_choices( FieldObject $object, WriteRequest $request ): WriteRequest {
        $fields = array_intersect_key( ValueCodec::active( $object->all_fields() ), $request->values );
        $before = $object->write_values( $fields );
        $values = [];
        foreach ( $request->values as $key => $value ) {
            $values[ $key ] = ChoiceValues::clear_removed( $fields[ $key ], $value,
                ValueCodec::editor_value( $fields[ $key ], $before['values'][ $key ] ), $request->selection[ $key ] ?? true );
        }
        return $request->with_values( $values );
    }

    public function plan( FieldObject $object, WriteRequest $request, WriteMode $mode, bool $as_seeded = false ) {
        $all = $object->all_fields();
        if ( ! $all ) {
            return new WP_Error( 'fields_missing', 'This record has no Fabrement fields.', [ 'status' => 404 ] );
        }
        $active = ValueCodec::active( $all );
        $values = $request->values;
        $paths = $request->paths;
        $explicit_clears = $request->clears;
        $selection = $request->selection;
        // $as_seeded: a translation that holds nothing yet, judged as it will hold once seeded.
        $stored = $as_seeded ? $object->write_values( $active ) : $object->stored( $active );
        $changes = [];
        $translation = $object->is_translation();
        foreach ( $values as $key => $value ) {
            if ( ! isset( $active[ $key ] ) ) {
                throw new InvalidArgumentException( 'Unknown, retired or conflicting field "' . esc_html( $key ) . '".' );
            }
            $was = $this->stored( $active[ $key ], $stored, $key );
            $kept = $was;
            $explicit_clear = $value === null || isset( $explicit_clears[ $key ] );
            $value = ValueCodec::normalize( $active[ $key ], $value, $paths[ $key ] ?? '', $kept );
            // What the record already holds is not written again; a value it does not hold is
            // stored as given, one equal to the default included.
            $unchanged = $was !== null && ValueCodec::same( $active[ $key ]['type'], $value, $was[0] );
            // A container may exist while the explicitly cleared child has no row yet.
            // Reconcile its storage even when its decoded value already looks empty.
            if ( $explicit_clear || ( isset( $selection[ $key ] ) && ValueSelection::missing( $active[ $key ], $value, $selection[ $key ], $key, $stored['present'], $translation ) ) ) {
                $unchanged = false;
            }
            if ( ! $unchanged ) {
                $changes[ $key ] = $value;
            }
        }
        return [ 'all' => $all, 'changes' => $changes, 'stored' => $stored ];
    }

    /** Required fields are checked on the final object, even in partial and no-op saves; every failing one is named at once. */
    public function validate_required( array $fields, array $values ): void {
        $errors = $this->required_errors( $fields, $values, '', [] );
        if ( count( $errors ) > 1 ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception object built elsewhere is thrown on; nothing is printed here.
            throw FieldValueError::several( $errors );
        }
        if ( $errors ) {
            throw $errors[0];
        }
    }

    private function required_errors( array $fields, array $values, string $prefix, array $trail ): array {
        $errors = [];
        foreach ( ValueCodec::active( $fields ) as $key => $field ) {
            $value = array_key_exists( $key, $values ) ? $values[ $key ] : ValueCodec::empty_value( $field );
            $path = $prefix . $field['name'];
            if ( $field['type'] === 'group' ) {
                $errors = array_merge( $errors, $this->required_errors( $field['sub_fields'], (array) $value, $path . '.', array_merge( $trail, [ $field['label'] ] ) ) );
            } elseif ( $field['type'] === 'repeater' ) {
                foreach ( (array) $value as $index => $row ) {
                    $errors = array_merge( $errors, $this->required_errors( $field['sub_fields'], $row['values'], $path . '[' . $index . '].',
                        array_merge( $trail, [ FieldMessages::row( $field['label'], (int) $index ) ] ) ) );
                }
            } elseif ( ! empty( $field['required'] ) ) {
                try {
                    ValueCodec::normalize( $field, $value, $path );
                } catch ( FieldValueError $e ) {
                    $errors[] = $e->as_saved( array_merge( $trail, [ $field['label'] ] ) );
                }
            }
        }
        return $errors;
    }


    public function commit( FieldObject $object, WriteRequest $request, array $plan, bool $create = false ): bool {
        $changed = $object->type === 'options' && ( $create || $request->values )
            && OptionVersions::ensure( $object, $plan['changes'], $request->selection );
        if ( ! $changed && $plan['changes'] ) {
            $changed = ValueRepository::write( $object->type, $object->id, $plan['all'], $plan['changes'], [], $request->selection );
        }
        // No-op saves retry a previously failed translation sync too.
        try {
            do_action( 'fabrement_fields_saved', $object->type, $object->id, array_keys( $plan['changes'] ), [] );
        } catch ( \Throwable $e ) {
            \Fabrement\Framework\Log::debug( '[fabrement][fields] A fabrement_fields_saved listener failed after values were committed: ' . $e->getMessage() );
        }
        return $changed;
    }
    /** The REST / MCP error for what plan() or the write threw. */
    private static function error_data( FieldValueError $e ): array {
        $data = [ 'path' => $e->at, 'reason' => $e->key, 'params' => $e->params, 'saved' => $e->saved() ];
        return $e->errors ? $data + [ 'errors' => array_map( [ self::class, 'error_data' ], $e->errors ) ] : $data;
    }

    public static function failure( \Exception $e, ?WriteMode $mode = null ): WP_Error {
        if ( $e instanceof FieldValueError ) {
            return new WP_Error( 'fields_invalid', $mode && $mode->is_editor() ? FieldMessages::person( $e ) : $e->getMessage(), [ 'status' => 400 ] + self::error_data( $e ) );
        }
        if ( $e instanceof InvalidArgumentException ) {
            return new WP_Error( 'fields_invalid', $e->getMessage(), [ 'status' => 400 ] );
        }
        if ( $e instanceof \RuntimeException ) {
            return new WP_Error( 'fields_storage', $e->getMessage(), [ 'status' => 500 ] );
        }
        throw $e;
    }

    /** [stored editor value] of one top-level field, or null when the record holds none. */
    private function stored( array $field, array $stored, string $key ): ?array {
        return $stored['exists'][ $key ] ? [ ValueCodec::editor_value( $field, $stored['values'][ $key ] ) ] : null;
    }

}
