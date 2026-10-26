<?php
/**
 * The button that issues an application password for the client on screen. What it
 * produces is shown once, by mcp-password-ready.php.
 *
 * Set by templates/pages/mcp-connection.php before it requires this partial.
 *
 * @var string     $fabrement_mcp_client     The AI client the screen shows (claude, codex; Cursor has its own button in mcp-cursor.php).
 * @var array|null $fabrement_mcp_connection The setup generated on the previous request, or null.
 */
defined( 'ABSPATH' ) || exit;
?>
<p class="fabrement-cred__hint"><?php esc_html_e( 'The AI client you give this password to acts on this site as you, and only through the Fabrement MCP endpoint: it can change content, but cannot install or change plugins, themes or users.', 'fabrement-lite' ); ?></p>
<form id="fabrement-mcp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fabrement-cred__mcp-form">
    <input type="hidden" name="action" value="fabrement_generate_mcp_command" />
    <?php wp_nonce_field( 'fabrement_generate_mcp_command' ); ?>
    <input type="hidden" name="fabrement_mcp_client" value="<?php echo esc_attr( $fabrement_mcp_client ); ?>">
    <button type="submit" class="button<?php echo null === $fabrement_mcp_connection ? ' button-primary' : ''; ?>">
        <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
        <?php echo esc_html( null === $fabrement_mcp_connection ? __( 'Generate application password', 'fabrement-lite' ) : __( 'Generate another password', 'fabrement-lite' ) ); ?>
    </button>
</form>
