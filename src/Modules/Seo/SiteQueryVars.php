<?php

namespace Fabrement\Modules\Seo;

/**
 * The URL query arguments a theme or plugin registered with the `query_vars` filter, as opposed
 * to WordPress's own. An SEO plugin that strips "unregistered" arguments from URLs needs this
 * list, or those arguments silently stop working.
 */
final class SiteQueryVars {

    /** @return list<string> Public query vars beyond the ones WordPress registers itself. */
    public static function added(): array {
        global $wp;
        if ( ! $wp instanceof \WP ) {
            return [];
        }
        $core = ( new \WP() )->public_query_vars;
        // parse_request() stores the filtered list on $wp; before it ran, apply the filter here.
        $all = did_action( 'parse_request' ) ? $wp->public_query_vars : apply_filters( 'query_vars', $wp->public_query_vars ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.

        return array_values( array_diff( array_map( 'strval', (array) $all ), $core ) );
    }
}
