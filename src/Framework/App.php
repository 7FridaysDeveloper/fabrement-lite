<?php

namespace Fabrement\Framework;

use Pimple\Container;

/**
 * Plugin root path and URL (set from main plugin file via boot()).
 */
final class App {


    private static string $path = '';

    private static string $url = '';

    private static ?Container $container = null;

    public static function boot( string $plugin_file ): void {
        self::$path       = plugin_dir_path( $plugin_file );
        self::$url        = plugin_dir_url( $plugin_file );
    }

    public static function set_container( Container $container ): void {
        self::$container = $container;
    }

    public static function has_container(): bool {
        return self::$container !== null;
    }

    public static function container(): Container {
        if ( self::$container === null ) {
            throw new \LogicException( 'Plugin container is not initialized. Call App::set_container() after App::boot().' );
        }

        return self::$container;
    }

    public static function path( string $relative = '' ): string {
        if ( $relative === '' ) {
            return self::$path;
        }

        return self::$path . ltrim( str_replace( '\\', '/', $relative ), '/' );
    }

    public static function url( string $relative = '' ): string {
        if ( $relative === '' ) {
            return self::$url;
        }

        return self::$url . ltrim( str_replace( '\\', '/', $relative ), '/' );
    }
}
