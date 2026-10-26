<?php

namespace Fabrement\Modules\Mcp\Support;

use WP_Error;

/**
 * Shapes ability results the way the MCP adapter (and the agent) expects.
 *
 * Success = plain array (the adapter serializes it into the tool result and
 * validates it against the ability's output_schema). Failure = WP_Error (the
 * adapter turns it into an isError MCP response the agent can act on).
 */
final class ToolResult {

    /**
     * Generic tool failure.
     */
    public static function error( string $code, string $message, array $data = [] ): WP_Error {
        return new WP_Error( $code, $message, $data );
    }
}
