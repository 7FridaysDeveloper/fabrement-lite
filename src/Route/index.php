<?php
defined( 'ABSPATH' ) || exit;
foreach ( [
    \Fabrement\Route\Editor\PostRefsRestRoute::class,
    \Fabrement\Route\Editor\MediaRefsRestRoute::class,
    \Fabrement\Route\Fields\PostFieldsRestRoute::class,
    \Fabrement\Route\Fields\OptionsFieldsRestRoute::class,
    \Fabrement\Route\SiteTemplate\AssignSiteTemplateArchiveRestRoute::class,
    \Fabrement\Route\SiteTemplate\AssignSiteTemplatePagesRestRoute::class,
    \Fabrement\Route\SiteTemplate\ContentSourceFilesRestRoute::class,
    \Fabrement\Route\SiteTemplate\DefaultSiteTemplateRestRoute::class,
    \Fabrement\Route\SiteTemplate\DeleteSiteTemplateRestRoute::class,
    \Fabrement\Route\SiteTemplate\ListSiteTemplateArchivesRestRoute::class,
    \Fabrement\Route\SiteTemplate\ListSiteTemplatePagesRestRoute::class,
    \Fabrement\Route\SiteTemplate\ListSiteTemplatesRestRoute::class,
    \Fabrement\Route\SiteTemplate\SaveContentSourceRestRoute::class,
    \Fabrement\Route\SiteTemplate\SaveSiteTemplateRestRoute::class,
    \Fabrement\Route\SiteTemplate\TranslateSiteTemplateRestRoute::class,
] as $fabrement_route ) { \Fabrement\Route\RestRoutesBootstrap::register( $fabrement_route ); }
\Fabrement\Route\RestRoutesBootstrap::init();
