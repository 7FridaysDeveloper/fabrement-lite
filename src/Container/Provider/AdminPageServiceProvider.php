<?php
namespace Fabrement\Container\Provider;

defined( 'ABSPATH' ) || exit;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Fabrement\Page\McpConnectionPage;
use Fabrement\Page\TemplatesPage;
final class AdminPageServiceProvider implements ServiceProviderInterface {
    public function register( Container $pimple ): void {
        $pimple[ McpConnectionPage::class ] = static fn() => new McpConnectionPage();
        $pimple[ TemplatesPage::class ] = static fn() => new TemplatesPage();
    }
}
