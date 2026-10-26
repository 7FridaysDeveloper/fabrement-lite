<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Which language version fabrement()->post() and posts() show on a WPML site: the version in the
 * page's language whatever its status, judged by WordPress's rules (published; private to who may
 * read it; drafts in a preview or when asked for), the same for an original and a translation, for
 * a visitor, an administrator and a type that is not public — and posts() exactly as post() per id.
 */
final class ReaderVersionsTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private const TYPE   = 'qa_rv';
    private const HIDDEN = 'qa_rv_hidden';

    private static array $pairs = [];

    protected function setUp(): void {
        parent::setUp();
        if ( self::$pairs !== [] ) {
            return;
        }
        $this->post_type( self::TYPE, [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
        $this->post_type( self::HIDDEN, [ 'translationMode' => 'translate', 'public' => false, 'supports' => [ 'title', 'editor' ] ] );
        $type   = self::TYPE;
        $hidden = self::HIDDEN;
        self::$pairs = $this->site_runs( "
            \$make = static function ( string \$type, string \$status, string \$title ): int {
                \$id = (int) wp_insert_post( [ 'post_type' => \$type, 'post_title' => \$title, 'post_author' => 1, 'post_status' => 'draft' ], true );
                global \$wpdb;
                \$wpdb->update( \$wpdb->posts, [ 'post_status' => \$status ], [ 'ID' => \$id ] );
                clean_post_cache( \$id );
                return \$id;
            };
            \$pair = static function ( string \$type, string \$original, string \$french ) use ( \$make ): array {
                \$o = \$make( \$type, \$original, \"EN \$original / FR \$french\" );
                do_action( 'wpml_set_element_language_details', [ 'element_id' => \$o, 'element_type' => 'post_' . \$type, 'trid' => false, 'language_code' => 'en', 'source_language_code' => null ] );
                \$f = 0;
                if ( \$french !== 'missing' ) {
                    \$trid = (int) apply_filters( 'wpml_element_trid', null, \$o, 'post_' . \$type );
                    \$f    = \$make( \$type, \$french, \"FR \$french / EN \$original\" );
                    do_action( 'wpml_set_element_language_details', [ 'element_id' => \$f, 'element_type' => 'post_' . \$type, 'trid' => \$trid, 'language_code' => 'fr', 'source_language_code' => 'en' ] );
                }
                return [ 'o' => \$o, 'f' => \$f ];
            };
            \$unknown = \$make( '$type', 'publish', 'No language in WPML' );
            global \$wpdb;
            \$wpdb->delete( \$wpdb->prefix . 'icl_translations', [ 'element_id' => \$unknown, 'element_type' => 'post_$type' ] );
            return [
                'unknown'        => [ 'o' => \$unknown, 'f' => 0 ],
                'published'      => \$pair( '$type', 'publish', 'publish' ),
                'frDraft'        => \$pair( '$type', 'publish', 'draft' ),
                'bothDrafts'     => \$pair( '$type', 'draft', 'draft' ),
                'enTrashed'      => \$pair( '$type', 'trash', 'publish' ),
                'frTrashed'      => \$pair( '$type', 'publish', 'trash' ),
                'frPrivate'      => \$pair( '$type', 'publish', 'private' ),
                'frMissing'      => \$pair( '$type', 'publish', 'missing' ),
                'hidden'         => \$pair( '$hidden', 'publish', 'publish' ),
                'hiddenFrMissing' => \$pair( '$hidden', 'publish', 'missing' ),
            ];" );
    }

    private function on_french_page( string $body, int $as = 0 ) {
        $user = $as > 0 ? "add_filter( 'determine_current_user', static fn() => $as, 99 );" : '';
        $this->site_code( 'qa-reader-versions', "$user
            add_action( 'template_redirect', static function () {
                if ( ! isset( \$_GET['qa_reader'] ) ) {
                    return;
                }
                \$ids = static fn( array \$posts ): array => array_map( static fn( \$p ): int => \$p->id(), \$posts );
                \$one = static fn( \$post ): int => \$post->id();
                wp_send_json( ( static function () use ( \$ids, \$one ) { $body } )() );
            }, 0 );" );
        try {
            $page = $this->get( '/fr/?qa_reader=1' );
        } finally {
            $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/qa-reader-versions.php' );
        }
        $this->assertSame( 200, $page['status'], $page['body'] );
        return json_decode( $page['body'], true );
    }

    public function test_a_visitor_gets_the_version_in_the_pages_language_by_its_own_status(): void {
        $p = self::$pairs;
        $this->assertSame( [
            'published'          => $p['published']['f'],
            'frDraft'            => 0,
            'frDraftAsked'       => $p['frDraft']['f'],
            'bothDraftsAsked'    => $p['bothDrafts']['f'],
            'enTrashed'          => $p['enTrashed']['f'],
            'frTrashedAsked'     => $p['frTrashed']['f'],
            'frPrivate'          => 0,
            'frMissing'          => 0,
            'hidden'             => $p['hidden']['f'],
            'hiddenFrMissing'    => 0,
            'theTranslationId'   => $p['published']['f'],
            'noLanguage'         => 0,
        ], $this->on_french_page( "return [
            'published'         => \$one( fabrement()->post( {$p['published']['o']} ) ),
            'frDraft'           => \$one( fabrement()->post( {$p['frDraft']['o']} ) ),
            'frDraftAsked'      => \$one( fabrement()->post( {$p['frDraft']['o']}, status: [ 'publish', 'draft' ] ) ),
            'bothDraftsAsked'   => \$one( fabrement()->post( {$p['bothDrafts']['o']}, status: [ 'publish', 'draft' ] ) ),
            'enTrashed'         => \$one( fabrement()->post( {$p['enTrashed']['o']} ) ),
            'frTrashedAsked'    => \$one( fabrement()->post( {$p['frTrashed']['o']}, status: [ 'publish', 'trash' ] ) ),
            'frPrivate'         => \$one( fabrement()->post( {$p['frPrivate']['o']} ) ),
            'frMissing'         => \$one( fabrement()->post( {$p['frMissing']['o']} ) ),
            'hidden'            => \$one( fabrement()->post( {$p['hidden']['o']} ) ),
            'hiddenFrMissing'   => \$one( fabrement()->post( {$p['hiddenFrMissing']['o']} ) ),
            'theTranslationId'  => \$one( fabrement()->post( {$p['published']['f']} ) ),
            'noLanguage'        => \$one( fabrement()->post( {$p['unknown']['o']} ) ),
        ];" ) );
    }

    public function test_an_administrator_on_a_normal_page_sees_private_versions_but_no_drafts(): void {
        $p = self::$pairs;
        $this->assertSame(
            [ 'frPrivate' => $p['frPrivate']['f'], 'frDraft' => 0, 'frDraftAsked' => $p['frDraft']['f'] ],
            $this->on_french_page( "return [
                'frPrivate'       => \$one( fabrement()->post( {$p['frPrivate']['o']} ) ),
                'frDraft'         => \$one( fabrement()->post( {$p['frDraft']['o']} ) ),
                'frDraftAsked'    => \$one( fabrement()->post( {$p['frDraft']['o']}, status: [ 'publish', 'draft' ] ) ),
            ];", 1 )
        );
    }

    public function test_posts_shows_what_post_shows_for_each_id_in_the_order_given_each_once(): void {
        $p    = self::$pairs;
        $list = implode( ', ', array_map( static fn( array $pair ): int => $pair['o'], $p ) ) . ', ' . $p['published']['f'];
        foreach ( [ 0, 1 ] as $as ) {
            foreach ( [ '', ", status: [ 'publish', 'draft', 'trash' ]", ", lang: 'en'" ] as $args ) {
                [ $many, $each ] = $this->on_french_page( "
                    \$given = [ $list ];
                    \$many  = \$ids( fabrement()->posts( \$given$args ) );
                    \$each  = array_values( array_unique( array_filter( array_map( static fn( int \$id ): int => \$one( fabrement()->post( \$id$args ) ), \$given ) ) ) );
                    return [ \$many, \$each ];", $as );
                $this->assertSame( $each, $many, ( $as ? 'administrator' : 'visitor' ) . " with$args" );
            }
        }
    }
}
