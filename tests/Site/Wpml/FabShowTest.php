<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * What fabrement() shows of a stored id on a three-language WPML site (en default, fr, pl), as WPML
 * shows it: the published version in the page's language or in `lang`; without one, as WPML is set
 * for the type ("translate" → nothing, "fallback" → the site's default language). An object asked
 * for in a language reads everything inside it. Fields keep the original's id.
 */
final class FabShowTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private const TRANSLATED = 'qa_show_t';
    private const FALLBACK = 'qa_show_f';
    private const TAXONOMY = 'qa_show_tax';

    /** Site code: $ids( list<Post|Term> ) gives their ids, in order. */
    private const IDS = '$ids = static fn( array $shown ): array => array_map( static fn( $one ): int => $one->id(), $shown );';

    /** Site code: $echo( fn() => … ) gives [ what the call returned, what it printed ]. */
    private const PRINTED = '$echo = static function ( callable $print ): array { ob_start(); $returned = $print(); return [ $returned, ob_get_clean() ]; };';

    /** Site code: $shown( fn() => … ) gives what the call printed. */
    private const SHOWN = '$shown = static function ( callable $draw ): string { ob_start(); $draw(); return (string) ob_get_clean(); };';

    /** @var array<string, int> */
    private static array $ids = [];

    protected function setUp(): void {
        parent::setUp();
        if ( self::$ids ) {
            return;
        }
        $this->post_type( self::TRANSLATED, [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
        $this->post_type( self::FALLBACK, [ 'translationMode' => 'fallback', 'supports' => [ 'title', 'editor' ] ] );
        $version = function ( int $id, string $lang, string $slug, bool $publish = true ): int {
            $out = (int) $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => $lang, 'slug' => $slug ] )['contentId'];
            if ( $publish ) {
                $this->mcp( 'set-content-status', [ 'contentId' => $out, 'status' => 'publish' ] );
            }
            return $out;
        };
        $ids = [];
        $ids['alpha']   = $this->record( self::TRANSLATED, 'Alpha' );
        $ids['alphaFr'] = $version( $ids['alpha'], 'fr', 'qa-alpha-fr' );
        $ids['beta']    = $this->record( self::TRANSLATED, 'Beta' );          // no French version
        $ids['delta']   = $this->record( self::TRANSLATED, 'Delta' );
        $ids['deltaFr'] = $version( $ids['delta'], 'fr', 'qa-delta-fr', false ); // a French draft
        $ids['gamma']   = $this->record( self::FALLBACK, 'Gamma' );           // fallback, no French version
        $this->taxonomy( self::TAXONOMY, [ 'singular' => 'Show topic', 'postTypes' => [ self::TRANSLATED ], 'translationMode' => 'translate' ] );
        $ids['kappa']   = (int) $this->mcp( 'create-term', [ 'taxonomy' => self::TAXONOMY, 'name' => 'Kappa' ] )['termId'];
        $ids['lambda']  = (int) $this->mcp( 'create-term', [ 'taxonomy' => self::TAXONOMY, 'name' => 'Lambda' ] )['termId'];
        $this->mcp( 'translate-term', [ 'termId' => $ids['kappa'], 'language' => 'fr', 'name' => 'Kappa FR', 'slug' => 'kappa-fr' ] );
        $kappa_fr = (int) $this->site_runs( "return (int) apply_filters( 'wpml_object_id', {$ids['kappa']}, '" . self::TAXONOMY . "', false, 'fr' );" );
        $this->site_runs( "global \$wpdb; \$wpdb->update( \$wpdb->terms, [ 'name' => 'Kappa <b>&</b> FR' ], [ 'term_id' => $kappa_fr ] ); clean_term_cache( $kappa_fr ); return true;" );
        self::$ids = $ids;
    }

    public static function tearDownAfterClass(): void {
        self::$ids = [];
        parent::tearDownAfterClass();
    }

    /** Run $body in French, as a French page is drawn. */
    private function in_french( string $body ) {
        return $this->site_runs( "do_action( 'wpml_switch_language', 'fr' ); " . $body );
    }

    public function test_a_record_is_shown_in_the_pages_language_as_wpml_is_set(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr, 'beta' => $b, 'delta' => $d, 'gamma' => $g ] = self::$ids;
        $id = static fn( string $call ): string => "( {$call} )->id()";
        $this->assertSame( [
            $a_fr,  // its French version
            $a_fr,  // given the French id, the same
            0,     // translate type, no French version: nothing, as WPML shows it
            0,     // a French draft is not shown
            $g,    // fallback type: the default language
            $a,    // lang: 'en'
            $a,    // lang: 'en' from the French id
        ], $this->in_french( 'return [ '
            . $id( "fabrement()->post( $a )" ) . ', '
            . $id( "fabrement()->post( $a_fr )" ) . ', '
            . $id( "fabrement()->post( $b )" ) . ', '
            . $id( "fabrement()->post( $d )" ) . ', '
            . $id( "fabrement()->post( $g )" ) . ', '
            . $id( "fabrement()->post( $a, lang: 'en' )" ) . ', '
            . $id( "fabrement()->post( $a_fr, lang: 'en' )" )
            . ' ];' ) );
    }

    /** A Post knows the language of the version shown; to_array() is that version's. */
    public function test_a_post_is_the_version_shown_in_its_language(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr, 'beta' => $b ] = self::$ids;
        $this->assertSame( [ [ $a_fr, 'fr', $a_fr ], [ $a, 'en', $a ], [ 0, null, [] ] ], $this->in_french( "return [
            [ fabrement()->post( $a )->id(), fabrement()->post( $a )->language(), fabrement()->post( $a )->to_array()['id'] ],
            [ fabrement()->post( $a_fr, lang: 'en' )->id(), fabrement()->post( $a_fr, lang: 'en' )->language(), fabrement()->post( $a_fr, lang: 'en' )->to_array()['id'] ],
            [ fabrement()->post( $b )->id(), fabrement()->post( $b )->language(), fabrement()->post( $b )->to_array() ],
        ];" ) );
    }

    public function test_a_list_keeps_its_order_shows_a_record_once_and_drops_what_cannot_be_shown(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr, 'beta' => $b, 'gamma' => $g ] = self::$ids;
        $this->assertSame(
            [ [ $a_fr, $g ], [ $a, $b, $g ] ],
            $this->in_french( self::IDS . " return [
                \$ids( fabrement()->posts( [ $a, $b, $g, $a ] ) ),
                \$ids( fabrement()->posts( [ $a, $b, $g, $a ], lang: 'en' ) ),
            ];" )
        );
    }

    public function test_a_longer_list_costs_no_more_queries_than_one_record(): void {
        [ 'alpha' => $a, 'beta' => $b, 'delta' => $d ] = self::$ids;
        [ $one, $three, $shown ] = $this->in_french( self::IDS . " global \$wpdb;
            \$cost = static function ( array \$list ) use ( \$wpdb ): array {
                wp_cache_flush();
                fabrement()->posts( [ $b ] );
                wp_cache_flush();
                \$before = \$wpdb->num_queries;
                \$posts  = fabrement()->posts( \$list );
                return [ \$wpdb->num_queries - \$before, \$posts ];
            };
            [ \$one ]           = \$cost( [ $a ] );
            [ \$three, \$posts ] = \$cost( [ $a, $b, $d ] );
            return [ \$one, \$three, \$ids( \$posts ) ];" );
        $this->assertSame( $one, $three, 'the records, their language versions and those versions: one query each, however many ids of a type' );
        $this->assertSame( [ self::$ids['alphaFr'] ], $shown, 'Beta has no French version and the French Delta is a draft' );
    }

    public function test_images_and_files_are_the_copies_in_the_pages_language(): void {
        $image = $this->image( 'qa-fab-show-many.png' );
        $this->assertSame( [ true, true ], $this->in_french( "return [
            fabrement()->attachments( [ $image ] )[0]->id() === fabrement()->attachment( $image )->id(),
            fabrement()->attachments( [ $image ], lang: 'en' )[0]->id() === fabrement()->attachment( $image, lang: 'en' )->id(),
        ];" ) );
    }

    public function test_a_list_of_records_shown_in_french_costs_what_one_costs(): void {
        $ids = [];
        foreach ( [ 'One', 'Two', 'Three', 'Four' ] as $title ) {
            $id = $this->record( self::TRANSLATED, 'Cost ' . $title );
            $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => 'fr' ] )['contentId'];
            $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
            $ids[] = $id;
        }
        $cost = fn( string $list ): int => (int) $this->in_french( "global \$wpdb;
            wp_cache_flush();
            \$before = \$wpdb->num_queries;
            foreach ( fabrement()->posts( [ $list ] ) as \$p ) {
                \$p->url(); \$p->date(); \$p->language();
            }
            return \$wpdb->num_queries - \$before;" );
        $cost( implode( ', ', $ids ) );
        $this->assertSame( $cost( (string) $ids[0] ), $cost( implode( ', ', $ids ) ), 'four records shown, with their address, date and language, cost what one does, as WP_Query reads them' );
    }

    public function test_the_version_shown_decides_the_kind_and_a_trashed_copy_is_still_the_copy(): void {
        $image  = $this->image( 'qa-fab-show-version.png' );
        $copies = ( new \Fabrement\Tests\Site\Support\WpmlSite( $this->site() ) )->group( $image, 'attachment' );
        $this->assertArrayHasKey( 'fr', $copies, 'WPML keeps a French copy of the upload' );
        $fr = (int) $copies['fr'];
        $this->site()->php( "global \$wpdb; \$wpdb->update( \$wpdb->posts, [ 'post_mime_type' => 'video/mp4' ], [ 'ID' => $fr ] ); clean_post_cache( $fr );" );

        [ $class, $as_image, $in_english, $listed ] = $this->in_french( "return [
            get_class( fabrement()->attachment( $image ) ),
            fabrement()->attachment( $image, kind: 'image' )->not_empty(),
            get_class( fabrement()->attachment( $image, lang: 'en' ) ),
            array_map( static fn( \$a ): string => \$a->kind(), fabrement()->attachments( [ $image ] ) ),
        ];" );
        $this->assertSame( 'Fabrement\Api\Media\Video', $class, 'the French copy is a video now, so the French page has a video' );
        $this->assertFalse( $as_image );
        $this->assertSame( 'Fabrement\Api\Media\Image', $in_english );
        $this->assertSame( [ 'video' ], $listed );

        $this->assertSame( [ 'fr', 'en', null ], $this->in_french( "return [
            fabrement()->attachment( $image )->language(),
            fabrement()->attachment( $image, lang: 'en' )->language(),
            fabrement()->attachment( [ 'id' => 0, 'url' => 'https://cdn.example.org/a.png' ] )->language(),
        ];" ), 'the language of the version shown, as a Post says it; none for an address alone' );

        $this->site_runs( "wp_trash_post( $fr ); return true;" );
        $this->assertSame( 'fr', $this->in_french( "return fabrement()->attachment( $image )->language();" ), 'the French copy in the trash: still the copy, as wpml_object_id() gives it' );
        $this->assertSame( [ $fr, [], $image ], $this->in_french( "return [
            fabrement()->attachment( $image )->id(),
            array_map( static fn( \$a ): int => \$a->id(), fabrement()->attachments( [ $image ] ) ),
            fabrement()->attachment( $image, lang: 'en' )->id(),
        ];" ), 'the French copy in the trash: one attachment still shows it, as WordPress and WPML do; a list leaves it out, as ACF does' );
    }

    /**
     * A language picked in a link that the site no longer has: the stored value is shown in the
     * language of the page and the log says which record to fix. One the template names is its own
     * mistake: nothing shown.
     */
    public function test_a_link_picked_in_a_language_the_site_no_longer_has(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr ] = self::$ids;
        [ $stored, $named, $fr_url ] = $this->in_french( "return [
            fabrement()->link( [ 'postId' => $a, 'lang' => 'de' ] )->url(),
            fabrement()->link( [ 'postId' => $a ], lang: 'de' )->url(),
            fabrement()->post( $a )->url(),
        ];" );
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        $this->assertSame( $fr_url, $stored, 'the stored language is ignored: the French version' );
        $this->assertSame( '', $named, 'a language the template names is not ignored' );
        $this->assertStringContainsString( 'the stored link to record ' . $a . ' names the language "de"', $log );
        $this->assertStringContainsString( 'lang: the site has no language "de"', $log );
    }

    public function test_languages_and_a_mistake_in_the_call(): void {
        [ 'alpha' => $a ] = self::$ids;
        [ $current, $codes, $wrong_lang, $wrong_missing, $posts ] = $this->in_french( "return [
            fabrement()->language(),
            array_column( fabrement()->languages(), 'code' ),
            fabrement()->post( $a, lang: 'xx' )->not_empty(),
            fabrement()->post( $a, missing: 'hide' )->not_empty(),
            fabrement()->posts( [ $a ], lang: 'xx' ),
        ];" );
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        $this->assertSame( 'fr', $current );
        $this->assertEqualsCanonicalizing( [ 'en', 'fr', 'pl' ], $codes );
        $this->assertSame( [ false, true, [] ], [ $wrong_lang, $wrong_missing, $posts ], 'a wrong call shows nothing and does not throw; an unknown option is left out' );
        $this->assertStringContainsString( 'the site has no language "xx"', $log );
        $this->assertStringContainsString( 'unknown option "missing"', $log );
    }

    public function test_a_wrong_language_is_said_even_when_there_is_nothing_to_show(): void {
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        $answers = $this->in_french( "return [ fabrement()->posts( [], lang: 'xx' ), fabrement()->posts( [], private: false ) ];" );
        $log     = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        $this->assertSame( [ [], [] ], $answers );
        $this->assertStringContainsString( 'the site has no language "xx"', $log, 'the same mistake is said whether the list is empty or not' );
        $this->assertStringContainsString( 'unknown option "private"', $log );
    }

    public function test_a_wrong_language_gives_an_empty_image_where_an_image_was_asked(): void {
        $this->post_type( 'qa_fab_cover', [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
        $this->fields( 'qa_fab_cover', [ self::field( 'cover', 'image' ) ] );
        $image  = $this->image( 'qa-fab-cover-lang.png' );
        $record = $this->record( 'qa_fab_cover', 'Covered' );
        $this->mcp( 'set-field-values', [ 'postId' => $record, 'values' => [ 'cover' => $image ] ] );
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        $out = $this->in_french( "return [
            get_class( fabrement()->attachment( $image, kind: 'image', lang: 'xx' ) ),
            fabrement()->attachment( $image, kind: 'image', lang: 'xx' )->link()->not_empty(),
            fabrement()->fields( $record )->get_image( 'cover', lang: 'xx' ) === null ? null : get_class( fabrement()->fields( $record )->get_image( 'cover', lang: 'xx' ) ),
            fabrement()->fields( $record )->get_image( 'cover' )->not_empty(),
        ];" );
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        $this->assertSame( 'Fabrement\Api\Media\Image', $out[0] );
        $this->assertFalse( $out[1] );
        $this->assertContains( $out[2], [ null, 'Fabrement\Api\Media\Image' ], 'an image reader never hands out another class' );
        $this->assertTrue( $out[3] );
        $this->assertStringContainsString( 'the site has no language "xx"', $log );
    }

    /** A version still in draft: shown in wp-admin and previews, not on the site, unless status says. */
    public function test_a_draft_version_is_shown_in_wp_admin_and_not_on_the_site(): void {
        [ 'delta' => $d, 'deltaFr' => $d_fr ] = self::$ids;
        $calls = "return [ fabrement()->post( $d )->id(), fabrement()->post( $d, status: 'publish' )->id(), fabrement()->post( $d, lang: 'en' )->id() ];";
        $this->assertSame( [ 0, 0, $d ], $this->in_french( $calls ), 'the site: the French draft is not shown' );
        $this->assertSame( [ $d_fr, 0, $d ], $this->in_french( "wp_set_current_user( 1 ); set_current_screen( 'dashboard' ); " . $calls ), 'wp-admin: the French draft' );
    }

    /** "All languages" in wp-admin: WPML filters nothing and turns no id, so everything is shown as it is. */
    public function test_with_all_languages_chosen_everything_is_shown_as_it_is(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr, 'beta' => $b, 'kappa' => $k ] = self::$ids;
        $image = $this->image( 'qa-fab-all-languages.png' );
        $this->assertSame( [ 'all', $a, $a_fr, $b, [ $a, $a_fr, $b ], $image, $k ], $this->site_runs( self::IDS . " do_action( 'wpml_switch_language', 'all' );
            return [
                apply_filters( 'wpml_current_language', null ),
                fabrement()->post( $a )->id(),
                fabrement()->post( $a_fr )->id(),
                fabrement()->post( $b )->id(),
                \$ids( fabrement()->posts( [ $a, $a_fr, $b ] ) ),
                fabrement()->attachment( $image )->id(),
                fabrement()->term( $k )->id(),
            ];" ) );
        $this->assertSame( [ $a_fr, 'fr' ], $this->site_runs( "do_action( 'wpml_switch_language', 'all' ); return [ fabrement()->post( $a, lang: 'fr' )->id(), apply_filters( 'wpml_current_language', null ) === 'all' ? 'fr' : 'switched' ];" ), 'lang: still asks for a language' );
    }

    /** The terms of a post in the page's language — or, asked in another language, in that one. */
    public function test_post_terms_of_the_version_shown(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr, 'beta' => $b, 'kappa' => $k ] = self::$ids;
        $tax = self::TAXONOMY;
        [ $fr, $en, $beta, $beta_default, $wrong, $names ] = $this->in_french( self::IDS . "
            \$kFr = (int) apply_filters( 'wpml_object_id', $k, '$tax', false, 'fr' );
            do_action( 'wpml_switch_language', 'en' );
            wp_set_object_terms( $a, [ $k ], '$tax' );
            wp_set_object_terms( $b, [ $k ], '$tax' );
            do_action( 'wpml_switch_language', 'fr' );
            wp_set_object_terms( $a_fr, [ \$kFr ], '$tax' );
            return [
                \$ids( fabrement()->post( $a )->terms( '$tax' ) ) === [ \$kFr ],
                \$ids( fabrement()->post( $a, lang: 'en' )->terms( '$tax' ) ),
                fabrement()->post( $b )->terms( '$tax' ),
                \$ids( fabrement()->post( $b, lang: 'en' )->terms( '$tax' ) ),
                fabrement()->post( $a )->terms( 'qa_no_such_tax' ),
                [ fabrement()->post( $a )->terms( '$tax' )[0]->name(), fabrement()->post( $a, lang: 'en' )->terms( '$tax' )[0]->name() ],
            ];" );
        $this->assertTrue( $fr, 'the French version, its French term' );
        $this->assertSame( [ $k ], $en, 'lang: the English version, its English term' );
        $this->assertSame( [], $beta, 'no French version of a translated type: nothing' );
        $this->assertSame( [ $k ], $beta_default, 'lang: en — the English version, its English term' );
        $this->assertSame( [], $wrong );
        $this->assertSame( [ 'Kappa <b>&</b> FR', 'Kappa' ], $names, 'each term read in the language asked for' );
        $this->assertStringContainsString( 'Post::terms(): there is no taxonomy "qa_no_such_tax"', $this->site()->debug_log() );
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
    }

    /**
     * WPML turns ids into the page's language in get_permalink(), get_the_terms() and get_term(): a
     * record or term asked for in another language reads them inside that language, and passes it on.
     */
    public function test_an_object_asked_in_another_language_reads_everything_in_it(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr, 'kappa' => $k ] = self::$ids;
        $tax = self::TAXONOMY;
        [ $page, $asked ] = $this->in_french( self::IDS . "
            \$kFr = (int) apply_filters( 'wpml_object_id', $k, '$tax', false, 'fr' );
            do_action( 'wpml_switch_language', 'en' );
            wp_set_object_terms( $a, [ $k ], '$tax' );
            do_action( 'wpml_switch_language', 'fr' );
            wp_set_object_terms( $a_fr, [ \$kFr ], '$tax' );
            \$read = static fn( \Fabrement\Api\Content\Post \$p ): array => [
                \$p->id(),
                \$p->url(),
                \$p->title(),
                \$ids( \$p->terms( '$tax' ) ),
                \$p->terms( '$tax' )[0]->url(),
                \$p->link()->url(),
                \$p->link()->post()->url(),
            ];
            return [ \$read( fabrement()->post( $a ) ), \$read( fabrement()->post( $a_fr, lang: 'en' ) ) ];" );
        $this->assertSame( $a_fr, $page[0] );
        $this->assertStringContainsString( '/fr/', $page[1] );
        $this->assertSame( $a, $asked[0] );
        $this->assertStringNotContainsString( '/fr/', $asked[1], 'the English address, not the French copy\'s' );
        $this->assertSame( 'Alpha', $asked[2] );
        $this->assertSame( [ $k ], $asked[3], 'the English term, not the French copy WPML would turn it into' );
        $this->assertStringNotContainsString( '/fr/', $asked[4] );
        $this->assertSame( $asked[1], $asked[5], 'a link to the record keeps the language' );
        $this->assertSame( $asked[1], $asked[6], 'and so does the record behind it' );
        $this->assertSame( 'fr', $this->in_french( "fabrement()->post( $a_fr, lang: 'en' )->url(); return apply_filters( 'wpml_current_language', null );" ), 'the page stays French' );
    }

    /** Only the languages this page exists in, with translated_only; escape applies to the strings. */
    public function test_languages_translated_only(): void {
        [ 'alpha' => $a, 'beta' => $b ] = self::$ids;
        $on = static fn( int $id ): string => "\$GLOBALS['wp_query'] = new WP_Query( [ 'p' => $id, 'post_type' => '" . self::TRANSLATED . "' ] ); \$GLOBALS['wp_the_query'] = \$GLOBALS['wp_query']; ";
        $this->assertSame(
            [ [ 'en', 'fr', 'pl' ], [ 'en' ], true ],
            $this->site_runs( $on( $b ) . "return [
                array_column( fabrement()->languages(), 'code' ),
                array_column( fabrement()->languages( translated_only: true ), 'code' ),
                array_map( 'esc_url', array_column( fabrement()->languages(), 'url' ) ) === array_map( 'esc_url', array_column( fabrement()->languages(), 'url' ) ),
            ];" ),
            'Beta exists only in English'
        );
        $this->assertSame( [ 'en', 'fr' ], $this->site_runs( $on( $a ) . "return array_column( fabrement()->languages( translated_only: true ), 'code' );" ), 'Alpha in English and French' );
    }

    /** The version is remembered for the request and chosen again when a post's cache is cleaned. */
    public function test_a_version_published_in_the_same_request_is_shown(): void {
        [ 'delta' => $d, 'deltaFr' => $d_fr ] = self::$ids;
        $this->assertSame( [ 0, $d_fr, 0 ], $this->in_french( "
            \$before = fabrement()->post( $d )->id();
            wp_update_post( [ 'ID' => $d_fr, 'post_status' => 'publish' ] );
            \$after = fabrement()->post( $d )->id();
            wp_update_post( [ 'ID' => $d_fr, 'post_status' => 'draft' ] );
            return [ \$before, \$after, fabrement()->post( $d )->id() ];" ) );
    }

    public function test_fields_give_the_stored_id_in_every_language(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr ] = self::$ids;
        $this->fields( self::TRANSLATED, [ self::field( 'related', 'post_object', [ 'postTypes' => [ self::TRANSLATED ] ] ) ] );
        $this->mcp( 'set-field-values', [ 'postId' => self::$ids['beta'], 'values' => [ 'related' => $a_fr ] ] );
        $this->assertSame( [ $a, $a_fr, $a, $a_fr ], $this->in_french( "\$f = fabrement()->fields( {$this->beta()} ); \$r = \$f->get( 'related' );
            return [ \$r, fabrement()->post( \$r )->id(), fabrement()->post( \$r, lang: 'en' )->id(), \$f->get_post_object( 'related' )->id() ];" ),
            'a French id chosen is stored as its original; fabrement()->post() and get_post_object() show the French one' );
    }

    /**
     * WordPress's French words, as `wp language core install fr_FR` would bring them: the month names,
     * the number separators and the file size units this class checks.
     */
    private function french_words(): void {
        $this->site_runs( "require_once ABSPATH . WPINC . '/pomo/mo.php';
            \$mo = new MO();
            foreach ( [ 'February' => 'février', 'March' => 'mars', 'July' => 'juillet', 'number_format_decimal_point' => ',', 'number_format_thousands_sep' => ' ' ] as \$en => \$fr ) {
                \$mo->add_entry( new Translation_Entry( [ 'singular' => \$en, 'translations' => [ \$fr ] ] ) );
            }
            foreach ( [ 'KB' => 'Ko', 'MB' => 'Mo' ] as \$en => \$fr ) {
                \$mo->add_entry( new Translation_Entry( [ 'singular' => \$en, 'context' => 'unit symbol', 'translations' => [ \$fr ] ] ) );
            }
            wp_mkdir_p( WP_LANG_DIR );
            return \$mo->export_to_file( WP_LANG_DIR . '/fr_FR.mo' );" );
    }

    /**
     * A template's words on a real page: what format() and the viewed record's date() write there,
     * asked for with ?qa_format (the site's date format 'j F Y').
     *
     * @return array<string, mixed>
     */
    private function page_writes( string $url, int $other ): array {
        $this->site_code( 'qa-format-page', "add_action( 'template_redirect', static function (): void {
            if ( ! isset( \$_GET['qa_format'] ) ) {
                return;
            }
            add_filter( 'pre_option_date_format', static fn() => 'j F Y' );
            \$page = fabrement()->format();
            \$en = fabrement()->format( lang: 'en' );
            \$fr = fabrement()->format( lang: 'fr' );
            \$viewed = fabrement()->viewed_post();
            wp_send_json( [
                'language' => fabrement()->language(),
                'page'     => [ \$page->date( '2026-03-05' ), \$page->number( 1234.5 ), \$page->filesize( 2048 ), \$page->filesize( 1468006 ) ],
                'en'       => [ \$en->date( '2026-03-05' ), \$en->number( 1234.5 ), \$en->filesize( 2048 ) ],
                'fr'       => [ \$fr->date( '2026-03-05' ), \$fr->number( 1234.5 ), \$fr->filesize( 1468006 ) ],
                'again'    => [ \$page->date( '2025-07-15', format: 'F' ), \$en->date( '2025-07-15', format: 'F' ), \$page->date( '2025-07-15', format: 'F' ) ],
                'viewed'   => [ \$viewed->id(), \$viewed->date(), \$viewed->datetime() ],
                'other'    => [ fabrement()->post( $other, lang: 'en' )->date( lang: 'en' ), fabrement()->post( $other, lang: 'fr' )->date( lang: 'fr' ), fabrement()->post( $other, lang: 'fr' )->date() ],
                'mistake'  => fabrement()->format( lang: 'xx' )->number( 1 ),
            ] );
        } );" );
        $page = $this->get( $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'qa_format=1' );
        $this->assertSame( 200, $page['status'] );
        return json_decode( $page['body'], true );
    }

    /**
     * format() writes in the page's language or in `lang`, its locale switched while formatting —
     * French month names, the decimal comma, French size units — and the page's kept between calls;
     * a record's date() is written in the record's language, on a page of any language.
     */
    public function test_format_and_a_records_date_in_their_language(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr ] = self::$ids;
        $this->french_words();
        $this->site_runs( "global \$wpdb;
            \$wpdb->update( \$wpdb->posts, [ 'post_date' => '2025-07-15 10:00:00', 'post_date_gmt' => '2025-07-15 10:00:00' ], [ 'ID' => $a ] );
            \$wpdb->update( \$wpdb->posts, [ 'post_date' => '2026-02-01 10:00:00', 'post_date_gmt' => '2026-02-01 10:00:00' ], [ 'ID' => $a_fr ] );
            clean_post_cache( $a );
            clean_post_cache( $a_fr );
            return true;" );
        [ $fr_url, $en_url ] = $this->site_runs( "return [ fabrement()->post( $a, lang: 'fr' )->url(), fabrement()->post( $a, lang: 'en' )->url() ];" );

        $french = $this->page_writes( $fr_url, $a );
        $this->assertSame( 'fr', $french['language'] );
        $this->assertSame( [ '5 mars 2026', "1\u{00A0}234,5", '2 Ko', '1,4 Mo' ], $french['page'], 'a French page: French' );
        $this->assertSame( [ '5 March 2026', '1,234.5', '2 KB' ], $french['en'], 'lang: en' );
        $this->assertSame( [ '5 mars 2026', "1\u{00A0}234,5", '1,4 Mo' ], $french['fr'] );
        $this->assertSame( [ 'juillet', 'July', 'juillet' ], $french['again'], 'each call in its language; nothing kept between them' );
        $this->assertSame( [ $a_fr, '1 février 2026', '2026-02-01T10:00:00+00:00' ], $french['viewed'] );
        $this->assertSame( [ '15 July 2025', '1 février 2026', '1 février 2026' ], $french['other'], 'date( lang: ) in that language; without it, in the page\'s' );
        $this->assertSame( '', $french['mistake'] );

        $english = $this->page_writes( $en_url, $a );
        $this->assertSame( 'en', $english['language'] );
        $this->assertSame( [ '5 March 2026', '1,234.5', '2 KB', '1.4 MB' ], $english['page'], 'an English page: English' );
        $this->assertSame( [ '5 mars 2026', "1\u{00A0}234,5", '1,4 Mo' ], $english['fr'], 'lang: fr switches to French' );
        $this->assertSame( [ 'July', 'July', 'July' ], $english['again'] );
        $this->assertSame( [ $a, '15 July 2025', '2025-07-15T10:00:00+00:00' ], $english['viewed'] );
        $this->assertSame( [ '15 July 2025', '1 février 2026', '1 February 2026' ], $english['other'], 'a French record on an English page: its date in English unless date( lang: \'fr\' )' );

        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        $this->assertStringContainsString( 'Fabrement::format(): ', $log );
        $this->assertStringContainsString( 'the site has no language "xx"', $log );
    }

    /** What a page in $url writes of the translated type's taxonomies, asked for with ?qa_tax_label. */
    private function taxonomies_on_page( string $url ): array {
        $t = self::TRANSLATED;
        $this->site_code( 'qa-tax-label-page', "add_action( 'init', static function (): void {
            register_taxonomy( 'qa_show_hidden', '$t', [ 'public' => false, 'label' => 'QA hidden' ] );
        } );
        add_action( 'template_redirect', static function (): void {
            if ( ! isset( \$_GET['qa_tax_label'] ) ) {
                return;
            }
            \$names = static fn( array \$list ): array => array_map( static fn( \$one ): string => \$one->name(), \$list );
            [ \$tax ] = fabrement()->taxonomies( '$t', public: true );
            ob_start();
            \$returned = \$tax->label( render: fab_r( tag: 'h2' ) );
            wp_send_json( [
                'language' => fabrement()->language(),
                'all'      => \$names( fabrement()->taxonomies( '$t' ) ),
                'public'   => \$names( fabrement()->taxonomies( '$t', public: true ) ),
                'other'    => \$names( fabrement()->taxonomies( '$t', public: false ) ),
                'label'    => [ \$tax->label(), \$returned, ob_get_clean(), \$tax->to_array(), get_taxonomy( \$tax->name() )->labels->name ],
                'terms'    => [ \$names( \$tax->terms( hide_empty: false ) ), \$names( fabrement()->terms( taxonomy: \$tax->name(), hide_empty: false ) ), \$names( \$tax->terms( hide_empty: false, lang: 'en' ) ) ],
            ] );
        }, 1 );" );
        $page = $this->get( $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'qa_tax_label=1' );
        $this->assertSame( 200, $page['status'] );
        return json_decode( $page['body'], true );
    }

    private function beta(): int {
        return self::$ids['beta'];
    }

    public function test_fields_reached_with_a_language_show_what_they_point_at_in_it(): void {
        [ 'alpha' => $a, 'alphaFr' => $a_fr ] = self::$ids;
        $type = self::TRANSLATED;
        $this->fields( $type, [
            self::field( 'related', 'post_object', [ 'postTypes' => [ $type ] ] ),
            self::field( 'box', 'group', [ 'sub_fields' => [ self::field( 'ref', 'post_object', [ 'postTypes' => [ $type ] ] ) ] ] ),
        ] );
        $this->mcp( 'set-field-values', [ 'postId' => $a, 'values' => [ 'related' => $a, 'box' => [ 'ref' => $a ] ] ] );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-lang-site', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA lang site' ],
            'fields' => [ self::field( 'pick', 'post_object', [ 'postTypes' => [ $type ] ] ) ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-lang-site', 'values' => [ 'pick' => $a ] ] );
        // Each language of an options page holds its own values: the French one names the same record.
        $this->mcp( 'set-field-values', [ 'options' => 'qa-lang-site', 'language' => 'fr', 'values' => [ 'pick' => $a ] ] );

        // An English page.
        $out = $this->site_runs( "
            \$fr = fabrement()->post( $a, lang: 'fr' );
            return [
                'post'       => \$fr->id(),
                'field'      => \$fr->fields()->get_post_object( 'related' )->id(),
                'group'      => \$fr->fields()->get_group( 'box' )->get_post_object( 'ref' )->id(),
                'chain'      => \$fr->fields()->get_post_object( 'related' )->fields()->get_post_object( 'related' )->id(),
                'named'      => \$fr->fields()->get_post_object( 'related', lang: 'en' )->id(),
                'pages'      => fabrement()->post( $a )->fields()->get_post_object( 'related' )->id(),
                'byId'       => fabrement()->fields( $a_fr )->get_post_object( 'related' )->id(),
                'options'    => fabrement()->options( 'qa-lang-site', lang: 'fr' )->get_post_object( 'pick' )->id(),
                'optionsEn'  => fabrement()->options( 'qa-lang-site', lang: 'fr' )->get_post_object( 'pick', lang: 'en' )->id(),
                'optionsPg'  => fabrement()->options( 'qa-lang-site' )->get_post_object( 'pick' )->id(),
                'language'   => apply_filters( 'wpml_current_language', null ),
            ];" );

        $this->assertSame( $a_fr, $out['post'] );
        $this->assertSame( $a_fr, $out['field'], 'as the Post\'s own terms and thumbnail: in the language it was read in' );
        $this->assertSame( $a_fr, $out['group'], 'a group of those fields too' );
        $this->assertSame( $a_fr, $out['chain'], 'and the fields of the record a field gave' );
        $this->assertSame( $a, $out['named'], 'lang: on the reader names another' );
        $this->assertSame( $a, $out['pages'], 'without a language asked: the page\'s, as before' );
        $this->assertSame( $a, $out['byId'], 'fields( id ) reads that record and shows in the page\'s language, as before' );
        $this->assertSame( $a_fr, $out['options'] );
        $this->assertSame( $a, $out['optionsEn'] );
        $this->assertSame( $a, $out['optionsPg'] );
        $this->assertSame( 'en', $out['language'], 'the page stays English' );
    }
}
