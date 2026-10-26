<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\FieldValueError;

/**
 * An ordered list of picked ids: records (relationship) or images (gallery). At most MAX_LIST. A
 * list holds each id once, as ACF's relationship and gallery do (and WP_Query / get_posts show them).
 */
final class ListType extends FieldType {

    private string $name;

    public function __construct( string $name ) {
        $this->name = $name;
    }

    public function name(): string {
        return $this->name;
    }

    private function is_gallery(): bool {
        return $this->name === 'gallery';
    }

    protected function check_bounds( array $raw, array &$field, string $at ): void {
        foreach ( [ 'min', 'max' ] as $bound ) {
            if ( ! array_key_exists( $bound, $raw ) ) {
                continue;
            }
            $n = $raw[ $bound ];
            if ( ! is_int( $n ) || $n < 0 || $n > ValueCodec::MAX_LIST ) {
                throw new SchemaError( esc_html( $at . '.' . $bound ), 'Invalid ' . esc_html( $bound ) . ' for a ' . esc_html( $field['type'] ) . ' field.' );
            }
            $field[ $bound ] = $n;
        }
        if ( isset( $field['min'], $field['max'] ) && $field['min'] > $field['max'] ) {
            throw new SchemaError( esc_html( $at ), 'min is greater than max.' );
        }
    }

    protected function check_post_types( array $raw, array &$field, string $at ): void {
        if ( $this->is_gallery() ) {
            parent::check_post_types( $raw, $field, $at );
            return;
        }
        Records::check_post_types( $raw, $field, $at );
    }

    public function empty_value( array $field ) {
        return [];
    }

    public function counted_items( $value ): ?array {
        return is_array( $value ) ? $value : [];
    }

    public function normalize( array $field, $value, $previous, bool $kept, string $at ) {
        $what = $this->is_gallery() ? 'image' : 'record';
        // A list stored before the cap may stay as it is or shrink, but take nothing new.
        $shrinking = is_array( $value ) && is_array( $previous ) && count( $value ) <= count( $previous ) && ! array_diff( $value, $previous );
        if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::LIST_SHAPE, [ 'what' => $what ] );
        }
        if ( count( $value ) > ValueCodec::MAX_LIST && ! $shrinking ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::LIST_TOO_LONG, [ 'what' => $what, 'max' => ValueCodec::MAX_LIST, 'count' => count( $value ) ] );
        }
        $had = is_array( $previous ) ? $previous : [];
        foreach ( $value as $i => $id ) {
            try {
                Records::check( $id, $this->is_gallery() ? 'image' : 'post_object', in_array( $id, $had, true ), $this->is_gallery() ? null : ( $field['postTypes'] ?? null ) );
                if ( ! $id ) {
                    throw new FieldValueError( FieldValueError::RECORD_EMPTY, [ 'what' => $what ] );
                }
            } catch ( FieldValueError $e ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception object built elsewhere is thrown on; nothing is printed here.
                throw $e->with( [ 'item' => $i + 1 ] );
            }
        }
        $value = array_map( [ Records::class, 'canonical' ], $value );
        if ( count( array_unique( $value ) ) !== count( $value ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::UNIQUE, [ 'what' => $what ] );
        }
        return $value;
    }

    public function flatten( array $field, $value, string $path, array &$meta ): void {
        $meta[ $path ] = $value === [] ? null : $value;
    }

    /** Rows in order (several), one row (a list of one; '' for an empty list), or one array (an options page). */
    public function inflate( array $field, $raw, string $path, array $meta, array &$present ) {
        $items = is_array( $raw ) ? $raw : [ $raw ];
        return array_values( array_filter( array_map( static fn( $id ): int => is_scalar( $id ) ? (int) $id : 0, $items ) ) );
    }

    /** Any language's id looks for the original it is stored as — every value in one question. */
    public function search_values( array $field, array $values ): array {
        return Records::search_values( $values );
    }

    /**
     * Each id in the reader's language, in order (a list holds each id once); a record with no
     * version to show there is left out.
     */
    public function reader_value( array $field, $value ) {
        $out = [];
        foreach ( is_array( $value ) ? $value : [] as $id ) {
            $id = $this->is_gallery() ? Records::reader_media( (int) $id ) : Records::reader_post( (int) $id );
            if ( $id > 0 ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    public function references( array $field, $value, array &$posts, array &$media ): void {
        foreach ( (array) $value as $id ) {
            if ( $this->is_gallery() ) {
                $media[ (int) $id ] = true;
            } else {
                $posts[ (int) $id ] = true;
            }
        }
    }
}
