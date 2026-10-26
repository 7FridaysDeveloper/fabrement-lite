<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateTranslator;
use Fabrement\Route\AbstractRestRoute;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /site-templates/{id}/content-source — set what the template's content slot
 * renders: { rules: [{context, file}] }.
 *
 * This writes the template's MANIFEST (see {@see ContentSourceConfig}); the template post is not
 * touched.
 *
 * ONE SET OF RULES PER TEMPLATE, NOT PER LANGUAGE. The manifest belongs to the whole group of
 * language versions, so a save sent against a version rewrites the rules every language
 * renders. That is accepted rather than refused — a rule names a theme file, and a file
 * is code that serves every language, so there is nothing here a translator could translate —
 * but it is not something a caller should have to infer, which is why the answer says so.
 *
 * Rules are validated exactly as save-template validates them
 * ({@see ContentSourceConfig::validate()}): a known view context (a term by id), an existing
 * theme file written for that view, one rule per view. A changed set with a rule that could
 * never render is refused with the reason; an unchanged set is kept as stored. Rows the screen
 * left unfinished (no view or no file picked) are ignored.
 *
 * Response: { contentSource: { rules[] } }.
 */
final class SaveContentSourceRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates/(?P<id>\d+)/content-source';
    }

    public function get_methods() {
        return WP_REST_Server::CREATABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function get_route_args(): array {
        return [
            'rules'         => [
                'type'     => 'array',
                'required' => false,
                'items'    => [
                    'type'       => 'object',
                    'properties' => [
                        'context' => [ 'type' => 'string' ],
                        'file'    => [ 'type' => 'string' ],
                    ],
                ],
            ],
        ];
    }

    public function handle( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        // find_any: a version is a record of its own and is addressed by its own id, so it is
        // reachable here. What it sets is NOT its own, though — see the class docblock: the rules
        // are the group's. Accepted, and said out loud in the response rather than refused.
        if ( SiteTemplateRegistry::find_any( $id ) === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }
        // …and only where the record is ours to edit at all — see refusal_for_version().
        $refusal = SiteTemplateTranslator::refusal_for_version( $id );
        if ( $refusal !== null ) {
            return $refusal;
        }

        $rules = array_values(
            array_filter(
                (array) ( $request->get_param( 'rules' ) ?? [] ),
                static fn( $r ) => is_array( $r ) && trim( (string) ( $r['context'] ?? '' ) ) !== '' && trim( (string) ( $r['file'] ?? '' ) ) !== ''
            )
        );
        $saved = ContentSourceConfig::write( $id, [ 'rules' => $rules ] );

        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        return new WP_REST_Response( [ 'contentSource' => $saved ], 200 );
    }
}
