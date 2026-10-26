<?php

namespace Fabrement;

/**
 * WordPress capability names for plugin features (admin UI, REST, …).
 */
final class Capabilities {

    /**
     * What every Fabrement admin screen, REST route and MCP tool asks for.
     * Currently the core `manage_options` capability; can be replaced with a dedicated cap later.
     */
    public const ADMIN = 'manage_options';

    /**
     * Capability required by DESTRUCTIVE MCP tools (delete / irreversible replace).
     * Defaults to the block-builder cap but is filterable, so a site can lock
     * destructive tools behind a higher bar than read/create tools.
     */
    public static function destructive(): string {
        return (string) apply_filters( 'fabrement_mcp_destructive_capability', self::ADMIN );
    }
}
