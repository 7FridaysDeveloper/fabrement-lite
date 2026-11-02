<?php

namespace Fabrement\Page;

defined( 'ABSPATH' ) || exit;

use Fabrement\Capabilities;

/**
 * Lite's main screen: connect an external agent over OAuth.
 */
final class McpConnectionPage extends AbstractAdminPage {

    public function get_capability(): string {
        // Whoever may connect an agent over OAuth.
        return Capabilities::CONNECT;
    }

    public function get_page_title(): string {
        return __( 'Fabrement Lite — MCP Connection', 'fabrement-lite' );
    }

    public function get_menu_slug(): string {
        return 'fabrement';
    }

    public function get_menu_parent_slug(): ?string {
        return null;
    }

    protected function template_name(): string {
        return 'mcp-connection';
    }

    public function register_menu(): void {
        add_menu_page( $this->get_page_title(), 'Fabrement', $this->get_capability(), 'fabrement', [ $this, 'render' ], \Fabrement\Page\AdminMenuIcon::data_uri(), 58 );
        add_submenu_page( 'fabrement', $this->get_page_title(), __( 'MCP Connection', 'fabrement-lite' ), $this->get_capability(), 'fabrement-credentials', [ $this, 'render' ] );
        remove_submenu_page( 'fabrement', 'fabrement' );
    }

    public function register_hooks(): void {
        // Local navigation and connection controls; no cloud UI bundle.
        $this->register_admin_assets(
            function (): void {
                $this->enqueue_tab_layout();
                $this->enqueue_shared_topnav();
                $this->enqueue_credentials_card();
                $this->enqueue_admin_style( 'fabrement-mcp-connection', 'mcp-connection.css', [ 'fabrement-credentials-card' ] );
                if ( $this->enqueue_admin_script( 'fabrement-mcp-connection', 'mcp-connection.js' ) ) {
                    wp_localize_script(
                        'fabrement-mcp-connection',
                        'fabrementMcpConnection',
                        [
                            'i18n' => [
                                'copied'     => __( 'Copied', 'fabrement-lite' ),
                                'copyFailed' => __( 'Copy failed', 'fabrement-lite' ),
                            ],
                        ]
                    );
                }

            }
        );
    }

    /**
     * A query parameter that only chooses what this screen shows (a client's guide, a tab), as a key.
     * Read-only: nothing is changed by it, so no nonce; templates whitelist the value.
     */
    public static function view_parameter( string $name ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; nothing is changed.
        return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_key( wp_unslash( $_GET[ $name ] ) ) : '';
    }

    protected function matches_admin_hook( string $hook ): bool {
        return $hook === self::hook_top_level( 'fabrement' )
            || $hook === self::hook_submenu( 'fabrement', 'fabrement-credentials' );
    }
}
