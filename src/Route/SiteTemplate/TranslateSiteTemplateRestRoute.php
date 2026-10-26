<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateTranslator;
use Fabrement\Route\AbstractRestRoute;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /site-templates/translate — start (or reopen) one language's version of a template:
 * { templateId, language }.
 *
 * Templates are listed on the plugin's own screen — the CPT is hidden from the menu — so a
 * translation plugin's list-screen "add translation" button is not there. Without this route the
 * whole language-aware render path would work and be unusable.
 *
 * A translation starts as a COPY of the original's content, not as a blank post. The content is
 * the template's block arrangement, and the point of translating a template is to reword it;
 * starting empty would mean rebuilding the arrangement by hand.
 *
 * The content, the width and the manifest key are copied. Which pages the template dresses stays
 * on the original and is read from there, so one template keeps one role on the site whatever
 * language it is being read in.
 *
 * Response: { templateId, language, editUrl, created }
 */
final class TranslateSiteTemplateRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates/translate';
    }

    public function get_methods() {
        return WP_REST_Server::CREATABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function get_route_args(): array {
        return [
            'templateId' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
            'language'   => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
        ];
    }

    public function handle( WP_REST_Request $request ) {
        // The work lives in SiteTemplateTranslator::open() — the agent's translate-template tool
        // opens the same record through the same door, so a refusal here is a refusal there.
        $opened = SiteTemplateTranslator::open(
            (int) $request->get_param( 'templateId' ),
            (string) $request->get_param( 'language' )
        );
        if ( is_wp_error( $opened ) ) {
            return $opened;
        }

        return $this->reply( $opened['id'], (string) $request->get_param( 'language' ), $opened['created'] );
    }

    private function reply( int $id, string $language, bool $created ): WP_REST_Response {
        return new WP_REST_Response(
            [
                'templateId' => $id,
                'language'   => $language,
                'editUrl'    => admin_url( 'post.php?post=' . $id . '&action=edit' ),
                'created'    => $created,
            ],
            $created ? 201 : 200
        );
    }
}
