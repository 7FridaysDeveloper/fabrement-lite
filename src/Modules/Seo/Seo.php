<?php

namespace Fabrement\Modules\Seo;

use Fabrement\Modules\Seo\Bridge\NullSeoBridge;
use Fabrement\Modules\Seo\Bridge\YoastBridge;
use Fabrement\Modules\Seo\Contract\SeoBridge;

/**
 * Which SEO plugin's store the tools talk to — {@see SeoBridge}, as Translation::bridge() is for
 * translations. Yoast when it runs, otherwise nothing.
 */
final class Seo {

    private static ?SeoBridge $bridge = null;

    public static function bridge(): SeoBridge {
        if ( self::$bridge !== null ) {
            return self::$bridge;
        }
        $bridge = defined( 'WPSEO_VERSION' ) ? new YoastBridge() : new NullSeoBridge();
        // Plugins load alphabetically and Yoast (wordpress-seo) comes after Fabrement: an answer
        // given before every plugin has loaded may only mean Yoast is not there YET.
        if ( $bridge->is_active() || did_action( 'plugins_loaded' ) ) {
            self::$bridge = $bridge;
        }
        return $bridge;
    }

    /**
     * Whether a supported SEO plugin is on disk, active or not — so a tool can say "activate it"
     * rather than "install it".
     */
    public static function installed(): bool {
        return self::bridge()->is_active() || YoastBridge::installed();
    }

    /** Override the bridge — for tests. Null forgets the choice. */
    public static function set( ?SeoBridge $bridge ): void {
        self::$bridge = $bridge;
    }
}
