<?php

namespace Fabrement\Route;

use Fabrement\Capabilities;
use WP_Error;
use WP_REST_Posts_Controller;

/** Keep Gutenberg's REST support without making published internal records public. */
final class InternalPostsRestController extends WP_REST_Posts_Controller {

    public function get_items_permissions_check( $request ) {
        if ( ! current_user_can( Capabilities::ADMIN ) ) {
            return $this->read_error();
        }

        return parent::get_items_permissions_check( $request );
    }

    public function get_item_permissions_check( $request ) {
        if ( ! current_user_can( Capabilities::ADMIN ) ) {
            return $this->read_error();
        }

        return parent::get_item_permissions_check( $request );
    }

    public function check_read_permission( $post ) {
        return current_user_can( Capabilities::ADMIN ) && parent::check_read_permission( $post );
    }

    private function read_error(): WP_Error {
        return new WP_Error(
            'rest_forbidden',
            __( 'You are not allowed to read Fabrement internal records.', 'fabrement-lite' ),
            [ 'status' => rest_authorization_required_code() ]
        );
    }
}
