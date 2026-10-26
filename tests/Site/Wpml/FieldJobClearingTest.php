<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * Words emptied on the original and WPML's translation jobs: a completed job empties them in a
 * translation WPML's editor manages, as WPML does for its own fields; words such a job would empty
 * are refused before they are written; hand-written translations and jobs made from a translation
 * keep their words.
 */
final class FieldJobClearingTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->fields( 'page', [
                self::field( 'subtitle', 'text' ),
                self::field( 'body', 'wysiwyg' ),
                self::field( 'box', 'group', [ 'translation' => 'copy', 'sub_fields' => [
                    self::field( 'note', 'text' ),
                    self::field( 'memo', 'text', [ 'translation' => 'ignore' ] ),
                ] ] ),
                self::field( 'steps', 'repeater', [ 'sub_fields' => [
                    self::field( 'title', 'text' ),
                    self::field( 'parts', 'repeater', [ 'sub_fields' => [ self::field( 'name', 'text' ) ] ] ),
                ] ] ),
                self::field( 'own', 'group', [ 'translation' => 'copy_once', 'sub_fields' => [
                    self::field( 'note', 'text' ),
                    self::field( 'once', 'text', [ 'translation' => 'copy_once' ] ),
                ] ] ),
            ] );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    private function wpml(): WpmlSite {
        return new WpmlSite( $this->site() );
    }

    private function set( int $id, array $values ): array {
        return $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
    }

    private function values( int $id ): array {
        return $this->mcp( 'get-field-values', [ 'postId' => $id ] )['values'];
    }

    private function translated_by_job( string $title ): array {
        $page = $this->record( 'page', $title );
        $this->set( $page, [
            'subtitle' => 'Hello',
            'box'      => [ 'note' => 'Note', 'memo' => 'Internal' ],
            'steps'    => [ [ 'title' => 'One', 'parts' => [ [ 'name' => 'Part' ] ] ] ],
            'own'      => [ 'note' => 'Own', 'once' => 'Once' ],
        ] );
        $this->wpml()->use_editor( $page, 'wpml' );
        $this->complete( $this->job( $page, 'fr' ), [ 'Hello' => 'Bonjour', 'Note' => 'Note FR', 'One' => 'Un', 'Part' => 'Pièce', 'Own' => 'Propre' ] );
        $fr = $this->wpml()->version( $page, 'fr' );
        $this->assertGreaterThan( 0, $fr, 'WPML made the French page from the job' );
        $this->set( $fr, [ 'box' => [ 'memo' => 'Interne' ], 'own' => [ 'once' => 'Une fois' ] ] );
        return [ $page, $fr ];
    }

    private function job( int $source, string $lang ): int {
        return (int) $this->site_runs( "wp_set_current_user( 1 ); return (int) wpml_tm_load_job_factory()->create_local_post_job( $source, '$lang', 1 );" );
    }

    private function words( int $job ): array {
        return $this->site_runs( "\$job = wpml_tm_load_job_factory()->get_translation_job( $job, false, 0, false );
            \$words = [];
            foreach ( \$job->elements as \$element ) {
                if ( strpos( (string) \$element->field_type, 'fabrement-field-' ) === 0 ) {
                    \$words[] = base64_decode( (string) \$element->field_data );
                }
            }
            sort( \$words );
            return \$words;" );
    }

    private function complete( int $job, array $map, array $unfinished = [], bool $unfiltered = true ): void {
        $this->site()->php( "wp_set_current_user( 1 );
            if ( ! " . var_export( $unfiltered, true ) . " ) {
                add_filter( 'map_meta_cap', static fn( \$caps, \$cap ) => \$cap === 'unfiltered_html' ? [ 'do_not_allow' ] : \$caps, 10, 2 );
            }
            if ( ! function_exists( 'wpml_tm_save_data' ) && defined( 'WPML_TM_PATH' ) ) {
                require_once WPML_TM_PATH . '/inc/wpml-private-actions-tm.php';
            }
            \$map        = " . var_export( $map, true ) . ";
            \$unfinished = " . var_export( $unfinished, true ) . ";
            \$job        = wpml_tm_load_job_factory()->get_translation_job( $job, false, 0, false );
            \$fields     = [];
            foreach ( \$job->elements as \$element ) {
                if ( ! \$element->field_translate ) {
                    continue;
                }
                \$text = base64_decode( (string) \$element->field_data );
                \$fields[ \$element->field_type ] = [ 'tid' => \$element->tid, 'field_type' => \$element->field_type, 'format' => 'base64',
                    'finished' => in_array( \$text, \$unfinished, true ) ? 0 : 1,
                    'data' => \$map[ \$text ] ?? ( \$element->field_type === 'title' ? 'Titre' : \$text ) ];
            }
            wpml_tm_save_data( [ 'job_id' => $job, 'complete' => 1, 'fields' => \$fields ], false );" );
    }

    public function test_a_completed_job_empties_the_words_the_original_emptied(): void {
        [ $page, $fr ] = $this->translated_by_job( 'Emptied words page' );
        $before = $this->values( $fr );
        $this->assertSame( 'Bonjour', $before['subtitle'] );
        $this->assertSame( 'Pièce', $before['steps'][0]['parts'][0]['name'] );

        $steps = $this->values( $page )['steps'];
        $steps[0]['title'] = null;
        $steps[0]['parts'][0]['name'] = null;
        $this->set( $page, [ 'subtitle' => null, 'box' => [ 'note' => null ], 'steps' => $steps, 'own' => [ 'note' => null ] ] );
        $this->assertSame( 'Bonjour', $this->values( $fr )['subtitle'], 'saving the original alone does not overwrite translated words' );

        $job = $this->job( $page, 'fr' );
        $this->assertSame( [], $this->words( $job ), 'WPML sends no empty words to translators' );
        $this->complete( $job, [] );

        $after = $this->values( $fr );
        $this->assertNull( $after['subtitle'] );
        $this->assertSame( [ 'note' => null, 'memo' => 'Interne' ], $after['box'], 'the word goes, the translation\'s own value stays' );
        $this->assertNull( $after['steps'][0]['title'] );
        $this->assertNull( $after['steps'][0]['parts'][0]['name'], 'nested rows, matched by their identities' );
        $this->assertSame( [ 'note' => null, 'once' => 'Une fois' ], $after['own'], 'words of a copy_once group follow the job, its own value stays' );
    }

    public function test_a_field_the_job_carries_unfinished_keeps_its_words(): void {
        [ $page, $fr ] = $this->translated_by_job( 'Unfinished field page' );
        $this->set( $page, [ 'subtitle' => 'Hello again' ] );
        $job = $this->job( $page, 'fr' );
        $this->set( $page, [ 'subtitle' => null ] );
        $this->complete( $job, [ 'Note' => 'Note FR' ], [ 'Hello again' ] );
        $this->assertSame( 'Bonjour', $this->values( $fr )['subtitle'], 'in the job, not finished: neither translated nor emptied' );
    }

    public function test_a_job_made_from_a_translation_empties_nothing(): void {
        $page = $this->record( 'page', 'Job from a translation page' );
        $this->set( $page, [ 'subtitle' => 'Hello' ] );
        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'fr', 'slug' => 'job-from-a-translation-page-fr' ] )['contentId'];
        $this->set( $fr, [ 'subtitle' => null ] );
        $this->wpml()->use_editor( $page, 'wpml' );
        $this->complete( $this->job( $page, 'de' ), [ 'Hello' => 'Hallo' ] );
        $de = $this->wpml()->version( $page, 'de' );
        $this->assertSame( 'Hallo', $this->values( $de )['subtitle'] );

        $this->complete( $this->job( $fr, 'de' ), [] );
        $this->assertSame( 'Hallo', $this->values( $de )['subtitle'], 'emptiness is the original\'s to decide, not another translation\'s' );
    }

    public function test_words_the_next_job_would_empty_are_refused_before_they_are_written(): void {
        [ $page, $fr ] = $this->translated_by_job( 'Refused words page' );
        $this->set( $page, [ 'subtitle' => null, 'box' => [ 'note' => null ] ] );

        $error = $this->mcp_error( 'set-field-values', [ 'postId' => $fr, 'values' => [ 'subtitle' => 'Nouveau' ] ] );
        $this->assertStringContainsString( 'the original has no words here', $error );
        $this->assertStringContainsString( 'set-field-values {postId: ' . $page . '}', $error, 'the agent is told where to add them' );
        $this->assertSame( 'Bonjour', $this->values( $fr )['subtitle'], 'nothing was written' );

        $this->assertStringContainsString( 'Box / Note', $this->mcp_error( 'set-field-values', [ 'postId' => $fr, 'values' => [ 'box' => [ 'note' => 'Autre' ] ] ] ) );
        $this->set( $fr, [ 'box' => [ 'note' => 'Note FR', 'memo' => 'Interne 2' ] ] );
        $this->assertSame( [ 'note' => 'Note FR', 'memo' => 'Interne 2' ], $this->values( $fr )['box'], 'words already held, sent back unchanged with a sibling of its own' );

        $this->set( $fr, [ 'subtitle' => null ] );
        $this->assertNull( $this->values( $fr )['subtitle'], 'emptying is allowed' );
    }

    public function test_a_job_saved_by_someone_without_unfiltered_html_keeps_the_markup_it_carries(): void {
        $page = $this->record( 'page', 'Markup page' );
        $body = '<p>Watch</p><iframe src="https://www.youtube.com/embed/abc"></iframe>';
        $this->set( $page, [ 'body' => $body ] );
        $this->assertStringContainsString( '<iframe', $this->values( $page )['body'], 'an administrator\'s embed is kept on the original' );
        $this->wpml()->use_editor( $page, 'wpml' );
        $this->complete( $this->job( $page, 'fr' ), [ $body => str_replace( 'Watch', 'Regarder', $body ) ], [], false );
        $fr = $this->values( $this->wpml()->version( $page, 'fr' ) )['body'];
        $this->assertStringContainsString( 'Regarder', $fr );
        $this->assertStringContainsString( '<iframe src="https://www.youtube.com/embed/abc"></iframe>', $fr, 'words from a WPML job are trusted as WPML trusts them for ACF fields' );
    }

    public function test_a_hand_written_translation_keeps_and_takes_its_words(): void {
        $page = $this->record( 'page', 'Hand written page' );
        $this->set( $page, [ 'subtitle' => 'Hello' ] );
        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'fr', 'slug' => 'hand-written-page-fr' ] )['contentId'];
        $this->set( $fr, [ 'subtitle' => 'Bonjour' ] );
        $this->set( $page, [ 'subtitle' => null ] );
        $this->assertSame( 'Bonjour', $this->values( $fr )['subtitle'] );
        $this->set( $fr, [ 'subtitle' => 'Salut' ] );
        $this->assertSame( 'Salut', $this->values( $fr )['subtitle'], 'the WordPress editor translation is the translator\'s own' );
    }
}
