<?php

namespace Fabrement\Container;

use Pimple\Container;
use Fabrement\Container\Provider\AdminPageServiceProvider;
use Fabrement\Container\Provider\FieldsServiceProvider;
use Fabrement\Container\Provider\McpServiceProvider;
use Fabrement\Container\Provider\RestRouteServiceProvider;

/**
 * Composes the plugin DI container from {@see \Pimple\ServiceProviderInterface} modules.
 */
final class PluginContainer {

    public static function create(): Container {
        $container = new Container();

        $container->register( new FieldsServiceProvider() );
        $container->register( new RestRouteServiceProvider() );
        $container->register( new AdminPageServiceProvider() );
        $container->register( new McpServiceProvider() );

        return $container;
    }
}
