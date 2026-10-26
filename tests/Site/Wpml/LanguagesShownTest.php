<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Records and terms fabrement() shows, read on real pages of a three-language WPML site (en
 * default, fr, pl) and in admin-ajax: in the page's language or in `lang`, as WPML shows them, for
 * every kind of translation group — with a copy, without one, created in French with or without an
 * English copy — in "translate", "display as translated" and untranslated types and taxonomies.
 */
final class LanguagesShownTest extends SiteTestCase {

    protected const SITE = 'wpml';

    /** @var array<string, array<string, mixed>> */
    private static array $pages = [];

    protected function setUp(): void {
        parent::setUp();
        if ( self::$pages ) {
            return;
        }
        $this->post_type( 'qa_ls_t', [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
        $this->post_type( 'qa_ls_f', [ 'translationMode' => 'fallback', 'supports' => [ 'title', 'editor' ] ] );
        foreach ( [ 'qa_ls_tt' => 'translate', 'qa_ls_tf' => 'fallback', 'qa_ls_tn' => 'none' ] as $tax => $mode ) {
            $this->taxonomy( $tax, [ 'singular' => ucfirst( $tax ), 'postTypes' => [ 'qa_ls_t' ], 'translationMode' => $mode ] );
        }
        $this->site_runs( "global \$wpdb;
            \$tt = static fn( int \$id ): int => (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT term_taxonomy_id FROM {\$wpdb->term_taxonomy} WHERE term_id = %d\", \$id ) );
            \$link = static function ( int \$id, int \$of, string \$type, string \$lang, string \$from ) use ( \$wpdb ): void {
                \$trid = (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT trid FROM {\$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = %s\", \$of, \$type ) );
                do_action( 'wpml_set_element_language_details', [ 'element_id' => \$id, 'element_type' => \$type, 'trid' => \$trid, 'language_code' => \$lang, 'source_language_code' => \$from ] );
            };
            \$term = static function ( string \$name, string \$tax, string \$lang, int \$of = 0, string \$from = 'en' ) use ( \$tt, \$link ): int {
                do_action( 'wpml_switch_language', \$lang );
                \$id = (int) wp_insert_term( \$name, \$tax, [ 'slug' => sanitize_title( \$name ) ] )['term_id'];
                if ( \$of ) { \$link( \$tt( \$id ), \$tt( \$of ), 'tax_' . \$tax, \$lang, \$from ); }
                return \$id;
            };
            \$post = static function ( string \$title, string \$type, string \$lang, int \$of = 0, string \$from = 'en' ) use ( \$link ): int {
                do_action( 'wpml_switch_language', \$lang );
                \$id = (int) wp_insert_post( [ 'post_title' => \$title, 'post_status' => 'publish', 'post_type' => \$type ] );
                if ( \$of ) { \$link( \$id, \$of, 'post_' . \$type, \$lang, \$from ); }
                return \$id;
            };
            \$ids = [];
            foreach ( [ 't' => 'qa_ls_tt', 'f' => 'qa_ls_tf' ] as \$p => \$tax ) {
                \$ids[ \$p . 'Copy' ]     = \$term( \"\$p copy EN\", \$tax, 'en' );
                \$ids[ \$p . 'CopyFr' ]   = \$term( \"\$p copy FR\", \$tax, 'fr', \$ids[ \$p . 'Copy' ] );
                \$ids[ \$p . 'Alone' ]    = \$term( \"\$p alone EN\", \$tax, 'en' );
                \$ids[ \$p . 'FrOrig' ]   = \$term( \"\$p origin FR\", \$tax, 'fr' );
                \$ids[ \$p . 'FrOrigEn' ] = \$term( \"\$p origin FR en\", \$tax, 'en', \$ids[ \$p . 'FrOrig' ], 'fr' );
                \$ids[ \$p . 'FrAlone' ]  = \$term( \"\$p alone FR\", \$tax, 'fr' );
            }
            \$ids['plain'] = \$term( 'plain one', 'qa_ls_tn', 'en' );
            foreach ( [ 'p' => 'qa_ls_t', 'g' => 'qa_ls_f' ] as \$p => \$type ) {
                \$ids[ \$p . 'Copy' ]     = \$post( \"\$p copy EN\", \$type, 'en' );
                \$ids[ \$p . 'CopyFr' ]   = \$post( \"\$p copy FR\", \$type, 'fr', \$ids[ \$p . 'Copy' ] );
                \$ids[ \$p . 'Alone' ]    = \$post( \"\$p alone EN\", \$type, 'en' );
                \$ids[ \$p . 'FrOrig' ]   = \$post( \"\$p origin FR\", \$type, 'fr' );
                \$ids[ \$p . 'FrOrigEn' ] = \$post( \"\$p origin FR en\", \$type, 'en', \$ids[ \$p . 'FrOrig' ], 'fr' );
            }
            do_action( 'wpml_switch_language', 'en' );
            \$user = (int) wp_insert_post( [ 'post_title' => 'Uses the terms', 'post_status' => 'publish', 'post_type' => 'qa_ls_t' ] );
            foreach ( [ 'qa_ls_tt', 'qa_ls_tf', 'qa_ls_tn' ] as \$tax ) {
                wp_set_object_terms( \$user, array_values( array_filter( \$ids, static fn( int \$id ): bool => ( \$t = get_term( \$id ) ) instanceof WP_Term && \$t->taxonomy === \$tax ) ), \$tax );
            }
            update_option( 'qa_languages_shown', \$ids );
            return true;" );
        $this->site_code( 'qa-languages-shown', "\$qa_shown = static function (): array {
            \$ids   = get_option( 'qa_languages_shown' );
            \$names = [ 'term' => [], 'post' => [] ];
            foreach ( \$ids as \$key => \$id ) {
                \$names[ preg_match( '/^[pg][A-Z]/', \$key ) === 1 ? 'post' : 'term' ][ \$id ] = \$key;
            }
            \$say   = static fn( \$one ): string => \$one->not_empty() ? ( \$names[ \$one instanceof \Fabrement\Api\Content\Post ? 'post' : 'term' ][ \$one->id() ] ?? (string) \$one->id() ) . ( str_contains( (string) \$one->url(), '/fr/' ) ? ' fr' : ' en' ) : '-';
            \$out   = [];
            foreach ( [ 'tCopy', 'tCopyFr', 'tAlone', 'tFrOrig', 'tFrAlone', 'fCopy', 'fAlone', 'fFrOrig', 'fFrAlone', 'plain' ] as \$key ) {
                \$out['term'][ \$key ] = [ \$say( fabrement()->term( \$ids[ \$key ] ) ), \$say( fabrement()->term( \$ids[ \$key ], lang: 'en' ) ), \$say( fabrement()->term( \$ids[ \$key ], lang: 'fr' ) ) ];
            }
            foreach ( [ 'pCopy', 'pCopyFr', 'pAlone', 'pFrOrig', 'gCopy', 'gAlone', 'gFrOrig' ] as \$key ) {
                \$out['post'][ \$key ] = [ \$say( fabrement()->post( \$ids[ \$key ] ) ), \$say( fabrement()->post( \$ids[ \$key ], lang: 'en' ) ), \$say( fabrement()->post( \$ids[ \$key ], lang: 'fr' ) ) ];
            }
            \$list = static fn( array \$all ): array => array_map( \$say, \$all );
            \$terms = [ \$ids['tCopy'], \$ids['tAlone'], \$ids['tFrOrig'], \$ids['fAlone'], \$ids['plain'] ];
            \$posts = [ \$ids['pCopy'], \$ids['pAlone'], \$ids['pFrOrig'], \$ids['gAlone'] ];
            \$out['lists'] = [
                'terms'          => \$list( fabrement()->terms( \$terms ) ),
                'terms en'       => \$list( fabrement()->terms( \$terms, lang: 'en' ) ),
                'terms tt'       => \$list( fabrement()->terms( \$terms, taxonomy: 'qa_ls_tt' ) ),
                'taxonomy tt'    => \$list( fabrement()->terms( taxonomy: 'qa_ls_tt' ) ),
                'taxonomy tt+tf' => \$list( fabrement()->terms( taxonomy: [ 'qa_ls_tt', 'qa_ls_tf' ] ) ),
                'native tt+tf'   => array_map( static fn( WP_Term \$t ): string => ( \$names['term'][ \$t->term_id ] ?? '' ) . ( str_contains( (string) get_term_link( \$t ), '/fr/' ) ? ' fr' : ' en' ), get_terms( [ 'taxonomy' => [ 'qa_ls_tt', 'qa_ls_tf' ] ] ) ),
                'posts'          => \$list( fabrement()->posts( \$posts ) ),
                'posts fr'       => \$list( fabrement()->posts( \$posts, lang: 'fr' ) ),
            ];
            return \$out;
        };
        add_action( 'template_redirect', static function () use ( \$qa_shown ): void {
            if ( isset( \$_GET['qa_shown'] ) ) { wp_send_json( \$qa_shown() ); }
        } );
        add_action( 'wp_ajax_nopriv_qa_shown', static function () use ( \$qa_shown ): void { wp_send_json( \$qa_shown() ); } );" );
        foreach ( [ 'en' => '/?qa_shown=1', 'fr' => '/fr/?qa_shown=1', 'admin fr' => '/wp-admin/admin-ajax.php?action=qa_shown&lang=fr' ] as $page => $path ) {
            $response = $this->get( $path );
            $this->assertSame( 200, $response['status'], $response['body'] );
            self::$pages[ $page ] = json_decode( $response['body'], true );
        }
    }

    public static function tearDownAfterClass(): void {
        self::$pages = [];
        parent::tearDownAfterClass();
    }

    /** [ term(), term( lang: 'en' ), term( lang: 'fr' ) ] of each term: its id and the language of its address. */
    public function test_a_term_is_its_version_in_the_language_asked_for(): void {
        $this->assertSame( [
            'tCopy'    => [ 'tCopy en', 'tCopy en', 'tCopyFr fr' ],
            'tCopyFr'  => [ 'tCopy en', 'tCopy en', 'tCopyFr fr' ],
            'tAlone'   => [ 'tAlone en', 'tAlone en', '-' ],
            'tFrOrig'  => [ 'tFrOrigEn en', 'tFrOrigEn en', 'tFrOrig fr' ],
            'tFrAlone' => [ '-', '-', 'tFrAlone fr' ],
            'fCopy'    => [ 'fCopy en', 'fCopy en', 'fCopyFr fr' ],
            'fAlone'   => [ 'fAlone en', 'fAlone en', 'fAlone fr' ],
            'fFrOrig'  => [ 'fFrOrigEn en', 'fFrOrigEn en', 'fFrOrig fr' ],
            'fFrAlone' => [ '-', '-', 'fFrAlone fr' ],
            'plain'    => [ 'plain en', 'plain en', 'plain fr' ],
        ], self::$pages['en']['term'], 'the English page' );
        $this->assertSame( [
            'tCopy'    => [ 'tCopyFr fr', 'tCopy en', 'tCopyFr fr' ],
            'tCopyFr'  => [ 'tCopyFr fr', 'tCopy en', 'tCopyFr fr' ],
            'tAlone'   => [ '-', 'tAlone en', '-' ],
            'tFrOrig'  => [ 'tFrOrig fr', 'tFrOrigEn en', 'tFrOrig fr' ],
            'tFrAlone' => [ 'tFrAlone fr', '-', 'tFrAlone fr' ],
            'fCopy'    => [ 'fCopyFr fr', 'fCopy en', 'fCopyFr fr' ],
            'fAlone'   => [ 'fAlone fr', 'fAlone en', 'fAlone fr' ],
            'fFrOrig'  => [ 'fFrOrig fr', 'fFrOrigEn en', 'fFrOrig fr' ],
            'fFrAlone' => [ 'fFrAlone fr', '-', 'fFrAlone fr' ],
            'plain'    => [ 'plain fr', 'plain en', 'plain fr' ],
        ], self::$pages['fr']['term'], 'the French page: an English term of a "display as translated" taxonomy at the French address, as WPML shows it' );
        $this->assertSame( [ '-', 'fAlone en', '-' ], self::$pages['admin fr']['term']['fAlone'], 'wp-admin: WPML falls back to the default language on the site only' );
        $this->assertSame( self::$pages['fr']['term']['tCopy'], self::$pages['admin fr']['term']['tCopy'] );
    }

    /** [ post(), post( lang: 'en' ), post( lang: 'fr' ) ] of each record. */
    public function test_a_record_is_its_version_in_the_language_asked_for(): void {
        $this->assertSame( [
            'pCopy'   => [ 'pCopy en', 'pCopy en', 'pCopyFr fr' ],
            'pCopyFr' => [ 'pCopy en', 'pCopy en', 'pCopyFr fr' ],
            'pAlone'  => [ 'pAlone en', 'pAlone en', '-' ],
            'pFrOrig' => [ 'pFrOrigEn en', 'pFrOrigEn en', 'pFrOrig fr' ],
            'gCopy'   => [ 'gCopy en', 'gCopy en', 'gCopyFr fr' ],
            'gAlone'  => [ 'gAlone en', 'gAlone en', 'gAlone fr' ],
            'gFrOrig' => [ 'gFrOrigEn en', 'gFrOrigEn en', 'gFrOrig fr' ],
        ], self::$pages['en']['post'], 'the English page: a record created in French shows its English version' );
        $this->assertSame( [
            'pCopy'   => [ 'pCopyFr fr', 'pCopy en', 'pCopyFr fr' ],
            'pCopyFr' => [ 'pCopyFr fr', 'pCopy en', 'pCopyFr fr' ],
            'pAlone'  => [ '-', 'pAlone en', '-' ],
            'pFrOrig' => [ 'pFrOrig fr', 'pFrOrigEn en', 'pFrOrig fr' ],
            'gCopy'   => [ 'gCopyFr fr', 'gCopy en', 'gCopyFr fr' ],
            'gAlone'  => [ 'gAlone fr', 'gAlone en', 'gAlone fr' ],
            'gFrOrig' => [ 'gFrOrig fr', 'gFrOrigEn en', 'gFrOrig fr' ],
        ], self::$pages['fr']['post'], 'the French page' );
        $this->assertSame( [ 'pFrOrig fr', 'pFrOrigEn en', 'pFrOrig fr' ], self::$pages['admin fr']['post']['pFrOrig'], 'wp-admin, where WPML turns no ids itself' );
    }

    public function test_lists_follow_the_same_rules_and_a_taxonomy_lists_what_get_terms_lists(): void {
        $this->assertSame( [ 'tCopy en', 'tAlone en', 'tFrOrigEn en', 'fAlone en', 'plain en' ], self::$pages['en']['lists']['terms'] );
        $this->assertSame( [ 'tCopyFr fr', 'tFrOrig fr', 'fAlone fr', 'plain fr' ], self::$pages['fr']['lists']['terms'] );
        $this->assertSame( [ 'tCopy en', 'tAlone en', 'tFrOrigEn en', 'fAlone en', 'plain en' ], self::$pages['fr']['lists']['terms en'] );
        $this->assertSame( [ 'tCopyFr fr', 'tFrOrig fr' ], self::$pages['fr']['lists']['terms tt'], 'ids and a taxonomy: only that taxonomy' );
        foreach ( [ 'en', 'fr', 'admin fr' ] as $page ) {
            $this->assertSame( self::$pages[ $page ]['lists']['native tt+tf'], self::$pages[ $page ]['lists']['taxonomy tt+tf'], $page . ': a taxonomy lists what get_terms() lists there' );
        }
        $this->assertSame( [ 'tAlone en', 'tCopy en', 'tFrOrigEn en' ], self::$pages['en']['lists']['taxonomy tt'] );
        $this->assertSame( [ 'pCopy en', 'pAlone en', 'pFrOrigEn en', 'gAlone en' ], self::$pages['en']['lists']['posts'] );
        $this->assertSame( [ 'pCopyFr fr', 'pFrOrig fr', 'gAlone fr' ], self::$pages['en']['lists']['posts fr'] );
        $this->assertSame( [ 'pCopyFr fr', 'pFrOrig fr', 'gAlone fr' ], self::$pages['fr']['lists']['posts'] );
    }
}
