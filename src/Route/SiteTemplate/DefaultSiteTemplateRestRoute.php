<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Route\AbstractRestRoute;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /site-templates/default — set (or clear) the default template for ONE
 * post type: { postType, templateId } (templateId 0 = clear that type). Each
 * post type has at most one default template (radio semantics across
 * templates), resolved at render time for every singular of that type without
 * its own explicit pick; nothing is written into posts, so switching it (or
 * clearing) re-dresses that type instantly.
 *
 * Response: { defaults: Record<string,number> }
 */
final class DefaultSiteTemplateRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates/default';
    }

    public function get_methods() {
        return WP_REST_Server::CREATABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function get_route_args(): array {
        return [
            'postType'   => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
            'templateId' => [ 'type' => 'integer', 'required' => true, 'minimum' => 0 ],
        ];
    }

    public function handle( WP_REST_Request $request ) {
        $post_type   = (string) $request->get_param( 'postType' );
        $template_id = (int) $request->get_param( 'templateId' );

        // Clearing (templateId 0) is never gated — see SetDefaultTemplateAbility: a type that
        // has gone private since it took a default must still be able to give it back.
        // "404" is the not-found view, kept in the same default map (see SiteTemplatePostType::NOT_FOUND).
        if ( $template_id > 0 && $post_type !== SiteTemplatePostType::NOT_FOUND && ! isset( SiteTemplatePostType::available_post_types()[ $post_type ] ) ) {
            return new WP_Error( 'fabrement_st_bad_type', __( 'A template cannot dress that content type.', 'fabrement-lite' ), [ 'status' => 400 ] );
        }

        if ( $template_id > 0 ) {
            $record = SiteTemplateRegistry::find_assignable( $template_id );
            if ( $record === null ) {
                return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
            }
        }

        $defaults = SiteTemplatePostType::set_default_for( $post_type, $template_id );
        return new WP_REST_Response( [ 'defaults' => (object) $defaults ], 200 );
    }
}
