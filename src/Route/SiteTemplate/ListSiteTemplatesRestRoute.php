<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateSummaries;
use Fabrement\Route\AbstractRestRoute;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /site-templates — every site template (broken ones included, flagged
 * `ready:false`) plus the per-post-type default map.
 *
 * Response: { templates: SiteTemplateSummary[], defaults: Record<string,number>, postTypes,
 * languages } — built by {@see \Fabrement\Modules\SiteTemplate\Service\SiteTemplateSummaries},
 * which the screen's inline preload uses too.
 */
final class ListSiteTemplatesRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates';
    }

    public function get_methods() {
        return WP_REST_Server::READABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function handle( WP_REST_Request $request ) {
        // The Templates screen's first paint is built by the same code (see the service) —
        // this route and the inline preload cannot drift apart into two different screens.
        return new WP_REST_Response( SiteTemplateSummaries::payload(), 200 );
    }
}
