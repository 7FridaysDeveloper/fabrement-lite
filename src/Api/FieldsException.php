<?php

namespace Fabrement\Api;

defined( 'ABSPATH' ) || exit;

/**
 * A call of the fields API that cannot be done as asked: an unknown field or row, a value the
 * field refuses. Thrown where the mistake is made — set_<type>(), add_row(),
 * clear() — or by save() for what only the whole record can tell (limits, translations).
 */
final class FieldsException extends \InvalidArgumentException {

    /**
     * What $fn returns; a refusal of the value layer (InvalidArgumentException) becomes this.
     *
     * @internal
     */
    public static function wrap( callable $fn ) {
        try {
            return $fn();
        } catch ( FieldsException $e ) {
            throw $e;
        } catch ( \InvalidArgumentException $e ) {
            throw new self( $e->getMessage(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the same message thrown on as this type; escaped where it was made.
        }
    }

    /**
     * A service's answer: a WP_Error is thrown as this.
     *
     * @internal
     * @param mixed $result
     */
    public static function check( $result ): void {
        if ( is_wp_error( $result ) ) {
            throw new self( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a WP_Error message passed on as plain text; never printed as HTML.
        }
    }
}
