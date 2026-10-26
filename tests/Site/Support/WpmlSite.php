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

    /** WPML's translation_id of a record (the key of its translation status). */
    public function translation_id( int $id, string $post_type = 'page' ): int {
        return (int) $this->site->value( 'SELECT translation_id FROM wp_icl_translations WHERE element_id = ? AND element_type = ?', [ $id, self::type( $post_type ) ] );
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

    /**
     * The translation counted as complete in WPML, as its "Translation complete" switch leaves it:
     * status 10, not outdated, the original's hash of today (WPML's own TranslationManagement).
     */
    public function mark_complete( int $original, int $translation, string $post_type = 'page' ): void {
        $this->site->php( sprintf(
            "wp_set_current_user( 1 );
            wpml_load_core_tm()->update_translation_status( [
                'translation_id' => %d,
                'status'         => 10,
                'needs_update'   => 0,
                'md5'            => ( new WPML_TM_Action_Helper() )->post_md5( get_post( %d ) ),
            ] );",
            $this->translation_id( $translation, $post_type ), $original
        ) );
    }

    /**
     * Which editor translates the record, as WPML's "Translate with …" switch in its language box
     * stores it: 'wpml' (WPML's translation editor) or 'native' (the WordPress editor).
     */
    public function use_editor( int $original, string $editor ): void {
        $this->site->php( sprintf(
            "update_post_meta( %d, WPML_TM_Post_Edit_TM_Editor_Mode::POST_META_KEY_EDITOR, %s );",
            $original, $editor === 'wpml' ? 'WPML_TM_Post_Edit_TM_Editor_Mode::EDITOR_WPML' : 'WPML_TM_Post_Edit_TM_Editor_Mode::EDITOR_NATIVE'
        ) );
    }
}
