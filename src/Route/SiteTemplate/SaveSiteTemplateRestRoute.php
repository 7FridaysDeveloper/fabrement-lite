<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateWriter;
use Fabrement\Route\AbstractRestRoute;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /site-templates — create (no id) or rename / change the width of a template (with id).
 * A new template starts as a lone Content Source block; its blocks are edited in Gutenberg.
 */
final class SaveSiteTemplateRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates';
    }

    public function get_methods() {
        return WP_REST_Server::CREATABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function get_route_args(): array {
        return [
            'id'           => [ 'type' => 'integer', 'required' => false, 'minimum' => 1 ],
            'title'        => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'contentWidth' => [ 'type' => 'string', 'required' => false, 'enum' => [ 'full', 'constrained' ] ],
        ];
    }

    public function handle( WP_REST_Request $request ) {
        $input = [
            'id'           => $request->get_param( 'id' ),
            'title'        => $request->get_param( 'title' ),
            'contentWidth' => $request->get_param( 'contentWidth' ),
        ];
        if ( (int) $input['id'] === 0 ) {
            $input['content'] = '<!-- wp:fabrement/content-source /-->';
        }
        $result = SiteTemplateWriter::save( $input );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( [ 'template' => $result ], 200 );
    }
}
