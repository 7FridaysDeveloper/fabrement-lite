<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Values\FieldValueError;

/** ONE picked item: image, file (media library) or post_object (a record). 0 = none. */
final class ItemType extends FieldType {

    private string $name;

    public function __construct( string $name ) {
        $this->name = $name;
    }

    public function name(): string {
        return $this->name;
    }

    private function is_media(): bool {
        return $this->name !== 'post_object';
    }

    protected function check_post_types( array $raw, array &$field, string $at ): void {
        if ( $this->is_media() ) {
            parent::check_post_types( $raw, $field, $at );
            return;
        }
        Records::check_post_types( $raw, $field, $at );
    }

    public function empty_value( array $field ) {
        return 0;
    }

    public function is_empty( $value ): bool {
        return parent::is_empty( $value ) || $value === 0;
    }

    public function from_public( array $field, $value, $previous, string $at ) {
        if ( $value === 0 ) {
            $error = ( new FieldValueError( FieldValueError::ITEM_ZERO ) )->at( $field, $at );
            throw $error;
        }
        return $value;
    }

    public function normalize( array $field, $value, $previous, bool $kept, string $at ) {
        Records::check( $value, $this->name, $kept, $field['postTypes'] ?? null );
        return Records::canonical( $value );
    }

    public function flatten( array $field, $value, string $path, array &$meta ): void {
        $meta[ $path ] = (string) (int) $value;
    }

    public function inflate( array $field, $raw, string $path, array $meta, array &$present ) {
        // What is not an id (an array or an object a foreign write left) is no item: (int) of an array is 1.
        return is_int( $raw ) || is_string( $raw ) ? max( 0, (int) $raw ) : 0;
    }

    /** Any language's id looks for the original, as normalize() stores it. */
    public function search_values( array $field, array $values ): array {
        return Records::search_values( $values );
    }

    /** Stored as the original; read in the reader's language (a record: 0 where it has none). */
    public function reader_value( array $field, $value ) {
        if ( $value <= 0 ) {
            return $value;
        }
        return $this->is_media() ? Records::reader_media( (int) $value ) : Records::reader_post( (int) $value );
    }

    public function references( array $field, $value, array &$posts, array &$media ): void {
        if ( (int) $value > 0 ) {
            if ( $this->is_media() ) {
                $media[ (int) $value ] = true;
            } else {
                $posts[ (int) $value ] = true;
            }
        }
    }
}
