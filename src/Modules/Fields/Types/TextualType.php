<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Fields\Values\FieldValueError;

/**
 * A value kept as a string: text, textarea, wysiwyg, select, date, date-time. The shape check and
 * the storage are shared; each type adds its own cleaning or rule.
 */
abstract class TextualType extends FieldType {

    private const MAX_BYTES = 262144;

    public function normalize( array $field, $value, $previous, bool $kept, string $at ) {
        if ( ! is_string( $value ) || strlen( $value ) > self::MAX_BYTES ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::TOO_LONG );
        }
        return $this->clean( $field, $value, $kept );
    }

    /** The type's own rule for a string already known to be one. */
    abstract protected function clean( array $field, string $value, bool $kept ): string;

    public function check_default( $value, array $field, string $at ) {
        if ( ! is_string( $value ) || ! $this->valid_default( $value, $field ) ) {
            throw new SchemaError( esc_html( $at ), 'The default does not fit this field.' );
        }
        return $value;
    }

    protected function valid_default( string $value, array $field ): bool {
        return strlen( $value ) <= 10000;
    }
}
