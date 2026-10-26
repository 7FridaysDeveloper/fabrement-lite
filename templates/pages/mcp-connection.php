<?php
/**
 * MCP Connection — an external agent reaching in to this site over MCP.
 * Local navigation and WordPress connection controls, without a Fabrement account.
 *
 * @var \Fabrement\Page\McpConnectionPage $page
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="wrap fabrement-page fabrement-cred">

    <?php require __DIR__ . '/../partials/lite-nav.php'; ?>
    <div class="fabrement-page__content">
        <?php
        // Shared page head (title + one-line description), the same one every tab opens with.
        $fabrement_head_title = __( 'MCP Connection', 'fabrement-lite' );
        $fabrement_head_sub   = __( 'Connect an AI agent — ChatGPT, Claude, Codex or Cursor — to manage this site over MCP.', 'fabrement-lite' );
        require __DIR__ . '/../partials/page-head.php';

        ?>

        <div class="fabrement-cred__panel fabrement-cred__panel--agents">
        <?php
        // ── Agent access (MCP) ─────────────────────────────────────────────
        $fabrement_mcp_connection = $page->consume_mcp_connection();
        // Button order: the one-click ways in first (Claude.ai, ChatGPT, Cursor), then Codex.
        $fabrement_oauth_clients_all  = \Fabrement\Modules\Mcp\McpConnectionConfig::OAUTH_CLIENTS;
        $fabrement_connection_clients = [
            'claude'  => $fabrement_oauth_clients_all['claude'],
            'chatgpt' => $fabrement_oauth_clients_all['chatgpt'],
            'cursor'  => 'Cursor',
            'codex'   => $fabrement_oauth_clients_all['codex'],
        ];
        $fabrement_requested_client = isset( $_GET['fabrement_oauth_client'] ) && is_string( $_GET['fabrement_oauth_client'] ) ? sanitize_key( wp_unslash( $_GET['fabrement_oauth_client'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; nothing is changed.
        $fabrement_mcp_client = $fabrement_mcp_connection['client'] ?? ( is_string( $fabrement_requested_client ) && isset( $fabrement_connection_clients[ $fabrement_requested_client ] ) ? $fabrement_requested_client : 'claude' );
        $fabrement_oauth_https = \Fabrement\Modules\Mcp\OAuth\Config::https();
        $fabrement_mcp_passwords = $page->get_mcp_passwords();
        $fabrement_oauth_grants = ( new \Fabrement\Modules\Mcp\OAuth\Store() )->ready()
            ? ( new \Fabrement\Modules\Mcp\OAuth\Store() )->grants() : [];

        /*
         * Each client has ONE recommended way in, shown as a numbered guide; everything else
         * waits on the Advanced tab so the first screen is one path, not a menu of them.
         * ChatGPT (OAuth only) and Cursor (application password only) have nothing else, so
         * they get no tabs at all.
         *
         * Advanced opens first when the recommended way cannot work or was not what was asked
         * for: no HTTPS (OAuth is out, the password is not), a password just generated (its
         * one-time setup lives there), or an explicit ?fabrement_setup=advanced.
         */
        $fabrement_has_advanced = in_array( $fabrement_mcp_client, [ 'claude', 'codex' ], true );
        $fabrement_requested_setup = isset( $_GET['fabrement_setup'] ) && is_string( $_GET['fabrement_setup'] ) ? sanitize_key( wp_unslash( $_GET['fabrement_setup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; nothing is changed.
        $fabrement_setup_view = $fabrement_has_advanced
            && ( null !== $fabrement_mcp_connection || ! $fabrement_oauth_https || 'advanced' === $fabrement_requested_setup ) ? 'advanced' : 'primary';
        $fabrement_setup_tabs = [
            'primary'  => __( 'Recommended', 'fabrement-lite' ),
            'advanced' => __( 'Advanced', 'fabrement-lite' ),
        ];

        $fabrement_mcp_copy_field = static function ( string $id, string $fabrement_label, string $value, bool $secret = false ): void {
            $copy_label = sprintf( /* translators: %s: what is copied, e.g. "command". */ __( 'Copy %s', 'fabrement-lite' ), $fabrement_label );
            ?>
            <div class="fabrement-cred__mcp-field">
                <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $fabrement_label ); ?></label>
                <div class="fabrement-cred__mcp-field-control">
                    <input id="<?php echo esc_attr( $id ); ?>" type="<?php echo $secret ? 'password' : 'text'; ?>" readonly
                        value="<?php echo esc_attr( $value ); ?>" autocomplete="off" spellcheck="false" data-lpignore="true" data-1p-ignore />
                    <button type="button" class="button fabrement-cred__mcp-copy" data-mcp-copy="<?php echo esc_attr( $id ); ?>"
                        title="<?php echo esc_attr( $copy_label ); ?>" aria-label="<?php echo esc_attr( $copy_label ); ?>">
                        <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
            <?php
        };
        ?>
        <div class="fabrement-cred__card fabrement-cred__mcp">
            <h2 class="fabrement-cred__title"><?php esc_html_e( 'Agent access (MCP)', 'fabrement-lite' ); ?></h2>
            <nav class="fabrement-cred__mcp-form fabrement-cred__oauth-clients" aria-label="<?php esc_attr_e( 'AI client', 'fabrement-lite' ); ?>">
                <?php foreach ( $fabrement_connection_clients as $id => $fabrement_label ) : ?>
                    <a class="fabrement-cred__mcp-client-button<?php echo $fabrement_mcp_client === $id ? ' is-active' : ''; ?>" data-client="<?php echo esc_attr( $id ); ?>"
                        <?php echo $fabrement_mcp_client === $id ? 'aria-current="page"' : ''; ?>
                        href="<?php echo esc_url( add_query_arg( [ 'page' => 'fabrement-credentials', 'fabrement_oauth_client' => $id ], admin_url( 'admin.php' ) ) . '#fabrement-connect' ); ?>"><?php echo esc_html( $fabrement_label ); ?></a>
                <?php endforeach; ?>
            </nav>
            <div id="fabrement-connect" class="fabrement-cred__connection-body">
                <?php if ( $fabrement_has_advanced ) : ?>
                    <div class="fabrement-cred__mcp-tabs fabrement-cred__setup-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Setup', 'fabrement-lite' ); ?>">
                        <?php foreach ( $fabrement_setup_tabs as $fabrement_view => $fabrement_label ) : ?>
                            <a class="fabrement-cred__mcp-tab" role="tab" id="fabrement-setup-tab-<?php echo esc_attr( $fabrement_view ); ?>" aria-controls="fabrement-setup-panel-<?php echo esc_attr( $fabrement_view ); ?>"
                                aria-selected="<?php echo $fabrement_setup_view === $fabrement_view ? 'true' : 'false'; ?>" data-setup-tab="<?php echo esc_attr( $fabrement_view ); ?>"
                                href="<?php echo esc_url( add_query_arg( [ 'page' => 'fabrement-credentials', 'fabrement_oauth_client' => $fabrement_mcp_client, 'fabrement_setup' => $fabrement_view ], admin_url( 'admin.php' ) ) . '#fabrement-connect' ); ?>"><?php echo esc_html( $fabrement_label ); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div id="fabrement-setup-panel-primary" data-setup-panel="primary"
                    <?php echo $fabrement_has_advanced ? 'role="tabpanel" aria-labelledby="fabrement-setup-tab-primary"' : ''; ?>
                    <?php echo 'primary' === $fabrement_setup_view ? '' : 'hidden'; ?>>
                    <?php
                    if ( 'cursor' === $fabrement_mcp_client ) {
                        require __DIR__ . '/partials/mcp-cursor.php';
                    } else {
                        require __DIR__ . '/partials/mcp-oauth.php';
                    }
                    ?>
                </div>

                <?php if ( $fabrement_has_advanced ) : ?>
                    <div id="fabrement-setup-panel-advanced" data-setup-panel="advanced" role="tabpanel" aria-labelledby="fabrement-setup-tab-advanced"
                        <?php echo 'advanced' === $fabrement_setup_view ? '' : 'hidden'; ?>>
                        <?php require __DIR__ . '/partials/mcp-oauth-advanced.php'; ?>
                        <?php
                        // The password is the same kind of fold as the OAuth one above it (mcp-oauth-advanced.php):
                        // closed, open only right after a password was generated. The list names the ways
                        // mcp-password-ready.php lays out for this client.
                        $fabrement_alt_password_ways = 'codex' === $fabrement_mcp_client
                            ? [ __( 'one of four ways', 'fabrement-lite' ), [ __( 'the Codex app form', 'fabrement-lite' ), __( 'a terminal command', 'fabrement-lite' ), __( 'the config.toml file', 'fabrement-lite' ), __( 'instructions for Codex', 'fabrement-lite' ) ] ]
                            : [ __( 'one of three ways', 'fabrement-lite' ), [ __( 'a terminal command', 'fabrement-lite' ), __( 'a .mcp.json file', 'fabrement-lite' ), __( 'instructions for Claude', 'fabrement-lite' ) ] ];
                        ?>
                        <details class="fabrement-cred__alt fabrement-cred__alt--fold" <?php echo empty( $fabrement_alt_password_open ) ? '' : 'open'; ?>>
                            <summary><h3 id="fabrement-alt-password-title" class="fabrement-cred__alt-title"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span><?php esc_html_e( 'Application password', 'fabrement-lite' ); ?></h3></summary>
                            <p class="fabrement-cred__alt-lead"><?php
                                // $fabrement_ui (bold names in a sentence) comes from mcp-oauth.php, rendered on the Recommended tab first.
                                $fabrement_ui(
                                    /* translators: 1: when to use it, 2: how long the password is shown, 3: how to treat it, 4: how many ways there are. */
                                    __( 'Use this %1$s: the site has no HTTPS (for example a local http:// site), OAuth is turned off, or the agent runs where no browser can open to approve access, such as a server. Instead of signing in, the client gets a WordPress application password. It is shown %2$s and works until you disconnect it, so %3$s. Generate it, then connect in %4$s:', 'fabrement-lite' ),
                                    __( 'when OAuth is not an option', 'fabrement-lite' ),
                                    __( 'once', 'fabrement-lite' ),
                                    __( 'keep it private', 'fabrement-lite' ),
                                    $fabrement_alt_password_ways[0]
                                );
                                ?></p>
                            <ul class="fabrement-cred__way-list">
                                <?php foreach ( $fabrement_alt_password_ways[1] as $fabrement_way ) : ?>
                                    <li><?php echo esc_html( $fabrement_way ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php require __DIR__ . '/partials/mcp-password-form.php'; ?>
                            <?php require __DIR__ . '/partials/mcp-password-ready.php'; ?>
                        </details>
                    </div>
                <?php endif; ?>
            </div>
            <span id="fabrement-oauth-copy-status" class="fabrement-cred__mcp-copy-status" role="status" aria-live="polite"></span>
            <span id="fabrement-mcp-copy-status" class="fabrement-cred__mcp-copy-status" role="status" aria-live="polite"></span>
        </div>
        <?php
        // A SIBLING of the setup card, not a section inside it. What is already
        // connected is not part of connecting something new: the client buttons above
        // switch which setup is shown, and the register below them never changed with
        // that — it lists every connection, of every client, whichever button is lit.
        // Nesting it said otherwise. The panel puts the two side by side (see
        // .fabrement-cred__panel--agents) and stacks them again when the screen is
        // too narrow for both.
        require __DIR__ . '/partials/mcp-access.php';
        ?>
        </div>
    </div>

</div>
