<?php

namespace Fabrement\Modules\Mcp\Support;

use WP_Error;

/** A post-type archive, named the way a tool is called: by post type. */
final class PostTypeArchive {

    /**
     * Whether the type has an archive at all — an error in the caller's terms when it has not.
     *
     * @return true|WP_Error
     */
    public static function check( string $post_type ) {
        $object = get_post_type_object( $post_type );
        if ( $object === null ) {
            return new WP_Error(
                'fabrement_unknown_post_type',
                sprintf( 'There is no post type "%s" on this site — see list-post-types.', $post_type ),
                [ 'status' => 404 ]
            );
        }
        if ( empty( $object->public ) || empty( $object->has_archive ) ) {
            return new WP_Error(
                'fabrement_no_archive',
                sprintf( 'The "%s" type has no archive to describe: it is %s.', $post_type, empty( $object->public ) ? 'not public' : 'registered without an archive' ),
                [ 'status' => 409 ]
            );
        }
        return true;
    }
}
