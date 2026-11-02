<?php

namespace Fabrement\Tests\Site\Support;

/**
 * What WPML keeps about the site's content, read straight from its tables — the language of a
 * record, its translation group, a translation's "needs update" flag — and the few things only a
 * person in WPML's own screens does, done as those screens do them: through WPML's public API in
 * the site's own code (WP-CLI), never by writing its tables.
 */
final class WpmlSite {

    public function __construct( private Site $site ) {}

    /** WPML's element type of a post type: post_page, post_post, post_qa_talk, post_attachment. */
    public static function type( string $post_type ): string {
        return 'post_' . $post_type;
    }

    /** The language WPML holds for a record; null when it holds none. */
    public function language( int $id, string $post_type = 'page' ): ?string {
        return $this->site->value( 'SELECT language_code FROM wp_icl_translations WHERE element_id = ? AND element_type = ?', [ $id, self::type( $post_type ) ] );
    }

    /** The record's translation group (trid); 0 when WPML holds none. */
    public function trid( int $id, string $post_type = 'page' ): int {
        return (int) $this->site->value( 'SELECT trid FROM wp_icl_translations WHERE element_id = ? AND element_type = ?', [ $id, self::type( $post_type ) ] );
    }

    /** @return array<string,int> language => record id, every member of the record's group. */
    public function group( int $id, string $post_type = 'page' ): array {
        $trid = $this->trid( $id, $post_type );
        if ( $trid === 0 ) {
            return [];
        }
        $rows = $this->site->rows( 'SELECT language_code, element_id FROM wp_icl_translations WHERE trid = ? AND element_type = ? AND element_id IS NOT NULL ORDER BY language_code', [ $trid, self::type( $post_type ) ] );
        return array_map( 'intval', array_column( $rows, 'element_id', 'language_code' ) );
    }

    /** The language version of a record in $lang; 0 when there is none. */
    public function version( int $id, string $lang, string $post_type = 'page' ): int {
        return $this->group( $id, $post_type )[ $lang ] ?? 0;
    }

    /** WPML's "needs update" flag of a translation; null when WPML keeps no status for it. */
    public function needs_update( int $translation, string $post_type = 'page' ): ?int {
        $flag = $this->site->value(
            'SELECT s.needs_update FROM wp_icl_translation_status s JOIN wp_icl_translations t ON t.translation_id = s.translation_id WHERE t.element_id = ? AND t.element_type = ?',
            [ $translation, self::type( $post_type ) ]
        );
        return $flag === null ? null : (int) $flag;
    }

    /**
     * Link a record into a translation group as WPML's own language box does when a person picks
     * "This is a translation of …" — WPML's public action, not our plugin.
     */
    public function link( int $id, int $trid, string $lang, string $post_type = 'page', string $source = 'en' ): void {
        $this->site->php( sprintf(
            "do_action( 'wpml_set_element_language_details', [ 'element_id' => %d, 'element_type' => '%s', 'trid' => %d, 'language_code' => '%s', 'source_language_code' => '%s' ] );",
            $id, self::type( $post_type ), $trid, $lang, $source
        ) );
    }

    /**
     * "Duplicate" in WPML's language box of the original, as a person clicks it (WPML's own
     * make_duplicate()). @return int The duplicate's id.
     */
    public function duplicate( int $id, string $lang ): int {
        return (int) trim( $this->site->php( sprintf( "wp_set_current_user( 1 ); global \$sitepress; echo (int) \$sitepress->make_duplicate( %d, '%s' );", $id, $lang ) ) );
    }
}
