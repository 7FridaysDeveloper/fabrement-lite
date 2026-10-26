<?php

namespace Fabrement\Route;

use Fabrement\Framework\App;

/**
 * Attaches a single {@see rest_api_init} callback and registers every route class you pass in.
 *
 * Usage: {@see register()} each route FQCN, then {@see init()} (see `src/Route/index.php`).
 * Each FQCN is resolved from the container (DI), so every route class must be registered there
 * (see {@see \Fabrement\Container\Provider\RestRouteServiceProvider}).
 */
final class RestRoutesBootstrap {

    /** @var list<class-string<AbstractRestRoute>> */
    private static array $route_classes = [];

    private static bool $hook_attached = false;

    /**
     * @param class-string<AbstractRestRoute> $route_class FQCN whose subclass defines path, methods, permission_callback, handle().
     */
    public static function register( string $route_class ): void {
        if ( ! is_subclass_of( $route_class, AbstractRestRoute::class, true ) ) {
            throw new \InvalidArgumentException(
                sprintf( '%s must extend %s.', esc_html( $route_class ), AbstractRestRoute::class )
            );
        }
        if ( in_array( $route_class, self::$route_classes, true ) ) {
            return;
        }
        self::$route_classes[] = $route_class;
    }

    public static function init(): void {
        if ( self::$hook_attached ) {
            return;
        }
        self::$hook_attached = true;

        if ( self::$route_classes === [] ) {
            return;
        }

        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
    }

    public static function register_routes(): void {
        $container = App::container();
        foreach ( self::$route_classes as $class ) {
            $container[ $class ]->register();
        }
    }
}
