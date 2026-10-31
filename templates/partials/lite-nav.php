<?php
defined( 'ABSPATH' ) || exit;
$fabrement_templates_active = $page instanceof \Fabrement\Page\TemplatesPage;
// Each link only for whoever may open its page: an Editor has Templates, not MCP Connection.
$fabrement_nav_connect = current_user_can( \Fabrement\Capabilities::CONNECT );
$fabrement_nav_home = admin_url( $fabrement_nav_connect ? 'admin.php?page=fabrement-credentials' : 'admin.php?page=fabrement-templates' );
?>
<nav class="fabrement-lite-nav" aria-label="<?php esc_attr_e( 'Fabrement navigation', 'fabrement-lite' ); ?>">
    <a class="fabrement-lite-nav__brand" href="<?php echo esc_url( $fabrement_nav_home ); ?>">
        <img src="<?php echo esc_url( \Fabrement\Framework\App::url( 'assets/images/fabrement-logo.svg' ) ); ?>" alt="Fabrement Lite" width="151" height="40">
    </a>
    <div class="fabrement-lite-nav__links">
        <?php if ( $fabrement_nav_connect ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=fabrement-credentials' ) ); ?>" <?php echo ! $fabrement_templates_active ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'MCP Connection', 'fabrement-lite' ); ?></a>
        <?php endif; ?>
        <?php if ( current_user_can( \Fabrement\Capabilities::TEMPLATES ) ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=fabrement-templates' ) ); ?>" <?php echo $fabrement_templates_active ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Templates', 'fabrement-lite' ); ?></a>
        <?php endif; ?>
    </div>
</nav>
