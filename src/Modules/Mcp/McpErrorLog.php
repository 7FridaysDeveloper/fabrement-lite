<?php

namespace Fabrement\Modules\Mcp;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;

/**
 * What the MCP server writes to the PHP error log: what went wrong on the site, not the answers an
 * agent already got. A tool that refuses its input ("select one of the choices"), a call without
 * the right, a tool name that does not exist — each is the answer the agent reads and acts on, and
 * on a site with WP_DEBUG_LOG they would fill debug.log with "[ERROR]" lines for every mistake an
 * agent corrects. A tool that throws, or a tool that cannot be registered, is still logged.
 */
final class McpErrorLog implements McpErrorHandlerInterface {

    /** The adapter's messages for what it also returns to the agent. */
    private const ANSWERS = [
        'Tool execution returned WP_Error',
        'Tool permission check failed',
        'Tool not found',
    ];

    public function log( string $message, array $context = [], string $type = 'error' ): void {
        if ( in_array( $message, self::ANSWERS, true ) ) {
            return;
        }
        ( new ErrorLogMcpErrorHandler() )->log( $message, $context, $type );
    }
}
