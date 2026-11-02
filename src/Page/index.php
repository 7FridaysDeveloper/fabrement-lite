<?php
defined( 'ABSPATH' ) || exit;
if ( ! \Fabrement\Framework\App::has_container() ) { return; }
foreach ( [
    \Fabrement\Page\McpConnectionPage::class,
    \Fabrement\Page\TemplatesPage::class,
] as $fabrement_page ) {
    \Fabrement\Page\AdminPagesBootstrap::register( \Fabrement\Framework\App::container()[ $fabrement_page ] );
}
\Fabrement\Page\AdminPagesBootstrap::init();
