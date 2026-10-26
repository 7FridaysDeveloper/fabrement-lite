<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Capabilities;

/**
 * Base for DESTRUCTIVE abilities (delete / irreversible replace). Requires the
 * separately-filterable destructive capability (a site may raise it above the
 * default block-builder cap) and advertises the MCP `destructiveHint` so a
 * client never silently auto-approves the call.
 */
abstract class DestructiveAbility extends AbstractAbility {

	public function check_permission() {
		return current_user_can( Capabilities::destructive() );
	}

	public function annotations(): array {
		return array( 'destructiveHint' => true );
	}
}
