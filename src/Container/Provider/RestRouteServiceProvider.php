<?php
namespace Fabrement\Container\Provider;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Fabrement\Modules\Fields\Write\FieldWrite;
use Fabrement\Route\Fields\PostFieldsRestRoute;
use Fabrement\Route\Fields\OptionsFieldsRestRoute;
final class RestRouteServiceProvider implements ServiceProviderInterface {
    public function register( Container $pimple ): void {
        $pimple[ PostFieldsRestRoute::class ] = static fn( Container $c ) => new PostFieldsRestRoute( $c[ FieldWrite::class ] );
        $pimple[ OptionsFieldsRestRoute::class ] = static fn( Container $c ) => new OptionsFieldsRestRoute( $c[ FieldWrite::class ] );
        foreach ( [ \Fabrement\Route\Editor\PostRefsRestRoute::class, \Fabrement\Route\Editor\MediaRefsRestRoute::class, \Fabrement\Route\SiteTemplate\AssignSiteTemplateArchiveRestRoute::class,
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
