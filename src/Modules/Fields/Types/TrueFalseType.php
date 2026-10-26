<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Fields\Values\FieldValueError;

/** A switch: true | false | null, stored "1" / "0" / "". False is a value, not absence. */
final class TrueFalseType extends FieldType {

    public function name(): string {
        return 'true_false';
    }

    public function allows_required(): bool {
        return false;
    }

    public function check_default( $value, array $field, string $at ) {
        if ( ! is_bool( $value ) ) {
            throw new SchemaError( esc_html( $at ), 'The default does not fit this field.' );
        }
        return $value;
    }

    public function empty_value( array $field ) {
        return null;
    }

    public function normalize( array $field, $value, $previous, bool $kept, string $at ) {
        if ( $value !== null && ! is_bool( $value ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::TRUE_FALSE );
        }
        return $value;
    }

    public function flatten( array $field, $value, string $path, array &$meta ): void {
        $meta[ $path ] = $value === null ? '' : ( $value ? '1' : '0' );
    }

    public function inflate( array $field, $raw, string $path, array $meta, array &$present ) {
        return $raw === '' || $raw === null ? null : (string) $raw === '1';
    }
}
