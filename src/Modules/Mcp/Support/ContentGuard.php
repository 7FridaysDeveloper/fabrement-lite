<?php

namespace Fabrement\Modules\Mcp\Support;

/**
 * The one answer to "that id is not content on this site".
 *
 * Eight tools reach this conclusion and each used to spell it out again, which is how the same
 * situation came to be reported under two different codes. What each tool counts AS content stays
 * where it is — one asks is_post_type_viewable(), another an explicit list of types, and
 * delete-content has a whole answer of its own for a media file. Those differences are deliberate,
 * and folding them together behind flags would cost more than the eight copies ever did.
 */
final class ContentGuard {

    public static function not_found( int $id ): \WP_Error {
        return new \WP_Error( 'not_found', self::not_found_message( $id ) );
    }

    /** For a caller that reports through its own exception type rather than a WP_Error. */
    public static function not_found_message( int $id ): string {
        return sprintf( 'No content with id %d — see list-content.', $id );
    }
}
