<?php

namespace Fabrement\Page;

use Fabrement\Framework\Keys;
use Fabrement\Capabilities;
use Fabrement\Modules\Mcp\McpConnectionConfig;
use Fabrement\Modules\Mcp\McpPasswordScope;

/**
 * Lite's main screen: connect an external agent using WordPress authentication.
 */
final class McpConnectionPage extends AbstractAdminPage {

    public function get_capability(): string {
        return Capabilities::ADMIN;
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
        add_action( 'admin_post_fabrement_generate_mcp_command', [ $this, 'handle_generate_mcp_command' ] );
        add_action( 'admin_post_fabrement_revoke_mcp_password', [ $this, 'handle_revoke_mcp_password' ] );

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
     * Creates a WordPress APPLICATION PASSWORD for the current user and builds the
     * selected client's setup text. Server-side, so the Basic token is
     * built from the REAL user_login, the URL from rest_url() (https/subdir-safe),
     * and the plaintext password appears exactly once — in this command (transient,
     * 5 minutes, per-user), same policy as core's own app-password UI.
     */
    public function handle_generate_mcp_command(): void {
        if ( ! current_user_can( $this->get_capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'fabrement-lite' ) );
        }
        check_admin_referer( 'fabrement_generate_mcp_command', '_wpnonce' );

        $client = ! isset( $_POST['fabrement_mcp_client'] ) ? 'claude'
            : ( is_string( $_POST['fabrement_mcp_client'] ) ? sanitize_key( wp_unslash( $_POST['fabrement_mcp_client'] ) ) : '' );
        if ( ! is_string( $client ) || ! isset( McpConnectionConfig::CLIENTS[ $client ] ) ) {
            wp_die( esc_html__( 'Choose a supported MCP client.', 'fabrement-lite' ) );
        }

        $user = wp_get_current_user();
        if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
            wp_die( esc_html__( 'Application passwords are not available for this user on this site.', 'fabrement-lite' ) );
        }

        $name = McpPasswordScope::PREFIX . ' (' . McpConnectionConfig::CLIENTS[ $client ] . ') - ' . wp_date( 'Y-m-d H:i' );

        // Note which passwords carry this exact name BEFORE issuing the new one. A repeat of this
        // POST — a double submit the page's own guard did not catch, a retried request — would
        // otherwise leave a second password behind, valid and unused, since only the last one
        // reaches the transient below. The name carries the minute it was issued in, so the repeat
        // replaces rather than accumulates.
        $superseded = self::passwords_named( $user->ID, $name );

        $created = \WP_Application_Passwords::create_new_application_password( $user->ID, [ 'name' => $name, 'app_id' => McpPasswordScope::APP_ID ] );
        if ( is_wp_error( $created ) ) {
            // Nothing was revoked: a create that fails must not cost the user the credential they
            // already had, which is the whole reason the old one is still there at this point.
            wp_die( esc_html( $created->get_error_message() ) );
        }

        // Only now — the replacement exists, so dropping what it supersedes leaves no gap.
        foreach ( $superseded as $uuid ) {
            \WP_Application_Passwords::delete_application_password( $user->ID, $uuid );
        }

        [ $plaintext ] = $created;
        $connection    = McpConnectionConfig::connection( $client, $this->mcp_endpoint_url(), $user->user_login, $plaintext, get_bloginfo( 'name' ) );

        set_transient( Keys::for_user( Keys::TRANSIENT_MCP_COMMAND, $user->ID ), $connection, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( add_query_arg( [ 'fabrement_mcp' => 'generated' ], $this->url() ) );
        exit;
    }

    /** Revokes one MCP application password (only ones this page issued — by app_id). */
    public function handle_revoke_mcp_password(): void {
        if ( ! current_user_can( $this->get_capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'fabrement-lite' ) );
        }
        check_admin_referer( 'fabrement_revoke_mcp_password', '_wpnonce' );

        $uuid    = isset( $_GET['uuid'] ) && is_string( $_GET['uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['uuid'] ) ) : '';
        $user_id = get_current_user_id();
        $item    = '' !== $uuid ? \WP_Application_Passwords::get_user_application_password( $user_id, $uuid ) : null;

        if ( is_array( $item ) && McpPasswordScope::is_mcp_password( $item ) ) {
            \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
        }

        wp_safe_redirect( add_query_arg( [ 'fabrement_mcp' => 'revoked' ], $this->url() ) );
        exit;
    }

    /** @return array{client:string,content:string,name?:string,url?:string,authorization?:string,file_config?:string,agent_instructions?:string,cli_command?:string,cli_command_posix?:string,install_url?:string}|null One-time setup data. */
    public function consume_mcp_connection(): ?array {
        $key = Keys::for_user( Keys::TRANSIENT_MCP_COMMAND, get_current_user_id() );
        $cmd = get_transient( $key );
        delete_transient( $key );

        if ( ! is_array( $cmd ) || ! is_string( $cmd['client'] ?? null )
            || ! isset( McpConnectionConfig::CLIENTS[ $cmd['client'] ] )
            || ! is_string( $cmd['content'] ?? null ) || $cmd['content'] === '' ) {
            return null;
        }

        $connection = [ 'client' => $cmd['client'], 'content' => $cmd['content'] ];
        foreach ( [ 'name', 'agent_instructions', 'install_url' ] as $field ) {
            if ( is_string( $cmd[ $field ] ?? null ) && $cmd[ $field ] !== '' ) {
                $connection[ $field ] = $cmd[ $field ];
            }
        }
        if ( is_string( $cmd['url'] ?? null ) && is_string( $cmd['authorization'] ?? null ) ) {
            $connection['url'] = $cmd['url'];
            $connection['authorization'] = $cmd['authorization'];
        }
        if ( is_string( $cmd['file_config'] ?? null ) && $cmd['file_config'] !== '' ) {
            $connection['file_config'] = $cmd['file_config'];
        }
        if ( is_string( $cmd['cli_command'] ?? null ) && $cmd['cli_command'] !== '' ) {
            $connection['cli_command'] = $cmd['cli_command'];
        }
        if ( is_string( $cmd['cli_command_posix'] ?? null ) && $cmd['cli_command_posix'] !== '' ) {
            $connection['cli_command_posix'] = $cmd['cli_command_posix'];
        }

        return $connection;
    }

    /**
     * The uuids of this user's MCP passwords carrying exactly this name, and only those.
     *
     * Exact equality, not the prefix: the name includes the client and the minute it was issued
     * in, so this can only ever match another attempt at the very same thing — never a password
     * the user made elsewhere, nor one this page issued for a different client or moment.
     *
     * @return array<int,string>
     */
    private static function passwords_named( int $user_id, string $name ): array {
        $uuids = [];
        foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
            $existing = (string) ( $item['name'] ?? '' );
            $uuid     = (string) ( $item['uuid'] ?? '' );
            if ( '' !== $uuid && $existing === $name && McpPasswordScope::is_mcp_password( $item ) ) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    /**
     * The current user's MCP application passwords (issued by this page), for the revoke list.
     *
     * `name` is the stored one — WordPress shows it on the Users screen, so it is never
     * rewritten. `label` is that name without the creation stamp this page appends to it:
     * the list shows the stamp under the title, beside the other dates, rather than inside
     * a heading it makes twice as long.
     * A renamed password has no stamp to drop, and its label is its name.
     *
     * @return list<array{uuid: string, name: string, label: string, created: int, last_used: int|null}>
     */
    public function get_mcp_passwords(): array {
        $out = [];
        foreach ( \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) as $item ) {
            if ( ! McpPasswordScope::is_mcp_password( $item ) ) {
                continue;
            }
            $out[] = [
                'uuid'      => (string) $item['uuid'],
                'name'      => (string) $item['name'],
                'label'     => self::password_label( (string) $item['name'] ),
                'created'   => (int) $item['created'],
                'last_used' => isset( $item['last_used'] ) ? (int) $item['last_used'] : null,
            ];
        }

        return $out;
    }

    /** The stored name without the ` - Y-m-d H:i` stamp handle_generate_mcp_command() appends. */
    public static function password_label( string $name ): string {
        $label = preg_replace( '/\s+-\s+\d{4}-\d{2}-\d{2} \d{2}:\d{2}\z/D', '', $name );

        return '' === (string) $label ? $name : (string) $label;
    }

    /** The MCP connection endpoint URL (for display). */
    public function mcp_endpoint_url(): string {
        return rest_url( 'fabrement/v1/mcp' );
    }

    /** Admin URL of this screen. */
    public function url(): string {
        return add_query_arg( [ 'page' => $this->get_menu_slug() ], admin_url( 'admin.php' ) );
    }

    protected function matches_admin_hook( string $hook ): bool {
        return $hook === self::hook_top_level( 'fabrement' )
            || $hook === self::hook_submenu( 'fabrement', 'fabrement-credentials' );
    }
}
