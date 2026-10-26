<?php
/**
 * Set by templates/pages/mcp-connection.php before it requires this partial.
 *
 * @var array $fabrement_oauth_grants  Every OAuth grant on the site (Store::grants()).
 * @var array $fabrement_mcp_passwords This user's MCP application passwords.
 */
defined( 'ABSPATH' ) || exit;

// A card of its own beside the setup card, not a section inside it: the switch controls
// site-wide access, and the list is every OAuth connection on the site (with who authorized it)
// plus this user's application passwords.
$fabrement_oauth_storage_ready = ( new \Fabrement\Modules\Mcp\OAuth\Store() )->ready();
$fabrement_oauth_allowed = $fabrement_oauth_storage_ready && (bool) get_option( \Fabrement\Framework\Keys::OPTION_MCP_OAUTH_ENABLED, false );
$fabrement_oauth_https = \Fabrement\Modules\Mcp\OAuth\Config::https();
$fabrement_connection_count = count( $fabrement_oauth_grants ) + count( $fabrement_mcp_passwords );
// Numeric, not the site's date_format: that one spells the month out ("September 22,
// 2026") and a date twice the width of its own label wraps every row in the side
// column. The creation stamp keeps the time it carried while it was part of the name —
// two passwords for the same client are made on the same day and the date alone would
// not tell them apart.
$fabrement_date = 'Y-m-d';
$fabrement_stamp = 'Y-m-d H:i';
?>
<aside id="fabrement-access" class="fabrement-cred__card fabrement-cred__access" aria-labelledby="fabrement-access-title">
    <h2 id="fabrement-access-title" class="fabrement-cred__title"><?php esc_html_e( 'Connection access', 'fabrement-lite' ); ?></h2>
    <?php // Answers the question the card is most often misread as: this is not the list for the client selected above. ?>
    <p class="fabrement-cred__access-lead"><?php esc_html_e( 'Every OAuth connection on this site and your application passwords, whichever client they were set up from.', 'fabrement-lite' ); ?></p>
    <div class="fabrement-cred__access-setting">
        <span class="dashicons dashicons-shield fabrement-cred__access-icon" aria-hidden="true"></span>
        <div class="fabrement-cred__access-info">
            <strong id="fabrement-access-oauth-label"><?php esc_html_e( 'OAuth', 'fabrement-lite' ); ?></strong>
            <span id="fabrement-access-scope" class="fabrement-cred__access-meta"><?php esc_html_e( 'Administrators', 'fabrement-lite' ); ?></span>
        </div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fabrement-cred__access-toggle">
            <input type="hidden" name="action" value="fabrement_mcp_oauth_settings">
            <input type="hidden" name="oauth_command" value="<?php echo $fabrement_oauth_allowed ? 'disable' : 'enable'; ?>">
            <?php wp_nonce_field( 'fabrement_mcp_oauth_settings' ); ?>
            <span class="fabrement-cred__access-state" aria-hidden="true"><?php echo esc_html( $fabrement_oauth_allowed ? __( 'On', 'fabrement-lite' ) : __( 'Off', 'fabrement-lite' ) ); ?></span>
            <button type="submit" class="fabrement-cred__access-switch" role="switch"
                aria-checked="<?php echo $fabrement_oauth_allowed ? 'true' : 'false'; ?>" aria-labelledby="fabrement-access-oauth-label"
                aria-describedby="fabrement-access-scope<?php echo $fabrement_oauth_https ? '' : ' fabrement-access-https'; ?><?php echo $fabrement_oauth_storage_ready ? '' : ' fabrement-access-storage'; ?>"
                title="<?php echo esc_attr( $fabrement_oauth_allowed ? __( 'Turn off OAuth for this site', 'fabrement-lite' ) : __( 'Turn on OAuth for this site', 'fabrement-lite' ) ); ?>"
                <?php echo ! $fabrement_oauth_allowed && ( ! $fabrement_oauth_https || ! $fabrement_oauth_storage_ready ) ? 'disabled' : ''; ?>
                <?php if ( $fabrement_oauth_allowed ) : ?>data-fabrement-confirm="<?php echo esc_attr( __( 'Turn off OAuth for this site? All OAuth connections will be revoked and must be authorized again. Application passwords will stay active.', 'fabrement-lite' ) ); ?>"<?php endif; ?>>
                <span class="fabrement-cred__access-switch-track" aria-hidden="true"></span>
            </button>
        </form>
    </div>
    <?php if ( ! $fabrement_oauth_storage_ready ) : ?>
        <p id="fabrement-access-storage" class="fabrement-cred__access-warning" role="status"><?php esc_html_e( 'OAuth storage is not ready. Deactivate and reactivate Fabrement Lite to retry setup.', 'fabrement-lite' ); ?></p>
    <?php endif; ?>
    <?php if ( ! $fabrement_oauth_https ) : ?>
        <p id="fabrement-access-https" class="fabrement-cred__access-warning"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e( 'OAuth unavailable: HTTPS required.', 'fabrement-lite' ); ?></p>
    <?php endif; ?>
    <?php // One icon for the list, not one per row: the rows below are a name and its Disconnect, on one line. ?>
    <h3 class="fabrement-cred__access-list-title"><?php esc_html_e( 'Connections', 'fabrement-lite' ); ?><span class="fabrement-cred__access-count"><?php echo esc_html( (string) $fabrement_connection_count ); ?></span></h3>
    <?php if ( ! $fabrement_connection_count ) : ?>
        <p class="fabrement-cred__access-empty"><?php esc_html_e( 'No connections yet.', 'fabrement-lite' ); ?></p>
    <?php else : ?>
        <ul class="fabrement-cred__access-list">
            <?php foreach ( $fabrement_oauth_grants as $fabrement_oauth_grant ) :
                $fabrement_connection_name = $fabrement_oauth_grant['payload']['client_name'] ?? __( 'OAuth client', 'fabrement-lite' );
                ?>
                <li class="fabrement-cred__access-row">
                    <div class="fabrement-cred__access-info">
                        <strong><?php echo esc_html( $fabrement_connection_name ); ?></strong>
                        <?php // A line each: what it is, when it was made, where it stands now. Nothing shares a line, so nothing wraps into one. ?>
                        <span class="fabrement-cred__access-meta">OAuth</span>
                        <?php $fabrement_grant_user = get_userdata( (int) $fabrement_oauth_grant['user_id'] ); ?>
                        <span class="fabrement-cred__access-meta"><?php echo esc_html( sprintf( /* translators: %s: user name. */ __( 'By %s', 'fabrement-lite' ), $fabrement_grant_user ? $fabrement_grant_user->display_name : __( 'a deleted user', 'fabrement-lite' ) ) ); ?></span>
                        <?php if ( ! empty( $fabrement_oauth_grant['payload']['created'] ) ) : ?>
                            <span class="fabrement-cred__access-meta"><?php echo esc_html( sprintf( /* translators: %s: date and time. */ __( 'Authorized %s', 'fabrement-lite' ), wp_date( $fabrement_stamp, (int) $fabrement_oauth_grant['payload']['created'] ) ) ); ?></span>
                        <?php endif; ?>
                        <span class="fabrement-cred__access-meta"><?php esc_html_e( 'Until disconnected', 'fabrement-lite' ); ?></span>
                    </div>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="fabrement_mcp_oauth_settings">
                        <input type="hidden" name="oauth_command" value="revoke">
                        <input type="hidden" name="family" value="<?php echo esc_attr( $fabrement_oauth_grant['family'] ); ?>">
                        <?php wp_nonce_field( 'fabrement_mcp_oauth_settings' ); ?>
                        <button type="submit" class="fabrement-cred__disconnect" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: connection name. */ __( 'Disconnect %s', 'fabrement-lite' ), $fabrement_connection_name ) ); ?>"
                            data-fabrement-confirm="<?php echo esc_attr( __( 'Disconnect this OAuth connection? Other connections will stay active.', 'fabrement-lite' ) ); ?>">
                            <span class="dashicons dashicons-editor-unlink" aria-hidden="true"></span><?php esc_html_e( 'Disconnect', 'fabrement-lite' ); ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
            <?php foreach ( $fabrement_mcp_passwords as $fabrement_pw ) : ?>
                <li class="fabrement-cred__access-row">
                    <div class="fabrement-cred__access-info">
                        <?php // The title is the client this password was made for; its creation stamp belongs with the other dates below. ?>
                        <strong><?php echo esc_html( $fabrement_pw['label'] ?? $fabrement_pw['name'] ); ?></strong>
                        <span class="fabrement-cred__access-meta"><?php esc_html_e( 'Application password', 'fabrement-lite' ); ?></span>
                        <?php if ( ! empty( $fabrement_pw['created'] ) ) : ?>
                            <span class="fabrement-cred__access-meta"><?php echo esc_html( sprintf( /* translators: %s: date and time. */ __( 'Created %s', 'fabrement-lite' ), wp_date( $fabrement_stamp, (int) $fabrement_pw['created'] ) ) ); ?></span>
                        <?php endif; ?>
                        <?php // Last, and alone: whether anything has actually used this password is what a revoke decision turns on. ?>
                        <span class="fabrement-cred__access-meta"><?php echo esc_html( $fabrement_pw['last_used'] ? sprintf( /* translators: %s: date. */ __( 'Last used %s', 'fabrement-lite' ), wp_date( $fabrement_date, (int) $fabrement_pw['last_used'] ) ) : __( 'Not used yet', 'fabrement-lite' ) ); ?></span>
                    </div>
                    <a class="fabrement-cred__disconnect" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: connection name. */ __( 'Disconnect %s', 'fabrement-lite' ), $fabrement_pw['name'] ) ); ?>"
                        href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fabrement_revoke_mcp_password&uuid=' . rawurlencode( $fabrement_pw['uuid'] ) ), 'fabrement_revoke_mcp_password' ) ); ?>"
                        data-fabrement-confirm="<?php echo esc_attr( __( 'Disconnect this application password? Agents using it lose access. Other connections will stay active.', 'fabrement-lite' ) ); ?>">
                        <span class="dashicons dashicons-editor-unlink" aria-hidden="true"></span><?php esc_html_e( 'Disconnect', 'fabrement-lite' ); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</aside>
