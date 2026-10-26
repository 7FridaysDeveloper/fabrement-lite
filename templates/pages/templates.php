<?php
/**
 * Templates — the template cards (React).
 *
 * @var \Fabrement\Page\TemplatesPage $page
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap fabrement-page fabrement-templates">
    <?php require __DIR__ . '/../partials/lite-nav.php'; ?>

    <div class="fabrement-page__content">
        <?php
        $fabrement_head_title = __( 'Templates', 'fabrement-lite' );
        $fabrement_head_sub   = __( 'Block frames around the page content. Assign a template to pages, post types or archives; open it in the editor to place blocks above and below the content.', 'fabrement-lite' );
        require __DIR__ . '/../partials/page-head.php';
        ?>

        <?php if ( ! $page->has_app() ) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e( 'The Templates screen is not built in this copy of the plugin. In assets/templates-app run npm install and npm run build.', 'fabrement-lite' ); ?></p></div>
        <?php endif; ?>

        <div id="fabrement-templates-app-root"></div>
    </div>
</div>
