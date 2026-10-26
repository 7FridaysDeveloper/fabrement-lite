<?php

namespace Fabrement\Modules\Fields\Write;

use WP_Error;

/** Routing can be accepted or refused; it cannot alter the validated values or their owner. */
final class WriteDecision {
    private ?WP_Error $error;

    private function __construct( ?WP_Error $error ) {
        $this->error = $error;
    }

    public static function route(): self {
        return new self( null );
    }

    public static function refuse( string $message ): self {
        return new self( new WP_Error( 'fields_routing_refused', $message, [ 'status' => 403 ] ) );
    }

    public function error(): ?WP_Error {
        return $this->error;
    }
}
