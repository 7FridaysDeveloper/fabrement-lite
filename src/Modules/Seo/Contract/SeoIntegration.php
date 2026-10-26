<?php

namespace Fabrement\Modules\Seo\Contract;

/**
 * One SEO plugin, seen from the page it renders: where Fabrement hooks in so that plugin's output
 * knows what Fabrement builds — images in blocks and fields for social previews and sitemaps,
 * Fabrement fields as title/description variables, the query arguments a theme or plugin registered.
 *
 * WHAT to give lives in Fabrement's own services ({@see \Fabrement\Modules\Seo\Content\ContentImages},
 * {@see \Fabrement\Modules\Seo\Content\FieldText}, {@see \Fabrement\Modules\Seo\SiteQueryVars}); an
 * implementation only knows its plugin's hooks and shapes, and starts the services only its plugin
 * uses. Yoast is the first; another plugin
 * (Rank Math, All in One SEO) is another class beside it, registered in SeoIntegrations.
 */
interface SeoIntegration {

    /** Is this plugin running on the site right now? Asked once every plugin has loaded. */
    public function is_active(): bool;

    /** Hook into the plugin. Called once, only when is_active(). */
    public function register(): void;
}
