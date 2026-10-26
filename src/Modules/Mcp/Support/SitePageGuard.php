<?php

namespace Fabrement\Modules\Mcp\Support;

use Fabrement\Modules\NotFound\NotFoundPage;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * The refusal delete-content and set-content-status give for a page the SITE points at — the
 * front page, the posts page, the not-found page — in any of its language versions.
 *
 * Taking one of those away (trash, draft, delete) empties the home, the blog or the 404 with no
 * error anywhere, so an agent must not do it as a side effect of another task: the setting is
 * re-pointed first (the home and posts page by the administrator in Settings, the not-found page
 * with update-site-settings), and then the page is an ordinary one.
 *
 * Compared by ORIGINAL: the settings store the original of the page's translation group, so a
 * version is recognised by its original.
 */
final class SitePageGuard {

    public static function refusal( \WP_Post $post, string $action ): ?WP_Error {
        $id       = (int) $post->ID;
        $original = Translation::original_of( $id );
        $title    = (string) $post->post_title;

        foreach ( [ 'page_on_front' => 'front page', 'page_for_posts' => 'posts page' ] as $option => $label ) {
            $assigned = (int) get_option( $option );
            if ( $assigned > 0 && Translation::original_of( $assigned ) === $original ) {
                return new WP_Error(
                    'is_site_page',
                    sprintf( '"%s" is the site\'s %s (or a language version of it) — %s it and the site loses it silently. The site administrator points the setting elsewhere first (Settings → Reading), if the user wants it gone.', $title, $label, $action )
                );
            }
        }
        if ( NotFoundPage::is_assigned( $id ) ) {
            return new WP_Error(
                'is_site_page',
                sprintf( '"%s" is the site\'s not-found (404) page (or a language version of it) — clear it first with update-site-settings {notFoundPage: 0}, if the user wants it gone.', $title )
            );
        }
        return null;
    }
}
