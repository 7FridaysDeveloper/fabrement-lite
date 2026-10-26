<?php
defined( 'ABSPATH' ) || exit;
$fabrement_options_active   = $page instanceof \Fabrement\Page\SiteOptionsPage;
$fabrement_templates_active = $page instanceof \Fabrement\Page\TemplatesPage;
?>
<nav class="fabrement-lite-nav" aria-label="<?php esc_attr_e( 'Fabrement navigation', 'fabrement-lite' ); ?>">
    <a class="fabrement-lite-nav__brand" href="<?php echo esc_url( admin_url( 'admin.php?page=fabrement-credentials' ) ); ?>">
        <img src="<?php echo esc_url( \Fabrement\Framework\App::url( 'assets/images/fabrement-logo.svg' ) ); ?>" alt="Fabrement Lite" width="151" height="40">
    </a>
    <div class="fabrement-lite-nav__links">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fabrement-credentials' ) ); ?>" <?php echo ! $fabrement_options_active && ! $fabrement_templates_active ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'MCP Connection', 'fabrement-lite' ); ?></a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fabrement-options' ) ); ?>" <?php echo $fabrement_options_active ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Site General Settings', 'fabrement-lite' ); ?></a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fabrement-templates' ) ); ?>" <?php echo $fabrement_templates_active ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Templates', 'fabrement-lite' ); ?></a>
    </div>
</nav>
