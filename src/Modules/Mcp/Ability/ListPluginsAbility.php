<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Capabilities;

/**
 * Which plugins this site has, active or not. Read-only: activating and deactivating stay with a
 * person. An agent reads it to know which integrations (Yoast, CF7, ACF, WPML…) it can count on.
 */
final class ListPluginsAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/list-plugins';
    }

    public function label(): string {
        return 'List plugins';
    }

    public function description(): string {
        return 'Every plugin installed on this site: file, name, version, active, and the must-use ones. Read-only — '
            . 'activating is the site owner\'s. Read it before relying on a plugin (Yoast, CF7, ACF, WPML…), or to tell the user '
            . 'what is installed. A missing plugin is installed by the site admin.';
    }

    public function check_permission() {
        return current_user_can( Capabilities::ADMIN ) && current_user_can( 'activate_plugins' );
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        $plugin = [
            'type'       => 'object',
            'properties' => [
                'file'    => [ 'type' => 'string', 'description' => 'The plugin\'s identifier, "folder/main-file.php".' ],
                'name'    => [ 'type' => 'string' ],
                'version' => [ 'type' => 'string' ],
                'active'  => [ 'type' => 'boolean' ],
                'network' => [ 'type' => 'boolean', 'description' => 'Active for the whole network (multisite only).' ],
            ],
        ];
        return [
            'type'       => 'object',
            'required'   => [ 'plugins' ],
            'properties' => [
                'plugins' => [ 'type' => 'array', 'items' => $plugin ],
                'mustUse' => [ 'type' => 'array', 'items' => $plugin, 'description' => 'Must-use plugins: always active, cannot be deactivated.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $row = static fn( string $file, array $data, bool $active ): array => [
            'file'    => $file,
            'name'    => (string) ( $data['Name'] ?? $file ),
            'version' => (string) ( $data['Version'] ?? '' ),
            'active'  => $active,
            'network' => is_multisite() && is_plugin_active_for_network( $file ),
        ];

        $plugins = [];
        foreach ( get_plugins() as $file => $data ) {
            $plugins[] = $row( (string) $file, (array) $data, is_plugin_active( (string) $file ) );
        }
        $must_use = [];
        foreach ( get_mu_plugins() as $file => $data ) {
            $must_use[] = $row( (string) $file, (array) $data, true );
        }
        return [ 'plugins' => $plugins, 'mustUse' => $must_use ];
    }
}
