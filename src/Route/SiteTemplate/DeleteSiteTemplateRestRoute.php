<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateDeleter;
use Fabrement\Route\AbstractRestRoute;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * DELETE /site-templates/{id} — remove a site template. Pages that explicitly
 * picked it are reset to the default template (no stale slugs), and deleting
 * the site-default template clears that option.
 *
 * Response: { deleted: true, clearedPages: number }
 */
final class DeleteSiteTemplateRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates/(?P<id>\d+)';
    }

    public function get_methods() {
        return WP_REST_Server::DELETABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function handle( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        // find_any: a template that ended up a draft or in the trash must still be deletable,
        // and so must one language's version — the deleter takes that one alone.
        if ( SiteTemplateRegistry::find_any( $id ) === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }

        $cleared = SiteTemplateDeleter::delete( $id );
        if ( is_wp_error( $cleared ) ) {
            return $cleared;
        }

        return new WP_REST_Response( [ 'deleted' => true, 'clearedPages' => $cleared ], 200 );
    }
}
