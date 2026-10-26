<?php

namespace Fabrement\Modules\Seo\Integration;

use Fabrement\Modules\Seo\PrimaryTerm;
use Fabrement\Modules\Seo\Seo;

/**
 * Yoast's main category kept in step with a record's terms — on Fabrement's own event
 * ({@see \Fabrement\Modules\Seo\TermChanges}), hooked by YoastIntegration only while Yoast runs.
 *
 * Yoast keeps it right only on its own screens: in the editor its JS moves the choice when the
 * chosen category is unticked, and quick edit checks it on the server. A term change through
 * wp_set_object_terms rebuilds nothing of Yoast's, so the terms Fabrement's tools change
 * (set-post-terms, a deleted term) would leave the old choice and the old breadcrumb trail
 * behind. Changes made elsewhere — WP-CLI, imports, another plugin — are Yoast's business.
 */
final class YoastPrimaryTerm {

    /** @var array<int,true> Records whose primary terms Yoast has to rebuild at the end of the request. */
    private static array $pending = [];

    /**
     * A term of `$taxonomy` came on or off record `$post_id`: drop a main term it no longer holds,
     * and mark the record for a rebuild when its breadcrumb trail or its address can follow the change.
     */
    public static function terms_changed( $post_id, $taxonomy ): void {
        $post_id  = (int) $post_id;
        $taxonomy = (string) $taxonomy;
        $seo      = Seo::bridge();
        $stored   = $seo->primary_term( $post_id, $taxonomy );
        if ( $stored > 0 && ! PrimaryTerm::holds( $post_id, $taxonomy, $stored ) ) {
            $seo->set_primary_term( $post_id, $taxonomy, 0 );
        }
        // A hierarchical taxonomy can name the record's address (a category in %category%, the
        // main one or the lowest id); the trail runs through the breadcrumb taxonomy, a flat one too,
        // even when no main term was chosen (Yoast then takes the deepest term).
        if ( $stored > 0 || is_taxonomy_hierarchical( $taxonomy ) || $taxonomy === $seo->breadcrumb_taxonomy( (string) get_post_type( $post_id ) ) ) {
            self::queue( $post_id );
        }
    }

    /**
     * Yoast's own tables — its primary-term row and the record's breadcrumb trail — brought up to
     * date once for every record changed in this request, at the end, when the terms and the choice
     * are both final (a REST save sets the terms AFTER the post). Runs on `shutdown`; tests call it.
     */
    public static function sync(): void {
        $posts         = array_keys( self::$pending );
        self::$pending = [];
        foreach ( $posts as $post_id ) {
            // Deleting a record takes its terms off too; a record gone by now needs nothing.
            if ( get_post( $post_id ) ) {
                Seo::bridge()->rebuild_primary_terms( $post_id );
            }
        }
    }

    private static function queue( int $post_id ): void {
        if ( $post_id <= 0 ) {
            return;
        }
        if ( self::$pending === [] ) {
            add_action( 'shutdown', [ self::class, 'sync' ], 0 );
        }
        self::$pending[ $post_id ] = true;
    }
}
