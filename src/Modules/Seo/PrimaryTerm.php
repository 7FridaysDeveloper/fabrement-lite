<?php

namespace Fabrement\Modules\Seo;

use Fabrement\Modules\Translation\Translation;

/**
 * A record's MAIN term in a hierarchical taxonomy — the category its breadcrumbs and category URL
 * use when it has several. The SEO plugin's notion, kept in its store ({@see Seo::bridge()}); none
 * without one.
 *
 * A choice the record no longer holds is dropped by the plugin's integration when the terms change
 * ({@see TermChanges}, Yoast: {@see Integration\YoastPrimaryTerm}).
 */
final class PrimaryTerm {

    /** @return list<string> The taxonomies of a type whose records can have one. */
    public static function taxonomies( string $post_type ): array {
        return Seo::bridge()->primary_taxonomies( $post_type );
    }

    /** The main term id; 0 when none is chosen or it is no longer one of the record's terms. */
    public static function get( int $post_id, string $taxonomy ): int {
        $id = Seo::bridge()->primary_term( $post_id, $taxonomy );
        return $id > 0 && self::holds( $post_id, $taxonomy, $id ) ? $id : 0;
    }

    /**
     * Choose the main term (0 clears it). The caller checks that the record holds it — and calls
     * this once the terms are final, so what the plugin derives from them is rebuilt now.
     */
    public static function set( int $post_id, string $taxonomy, int $term_id ): void {
        Seo::bridge()->set_primary_term( $post_id, $taxonomy, $term_id );
        Seo::bridge()->rebuild_primary_terms( $post_id );
    }

    /**
     * In the RECORD's language: a translation plugin maps the record's terms to the request's
     * language, and the stored id (the record's own term) would then match none of them.
     */
    public static function holds( int $post_id, string $taxonomy, int $term_id ): bool {
        $language = Translation::is_multilingual() ? Translation::live_language_of( $post_id ) : '';
        return (bool) Translation::bridge()->in_language(
            $language,
            static fn(): bool => has_term( $term_id, $taxonomy, $post_id )
        );
    }
}
