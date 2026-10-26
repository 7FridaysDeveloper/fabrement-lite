<?php

namespace Fabrement\Tests;

/**
 * What a crawler reads in a page's <head>: the page is visited with go_to(), and what Yoast prints
 * there is taken apart. For a WP_UnitTestCase. Over real HTTP: WebRequests.
 */
trait PageHead {

    /** @return array[] The JSON-LD nodes printed at $url. */
    protected function graph_at( string $url ): array {
        // A real request is put in its language by WPML from the address before anything else
        // runs; go_to() starts after that point. WPML's own parser reads the address here.
        if ( isset( $GLOBALS['sitepress'] ) ) {
            do_action( 'wpml_switch_language', $GLOBALS['sitepress']->get_language_from_url( $url ) );
        }
        $this->go_to( $url );
        YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class )->clear();
        ob_start();
        do_action( 'wpseo_head' );
        return self::json_ld( (string) ob_get_clean() );
    }

    /** @return array|null The first node of that @type at $url. */
    protected function node_at( string $url, string $type ): ?array {
        return self::node_of( $this->graph_at( $url ), $type );
    }

    /** @return array[] The JSON-LD nodes of every ld+json block in $html. */
    protected static function json_ld( string $html ): array {
        $nodes = [];
        preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#s', $html, $m );
        foreach ( $m[1] as $json ) {
            $data = json_decode( html_entity_decode( $json ), true );
            foreach ( (array) ( $data['@graph'] ?? [ $data ] ) as $node ) {
                if ( is_array( $node ) ) {
                    $nodes[] = $node;
                }
            }
        }
        return $nodes;
    }

    /** @param array[] $nodes @return array|null The first node of that @type. */
    protected static function node_of( array $nodes, string $type ): ?array {
        foreach ( $nodes as $node ) {
            if ( in_array( $type, (array) ( $node['@type'] ?? [] ), true ) ) {
                return $node;
            }
        }
        return null;
    }
}
