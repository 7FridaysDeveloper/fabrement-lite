<?php

namespace Fabrement\Modules\Mcp\OAuth;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Lifecycle\NoticeScreen;

final class Lifecycle {
    public static function install_site( ?Store $store = null ): void {
        $store = $store ?? new Store();
        try {
            $store->install();
            $store->initialize_options();
            if ( ! wp_next_scheduled( 'fabrement_mcp_oauth_cleanup' ) ) {
                $scheduled = wp_schedule_event( time() + 3600, 'daily', 'fabrement_mcp_oauth_cleanup', [], true );
                if ( is_wp_error( $scheduled ) || ! $scheduled ) {
                    throw new OAuthError( 'server_error', 'Could not schedule OAuth expiry cleanup.', 500 );
                }
            }
        } catch ( \Throwable $error ) {
            delete_option( Keys::OPTION_MCP_OAUTH_SCHEMA );
            throw $error;
        }
    }

    public static function notice(): void {
        if ( ! current_user_can( 'manage_options' ) || get_option( Keys::OPTION_DB_UPDATE_ERROR ) ) {
            return;
        }
        // The table is looked at only on the screens the notice shows on.
        if ( ! NoticeScreen::here() || ( new Store() )->ready() ) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__( 'Fabrement OAuth storage is not ready. Deactivate and reactivate Fabrement Lite to repair it. Existing plugin data is preserved.', 'fabrement-lite' )
        );
    }
}
