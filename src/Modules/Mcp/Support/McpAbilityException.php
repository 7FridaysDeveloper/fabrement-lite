<?php

namespace Fabrement\Modules\Mcp\Support;

use WP_Error;

/**
 * Exception-flavoured failure for ability internals: deep helpers may throw instead of
 * threading WP_Error through every return type; the ability's execute() catches it and
 * converts via {@see to_error()}. Never leaks past an ability.
 */
final class McpAbilityException extends \RuntimeException {

    /** @var array<string, mixed> */
    private array $data;

    private string $slug;

    /**
     * @param array<string, mixed> $data Extra payload surfaced to the agent (violations, status, …).
     */
    public function __construct( string $message, array $data = [], string $slug = 'tool_error' ) {
        parent::__construct( $message );
        $this->data = $data;
        $this->slug = $slug;
    }

    public function to_error(): WP_Error {
        return new WP_Error( $this->slug, $this->getMessage(), $this->data );
    }
}
