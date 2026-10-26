<?php

namespace Fabrement\Route\SiteTemplate;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding;
use Fabrement\Route\AbstractRestRoute;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /site-templates/archives — every ARCHIVE view a template can dress, with
 * the template currently bound to it.
 *
 * Archives are the one view kind that cannot store its own pick: a singular
 * carries the choice on the post (or inherits its type's default), but an
 * archive has no post to write to. So the binding lives in one site option and
 * the bindable views are DERIVED here rather than stored: the posts index always
 * exists, plus every public post type whose `has_archive` is on. A type that
 * loses `has_archive` simply stops being listed — a stale binding for it is
 * inert, because the render side only ever asks about the CURRENT view.
 *
 * Response: { archives: [{ context, label, url, templateId }] }
 * where context is "blog-home" or "archive:<postType>", and templateId 0 means
 * the theme still renders that view.
 */
final class ListSiteTemplateArchivesRestRoute extends AbstractRestRoute {

    public function get_route(): string {
        return '/site-templates/archives';
    }

    public function get_methods() {
        return WP_REST_Server::READABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( Capabilities::ADMIN );
    }

    public function handle( WP_REST_Request $request ) {
        return new WP_REST_Response( [ 'archives' => self::archives() ], 200 );
    }

    /**
     * The bindable archive views, each with its current binding. Shared with the
     * assign route so both answer with the SAME list after a change.
     *
     * @return list<array{context:string,label:string,url:string,templateId:int}>
     */
    public static function archives(): array {
        $bindings = ArchiveTemplateBinding::all();

        // The posts index first — it exists on every site, archive or not.
        $out = [
            [
                'context'    => 'blog-home',
                // NOT "the home page": this is the posts index wherever it lives — the
                // front page on a default site, or the separate page named under
                // Settings → Reading → Posts page. is_home() is what matches it.
                'label'      => __( 'Posts index (the blog listing)', 'fabrement-lite' ),
                'url'        => (string) ( get_post_type_archive_link( 'post' ) ?: home_url( '/' ) ),
                'templateId' => $bindings['blog-home'] ?? 0,
            ],
        ];

        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
            if ( empty( $type->has_archive ) ) {
                continue;
            }
            $context = 'archive:' . $type->name;
            $out[]   = [
                'context'    => $context,
                /* translators: %s: post type singular name. */
                'label'      => sprintf( __( '%s archive', 'fabrement-lite' ), $type->labels->singular_name ?? $type->name ),
                'url'        => (string) ( get_post_type_archive_link( $type->name ) ?: '' ),
                'templateId' => $bindings[ $context ] ?? 0,
            ];
        }

        return $out;
    }
}
