<?php
defined( 'ABSPATH' ) || exit;
if ( ! \Fabrement\Framework\App::has_container() ) { return; }
foreach ( [
    \Fabrement\Page\McpConnectionPage::class,
    \Fabrement\Page\TemplatesPage::class,
    \Fabrement\Page\SiteOptionsPage::class,
] as $page ) {
    \Fabrement\Page\AdminPagesBootstrap::register( \Fabrement\Framework\App::container()[ $page ] );
}
\Fabrement\Page\AdminPagesBootstrap::init();
