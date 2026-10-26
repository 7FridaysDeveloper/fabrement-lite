<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Values\FieldValueError;

/** One of a fixed list: the VALUE is stored, the label shown (fabrement_get_choice_label). */
final class SelectType extends TextualType {

    public function name(): string {
        return 'select';
    }

    public function api_value( array $field, $value ) {
        return Choices::available( $field, $value );
    }

    protected function clean( array $field, string $value, bool $kept ): string {
        if ( $value !== '' && ! $kept && ! Choices::offers( $field, $value ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::CHOICE, [ 'value' => $value, 'choices' => array_column( $field['choices'] ?? [], 'value' ) ] );
        }
        return $value;
    }

    protected function check_choices( array $raw, array &$field, string $at ): void {
        Choices::check( $raw, $field, $at );
    }

    protected function valid_default( string $value, array $field ): bool {
        return $value === '' || Choices::offers( $field, $value );
    }
}
