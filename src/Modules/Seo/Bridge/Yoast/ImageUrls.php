<?php

namespace Fabrement\Modules\Seo\Bridge\Yoast;

/**
 * Yoast keeps an image twice: the attachment id and, beside it, the address it prints. A write of
 * the id brings the address with it, for every store of the bridge (post meta, term meta, options).
 *
 * @internal
 */
final class ImageUrls {

    /**
     * @param array<string,mixed>  $fields
     * @param array<string,string> $map     id field => the field holding its address
     * @param array<string,mixed>  $cleared id field => what a cleared id is stored as, for stores
     *                                      that keep the id themselves rather than encode it
     * @return array<string,mixed> the fields with each given image id's address beside it
     */
    public static function fill( array $fields, array $map, array $cleared = [] ): array {
        foreach ( $map as $id_field => $url_field ) {
            if ( ! array_key_exists( $id_field, $fields ) ) {
                continue;
            }
            $id = (int) $fields[ $id_field ];
            if ( array_key_exists( $id_field, $cleared ) ) {
                $fields[ $id_field ] = $id > 0 ? $id : $cleared[ $id_field ];
            }
            $fields[ $url_field ] = $id > 0 ? (string) wp_get_attachment_image_url( $id, 'full' ) : '';
        }
        return $fields;
    }
}
