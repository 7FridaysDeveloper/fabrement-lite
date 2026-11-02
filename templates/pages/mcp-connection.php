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
        $fabrement_head_sub   = __( 'Connect an AI agent — ChatGPT, Claude or Codex — to work with the content of this site over MCP. The agent gets the permissions of an Editor at most, the site data it reads goes to its AI provider, and you can disconnect it here at any time.', 'fabrement-lite' );
        require __DIR__ . '/../partials/page-head.php';

        ?>

        <div class="fabrement-cred__panel fabrement-cred__panel--agents">
        <?php
        // ── Agent access (MCP) ─────────────────────────────────────────────
        // Button order: the one-click ways in first (Claude.ai, ChatGPT), then Codex.
        $fabrement_connection_clients = \Fabrement\Modules\Mcp\McpConnectionConfig::OAUTH_CLIENTS;
        $fabrement_requested_client = \Fabrement\Page\McpConnectionPage::view_parameter( 'fabrement_oauth_client' );
        $fabrement_mcp_client = isset( $fabrement_connection_clients[ $fabrement_requested_client ] ) ? $fabrement_requested_client : 'claude';
        $fabrement_oauth_https = \Fabrement\Modules\Mcp\OAuth\Config::https();
        // Every connection on the site: only an administrator opens this page.
        $fabrement_oauth_store = new \Fabrement\Modules\Mcp\OAuth\Store();
        $fabrement_oauth_grants = $fabrement_oauth_store->ready() ? $fabrement_oauth_store->grants() : [];

        /*
         * Each client has ONE recommended way in, shown as a numbered guide; everything else
         * waits on the Advanced tab so the first screen is one path, not a menu of them.
         * ChatGPT has nothing else, so it gets no tabs at all. Advanced opens first only on an
         * explicit ?fabrement_setup=advanced.
         */
        $fabrement_has_advanced = in_array( $fabrement_mcp_client, [ 'claude', 'codex' ], true );
        $fabrement_requested_setup = \Fabrement\Page\McpConnectionPage::view_parameter( 'fabrement_setup' );
        $fabrement_setup_view = $fabrement_has_advanced && 'advanced' === $fabrement_requested_setup ? 'advanced' : 'primary';
        $fabrement_setup_tabs = [
            'primary'  => __( 'Recommended', 'fabrement-lite' ),
            'advanced' => __( 'Advanced', 'fabrement-lite' ),
        ];

        $fabrement_mcp_copy_field = static function ( string $id, string $fabrement_label, string $value ): void {
            $copy_label = sprintf( /* translators: %s: what is copied, e.g. "command". */ __( 'Copy %s', 'fabrement-lite' ), $fabrement_label );
            ?>
            <div class="fabrement-cred__mcp-field">
                <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $fabrement_label ); ?></label>
                <div class="fabrement-cred__mcp-field-control">
                    <input id="<?php echo esc_attr( $id ); ?>" type="text" readonly
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
                <?php foreach ( $fabrement_connection_clients as $fabrement_client_id => $fabrement_label ) : ?>
                    <a class="fabrement-cred__mcp-client-button<?php echo $fabrement_mcp_client === $fabrement_client_id ? ' is-active' : ''; ?>" data-client="<?php echo esc_attr( $fabrement_client_id ); ?>"
                        <?php echo $fabrement_mcp_client === $fabrement_client_id ? 'aria-current="page"' : ''; ?>
                        href="<?php echo esc_url( add_query_arg( [ 'page' => 'fabrement-credentials', 'fabrement_oauth_client' => $fabrement_client_id ], admin_url( 'admin.php' ) ) . '#fabrement-connect' ); ?>"><?php echo esc_html( $fabrement_label ); ?></a>
                <?php endforeach; ?>
            </nav>
            <div id="fabrement-connect" class="fabrement-cred__connection-body">
                <?php if ( ! $fabrement_oauth_https ) : ?>
                    <p class="fabrement-cred__guide-note" role="status"><?php esc_html_e( 'Connecting an AI agent needs HTTPS. The addresses of this site do not use HTTPS, so no AI client can connect until they do.', 'fabrement-lite' ); ?></p>
                <?php endif; ?>
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
                    <?php require __DIR__ . '/partials/mcp-oauth.php'; ?>
                </div>

                <?php if ( $fabrement_has_advanced ) : ?>
                    <div id="fabrement-setup-panel-advanced" data-setup-panel="advanced" role="tabpanel" aria-labelledby="fabrement-setup-tab-advanced"
                        <?php echo 'advanced' === $fabrement_setup_view ? '' : 'hidden'; ?>>
                        <?php require __DIR__ . '/partials/mcp-oauth-advanced.php'; ?>
                    </div>
                <?php endif; ?>
            </div>
            <span id="fabrement-oauth-copy-status" class="fabrement-cred__mcp-copy-status" role="status" aria-live="polite"></span>
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
