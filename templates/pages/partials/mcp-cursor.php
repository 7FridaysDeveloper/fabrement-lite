<?php
/**
 * Cursor's setup: it has one way in, an application password, so there is no Advanced tab.
 *
 * No numbered guide either. It is two clicks — generate the application password here, then Add to
 * Cursor, which opens Cursor with the server ready to install — so the screen is a
 * description and the next button; once the password exists, the first step stays in
 * view as done, beside Add to Cursor. The config file and the AI
 * instructions are for when the link does not open Cursor, and wait in an accordion.
 *
 * Set by templates/pages/mcp-connection.php before it requires this partial.
 *
 * @var array|null $fabrement_mcp_connection The setup generated on the previous request, or null.
 */
defined( 'ABSPATH' ) || exit;

$fabrement_code_box = require __DIR__ . '/mcp-code-box.php';

// A sentence with its key words in bold, as in mcp-oauth.php (not rendered for Cursor).
$fabrement_ui = static function ( string $sentence, string ...$names ): void {
    $bold = array_map( static fn( string $name ): string => '<strong>' . esc_html( $name ) . '</strong>', $names );
    echo wp_kses( vsprintf( esc_html( $sentence ), $bold ), [ 'strong' => [] ] );
};
?>
<section class="fabrement-cred__guide" aria-labelledby="fabrement-cursor-guide-title">
    <h3 id="fabrement-cursor-guide-title" class="fabrement-cred__guide-title"><?php esc_html_e( 'Connect this site in Cursor', 'fabrement-lite' ); ?></h3>
    <p class="fabrement-cred__guide-lead"><?php esc_html_e( 'Two clicks: generate a private application password for Cursor, then add the server — Cursor opens and asks you to confirm. Start a new chat afterwards so Cursor loads the tools. The password works until you disconnect it.', 'fabrement-lite' ); ?></p>

    <?php if ( null === $fabrement_mcp_connection ) : ?>
        <form id="fabrement-mcp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fabrement-cred__mcp-form">
            <input type="hidden" name="action" value="fabrement_generate_mcp_command" />
            <?php wp_nonce_field( 'fabrement_generate_mcp_command' ); ?>
            <input type="hidden" name="fabrement_mcp_client" value="cursor">
            <button type="submit" class="button button-primary">
                <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                <?php esc_html_e( 'Generate application password', 'fabrement-lite' ); ?>
            </button>
        </form>
    <?php else : ?>
        <div class="fabrement-cred__mcp-ready" data-mcp-ready="cursor">
            <div class="fabrement-cred__mcp-actions">
                <?php
                // The first click, done: where its button stood, so Add to Cursor reads as the next
                // step. A status, not a disabled button — that would read as "unavailable".
                ?>
                <span class="button fabrement-cred__mcp-done" role="status">
                    <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                    <?php esc_html_e( 'Application password generated', 'fabrement-lite' ); ?>
                </span>
                <a class="button button-primary" id="fabrement-mcp-install"
                    href="<?php echo esc_url( $fabrement_mcp_connection['install_url'] ?? '', [ 'cursor' ] ); ?>">
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                    <?php esc_html_e( 'Add to Cursor', 'fabrement-lite' ); ?>
                </a>
            </div>
            <p class="fabrement-cred__guide-note"><?php esc_html_e( 'The key is on this page only — leave it and a new one has to be created.', 'fabrement-lite' ); ?></p>

            <?php
            // The same fold and two-column ways as Claude's Advanced tab (mcp-oauth-advanced.php).
            ?>
            <details class="fabrement-cred__alt fabrement-cred__alt--fold fabrement-cred__cursor-manual">
                <summary><h4 class="fabrement-cred__alt-title"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span><?php esc_html_e( 'If Cursor does not open, add the server by hand', 'fabrement-lite' ); ?></h4></summary>
                <p class="fabrement-cred__alt-lead"><?php
                    $fabrement_ui(
                        /* translators: %s: how many ways there are. */
                        __( '%s; pick one. Check existing MCP servers before adding this connection. If this name is already used for another site or OAuth, choose a different name or explicitly replace that connection.', 'fabrement-lite' ),
                        __( 'Two ways', 'fabrement-lite' )
                    );
                    ?></p>
                <?php if ( ( $fabrement_mcp_connection['file_config'] ?? '' ) !== '' ) : ?>
                    <div class="fabrement-cred__way">
                        <div class="fabrement-cred__way-text">
                            <h5 class="fabrement-cred__way-title"><span class="dashicons dashicons-media-code" aria-hidden="true"></span>~/.cursor/mcp.json</h5>
                            <p><?php
                                $fabrement_ui(
                                    /* translators: 1: the file, 2: where it lives, 3: what to do afterwards. */
                                    __( 'Add this server to %1$s, %2$s. If the file already has other servers, add this entry next to them instead of replacing the file. Then %3$s so Cursor loads the tools.', 'fabrement-lite' ),
                                    'mcp.json',
                                    __( 'the Cursor settings file in your home folder', 'fabrement-lite' ),
                                    __( 'start a new chat', 'fabrement-lite' )
                                );
                                ?></p>
                            <p class="fabrement-cred__private-notice"><span class="dashicons dashicons-lock" aria-hidden="true"></span><span><?php
                                $fabrement_ui(
                                    /* translators: %s: what not to do with the file. */
                                    __( 'This file holds the password: %s, and do not share it.', 'fabrement-lite' ),
                                    __( 'do not commit it to Git', 'fabrement-lite' )
                                );
                                ?></span></p>
                        </div>
                        <div class="fabrement-cred__way-code">
                            <?php $fabrement_code_box( 'fabrement-mcp-cmd', 'JSON', $fabrement_mcp_connection['file_config'], 11, __( 'Copy configuration', 'fabrement-lite' ) ); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( ( $fabrement_mcp_connection['agent_instructions'] ?? '' ) !== '' ) : ?>
                    <div class="fabrement-cred__way">
                        <div class="fabrement-cred__way-text">
                            <h5 class="fabrement-cred__way-title"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><?php esc_html_e( 'Let Cursor set it up', 'fabrement-lite' ); ?></h5>
                            <p><?php
                                $fabrement_ui(
                                    /* translators: 1: where to paste the text, 2: what Cursor then does. */
                                    __( 'Or paste this into %1$s and let it %2$s. It keeps your other MCP servers, asks before replacing one with the same name, and only checks the connection — it changes nothing on the site.', 'fabrement-lite' ),
                                    __( 'Cursor chat', 'fabrement-lite' ),
                                    __( 'set up the connection itself', 'fabrement-lite' )
                                );
                                ?></p>
                            <p class="fabrement-cred__private-notice" id="fabrement-mcp-private-notice"><span class="dashicons dashicons-lock" aria-hidden="true"></span><span><?php esc_html_e( 'Contains a private application password. Share only with your trusted AI client.', 'fabrement-lite' ); ?></span></p>
                        </div>
                        <div class="fabrement-cred__way-code">
                            <?php $fabrement_code_box( 'fabrement-mcp-instructions', __( 'AI instructions', 'fabrement-lite' ), $fabrement_mcp_connection['agent_instructions'], 10, __( 'Copy instructions', 'fabrement-lite' ), false, 'fabrement-mcp-private-notice' ); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </details>
        </div>
    <?php endif; ?>
</section>
