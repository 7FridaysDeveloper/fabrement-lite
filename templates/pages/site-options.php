<?php
/**
 * Site General Settings — one tab per options page (options.<slug>.json), the tab on screen shows
 * that page's form. The shell (top nav, head, sub-tab strip) is the one every Fabrement screen
 * wears, from assets/admin/tab-layout.css.
 *
 * @var \Fabrement\Page\SiteOptionsPage $page
 */

defined( 'ABSPATH' ) || exit;

$fabrement_pages   = $page->pages();
$fabrement_current = $page->current();
?>
<div class="wrap fabrement-page fabrement-site-options">
    <?php require __DIR__ . '/../partials/lite-nav.php'; ?>

    <div class="fabrement-page__content">
        <?php
        $fabrement_head_title = __( 'Site General Settings', 'fabrement-lite' );
        $fabrement_head_sub   = $fabrement_current && $fabrement_current['description'] !== ''
            ? $fabrement_current['description']
            : __( 'Settings the whole site shares — contacts, social links, company details — read by the theme wherever it shows them.', 'fabrement-lite' );
        require __DIR__ . '/../partials/page-head.php';
        ?>

        <?php if ( ! $fabrement_pages ) : ?>
            <div class="fabrement-site-options__empty">
                <p><?php esc_html_e( 'This site has no options pages yet. Ask your connected agent to create one — for example, "add a Site settings page with the phone, email and social links".', 'fabrement-lite' ); ?></p>
            </div>
        <?php else : ?>
            <nav class="fabrement-subtabs" aria-label="<?php esc_attr_e( 'Options page', 'fabrement-lite' ); ?>">
                <?php
                foreach ( $fabrement_pages as $fabrement_tab ) :
                    $fabrement_tab_on = $fabrement_current && $fabrement_current['slug'] === $fabrement_tab['slug'];
                    ?>
                    <a class="fabrement-subtabs__tab<?php echo $fabrement_tab_on ? ' is-active' : ''; ?>"
                        href="<?php echo esc_url( $page->tab_url( $fabrement_tab['slug'] ) ); ?>"
                        <?php echo $fabrement_tab_on ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html( $fabrement_tab['menuTitle'] !== '' ? $fabrement_tab['menuTitle'] : $fabrement_tab['title'] ); ?>
                        <span class="fabrement-subtabs__badge"><?php echo (int) $fabrement_tab['fields']; ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            if ( $fabrement_current ) {
                \Fabrement\Modules\Fields\Admin\OptionsAdmin::render_form( $fabrement_current );
            }
            ?>
        <?php endif; ?>
    </div>
</div>
