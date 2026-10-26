<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplatePages;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Route\AbstractRestRoute;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /site-templates/{id}/pages — reconcile which pages EXPLICITLY pick
 * this template: { pageIds: number[] }. Selected pages get the template
 * written into `_wp_page_template` (exactly what the native picker does);
 * previously-picked pages missing from the list are reset to the default
 * template. Pages on other/foreign templates that are not selected are never
 * touched.
 *
 * Response: { assigned, cleared, pageCount }
 */
final class AssignSiteTemplatePagesRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates/(?P<id>\d+)/pages';
    }

    public function get_methods() {
        return WP_REST_Server::CREATABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function get_route_args(): array {
        return [
            'pageIds' => [
                'type'     => 'array',
                'required' => true,
                'items'    => [ 'type' => 'integer', 'minimum' => 1 ],
            ],
        ];
    }

    public function handle( WP_REST_Request $request ) {
        $id     = (int) $request->get_param( 'id' );
        $record = SiteTemplateRegistry::find_assignable( $id );
        if ( $record === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }

        $result = SiteTemplatePages::reconcile( $record->slug, (array) $request->get_param( 'pageIds' ) );

        return new WP_REST_Response(
            [
                'assigned'  => $result['assigned'],
                'cleared'   => $result['cleared'],
                'pageCount' => SiteTemplatePages::counts()[ $record->slug ] ?? 0,
            ],
            200
        );
    }
}
