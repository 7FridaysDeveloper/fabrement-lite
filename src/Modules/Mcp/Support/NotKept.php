<?php

namespace Fabrement\Modules\Mcp\Support;

use WP_Error;

/**
 * The refusal of an SEO write the plugin would not keep as sent — said before anything is written,
 * so the value it already holds stays.
 */
final class NotKept {

    /** @param array<string,string> $fields field => what the plugin would store instead */
    public static function error( array $fields ): ?WP_Error {
        if ( $fields === [] ) {
            return null;
        }
        $parts = [];
        foreach ( $fields as $field => $kept ) {
            $parts[] = $kept === ''
                ? sprintf( '%s: Yoast would not keep it', $field )
                : sprintf( '%s: Yoast would store "%s"', $field, $kept );
        }
        return new WP_Error(
            'not_kept',
            implode( '; ', $parts ) . ' — it keeps no HTML tags, no lone "<", no "%" followed by two hex digits (%%variables%% are fine), and only addresses it can read. Nothing was written.',
            [ 'fields' => $fields ]
        );
    }
}
