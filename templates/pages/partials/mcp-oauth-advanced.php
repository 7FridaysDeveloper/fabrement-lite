<?php
/**
 * The Advanced tab's OAuth options: the ways to connect the same client that are not its
 * Recommended guide, folded into one accordion with each way laid out as text beside its
 * code. Claude Code's command and .mcp.json for Claude; the CLI commands, config.toml and
 * AI instructions for Codex.
 * The application password, which every such client also has, follows in the template.
 *
 * Set by templates/pages/mcp-connection.php before it requires this partial.
 *
 * @var string $fabrement_mcp_client The AI client the screen shows (codex, claude).
 */
defined( 'ABSPATH' ) || exit;

require __DIR__ . '/mcp-oauth-state.php';
$fabrement_code_box = require __DIR__ . '/mcp-code-box.php';

// OAuth off: point at the one place it is turned on, instead of a second Connect button.
$fabrement_oauth_off_hint = static function () use ( $fabrement_oauth_https ): void {
    ?>
    <p class="fabrement-cred__hint">
        <?php
        echo esc_html( $fabrement_oauth_https
            ? __( 'Turn on OAuth under Connection access first.', 'fabrement-lite' )
            : __( 'HTTPS required for OAuth. Use an application password below.', 'fabrement-lite' ) );
        ?>
    </p>
    <?php
};
?>
<?php if ( 'claude' === $fabrement_oauth_client ) : ?>
    <?php
    /*
     * Claude's two ways are the same Claude Code with a different sign-in, so they fold into
     * an accordion, both closed. The password one opens only when its one-time setup was just
     * generated — it is shown once and must not be missed; the template reads the flag.
     */
    $fabrement_alt_password_open = ! empty( $fabrement_mcp_connection );
    ?>
    <details class="fabrement-cred__alt fabrement-cred__alt--fold">
        <summary><h3 id="fabrement-alt-oauth-title" class="fabrement-cred__alt-title"><span class="dashicons dashicons-shield" aria-hidden="true"></span><?php esc_html_e( 'Claude Code (OAuth)', 'fabrement-lite' ); ?></h3></summary>
        <?php
        // $fabrement_ui (bold names in a sentence) comes from mcp-oauth.php, rendered on the Recommended tab first.
        ?>
        <p class="fabrement-cred__alt-lead"><?php
            $fabrement_ui(
                /* translators: 1: how many ways there are, 2: a place Claude Code runs, 3: another place it runs, 4: a kind of site it reaches. */
                __( '%1$s to add this site to Claude Code; pick one. Either way it works in the %2$s and in your %3$s, and since it connects from your computer, it also reaches a %4$s with a trusted HTTPS certificate.', 'fabrement-lite' ),
                __( 'Two ways', 'fabrement-lite' ),
                __( 'terminal', 'fabrement-lite' ),
                __( 'IDE', 'fabrement-lite' ),
                __( 'local site', 'fabrement-lite' )
            );
            ?></p>
        <?php if ( $fabrement_oauth_setup ) : ?>
            <div class="fabrement-cred__way">
                <div class="fabrement-cred__way-text">
                    <h4 class="fabrement-cred__way-title"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span><?php esc_html_e( 'In a terminal', 'fabrement-lite' ); ?></h4>
                    <p><?php
                        $fabrement_ui(
                            /* translators: 1: where to run the commands, 2: the step after them, 3: how widely the site is added. */
                            __( 'Run both commands %1$s, then %2$s. The site is added to Claude Code for %3$s.', 'fabrement-lite' ),
                            __( 'in a terminal', 'fabrement-lite' ),
                            __( 'approve access in the browser', 'fabrement-lite' ),
                            __( 'every project', 'fabrement-lite' )
                        );
                        ?></p>
                    <a class="fabrement-cred__mcp-link" href="https://code.claude.com/docs/en/mcp" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official Claude Code MCP documentation', 'fabrement-lite' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                </div>
                <div class="fabrement-cred__way-code">
                    <?php $fabrement_code_box( 'fabrement-oauth-command', __( 'Claude Code', 'fabrement-lite' ), $fabrement_oauth_setup['cli_command'], 3, __( 'Copy command', 'fabrement-lite' ), true ); ?>
                </div>
            </div>
            <div class="fabrement-cred__way">
                <div class="fabrement-cred__way-text">
                    <h4 class="fabrement-cred__way-title"><span class="dashicons dashicons-media-code" aria-hidden="true"></span>.mcp.json</h4>
                    <p><?php
                        $fabrement_ui(
                            /* translators: 1: file name, 2: where the file goes, 3: how widely the site is added, 4: the Claude Code command to sign in. */
                            __( 'Save this as %1$s in the %2$s. Claude Code then connects to this site %3$s. The file holds no password, so it can be committed and shared with your team; each person signs in once with %4$s in Claude Code.', 'fabrement-lite' ),
                            '.mcp.json',
                            __( 'root of a project folder', 'fabrement-lite' ),
                            __( 'only in that folder', 'fabrement-lite' ),
                            '/mcp'
                        );
                        ?></p>
                </div>
                <div class="fabrement-cred__way-code">
                    <?php $fabrement_code_box( 'fabrement-oauth-config', 'JSON', $fabrement_oauth_setup['file_config'], 9, __( 'Copy configuration', 'fabrement-lite' ), true ); ?>
                </div>
            </div>
        <?php else : ?>
            <?php $fabrement_oauth_off_hint(); ?>
        <?php endif; ?>
    </details>
<?php elseif ( 'codex' === $fabrement_oauth_client ) : ?>
    <?php
    // The same fold as Claude's: closed, with the password one opening after it is generated.
    $fabrement_alt_password_open = ! empty( $fabrement_mcp_connection );
    ?>
    <details class="fabrement-cred__alt fabrement-cred__alt--fold">
        <summary><h3 id="fabrement-alt-oauth-title" class="fabrement-cred__alt-title"><span class="dashicons dashicons-shield" aria-hidden="true"></span><?php esc_html_e( 'Codex (OAuth)', 'fabrement-lite' ); ?></h3></summary>
        <?php
        // $fabrement_ui (bold names in a sentence) comes from mcp-oauth.php, rendered on the Recommended tab first.
        ?>
        <p class="fabrement-cred__alt-lead"><?php
            $fabrement_ui(
                /* translators: 1: how many ways there are, 2: Codex surfaces that share the setup, 3: a kind of site it reaches. */
                __( '%1$s to add this site to Codex without the app form; pick one. Either way the %2$s pick it up, and since Codex connects from your computer, it also reaches a %3$s with a trusted HTTPS certificate.', 'fabrement-lite' ),
                __( 'Three ways', 'fabrement-lite' ),
                __( 'app, CLI and IDE extension', 'fabrement-lite' ),
                __( 'local site', 'fabrement-lite' )
            );
            ?></p>
        <?php if ( $fabrement_oauth_setup ) : ?>
            <div class="fabrement-cred__way">
                <div class="fabrement-cred__way-text">
                    <h4 id="fabrement-alt-cli-title" class="fabrement-cred__way-title"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span><?php esc_html_e( 'In a terminal', 'fabrement-lite' ); ?></h4>
                    <p><?php
                        $fabrement_ui(
                            /* translators: 1: where to run the commands, 2: what the first one does, 3: what the second one does. */
                            __( 'Run both commands %1$s: the first %2$s, the second opens the browser to %3$s.', 'fabrement-lite' ),
                            __( 'in a terminal', 'fabrement-lite' ),
                            __( 'adds this site to Codex', 'fabrement-lite' ),
                            __( 'approve access', 'fabrement-lite' )
                        );
                        ?></p>
                    <a class="fabrement-cred__mcp-link" href="https://learn.chatgpt.com/docs/extend/mcp?surface=cli" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official Codex MCP documentation', 'fabrement-lite' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                </div>
                <div class="fabrement-cred__way-code">
                    <?php $fabrement_code_box( 'fabrement-oauth-command', __( 'Codex CLI', 'fabrement-lite' ), $fabrement_oauth_setup['cli_command'], 3, __( 'Copy command', 'fabrement-lite' ), true ); ?>
                </div>
            </div>
            <div class="fabrement-cred__way">
                <div class="fabrement-cred__way-text">
                    <h4 class="fabrement-cred__way-title"><span class="dashicons dashicons-media-code" aria-hidden="true"></span>~/.codex/config.toml</h4>
                    <p><?php
                        $fabrement_ui(
                            /* translators: 1: the file, 2: where it lives, 3: the login command, e.g. codex mcp login fabrement-site. */
                            __( 'Or add this to %1$s, %2$s, next to any servers already there. Then sign in with %3$s.', 'fabrement-lite' ),
                            'config.toml',
                            __( 'the Codex settings file in your home folder', 'fabrement-lite' ),
                            'codex mcp login ' . $fabrement_oauth_setup['name']
                        );
                        ?></p>
                </div>
                <div class="fabrement-cred__way-code">
                    <?php $fabrement_code_box( 'fabrement-oauth-config', 'TOML', $fabrement_oauth_setup['file_config'], 3, __( 'Copy configuration', 'fabrement-lite' ), true ); ?>
                </div>
            </div>
            <div class="fabrement-cred__way">
                <div class="fabrement-cred__way-text">
                    <h4 class="fabrement-cred__way-title"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><?php esc_html_e( 'Let Codex set it up', 'fabrement-lite' ); ?></h4>
                    <p><?php
                        $fabrement_ui(
                            /* translators: 1: where to paste the text, 2: what Codex then does. */
                            __( 'Or paste this into %1$s and let it %2$s. It keeps your other MCP servers, asks before replacing one with the same name, and only checks the connection — it changes nothing on the site.', 'fabrement-lite' ),
                            __( 'Codex', 'fabrement-lite' ),
                            __( 'set up the connection itself', 'fabrement-lite' )
                        );
                        ?></p>
                </div>
                <div class="fabrement-cred__way-code">
                    <?php $fabrement_code_box( 'fabrement-oauth-instructions', __( 'AI instructions', 'fabrement-lite' ), $fabrement_oauth_setup['agent_instructions'], 10, __( 'Copy instructions', 'fabrement-lite' ) ); ?>
                </div>
            </div>
        <?php else : ?>
            <?php $fabrement_oauth_off_hint(); ?>
        <?php endif; ?>
    </details>
<?php endif; ?>
