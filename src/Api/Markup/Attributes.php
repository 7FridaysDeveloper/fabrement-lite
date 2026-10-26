<?php

namespace Fabrement\Api\Markup;

use Fabrement\Api\Support\Quietly;

defined( 'ABSPATH' ) || exit;

/** Checks on values that become markup attributes. */
final class Attributes {

    /**
     * An address as it will be printed. WordPress prints an address only when its scheme is one it
     * allows (esc_url); one it strips would leave href="" or src="" — a link that reloads the page.
     * Such an address is no address: one debug-log line, and the answer is empty.
     */
    public static function address( string $raw, string $where ): string {
        if ( $raw === '' || esc_url( $raw ) !== '' ) {
            return $raw;
        }
        Quietly::log( $where, 'the address "' . $raw . '" is not one WordPress prints (an empty address, or a scheme it does not allow) — the answer is empty.' );
        return '';
    }
}
