<?php
/** @var array $authorization */
/** @var string $pending */
defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php esc_html_e( 'Connect to Fabrement', 'fabrement-lite' ); ?></title>
    <?php wp_print_styles( 'fabrement-oauth-consent' ); ?>
</head>
<body><main>
    <h1><?php esc_html_e( 'Connect to Fabrement', 'fabrement-lite' ); ?></h1>
    <dl>
        <div>
            <dt><?php esc_html_e( 'Website', 'fabrement-lite' ); ?></dt>
            <dd><?php echo esc_html( home_url() ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'WordPress account', 'fabrement-lite' ); ?></dt>
            <dd><?php echo esc_html( wp_get_current_user()->user_login ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Application', 'fabrement-lite' ); ?></dt>
            <dd><?php echo esc_html( $authorization['client_name'] ); ?><br><small><?php echo esc_html( $authorization['client_id'] ); ?></small></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Return address', 'fabrement-lite' ); ?></dt>
            <dd><?php echo esc_html( $authorization['redirect_uri'] ); ?>
                <?php if ( in_array( strtolower( (string) wp_parse_url( $authorization['redirect_uri'], PHP_URL_HOST ) ), [ 'localhost', '127.0.0.1', '[::1]' ], true ) ) : ?>
                    <br><small><?php esc_html_e( 'This is a temporary callback on your computer used by the CLI to finish sign-in. It is not the address of this website or its MCP server. Keep the CLI running until sign-in finishes.', 'fabrement-lite' ); ?></small>
                <?php endif; ?>
            </dd>
        </div>
    </dl>
    <h2><?php esc_html_e( 'Access to this site\'s content', 'fabrement-lite' ); ?></h2>
    <p><?php esc_html_e( 'This authorization works only through the Fabrement MCP server on this website. The OAuth token cannot sign in to wp-admin or authenticate requests to other WordPress REST API routes or other MCP servers.', 'fabrement-lite' ); ?></p>
    <p class="warning"><?php esc_html_e( 'This is not read-only access: the connected agent can change this site\'s content.', 'fabrement-lite' ); ?></p>
    <ul>
        <li><?php esc_html_e( 'Read, create, edit and delete pages and posts, blocks on pages, templates, media, menus, forms, terms, and Fabrement fields, options pages and their values.', 'fabrement-lite' ); ?></li>
        <li><?php esc_html_e( 'Edit SEO data through Yoast SEO, and the site title, tagline, icon and 404 page.', 'fabrement-lite' ); ?></li>
        <li><?php esc_html_e( 'Send requested site data to the connected AI provider.', 'fabrement-lite' ); ?></li>
    </ul>
    <p><?php esc_html_e( 'It cannot install or change plugins, themes or users, and cannot change post types, taxonomies, the home page, the posts page or permalinks.', 'fabrement-lite' ); ?></p>
    <p><?php esc_html_e( 'Access remains active until disconnected. You can disconnect it at any time in Fabrement → MCP Connection. Your WordPress password is not shared.', 'fabrement-lite' ); ?></p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="fabrement_mcp_authorize">
        <input type="hidden" name="pending" value="<?php echo esc_attr( $pending ); ?>">
        <?php wp_nonce_field( 'fabrement_mcp_consent_' . $pending ); ?>
        <div class="actions">
            <button class="allow" name="decision" value="allow"><?php esc_html_e( 'Allow access', 'fabrement-lite' ); ?></button>
            <button name="decision" value="deny"><?php esc_html_e( 'Cancel', 'fabrement-lite' ); ?></button>
        </div>
    </form>
</main></body>
</html>
