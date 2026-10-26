<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The site's own code (an import, a cron job) writing fields with fabrement()->fields() on a
 * three-language WPML site, where an agent made the type, filled a record and had it translated.
 * A repeater row is named by its _row, so each translation keeps its words on its row whatever the
 * code does to the original's rows; a list written anew, without any _row, is new rows.
 */
final class FieldsCodeTranslationTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private const TYPE = 'qa_session';
    private const SPEAKER = 'qa_speaker';

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->post_type( self::TYPE, [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
            $this->post_type( self::SPEAKER, [ 'translationMode' => 'translate', 'supports' => [ 'title', 'editor' ] ] );
            $this->fields( self::TYPE, [
                self::field( 'tagline', 'text' ),
                self::field( 'speakers', 'relationship', [ 'postTypes' => [ self::SPEAKER ] ] ),
                self::field( 'steps', 'repeater', [ 'sub_fields' => [ self::field( 'title', 'text' ), self::field( 'code', 'text', [ 'translation' => 'copy' ] ) ] ] ),
            ] );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    private function set( int $id, array $values ): void {
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
    }

    private function values( int $id ): array {
        return $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values'];
    }

    public function test_sql_null_clears_survive_copy_once_and_copy_sync_at_every_depth(): void {
        $this->post_type( 'qa_nullcopy', [ 'translationMode' => 'translate' ] );
        $choice = [ 'choices' => [ [ 'value' => 'kyiv', 'label' => 'Kyiv' ], [ 'value' => '0', 'label' => 'Zero' ] ], 'default' => 'kyiv' ];
        $fields = [];
        $initial = [];
        $later = [];
        $clears = [];
        foreach ( [ [ 'select', 'kyiv', '0', $choice ], [ 'number', 7, 0, [ 'default' => 7 ] ], [ 'true_false', true, false, [ 'default' => true ] ], [ 'text', 'First', 'Later', [ 'default' => 'Default' ] ] ] as [ $type, $first, $next, $extra ] ) {
            foreach ( [ 'copy', 'copy_once' ] as $policy ) {
                $name = 'nc_' . ( $policy === 'copy' ? 'copy_' : 'own_' ) . $type;
                $fields[] = self::field( $name, $type, [ 'translation' => $policy ] + $extra );
                $initial[ $name ] = $first;
                $later[ $name ] = $next;
                if ( $policy === 'copy_once' ) { $clears[ $name ] = null; }
            }
        }
        $fields[] = self::field( 'nc_group', 'group', [ 'translation' => 'copy', 'sub_fields' => [
            self::field( 'city', 'select', [ 'translation' => 'copy_once' ] + $choice ),
            self::field( 'title', 'text', [ 'translation' => 'translate' ] ),
        ] ] );
        $this->fields( 'qa_nullcopy', $fields );
        $en = $this->record( 'qa_nullcopy', 'Null translation' );
        $this->set( $en, $initial + [ 'nc_group' => [ 'city' => 'kyiv', 'title' => 'Original' ] ] );
        $pl = (int) $this->mcp( 'translate-content', [ 'contentId' => $en, 'language' => 'pl', 'slug' => 'qa-nullcopy-pl' ] )['contentId'];
        $this->set( $pl, $clears + [ 'nc_group' => [ 'city' => null, 'title' => 'Polish' ] ] );
        $this->set( $en, $later + [ 'nc_group' => [ 'city' => '0', 'title' => 'Changed' ] ] );
        $values = $this->values( $pl );
        foreach ( $later as $name => $value ) {
            $this->assertSame( array_key_exists( $name, $clears ) ? null : $value, $values[ $name ], $name );
            if ( array_key_exists( $name, $clears ) ) {
                $this->assertSame( [ null ], $this->site()->meta( $pl, 'fab_' . $name ), $name );
            }
        }
        $this->assertSame( [ 'city' => null, 'title' => 'Polish' ], $values['nc_group'] );
        $this->assertSame( [ null ], $this->site()->meta( $pl, 'fab_nc_group_city' ) );
        $this->set( $en, array_fill_keys( array_keys( $initial ), null ) );
        foreach ( array_keys( $initial ) as $name ) {
            $this->assertSame( [ null ], $this->site()->meta( $en, 'fab_' . $name ), $name );
            $this->assertSame( [ null ], $this->site()->meta( $pl, 'fab_' . $name ), $name );
        }
        $this->set( $en, $later );
        foreach ( $later as $name => $value ) {
            $this->assertSame( array_key_exists( $name, $clears ) ? null : $value, $this->values( $pl )[ $name ], $name . ' after another sync' );
        }
        // Both languages explicitly empty: the NULL query must keep the caller's language scope.
        $this->set( $en, [ 'nc_own_text' => null ] );
        $queries = $this->site_runs( <<<'PHP'
$base = ['post_type' => 'qa_nullcopy', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1,
    'orderby' => 'ID', 'order' => 'ASC', 'fabrement_meta_null' => 'fab_nc_own_text'];
$run = static function(array $extra) use ($base): array {
    $query = new WP_Query($extra + $base);
    return [array_map('intval', $query->posts), $query->get('suppress_filters')];
};
return [
    'en' => $run(['lang' => 'en', 'suppress_filters' => false]),
    'pl' => $run(['lang' => 'pl', 'suppress_filters' => false]),
    'all' => $run(['lang' => 'pl', 'suppress_filters' => true]),
    'get_posts' => array_map('intval', get_posts($base)),
];
PHP
        );
        $this->assertSame( [ [ $en ], false ], $queries['en'] );
        $this->assertSame( [ [ $pl ], false ], $queries['pl'] );
        $this->assertSame( [ [ $en, $pl ], true ], $queries['all'] );
        $this->assertSame( [ $en, $pl ], $queries['get_posts'] );
    }

    /** A record with three steps, translated into French with its own words. @return array{0:int,1:int,2:list<string>} */
    private function translated_steps( string $title, string $slug ): array {
        $talk = $this->record( self::TYPE, $title );
        $this->set( $talk, [ 'steps' => [ [ 'title' => 'One', 'code' => 'a' ], [ 'title' => 'Two', 'code' => 'b' ], [ 'title' => 'Three', 'code' => 'c' ] ] ] );
        $out = $this->mcp( 'translate-content', [ 'contentId' => $talk, 'language' => 'fr', 'slug' => $slug ] );
        $fr  = (int) $out['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        $ids = array_column( $this->values( $fr )['steps'], '_row' );
        $this->set( $fr, [ 'steps' => [
            [ '_row' => $ids[0], 'title' => 'Un', 'code' => 'a' ],
            [ '_row' => $ids[1], 'title' => 'Deux', 'code' => 'b' ],
            [ '_row' => $ids[2], 'title' => 'Trois', 'code' => 'c' ],
        ] ] );
        return [ $talk, $fr, $ids ];
    }

    public function test_the_sites_code_changes_rows_and_translations_stay_on_their_rows(): void {
        [ $talk, $fr, [ $one, $two, $three ] ] = $this->translated_steps( 'Rows from code', 'qa-rows-code-fr' );

        // The first row removed, the last moved to the top, a copied code changed: one save.
        $this->site_runs( "\$f = fabrement()->fields( $talk );
            \$f->delete_row( 'steps', '$one' )->move_row( 'steps', '$three', before: '$two' )->update_row( 'steps', '$two', [ 'code' => 'B' ] );
            \$f->save();" );
        $this->assertSame( [ 'Three', 'Two' ], array_column( $this->values( $talk )['steps'], 'title' ) );
        $french = $this->values( $fr )['steps'];
        $this->assertSame( [ 'Trois', 'Deux' ], array_column( $french, 'title' ), 'each French row stays with its row, not with a place' );
        $this->assertSame( [ $three, $two ], array_column( $french, '_row' ) );
        $this->assertSame( [ 'c', 'B' ], array_column( $french, 'code' ), 'the copied sub-field follows the original' );
        $this->assertSame( [ 'Trois', 'Deux' ], array_column( $this->site_runs( "return fabrement_get_field( 'steps', $fr );" ), 'title' ), 'what a French template reads' );

        // The translation's own code may change its words, not its rows.
        $this->site_runs( "fabrement()->fields( $fr )->update_row( 'steps', '$two', [ 'title' => 'Deuxième' ] )->save();" );
        $this->assertSame( [ 'Trois', 'Deuxième' ], array_column( $this->values( $fr )['steps'], 'title' ) );

        // A row added from the translation is the original's (rows follow it), with what it was given,
        // and reaches every language from there; a copied sub-field written here is the original's too.
        $four = $this->site_runs( "\$f = fabrement()->fields( $fr );
            \$row = \$f->add_row( 'steps', [ 'title' => 'Quatre', 'code' => 'd' ] );
            \$f->set_text( 'steps', '$three', 'code', 'C' );
            \$f->save();
            return \$row;" );
        $this->assertSame( [ 'Three', 'Two', 'Quatre' ], array_column( $this->values( $talk )['steps'], 'title' ), 'the original holds the new row, its words until translated' );
        $this->assertSame( [ 'C', 'B', 'd' ], array_column( $this->values( $talk )['steps'], 'code' ) );
        $this->assertSame( [ $three, $two, $four ], array_column( $this->values( $talk )['steps'], '_row' ) );
        $this->assertSame( [ 'Trois', 'Deuxième', 'Quatre' ], array_column( $this->values( $fr )['steps'], 'title' ), 'French keeps its own words' );
        $this->assertSame( [ 'C', 'B', 'd' ], array_column( $this->values( $fr )['steps'], 'code' ) );

        // Removing and moving rows from the translation is done on the original as well.
        $this->site_runs( "fabrement()->fields( $fr )->delete_row( 'steps', '$two' )->move_row( 'steps', '$four', before: '$three' )->save();" );
        $this->assertSame( [ 'Quatre', 'Three' ], array_column( $this->values( $talk )['steps'], 'title' ) );
        $this->assertSame( [ 'Quatre', 'Trois' ], array_column( $this->values( $fr )['steps'], 'title' ) );
    }

    /** A copy value written from a translation is the original's: every language reads it. */
    public function test_a_copy_value_written_from_a_translation_changes_every_language(): void {
        [ $talk, $fr ] = $this->translated_steps( 'Copy from French', 'qa-copy-fr' );
        $this->site_runs( "fabrement()->fields( $fr )->set_text( 'tagline', 'Nous créons' )->save();" );
        $this->mcp( 'set-field-values', [ 'postId' => $talk, 'values' => [ 'tagline' => 'We build' ] ] );
        $pl = (int) $this->mcp( 'translate-content', [ 'contentId' => $talk, 'language' => 'pl', 'slug' => 'qa-copy-pl' ] )['contentId'];
        $this->site_runs( "fabrement()->fields( $fr )->set_rows( 'steps', fabrement()->fields( $fr )->get( 'steps' ) )->save();" );   // unchanged: nothing written
        $ids = array_column( $this->values( $talk )['steps'], '_row' );
        $this->site_runs( "fabrement()->fields( $fr )->set_text( 'steps', '{$ids[0]}', 'code', 'Z' )->save();" );
        foreach ( [ $talk, $fr, $pl ] as $id ) {
            $this->assertSame( 'Z', $this->values( $id )['steps'][0]['code'], 'every language: ' . $id );
        }
        $this->assertSame( 'We build', $this->values( $talk )['tagline'], 'a translated word stays where it was written' );
        $this->assertSame( 'Nous créons', $this->values( $fr )['tagline'] );
    }

    public function test_a_list_written_anew_starts_its_translations_over(): void {
        [ $talk, $fr, $old ] = $this->translated_steps( 'Rows imported', 'qa-rows-import-fr' );

        // An import writes the list anew, without _row: new rows, whose translations start from the original.
        $this->site_runs( "fabrement()->fields( $talk )->set( 'steps', [ [ 'title' => 'Alpha', 'code' => 'x' ], [ 'title' => 'Beta', 'code' => 'y' ] ] )->save();" );
        $fresh = $this->values( $fr )['steps'];
        $this->assertSame( [ 'Alpha', 'Beta' ], array_column( $fresh, 'title' ), 'no French words are left on rows that are not theirs' );
        $this->assertSame( [], array_intersect( $old, array_column( $fresh, '_row' ) ) );
        $this->assertSame( array_column( $this->values( $talk )['steps'], '_row' ), array_column( $fresh, '_row' ) );
    }

    /**
     * Read in French, a list shows only the records French has. The site's code running in French
     * (a French form, an import in that language) adding one with add_item() leaves the
     * record French cannot see where it was; set() writes the list it is given, in any language.
     * The list is the original's (relationship is copy): its translation follows it.
     */
    public function test_adding_to_a_list_in_a_translation_keeps_what_that_language_cannot_see(): void {
        $version = function ( int $id, string $slug ): int {
            $out = $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => 'fr', 'slug' => $slug ] );
            $this->mcp( 'set-content-status', [ 'contentId' => (int) $out['contentId'], 'status' => 'publish' ] );
            return (int) $out['contentId'];
        };
        $ann   = $this->record( self::SPEAKER, 'Ann' );
        $bob   = $this->record( self::SPEAKER, 'Bob' );   // no French version
        $cid   = $this->record( self::SPEAKER, 'Cid' );
        $ann_fr = $version( $ann, 'qa-ann-fr' );
        $cid_fr = $version( $cid, 'qa-cid-fr' );
        $talk  = $this->record( self::TYPE, 'Speakers talk' );
        $this->set( $talk, [ 'speakers' => [ $ann, $bob ] ] );
        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $talk, 'language' => 'fr', 'slug' => 'qa-speakers-fr' ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );

        $seen = $this->site_runs( "do_action( 'wpml_switch_language', 'fr' );
            \$f = fabrement()->fields( $talk );
            \$before = \$f->get( 'speakers' );
            \$f->add_item( 'speakers', $cid_fr )->save();
            \$ids = static fn( array \$posts ): array => array_map( static fn( \Fabrement\Api\Content\Post \$p ): int => \$p->id(), \$posts );
            \$shown = static fn( int \$id ): array => \$ids( fabrement()->posts( fabrement()->fields( \$id )->get( 'speakers' ) ) );
            return [ \$before, fabrement()->fields( $talk )->get( 'speakers' ), fabrement()->fields( $fr )->get( 'speakers' ), \$shown( $talk ), \$shown( $fr ), \$ids( fabrement()->posts( [ $ann, $bob ], lang: 'en' ) ), \$ids( fabrement()->fields( $fr )->get_relationship( 'speakers' ) ) ];" );
        $this->assertSame( [ [ $ann, $bob ], [ $ann, $bob, $cid ], [ $ann, $bob, $cid ] ], array_slice( $seen, 0, 3 ), 'get() gives what is stored: the originals, Bob too — in any language' );
        $this->assertSame( [ [ $ann_fr, $cid_fr ], [ $ann_fr, $cid_fr ] ], array_slice( $seen, 3, 2 ), 'fabrement()->posts() shows French: Ann and Cid, not Bob, who has no French version' );
        $this->assertSame( [ $ann, $bob ], $seen[5], 'lang: shows another language' );
        $this->assertSame( [ $ann_fr, $cid_fr ], $seen[6], 'get_relationship() shows the same Posts' );
        $this->assertSame( [ $ann, $bob, $cid ], $this->values( $talk )['speakers'], 'Bob is still on the list: French could not see him, so did not touch him' );
        $this->assertSame( [ $ann, $bob, $cid ], $this->values( $fr )['speakers'], 'the version follows the original' );

        // Written from the translation, the copied list is the original's: every language has it.
        $dee   = $this->record( self::SPEAKER, 'Dee' );
        $this->site_runs( "fabrement()->fields( $fr )->delete_item( 'speakers', $cid_fr )->add_item( 'speakers', $dee )->save();" );
        $this->assertSame( [ $ann, $bob, $dee ], $this->values( $talk )['speakers'], 'Bob untouched, Cid removed, Dee added — on the original' );
        $this->assertSame( [ $ann, $bob, $dee ], $this->values( $fr )['speakers'] );

        // set() writes what it is given, in any language.
        $this->site_runs( "do_action( 'wpml_switch_language', 'fr' ); fabrement()->fields( $talk )->set_relationship( 'speakers', [ $ann_fr ] )->save();" );
        $this->assertSame( [ $ann ], $this->values( $talk )['speakers'] );
    }

    /** clear() from a translation: what the original owns is cleared there, so in every language; the words only here. */
    public function test_clearing_on_a_translation_clears_what_the_original_owns_everywhere(): void {
        [ $talk, $fr ] = $this->translated_steps( 'Cleared steps', 'qa-cleared-steps-fr' );
        $this->set( $talk, [ 'tagline' => 'Hello' ] );
        $this->set( $fr, [ 'tagline' => 'Bonjour' ] );
        $seen = $this->site_runs( "\$f = fabrement()->fields( $fr );
            \$f->clear( 'steps' )->clear( 'tagline' );
            \$pending = [ \$f->get( 'steps' ), \$f->get( 'tagline' ) ];
            \$f->save();
            return [ \$pending, fabrement()->fields( $talk )->get( 'steps' ), fabrement()->fields( $fr )->get( 'steps' ), fabrement()->fields( $talk )->get( 'tagline' ), fabrement()->fields( $fr )->get( 'tagline' ) ];" );
        $this->assertSame( [ null, null ], $seen[0], 'a clear not saved yet reads as it will be stored' );
        $this->assertSame( [ null, null, 'Hello', null ], array_slice( $seen, 1 ), 'the rows go everywhere; the French words only in French' );
    }

    /** One page reads two records, and code writes both: each object is its own. */
    public function test_two_records_on_one_page_do_not_mix(): void {
        [ $talk, $fr ] = $this->translated_steps( 'Two records', 'qa-two-records-fr' );
        $other = $this->record( self::TYPE, 'Other record' );
        $out = $this->site_runs( "\$a = fabrement()->fields( $talk );
            \$b = fabrement()->fields( $other );
            \$a->set( 'tagline', 'A' );
            \$b->set( 'tagline', 'B' );
            \$before = [ \$a->get( 'tagline' ), \$b->get( 'tagline' ), fabrement()->fields( $talk )->get( 'tagline' ) ];
            \$b->save();
            \$a->save();
            \$fr = fabrement()->fields( $fr );
            return [ \$before, fabrement_get_field( 'tagline', $talk ), fabrement_get_field( 'tagline', $other ), count( \$fr->get_rows( 'steps' ) ), count( fabrement()->fields( $other )->get_rows( 'steps' ) ) ];" );
        $this->assertSame( [ [ 'A', 'B', null ], 'A', 'B', 3, 0 ], $out, 'unsaved changes belong to their object only' );
    }
}
