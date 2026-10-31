<?php

namespace Fabrement\Route;

use WP_Error;
use WP_REST_Posts_Controller;

/** Keep Gutenberg's REST support without making published internal records public. */
final class InternalPostsRestController extends WP_REST_Posts_Controller {

    public function get_items_permissions_check( $request ) {
        if ( ! $this->may_edit() ) {
            return $this->read_error();
        }

        return parent::get_items_permissions_check( $request );
    }

    public function get_item_permissions_check( $request ) {
        if ( ! $this->may_edit() ) {
            return $this->read_error();
        }

        return parent::get_item_permissions_check( $request );
    }

    public function check_read_permission( $post ) {
        return $this->may_edit() && parent::check_read_permission( $post );
    }

    /** Reading an internal record takes its type's own edit cap (a template: Capabilities::TEMPLATES). */
    private function may_edit(): bool {
        $type = get_post_type_object( $this->post_type );
        return $type !== null && current_user_can( $type->cap->edit_posts );
    }

    private function read_error(): WP_Error {
        return new WP_Error(
            'rest_forbidden',
            __( 'You are not allowed to read Fabrement internal records.', 'fabrement-lite' ),
            [ 'status' => rest_authorization_required_code() ]
        );
    }
}
