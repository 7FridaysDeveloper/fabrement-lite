<?php

namespace Fabrement\Route\Editor;

use Fabrement\Route\AbstractRestRoute;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET /media-refs?ids=12,34 — previews of the media the editor holds (thumbnail, alt, title, file
 * name, type, size), several ids in one request.
 *
 * Why not core's /wp/v2/media?include=: WPML scopes that listing to the admin's language and
 * answers with the translated copies under OTHER ids, so the editor could not match them to what
 * it holds. Read here by id, exactly ({@see EditorRefs::media()}).
 */
final class MediaRefsRestRoute extends AbstractRestRoute {

    private const MAX = 100;

    public function get_route(): string {
        return '/media-refs';
    }

    public function get_methods() {
        return WP_REST_Server::READABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( 'edit_posts' );
    }

    public function get_route_args(): array {
        return [
            'ids' => [ 'type' => 'string', 'required' => true ],
        ];
    }

    public function handle( WP_REST_Request $request ) {
        $ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', explode( ',', (string) $request->get_param( 'ids' ) ) ) ) ) ), 0, self::MAX );
        return rest_ensure_response( EditorRefs::media( $ids ) );
    }
}
