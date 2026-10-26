<?php

namespace Fabrement\Modules\Seo;

use Fabrement\Modules\Seo\Contract\SeoIntegration;
use Fabrement\Modules\Seo\Integration\YoastIntegration;

/**
 * Which SEO plugins Fabrement talks to, and when it starts.
 *
 * Asked on `plugins_loaded`, not when this file loads: plugins load alphabetically, and Yoast
 * (wordpress-seo) comes after Fabrement — it would not be there yet. Every active one is hooked;
 * a site with none gets nothing — no SEO output, no structured data.
 */
final class SeoIntegrations {

    public static function init(): void {
        add_action( 'plugins_loaded', [ self::class, 'register' ], 20 );
    }

    public static function register(): void {
        foreach ( self::candidates() as $integration ) {
            if ( $integration->is_active() ) {
                $integration->register();
            }
        }
    }

    /** @return list<SeoIntegration> Every plugin Fabrement knows. Add the next one here. */
    private static function candidates(): array {
        return [ new YoastIntegration() ];
    }
}
