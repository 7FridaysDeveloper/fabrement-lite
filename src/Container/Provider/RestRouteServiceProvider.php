<?php
namespace Fabrement\Container\Provider;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
final class RestRouteServiceProvider implements ServiceProviderInterface {
    public function register( Container $pimple ): void {
        foreach ( [ \Fabrement\Route\SiteTemplate\AssignSiteTemplateArchiveRestRoute::class,
            \Fabrement\Route\SiteTemplate\AssignSiteTemplatePagesRestRoute::class,
            \Fabrement\Route\SiteTemplate\ContentSourceFilesRestRoute::class,
            \Fabrement\Route\SiteTemplate\DefaultSiteTemplateRestRoute::class,
            \Fabrement\Route\SiteTemplate\DeleteSiteTemplateRestRoute::class,
            \Fabrement\Route\SiteTemplate\ListSiteTemplateArchivesRestRoute::class,
            \Fabrement\Route\SiteTemplate\ListSiteTemplatePagesRestRoute::class,
            \Fabrement\Route\SiteTemplate\ListSiteTemplatesRestRoute::class,
            \Fabrement\Route\SiteTemplate\SaveContentSourceRestRoute::class,
            \Fabrement\Route\SiteTemplate\SaveSiteTemplateRestRoute::class,
            \Fabrement\Route\SiteTemplate\TranslateSiteTemplateRestRoute::class ] as $class ) {
            $pimple[ $class ] = static fn() => new $class();
        }
    }
}
