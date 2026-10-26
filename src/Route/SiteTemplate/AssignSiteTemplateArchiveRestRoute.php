<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Route\AbstractRestRoute;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /site-templates/archive — bind ONE archive view to a template, or unbind
 * it: { context, templateId } (templateId 0 = the theme takes the view back).
 *
 * Radio semantics per view: an archive has exactly one template, so binding
 * simply overwrites. Nothing is written into posts — the map is a site option
 * the appliers read at render time, so switching (or clearing) re-dresses the
 * archive instantly.
 *
 * Binding is the SECOND half of making an archive custom. The first is a rule in
 * the template's content slot for this same context — without it the archive
 * renders the template's frame around an empty middle. The two are deliberately
 * separate: the frame and the body are chosen in different places (this screen
 * vs the template's content-source block).
 *
 * Response: { archives: [...] } — the full list, so one call re-syncs the UI.
 */
final class AssignSiteTemplateArchiveRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates/archive';
    }

    public function get_methods() {
        return WP_REST_Server::CREATABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function get_route_args(): array {
        return [
            // Not sanitize_key: the context carries a colon ("archive:product"),
            // which sanitize_key would silently eat into "archiveproduct".
            'context'    => [ 'type' => 'string', 'required' => true ],
            'templateId' => [ 'type' => 'integer', 'required' => true, 'minimum' => 0 ],
        ];
    }

    public function handle( WP_REST_Request $request ) {
        $context     = trim( (string) $request->get_param( 'context' ) );
        $template_id = (int) $request->get_param( 'templateId' );

        // A bound archive must point at a REAL, ready template; unbinding is always fine.
        if ( $template_id > 0 ) {
            $record = SiteTemplateRegistry::find_assignable( $template_id );
            if ( $record === null ) {
                return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
            }
        }

        // The view must be one WordPress actually renders. Binding "archive:<type>"
        // for a type with has_archive off would create a binding no request can ever
        // reach — a setting that looks applied and silently does nothing.
        //
        // Only when BINDING, though. On an unbind the test inverts: a type that lost
        // has_archive, or went away with the plugin that registered it, is exactly the
        // case where a stale binding MUST be removable. Gating unbind here stranded the
        // row — ListSiteTemplateArchives skips unregistered types so the chip vanished
        // from the screen, while ArchiveTemplateBinding::all() kept returning it (only
        // forget_template() prunes, and that fires on template delete, not on a type
        // disappearing) and re-applied it the moment the type came back.
        if ( $template_id > 0 && strncmp( $context, 'archive:', 8 ) === 0 ) {
            $type   = substr( $context, 8 );
            $object = get_post_type_object( $type );
            if ( $object === null ) {
                return new WP_Error( 'fabrement_st_unknown_type', __( 'That content type does not exist.', 'fabrement-lite' ), [ 'status' => 400 ] );
            }
            if ( empty( $object->has_archive ) ) {
                return new WP_Error(
                    'fabrement_st_no_archive',
                    __( 'That content type has no archive page, so there is nothing to dress.', 'fabrement-lite' ),
                    [ 'status' => 400 ]
                );
            }
        }

        // Last gate: the context dialect itself ("blog-home" / "archive:<slug>").
        if ( ! ArchiveTemplateBinding::set( $context, $template_id ) ) {
            return new WP_Error( 'fabrement_st_bad_context', __( 'Unknown archive view.', 'fabrement-lite' ), [ 'status' => 400 ] );
        }

        return new WP_REST_Response( [ 'archives' => ListSiteTemplateArchivesRestRoute::archives() ], 200 );
    }
}
