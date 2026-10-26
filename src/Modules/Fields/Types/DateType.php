<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaValidator;
use Fabrement\Modules\Fields\Values\FieldValueError;

/** "YYYY-MM-DD" (date_picker) or "YYYY-MM-DDTHH:MM" in site time (date_time_picker); "" = empty. */
final class DateType extends TextualType {

    private bool $with_time;

    public function __construct( bool $with_time ) {
        $this->with_time = $with_time;
    }

    public function name(): string {
        return $this->with_time ? 'date_time_picker' : 'date_picker';
    }

    protected function clean( array $field, string $value, bool $kept ): string {
        if ( $value !== '' && ! SchemaValidator::date( $value, $this->with_time ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::DATE_INVALID, [ 'time' => $this->with_time ] );
        }
        return $value;
    }

    protected function valid_default( string $value, array $field ): bool {
        return $value === '' || SchemaValidator::date( $value, $this->with_time );
    }
}
