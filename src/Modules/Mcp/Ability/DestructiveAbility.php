<?php

namespace Fabrement\Modules\Mcp\Ability;

/**
 * Base for DESTRUCTIVE abilities (delete / irreversible replace): advertises the MCP
 * `destructiveHint` so a client never silently auto-approves the call. Each tool checks
 * its own object (delete_post, delete_term…) in execute(), as wp-admin does.
 */
abstract class DestructiveAbility extends AbstractAbility {

	public function annotations(): array {
		return array( 'destructiveHint' => true );
	}
}
