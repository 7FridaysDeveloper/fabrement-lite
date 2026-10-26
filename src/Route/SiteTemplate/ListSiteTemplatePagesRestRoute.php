<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplatePages;
use Fabrement\Route\AbstractRestRoute;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /site-templates/pages — every page with its CURRENT `_wp_page_template`
 * value, for the SPA's per-template page picker.
 *
 * Response: { pages: [{ id, title, status, template, isFront }] }
 */
final class ListSiteTemplatePagesRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates/pages';
    }

    public function get_methods() {
        return WP_REST_Server::READABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function get_route_args(): array {
        return [
            // Bounded, server-side search — the CPT editor panel passes these so a
            // site with thousands of pages loads only a small window at a time.
            'search'       => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'limit'        => [ 'type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 100 ],
            // The current template's slug: its own picks are always returned so
            // their checkboxes stay correct even outside the search window.
            'templateSlug' => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
        ];
    }

    public function handle( WP_REST_Request $request ) {
        $limit         = (int) ( $request->get_param( 'limit' ) ?? 0 );
        $template_slug = (string) ( $request->get_param( 'templateSlug' ) ?? '' );

        // No post-type scoping: a pick is a per-record decision available on every public type, so
        // narrowing the list would hide the very record somebody came here to pick — and this panel
        // sends the full set back, so a row it never showed would be cleared on save.
        // A MISSING limit is not "no limit". Once a pick could be made on any public type, the
        // unbounded branch meant loading every record on the site plus its meta into one request;
        // nothing in this plugin asks for that, and a caller who simply forgets the parameter
        // should not be the one who discovers it. Default it instead.
        $rows = SiteTemplatePages::search_rows(
            (string) ( $request->get_param( 'search' ) ?? '' ),
            $limit > 0 ? $limit : 50,
            $template_slug
        );

        return new WP_REST_Response( [ 'pages' => $rows ], 200 );
    }
}
