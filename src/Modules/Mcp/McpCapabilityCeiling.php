<?php

namespace Fabrement\Modules\Mcp;

/**
 * An MCP session holds an Editor's capabilities at most, whoever signed in: on the MCP route
 * every cap the site's Editor role does not hold reads as absent (other plugins' admin caps
 * included), and the administrator-only caps below never count, even if the role was given
 * them. A user keeps only what they hold themselves. wp-admin is untouched.
 *
 * unfiltered_html stays: Editors hold it too, and without it kses rewrites what is saved.
 */
final class McpCapabilityCeiling {

    /** Administrator-only caps on a single site, those core grants on the fly included. */
    public const WITHHELD = [
        'administrator',
        'manage_options',
        'switch_themes', 'edit_theme_options', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes', 'resume_themes',
        'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'resume_plugins',
        'list_users', 'create_users', 'edit_users', 'delete_users', 'promote_users', 'remove_users',
        'update_core', 'edit_files', 'import', 'export', 'edit_dashboard',
        'install_languages', 'view_site_health_checks', 'unfiltered_upload',
        'level_8', 'level_9', 'level_10',
        'wpseo_manage_options',
    ];

    /** @var callable|null Returns the Editor role's caps; null: the site's own role. */
    private static $editor_caps = null;

    /** True while a check reads the user's own caps past the ceiling. */
    private static bool $lifted = false;

    public static function register(): void {
        // Last, after core's own on-the-fly grants (priority 1) and any other plugin's.
        add_filter( 'user_has_cap', [ self::class, 'withhold' ], PHP_INT_MAX, 4 );
    }

    /**
     * Runs a capability check on the user's own caps, as in wp-admin: who may connect is an
     * Administrator, though the session itself holds an Editor's caps.
     *
     * @return mixed What $check returns.
     */
    public static function uncapped( callable $check ) {
        $was          = self::$lifted;
        self::$lifted = true;
        try {
            return $check();
        } finally {
            self::$lifted = $was;
        }
    }

    /**
     * @param array<string,bool> $allcaps
     * @param mixed              $caps
     * @param mixed              $args
     * @param mixed              $user
     * @return array<string,bool>
     */
    public static function withhold( $allcaps, $caps = [], $args = [], $user = null ) {
        // Only the signed-in user; read from the global, as asking for it could start the login again.
        $current = $GLOBALS['current_user'] ?? null;
        if ( self::$lifted || ! is_array( $allcaps ) || ! $user instanceof \WP_User || ! $current instanceof \WP_User || (int) $current->ID !== (int) $user->ID ) {
            return $allcaps;
        }
        // Yoast gives administrators its advanced fields only through its settings cap: keep those
        // fields, as an Editor has them.
        if ( ! empty( $allcaps['wpseo_manage_options'] ) ) {
            $allcaps['wpseo_edit_advanced_metadata'] = true;
        }
        $allowed = self::editor_caps();
        foreach ( $allcaps as $cap => $granted ) {
            if ( $granted && ( empty( $allowed[ $cap ] ) || in_array( $cap, self::WITHHELD, true ) ) ) {
                $allcaps[ $cap ] = false;
            }
        }
        return $allcaps;
    }

    /** @return array<string,bool> No Editor role on the site: next to nothing is allowed. */
    private static function editor_caps(): array {
        if ( self::$editor_caps !== null ) {
            return ( self::$editor_caps )();
        }
        $role = get_role( 'editor' );
        $caps = $role instanceof \WP_Role ? $role->capabilities : [];
        // Yoast gives the Editor role its advanced fields; kept even where its roles were not saved.
        $caps['wpseo_edit_advanced_metadata'] = true;
        return $caps;
    }
}
