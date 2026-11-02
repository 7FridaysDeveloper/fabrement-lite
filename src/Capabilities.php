<?php

namespace Fabrement;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress capability names for plugin features (admin UI, REST, …).
 */
final class Capabilities {

    /**
     * Who may connect an MCP client, approve its OAuth access and keep using it: an
     * Administrator. The client then works with an Editor's caps at most
     * ({@see \Fabrement\Modules\Mcp\McpCapabilityCeiling}); ask through can_connect().
     */
    public const CONNECT = 'manage_options';


    /**
     * Templates (the `fabrement_template` post type, its screen, its REST routes and the MCP
     * template tools): an Editor's, as pages that are not their own are. Site-wide assignments
     * (post-type defaults, archives, 404) are made only in wp-admin.
     */
    public const TEMPLATES = 'edit_others_pages';

    /**
     * May this user (default: the signed-in one) connect? Asked on the user's own caps, past the
     * MCP ceiling, which withholds CONNECT itself during an MCP request.
     */
    public static function can_connect( ?\WP_User $user = null ): bool {
        return (bool) \Fabrement\Modules\Mcp\McpCapabilityCeiling::uncapped(
            static fn(): bool => $user === null ? current_user_can( self::CONNECT ) : user_can( $user, self::CONNECT )
        );
    }
}
