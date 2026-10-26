<?php

namespace Fabrement\Modules\Mcp\Ability;

/**
 * One MCP tool of the Fabrement server, registered as a WP Ability.
 *
 * Implementations are thin adapters: schema + permission + a call into an
 * existing module service. Business logic stays in the owning module — never inside an ability.
 */
interface AbilityInterface {

    /**
     * Registration name, namespaced with a slash: "fabrement/list-blocks".
     * The MCP tool name the agent sees is the sanitized form
     * ("fabrement-list-blocks" — the adapter swaps "/" for "-").
     */
    public function name(): string;

    /** Human-readable label shown in ability registries. */
    public function label(): string;

    /** Tool description the agent reads in tools/list — write it for the agent. */
    public function description(): string;

    /** JSON Schema (array form) of the tool input. */
    public function input_schema(): array;

    /**
     * JSON Schema (array form) of the tool output, or [] for none.
     * When present the adapter validates execute() results against it.
     */
    public function output_schema(): array;

    /**
     * MCP behavior-hint annotations for clients ({readOnlyHint: true}, …),
     * or [] for none. Clients use these e.g. to auto-allow read-only tools.
     */
    public function annotations(): array;

    /**
     * Whether the current (already authenticated) user may call this tool.
     *
     * @return bool|\WP_Error
     */
    public function check_permission();

    /**
     * Execute the tool. Return a plain array (the adapter serializes it into
     * the MCP result) or a WP_Error (becomes an isError MCP response the
     * agent can react to — e.g. validation violations).
     *
     * @param array $input Input already validated against input_schema().
     * @return array|\WP_Error
     */
    public function execute( array $input );
}
