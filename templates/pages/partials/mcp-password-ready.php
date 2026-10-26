<?php
/**
 * The setup built around a freshly issued application password: every way the client can
 * take it, each laid out as what to do beside the code to copy, nothing folded — the same
 * layout as the OAuth options (mcp-oauth-advanced.php). The connection is a one-time
 * transient, so this is the only time the password is on screen.
 *
 * Set by templates/pages/mcp-connection.php before it requires this partial; prints
 * nothing when no password was generated on the previous request.
 *
 * Claude and Codex only: Cursor's one-click setup is its own (mcp-cursor.php).
 *
 * @var string     $fabrement_mcp_client     The AI client the screen shows (claude, codex).
 * @var array|null $fabrement_mcp_connection The generated setup (McpConnectionConfig::connection()).
 * @var callable   $fabrement_mcp_copy_field Renders one labelled, copyable value.
 * @var callable   $fabrement_ui             A sentence with its names in bold (mcp-oauth.php, on the Recommended tab).
 */
defined( 'ABSPATH' ) || exit;

if ( null === $fabrement_mcp_connection ) {
    return;
}

$fabrement_code_box               = require __DIR__ . '/mcp-code-box.php';
$fabrement_mcp_is_codex           = $fabrement_mcp_client === 'codex';
$fabrement_mcp_has_fields         = isset( $fabrement_mcp_connection['url'], $fabrement_mcp_connection['authorization'] );
$fabrement_mcp_server_name        = $fabrement_mcp_connection['name'] ?? '';
$fabrement_mcp_cli_command        = isset( $fabrement_mcp_connection['cli_command'] ) && is_string( $fabrement_mcp_connection['cli_command'] ) ? $fabrement_mcp_connection['cli_command'] : '';
$fabrement_mcp_cli_command_posix  = isset( $fabrement_mcp_connection['cli_command_posix'] ) && is_string( $fabrement_mcp_connection['cli_command_posix'] ) ? $fabrement_mcp_connection['cli_command_posix'] : '';
$fabrement_mcp_file_config        = $fabrement_mcp_connection['file_config'] ?? '';
$fabrement_mcp_agent_instructions = $fabrement_mcp_connection['agent_instructions'] ?? '';
$fabrement_mcp_product            = $fabrement_mcp_is_codex ? 'Codex' : 'Claude Code';

// A way's left half: icon and title, then whatever explains it.
$fabrement_way_head = static function ( string $icon, string $title ): void {
    ?>
    <h4 class="fabrement-cred__way-title"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $title ); ?></h4>
    <?php
};
// The file holds the password in plain text; the OAuth file does not, so this is said here only.
$fabrement_file_notice = static function () use ( $fabrement_ui ): void {
    ?>
    <p class="fabrement-cred__private-notice"><span class="dashicons dashicons-lock" aria-hidden="true"></span><span><?php
        // One span: the notice is a flex row, and bare text around <strong> would split into columns.
        $fabrement_ui(
            /* translators: %s: what not to do with the file. */
            __( 'This file holds the password: %s, and do not share it.', 'fabrement-lite' ),
            __( 'do not commit it to Git', 'fabrement-lite' )
        );
        ?></span></p>
    <?php
};
?>
<div class="fabrement-cred__mcp-ready" data-mcp-ready="<?php echo esc_attr( $fabrement_mcp_client ); ?>">
    <p class="fabrement-cred__hint">
        <?php esc_html_e( 'Private access key. Available on this page only.', 'fabrement-lite' ); ?>
    </p>
    <p class="fabrement-cred__hint"><?php esc_html_e( 'Check existing MCP servers before adding this connection. If this name is already used for another site or OAuth, choose a different name or explicitly replace that connection.', 'fabrement-lite' ); ?></p>

    <?php if ( $fabrement_mcp_is_codex && $fabrement_mcp_has_fields ) : ?>
        <div class="fabrement-cred__way" data-mcp-panel="ui">
            <div class="fabrement-cred__way-text">
                <?php $fabrement_way_head( 'desktop', __( 'Codex app', 'fabrement-lite' ) ); ?>
                <p><?php
                    $fabrement_ui(
                        /* translators: 1: the transport to choose, 2: the header name, 3: the field to leave empty. */
                        __( 'In the Codex app, add a %1$s server with this name and URL. Add a Headers row: paste %2$s into the key field and the Header value into the value field. Leave the %3$s empty.', 'fabrement-lite' ),
                        'Streamable HTTP',
                        'Authorization',
                        __( 'Bearer token environment variable', 'fabrement-lite' )
                    );
                    ?></p>
                <a class="fabrement-cred__mcp-link" href="https://learn.chatgpt.com/docs/extend/mcp#configure-in-the-chatgpt-desktop-app" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official Codex MCP documentation', 'fabrement-lite' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
            </div>
            <div class="fabrement-cred__way-code">
                <div class="fabrement-cred__mcp-fields">
                    <?php
                    $fabrement_mcp_copy_field( 'fabrement-mcp-name', __( 'Name', 'fabrement-lite' ), $fabrement_mcp_server_name );
                    $fabrement_mcp_copy_field( 'fabrement-mcp-url', __( 'URL', 'fabrement-lite' ), $fabrement_mcp_connection['url'] );
                    $fabrement_mcp_copy_field( 'fabrement-mcp-header-key', __( 'Header key', 'fabrement-lite' ), 'Authorization' );
                    $fabrement_mcp_copy_field( 'fabrement-mcp-header-value', __( 'Header value', 'fabrement-lite' ), $fabrement_mcp_connection['authorization'], true );
                    ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ( $fabrement_mcp_cli_command !== '' ) : ?>
        <div class="fabrement-cred__way" data-mcp-panel="cli">
            <div class="fabrement-cred__way-text">
                <?php $fabrement_way_head( 'editor-code', __( 'In a terminal', 'fabrement-lite' ) ); ?>
                <?php if ( $fabrement_mcp_is_codex ) : ?>
                    <p><?php
                        $fabrement_ui(
                            /* translators: 1: Windows shell instruction, 2: what to do next. */
                            __( 'Windows: %1$s, then %2$s. Already running terminals and apps do not receive the new token automatically.', 'fabrement-lite' ),
                            __( 'Run in PowerShell', 'fabrement-lite' ),
                            __( 'start Codex from that same terminal', 'fabrement-lite' )
                        );
                        ?></p>
                    <?php if ( $fabrement_mcp_cli_command_posix !== '' ) : ?>
                        <p><?php
                            $fabrement_ui(
                                /* translators: 1: Linux/macOS shell instruction, 2: what to do next. */
                                __( 'Linux/macOS: %1$s, then %2$s. The exported token lasts only for that terminal session.', 'fabrement-lite' ),
                                __( 'Run in bash or zsh', 'fabrement-lite' ),
                                __( 'start Codex from that same terminal', 'fabrement-lite' )
                            );
                            ?></p>
                    <?php endif; ?>
                    <p><?php
                        $fabrement_ui(
                            /* translators: %s: the way that lasts. */
                            __( 'For the desktop app, or a setup that lasts, use the Codex app or %s instead.', 'fabrement-lite' ),
                            'config.toml'
                        );
                        ?></p>
                <?php else : ?>
                    <p><?php
                        $fabrement_ui(
                            /* translators: 1: where to run the command, 2: how widely the site is added, 3: a place Claude Code runs, 4: another place it runs. */
                            __( 'Run this command %1$s. The password is already inside it, so there is nothing to approve in the browser. The site is added to Claude Code for %2$s, in the %3$s and in your %4$s.', 'fabrement-lite' ),
                            __( 'in a terminal', 'fabrement-lite' ),
                            __( 'every project', 'fabrement-lite' ),
                            __( 'terminal', 'fabrement-lite' ),
                            __( 'IDE', 'fabrement-lite' )
                        );
                        ?></p>
                    <a class="fabrement-cred__mcp-link" href="https://code.claude.com/docs/en/mcp" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official Claude Code MCP documentation', 'fabrement-lite' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                <?php endif; ?>
            </div>
            <div class="fabrement-cred__way-code">
                <?php if ( $fabrement_mcp_is_codex ) : ?>
                    <?php $fabrement_code_box( 'fabrement-mcp-cli-windows', __( 'Windows PowerShell', 'fabrement-lite' ), $fabrement_mcp_cli_command, 4, __( 'Copy command', 'fabrement-lite' ) ); ?>
                    <?php if ( $fabrement_mcp_cli_command_posix !== '' ) : ?>
                        <?php $fabrement_code_box( 'fabrement-mcp-cli-posix', __( 'Linux/macOS', 'fabrement-lite' ), $fabrement_mcp_cli_command_posix, 3, __( 'Copy command', 'fabrement-lite' ) ); ?>
                    <?php endif; ?>
                <?php else : ?>
                    <?php $fabrement_code_box( 'fabrement-mcp-cli', __( 'Claude Code', 'fabrement-lite' ), $fabrement_mcp_cli_command, 3, __( 'Copy command', 'fabrement-lite' ) ); ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ( $fabrement_mcp_file_config !== '' ) : ?>
        <div class="fabrement-cred__way" data-mcp-panel="config">
            <div class="fabrement-cred__way-text">
                <?php $fabrement_way_head( 'media-code', $fabrement_mcp_is_codex ? '~/.codex/config.toml' : '.mcp.json' ); ?>
                <p><?php
                    if ( $fabrement_mcp_is_codex ) {
                        $fabrement_ui(
                            /* translators: 1: the file, 2: where it lives, 3: Codex surfaces that read it. */
                            __( 'Or add this to %1$s, %2$s, next to any servers already there. It lasts, and the %3$s all read it.', 'fabrement-lite' ),
                            'config.toml',
                            __( 'the Codex settings file in your home folder', 'fabrement-lite' ),
                            __( 'app, CLI and IDE extension', 'fabrement-lite' )
                        );
                    } else {
                        $fabrement_ui(
                            /* translators: 1: file name, 2: where the file goes, 3: how widely the site is added. */
                            __( 'Or save this as %1$s in the %2$s. Claude Code then connects to this site %3$s.', 'fabrement-lite' ),
                            '.mcp.json',
                            __( 'root of a project folder', 'fabrement-lite' ),
                            __( 'only in that folder', 'fabrement-lite' )
                        );
                    }
                    ?></p>
                <?php $fabrement_file_notice(); ?>
            </div>
            <div class="fabrement-cred__way-code">
                <?php $fabrement_code_box( 'fabrement-mcp-cmd', $fabrement_mcp_is_codex ? 'TOML' : 'JSON', $fabrement_mcp_file_config, $fabrement_mcp_is_codex ? 4 : 11, __( 'Copy configuration', 'fabrement-lite' ) ); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ( $fabrement_mcp_agent_instructions !== '' ) : ?>
        <div class="fabrement-cred__way fabrement-cred__way--instructions">
            <div class="fabrement-cred__way-text">
                <?php
                /* translators: %s: the AI client, e.g. Codex. */
                $fabrement_way_head( 'format-chat', sprintf( __( 'Let %s set it up', 'fabrement-lite' ), $fabrement_mcp_is_codex ? 'Codex' : 'Claude' ) );
                ?>
                <p><?php
                    $fabrement_ui(
                        /* translators: 1: where to paste the text, 2: what the client then does. */
                        __( 'Or paste this into %1$s and let it %2$s. It keeps your other MCP servers, asks before replacing one with the same name, and only checks the connection — it changes nothing on the site.', 'fabrement-lite' ),
                        $fabrement_mcp_product,
                        __( 'set up the connection itself', 'fabrement-lite' )
                    );
                    ?></p>
                <p class="fabrement-cred__private-notice" id="fabrement-mcp-private-notice"><span class="dashicons dashicons-lock" aria-hidden="true"></span><span><?php esc_html_e( 'Contains a private application password. Share only with your trusted AI client.', 'fabrement-lite' ); ?></span></p>
            </div>
            <div class="fabrement-cred__way-code">
                <?php $fabrement_code_box( 'fabrement-mcp-instructions', __( 'AI instructions', 'fabrement-lite' ), $fabrement_mcp_agent_instructions, 10, __( 'Copy instructions', 'fabrement-lite' ), false, 'fabrement-mcp-private-notice' ); ?>
            </div>
        </div>
    <?php endif; ?>
</div>
