<?php
/**
 * The Recommended setup for an OAuth client (Claude.ai, ChatGPT, Codex): a numbered guide
 * whose steps carry the controls they need. The other ways to connect the same client are
 * in mcp-oauth-advanced.php, on the Advanced tab.
 *
 * Set by templates/pages/mcp-connection.php before it requires this partial.
 *
 * @var string   $fabrement_mcp_client     The AI client the screen shows (chatgpt, codex, claude).
 * @var callable $fabrement_mcp_copy_field Renders one labelled, copyable value.
 */
defined( 'ABSPATH' ) || exit;

require __DIR__ . '/mcp-oauth-state.php';
$fabrement_step     = require __DIR__ . '/mcp-step.php';

/*
 * The step that needs OAuth: while OAuth is off it offers the button that turns it on (or
 * says why it cannot), and only once it is on does the step show what to paste. Rendered
 * in exactly one step per guide, so its ids stay unique.
 */
$fabrement_oauth_gate = static function () use ( $fabrement_oauth_setup, $fabrement_oauth_enabled, $fabrement_oauth_https, $fabrement_oauth_storage_ready, $fabrement_oauth_client, $fabrement_oauth_clients ): bool {
    if ( $fabrement_oauth_setup ) {
        return true;
    }
    if ( ! $fabrement_oauth_enabled && $fabrement_oauth_client !== '' ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fabrement-cred__mcp-form">
            <input type="hidden" name="action" value="fabrement_mcp_oauth_settings">
            <input type="hidden" name="oauth_command" value="connect">
            <?php wp_nonce_field( 'fabrement_mcp_oauth_settings' ); ?>
            <input type="hidden" name="oauth_client" value="<?php echo esc_attr( $fabrement_oauth_client ); ?>">
            <button type="submit" class="button button-primary" <?php echo $fabrement_oauth_https && $fabrement_oauth_storage_ready ? '' : 'disabled aria-describedby="fabrement-oauth-unavailable"'; ?>>
                <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                <?php echo esc_html( sprintf( /* translators: %s: AI client name, e.g. ChatGPT. */ __( 'Connect %s', 'fabrement-lite' ), $fabrement_oauth_clients[ $fabrement_oauth_client ] ) ); ?>
            </button>
        </form>
    <?php endif;
    if ( ! $fabrement_oauth_https ) : ?>
        <p id="fabrement-oauth-unavailable" class="fabrement-cred__hint" role="status"><?php esc_html_e( 'HTTPS required for OAuth.', 'fabrement-lite' ); ?></p>
    <?php elseif ( ! $fabrement_oauth_storage_ready ) : ?>
        <p id="fabrement-oauth-unavailable" class="fabrement-cred__hint" role="status"><?php esc_html_e( 'OAuth storage is not ready. Deactivate and reactivate Fabrement Lite to retry setup.', 'fabrement-lite' ); ?></p>
    <?php endif;
    return false;
};

/*
 * One guide sentence with the names of the client's own controls in bold, so the eye finds
 * "Security and login" or "Create" without reading the whole line. The sentence and each
 * name are translated and escaped separately; only the <strong> around a name is markup.
 */
$fabrement_ui = static function ( string $sentence, string ...$names ): void {
    $bold = array_map( static fn( string $name ): string => '<strong>' . esc_html( $name ) . '</strong>', $names );
    echo wp_kses( vsprintf( esc_html( $sentence ), $bold ), [ 'strong' => [] ] );
};

/* translators: the Create button of ChatGPT's New Plugin form. */
$fabrement_create = __( 'Create', 'fabrement-lite' );

// Name and URL to paste, for the client forms that do not fill themselves in.
$fabrement_oauth_fields = static function () use ( $fabrement_oauth_setup, $fabrement_mcp_copy_field ): void {
    ?>
    <div class="fabrement-cred__mcp-fields fabrement-cred__oauth-fields">
        <?php
        $fabrement_mcp_copy_field( 'fabrement-oauth-name', __( 'Server name', 'fabrement-lite' ), $fabrement_oauth_setup['name'] );
        $fabrement_mcp_copy_field( 'fabrement-oauth-url', __( 'MCP server URL', 'fabrement-lite' ), $fabrement_oauth_setup['url'] );
        ?>
    </div>
    <?php
};
?>
<section id="fabrement-oauth" class="fabrement-cred__guide" aria-labelledby="fabrement-<?php echo esc_attr( $fabrement_oauth_client ); ?>-guide-title">
    <?php if ( 'claude' === $fabrement_oauth_client ) : ?>
        <?php
        /*
         * No numbered guide here: the button opens Claude's own form already filled in, so
         * there is nothing to walk through step by step. A description, the button, and the
         * name and URL tucked away for the case where the form opens empty.
         */
        ?>
        <h3 id="fabrement-claude-guide-title" class="fabrement-cred__guide-title"><?php esc_html_e( 'Connect this site in Claude.ai (Web and Desktop connections)', 'fabrement-lite' ); ?></h3>
        <p class="fabrement-cred__guide-lead"><?php $fabrement_ui( /* translators: 1: Claude form name, 2: Claude button, 3: this site's consent page title, 4: its approve button. */ __( 'The button opens Claude\'s %1$s form with this site\'s name and URL already filled in. After you select %2$s, Claude takes you to this site\'s %3$s page by itself — if WordPress asks, sign in as an administrator first. Click %4$s and you are sent back to Claude, connected. Then enable the connector in a new chat.', 'fabrement-lite' ), __( 'Add custom connector', 'fabrement-lite' ), __( 'Continue', 'fabrement-lite' ), __( 'Connect to Fabrement', 'fabrement-lite' ), __( 'Allow access', 'fabrement-lite' ) ); ?></p>
        <?php if ( $fabrement_oauth_gate() ) : ?>
            <div class="fabrement-cred__mcp-actions">
                <a class="button button-primary" href="<?php echo esc_url( $fabrement_oauth_setup['settings_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Connect to Claude (1-click)', 'fabrement-lite' ); ?>
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                </a>
            </div>
            <p class="fabrement-cred__hint"><?php esc_html_e( 'The connector is saved to your Claude account, so once connected here it is also available in the Claude desktop and mobile apps — no separate setup.', 'fabrement-lite' ); ?></p>
            <details class="fabrement-cred__mcp-detail">
                <summary><?php esc_html_e( 'If the form opens empty, paste the server name and MCP server URL from this page.', 'fabrement-lite' ); ?></summary>
                <?php $fabrement_oauth_fields(); ?>
            </details>
        <?php endif; ?>
        <?php
        // Only where it applies: Claude.ai connects from its own servers, so a site they
        // cannot reach — no HTTPS, or a local hostname — needs the Advanced tab instead.
        $fabrement_site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! $fabrement_oauth_https || preg_match( '/(^localhost$|\.(local|test|localhost)$)/i', $fabrement_site_host ) ) :
            ?>
            <p class="fabrement-cred__guide-note"><?php esc_html_e( 'Claude.ai needs a public HTTPS server URL and cannot reach a local address (.local, .test, localhost) directly. For a local site, use Claude Code on the Advanced tab.', 'fabrement-lite' ); ?></p>
        <?php endif; ?>

    <?php elseif ( 'chatgpt' === $fabrement_oauth_client ) : ?>
        <h3 id="fabrement-chatgpt-guide-title" class="fabrement-cred__guide-title"><?php esc_html_e( 'Connect this site in ChatGPT', 'fabrement-lite' ); ?></h3>
        <ol class="fabrement-cred__steps">
            <?php
            $fabrement_step(
                __( 'Enable developer mode (optional)', 'fabrement-lite' ),
                static function () use ( $fabrement_ui ): void {
                    ?>
                    <p><?php $fabrement_ui( /* translators: 1: ChatGPT menu item, 2: ChatGPT page. */ __( 'Not every account needs this, and whether it does depends on your ChatGPT plan. If %1$s already shows on the %2$s page, skip this step.', 'fabrement-lite' ), __( 'Create app', 'fabrement-lite' ), __( 'Plugins', 'fabrement-lite' ) ); ?></p>
                    <p><?php $fabrement_ui( /* translators: 1: ChatGPT settings section, 2: ChatGPT setting name. */ __( 'Otherwise, in ChatGPT settings, go to %1$s and enable %2$s.', 'fabrement-lite' ), __( 'Security and login', 'fabrement-lite' ), __( 'Developer mode', 'fabrement-lite' ) ); ?></p>
                    <p><?php $fabrement_ui( /* translators: %s: path in ChatGPT settings. */ __( 'If your workspace uses the Apps layout, look under %s instead. Workspace permission may need to be granted by an administrator first.', 'fabrement-lite' ), __( 'Settings > Apps > Advanced settings', 'fabrement-lite' ) ); ?></p>
                    <div class="fabrement-cred__mcp-actions">
                        <a class="fabrement-cred__mcp-link" href="https://chatgpt.com/plugins#settings/Security" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open ChatGPT security settings', 'fabrement-lite' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                    </div>
                    <?php
                },
                [
                    'src'    => 'chatgpt-developer-mode.png',
                    'width'  => 1037,
                    'height' => 727,
                    'data'   => 'chatgpt-guide',
                    'alt'    => __( 'ChatGPT settings with Security and login selected and the Developer mode switch highlighted.', 'fabrement-lite' ),
                ]
            );
            $fabrement_step(
                __( 'Open ChatGPT Plugins', 'fabrement-lite' ),
                static function () use ( $fabrement_ui ): void {
                    ?>
                    <p><?php $fabrement_ui( /* translators: 1: ChatGPT page, 2: button, 3: search field, 4: menu item. */ __( 'Open the %1$s page. Click the %2$s button next to %3$s and choose %4$s.', 'fabrement-lite' ), __( 'Plugins', 'fabrement-lite' ), __( 'plus (+)', 'fabrement-lite' ), __( 'Search plugins', 'fabrement-lite' ), __( 'Create app', 'fabrement-lite' ) ); ?></p>
                    <p><?php $fabrement_ui( /* translators: 1: dialog name, 2: button. */ __( 'In the %1$s dialog, click %2$s at the bottom left.', 'fabrement-lite' ), __( 'New Plugin', 'fabrement-lite' ), __( 'Create MCP App', 'fabrement-lite' ) ); ?></p>
                    <div class="fabrement-cred__mcp-actions">
                        <a class="button button-primary" href="https://chatgpt.com/plugins" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Open ChatGPT Plugins', 'fabrement-lite' ); ?>
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                        </a>
                    </div>
                    <?php
                },
                [
                    [
                        'src'    => 'chatgpt-create-app.png',
                        'width'  => 1000,
                        'height' => 225,
                        'data'   => 'chatgpt-guide',
                        'alt'    => __( 'ChatGPT Plugins page with the plus menu next to Search plugins open and an arrow pointing to Create app.', 'fabrement-lite' ),
                    ],
                    [
                        'src'    => 'chatgpt-new-plugin-mcp-app.png',
                        'width'  => 675,
                        'height' => 395,
                        'data'   => 'chatgpt-guide',
                        'alt'    => __( 'ChatGPT New Plugin dialog with an arrow pointing to the Create MCP App button at the bottom left.', 'fabrement-lite' ),
                    ],
                ]
            );
            $fabrement_step(
                __( 'Fill in the New Plugin dialog', 'fabrement-lite' ),
                static function () use ( $fabrement_oauth_gate, $fabrement_oauth_fields, $fabrement_ui, $fabrement_create ): void {
                    ?>
                    <p><?php $fabrement_ui( /* translators: 1: form field, 2: option, 3: form section. */ __( 'Paste the server name into %1$s, choose %2$s under %3$s and paste the MCP server URL. Description and icon are optional.', 'fabrement-lite' ), __( 'Name', 'fabrement-lite' ), __( 'Server URL', 'fabrement-lite' ), __( 'Connection', 'fabrement-lite' ) ); ?></p>
                    <?php if ( $fabrement_oauth_gate() ) { $fabrement_oauth_fields(); } ?>
                    <p><?php $fabrement_ui( /* translators: 1: form field, 2: authentication option, 3: registration option. */ __( 'Leave %1$s on %2$s. If asked for registration, choose %3$s. Leave client ID and client secret empty.', 'fabrement-lite' ), __( 'Authentication', 'fabrement-lite' ), 'OAuth', __( 'Dynamic Client Registration', 'fabrement-lite' ) ); ?></p>
                    <p><?php $fabrement_ui( /* translators: 1: checkbox label, 2: button label. */ __( 'Read the access warning. Only if you trust this server, check %1$s and click %2$s.', 'fabrement-lite' ), __( 'I understand and want to continue', 'fabrement-lite' ), $fabrement_create ); ?></p>
                    <?php
                },
                [
                    'src'    => 'chatgpt-new-plugin-form.png',
                    'width'  => 658,
                    'height' => 842,
                    'data'   => 'chatgpt-guide',
                    'alt'    => __( 'ChatGPT New Plugin form with arrows pointing to the Name field, the Server URL field and the I understand and want to continue checkbox, above the Create button.', 'fabrement-lite' ),
                ]
            );
            $fabrement_step(
                __( 'Authorize in WordPress', 'fabrement-lite' ),
                static function () use ( $fabrement_ui, $fabrement_create ): void {
                    ?>
                    <p><?php $fabrement_ui( /* translators: 1: ChatGPT button, 2: this site's consent page title, 3: its approve button. */ __( 'After you click %1$s, ChatGPT takes you to this site\'s %2$s page by itself — if WordPress asks, sign in as an administrator first. Review the permissions and click %3$s: you are sent back to ChatGPT with the connection in place.', 'fabrement-lite' ), $fabrement_create, __( 'Connect to Fabrement', 'fabrement-lite' ), __( 'Allow access', 'fabrement-lite' ) ); ?></p>
                    <?php
                }
            );
            $fabrement_step(
                __( 'Start a new chat', 'fabrement-lite' ),
                static function () use ( $fabrement_ui ): void {
                    ?>
                    <p><?php $fabrement_ui( /* translators: %s: ChatGPT menu name. */ __( 'Select this connection from the %s menu. First ask ChatGPT to inspect the site without making changes.', 'fabrement-lite' ), __( 'tools', 'fabrement-lite' ) ); ?></p>
                    <?php
                }
            );
            ?>
        </ol>
        <p class="fabrement-cred__guide-note"><?php esc_html_e( 'A public HTTPS URL is required for this direct connection. ChatGPT cannot reach a local address (.local, .test, localhost) directly. Developer mode availability depends on your account and workspace policy.', 'fabrement-lite' ); ?></p>
        <p class="fabrement-cred__guide-note"><a href="https://developers.openai.com/plugins/deploy/connect-chatgpt" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official OpenAI setup guide', 'fabrement-lite' ); ?></a> &middot; <a href="https://help.openai.com/en/articles/12584461-developer-mode-and-mcp-apps-in-chatgpt" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Workspace permissions', 'fabrement-lite' ); ?></a></p>

    <?php elseif ( 'codex' === $fabrement_oauth_client ) : ?>
        <?php
        /*
         * The app, not the terminal: the Codex desktop app and the IDE extension add a server
         * through a form and sign in with a button, so nothing needs installing beyond Codex
         * itself. The CLI commands are on the Advanced tab (mcp-oauth-advanced.php), for those
         * who already live in one. The three share ~/.codex/config.toml, so a server added in
         * one is there in the others.
         */
        ?>
        <h3 id="fabrement-codex-guide-title" class="fabrement-cred__guide-title"><?php esc_html_e( 'Connect this site in Codex', 'fabrement-lite' ); ?></h3>
        <ol class="fabrement-cred__steps">
            <?php
            $fabrement_step(
                __( 'Open the Codex app', 'fabrement-lite' ),
                static function () use ( $fabrement_ui ): void {
                    ?>
                    <p><?php $fabrement_ui( /* translators: 1: desktop app name, 2: editor extension name. */ __( 'Use the %1$s or the %2$s extension for VS Code or Cursor. Codex on the web cannot connect to your own MCP server.', 'fabrement-lite' ), __( 'ChatGPT desktop app', 'fabrement-lite' ), 'Codex' ); ?></p>
                    <?php
                }
            );
            $fabrement_step(
                __( 'Add the server', 'fabrement-lite' ),
                static function () use ( $fabrement_oauth_gate, $fabrement_oauth_fields, $fabrement_ui ): void {
                    ?>
                    <p><?php $fabrement_ui( /* translators: 1: Codex settings page, 2: menu button, 3: menu item. */ __( 'In Codex, open %1$s, click %2$s and choose %3$s.', 'fabrement-lite' ), __( 'Settings > Plugins', 'fabrement-lite' ), __( 'Add', 'fabrement-lite' ), __( 'Add MCP server', 'fabrement-lite' ) ); ?></p>
                    <p><?php $fabrement_ui( /* translators: 1: form field, 2: form field, 3: server type option, 4: form field, 5: button. */ __( 'Paste the server name into %1$s, switch %2$s to %3$s, paste the MCP server URL into %4$s and click %5$s. Leave the token and header fields empty.', 'fabrement-lite' ), __( 'Name', 'fabrement-lite' ), __( 'Type', 'fabrement-lite' ), 'Streamable HTTP', 'URL', __( 'Save', 'fabrement-lite' ) ); ?></p>
                    <?php if ( $fabrement_oauth_gate() ) { $fabrement_oauth_fields(); } ?>
                    <p class="fabrement-cred__hint"><?php esc_html_e( 'In the IDE extension, MCP servers are under the gear icon.', 'fabrement-lite' ); ?></p>
                    <?php
                },
                [
                    [
                        'src'    => 'codex-add-mcp-server.png',
                        'width'  => 843,
                        'height' => 192,
                        'data'   => 'codex-guide',
                        'alt'    => __( 'Codex Plugins settings with the Add menu open and an arrow pointing to Add MCP server.', 'fabrement-lite' ),
                    ],
                    [
                        'src'    => 'codex-custom-mcp-form.png',
                        'width'  => 865,
                        'height' => 940,
                        'data'   => 'codex-guide',
                        'alt'    => __( 'Codex Connect to a custom MCP form with arrows pointing to Name, Streamable HTTP and URL.', 'fabrement-lite' ),
                    ],
                ]
            );
            $fabrement_step(
                __( 'Sign in', 'fabrement-lite' ),
                static function () use ( $fabrement_ui ): void {
                    ?>
                    <p><?php $fabrement_ui( /* translators: 1: Codex tab, 2: list heading, 3: Codex button, 4: this site's consent page title, 5: its approve button. */ __( 'The server now shows on the %1$s tab under %2$s. Click %3$s next to it. Your browser opens this site\'s %4$s page — if WordPress asks, sign in as an administrator first. Review the permissions and click %5$s: you are sent back to Codex with the connection in place.', 'fabrement-lite' ), 'MCPs', __( 'Servers', 'fabrement-lite' ), __( 'Authenticate', 'fabrement-lite' ), __( 'Connect to Fabrement', 'fabrement-lite' ), __( 'Allow access', 'fabrement-lite' ) ); ?></p>
                    <?php
                },
                [
                    'src'    => 'codex-authenticate.png',
                    'width'  => 783,
                    'height' => 294,
                    'data'   => 'codex-guide',
                    'alt'    => __( 'Codex MCPs tab with this site in the Servers list and an arrow pointing to its Authenticate button.', 'fabrement-lite' ),
                ]
            );
            $fabrement_step(
                __( 'Start a new chat', 'fabrement-lite' ),
                static function (): void {
                    ?>
                    <p><?php esc_html_e( 'Codex loads its tools when a chat starts. First ask it to inspect the site without making changes.', 'fabrement-lite' ); ?></p>
                    <?php
                }
            );
            ?>
        </ol>
        <p class="fabrement-cred__guide-note"><?php esc_html_e( 'Codex connects from your computer, so a local site works too if your computer trusts its HTTPS certificate. Terminal commands, a config file, AI instructions and application passwords are on the Advanced tab.', 'fabrement-lite' ); ?></p>
    <?php endif; ?>
</section>
