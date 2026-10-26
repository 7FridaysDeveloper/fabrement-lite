<?php

namespace Fabrement\Route\Editor;

use Fabrement\Modules\Translation\Translation;
use Fabrement\Route\AbstractRestRoute;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET /post-refs?ids=12,34&lang=pl — the records a picker holds, for the editor.
 *
 * Why not core's /wp/v2/search: the translation plugin scopes every REST listing to the admin's
 * current language, so on a Polish page the English records a block was built with come back
 * as nothing and the picker paints them "unavailable". Read here by id, across languages, with
 * what a page in `lang` will actually show for each — the version fabrement()->post() reads
 * there ({@see \Fabrement\Modules\Translation\Version::posts()}).
 */
final class PostRefsRestRoute extends AbstractRestRoute {

    private const MAX = 100;

    public function get_route(): string {
        return '/post-refs';
    }

    public function get_methods() {
        return WP_REST_Server::READABLE;
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        return current_user_can( 'edit_posts' );
    }

    public function get_route_args(): array {
        return [
            'ids'  => [ 'type' => 'string', 'required' => true ],
            'lang' => [ 'type' => 'string', 'default' => '' ],
        ];
    }

    public function handle( WP_REST_Request $request ) {
        $ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', explode( ',', (string) $request->get_param( 'ids' ) ) ) ) ) ), 0, self::MAX );
        return rest_ensure_response( self::rows( $ids, sanitize_key( (string) $request->get_param( 'lang' ) ) ) );
    }

    /** The picker rows for these ids — {@see EditorRefs::posts()}. @param int[] $ids */
    public static function rows( array $ids, string $lang ): array {
        return EditorRefs::posts( $ids, $lang );
    }
}
