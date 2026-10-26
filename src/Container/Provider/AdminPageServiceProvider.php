<?php
namespace Fabrement\Container\Provider;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Fabrement\Page\McpConnectionPage;
use Fabrement\Page\SiteOptionsPage;
use Fabrement\Page\TemplatesPage;
final class AdminPageServiceProvider implements ServiceProviderInterface {
    public function register( Container $pimple ): void {
        $pimple[ McpConnectionPage::class ] = static fn() => new McpConnectionPage();
        $pimple[ SiteOptionsPage::class ] = static fn() => new SiteOptionsPage();
        $pimple[ TemplatesPage::class ] = static fn() => new TemplatesPage();
    }
}
