<?php

namespace Fabrement\Modules\Mcp;

use Fabrement\Capabilities;
use Fabrement\Modules\Mcp\Support\Instructions;

/**
 * Creates the Fabrement custom MCP server on `mcp_adapter_init`.
 *
 * Custom server = every Roster ability is a standalone MCP tool with its
 * full schema visible in tools/list (no discover-then-execute indirection).
 */
final class ServerDefinition {

    public const SERVER_ID      = 'fabrement';
    public const ROUTE_NAMESPACE = 'fabrement/v1';
    public const ROUTE          = 'mcp';

    private Registrar $registrar;

    public function __construct( Registrar $registrar ) {
        $this->registrar = $registrar;
    }

    /**
     * @param \WP\MCP\Core\McpAdapter $adapter
     */
    public function register( $adapter ): void {
        // A Throwable here means the adapter in memory has another API — another plugin's copy.
        // Logged and skipped: MCP goes off, the site stays up.
        try {
            $this->create( $adapter );
        } catch ( \Throwable $e ) {
            \Fabrement\Framework\Log::debug( '[fabrement][mcp] create_server threw: ' . $e->getMessage() );
        }
    }

    /**
     * @param \WP\MCP\Core\McpAdapter $adapter
     */
    private function create( $adapter ): void {
        $result = $adapter->create_server(
            self::SERVER_ID,
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            'Fabrement Lite',
            Instructions::text(),
            defined( 'FABREMENT_BLOCKS_VERSION' ) ? FABREMENT_BLOCKS_VERSION : 'dev',
            [ \WP\MCP\Transport\HttpTransport::class ],
            McpErrorLog::class,   // a refusal is the agent's answer, not a line for debug.log
            null,
            $this->registrar->ability_names(),
            [],
            [],
            // Transport gate for the WHOLE server; each ability re-checks its own
            // permission on top of this.
            static function (): bool {
                return current_user_can( Capabilities::ADMIN );
            }
        );

        if ( is_wp_error( $result ) ) {
            \Fabrement\Framework\Log::debug( '[fabrement][mcp] create_server failed: ' . $result->get_error_message() );
        }
    }
}
