<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SeoPage;
use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Fields as words in Yoast's title and description templates: %%fab_…%% for a record's fields,
 * %%fabopt_<page>__…%% for an options page — an agent writes the template with set-seo-defaults,
 * fills the fields, and the crawler reads each one as a reader would (a choice by its label, a
 * number and a date in the site's format, picked records by their titles while published, a
 * repeater by its rows), changing as the values change and saying nothing behind a password.
 */
final class YoastVariablesTest extends SiteTestCase {

    private const TYPE    = 'qa_scourse';
    private const TEACHER = 'qa_teacher';

    private const TEMPLATE = 'L=%%fab_level%%; P=%%fab_price%%; D=%%fab_day%%; S=%%fab_starts%%; T=%%fab_teacher%%; G=%%fab_speakers%%; C=%%fab_venue_city%%; M=%%fab_modules%%; V=%%fab_venue_slots%%.';

    private static bool $ready = false;

    private static string $site_name = '';

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            $this->site()->wp( 'option', 'update', 'date_format', 'j F Y' );
            $this->site()->wp( 'option', 'update', 'time_format', 'H:i' );
            $this->site()->wp( 'option', 'update', 'timezone_string', 'Europe/Kyiv' );
            self::$site_name = trim( $this->site()->wp( 'option', 'get', 'blogname' ) );
            $this->post_type( self::TEACHER, [ 'singular' => 'Teacher' ] );
            $this->post_type( self::TYPE, [ 'singular' => 'Course' ] );
            $this->fields( self::TYPE, [
                self::field( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => 'For beginners' ], [ 'value' => 'pro', 'label' => 'For pros' ] ], 'default' => 'beginner' ] ),
                self::field( 'price', 'number', [ 'format' => 'decimal' ] ),
                self::field( 'day', 'date_picker' ),
                self::field( 'starts', 'date_time_picker' ),
                self::field( 'photo', 'image' ),
                self::field( 'teacher', 'post_object', [ 'postTypes' => [ self::TEACHER ] ] ),
                self::field( 'speakers', 'relationship', [ 'postTypes' => [ self::TEACHER ] ] ),
                self::field( 'venue', 'group', [ 'sub_fields' => [ self::field( 'city', 'text' ), self::field( 'slots', 'repeater', [ 'sub_fields' => [ self::field( 'time', 'text' ) ] ] ) ] ] ),
                self::field( 'modules', 'repeater', [ 'sub_fields' => [ self::field( 'title', 'text' ) ] ] ),
                self::field( 'free', 'true_false' ),
            ] );
            $this->mcp( 'set-seo-defaults', [ 'postType' => self::TYPE, 'titleTemplate' => '%%title%% — %%fab_venue_city%% %%sep%% %%sitename%%', 'metaDescTemplate' => self::TEMPLATE ] );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready    = false;
        self::$site_name = '';
        parent::tearDownAfterClass();
    }

    private function set( int $id, array $values ): void {
        $this->mcp( 'set-field-values', [ 'postId' => $id, 'values' => $values ] );
    }

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    private function head( int $id ): SeoPage {
        $page = $this->get( $this->url( $id ) );
        $this->assertSame( 200, $page['status'] );
        return SeoPage::of( $page['body'] );
    }

    /** The description as the agent (get-seo) and the crawler (the page) read it — the same. */
    private function description( int $id ): string {
        $agent = (string) $this->mcp( 'get-seo', [ 'contentId' => $id ] )['content']['effective']['description'];
        $this->assertSame( $agent, $this->head( $id )->description(), 'get-seo shows what the page prints' );
        return $agent;
    }

    private function teacher( string $name, string $status = 'publish' ): int {
        return $this->record( self::TEACHER, $name, $status );
    }

    public function test_the_fields_as_a_reader_reads_them_and_as_they_change(): void {
        [ $olena, $ivan, $maria, $petro ] = [ $this->teacher( 'Olena' ), $this->teacher( 'Ivan' ), $this->teacher( 'Maria' ), $this->teacher( 'Petro' ) ];
        $draft = $this->teacher( 'Draft', 'draft' );
        $id    = $this->record( self::TYPE, 'Negotiation' );
        $this->set( $id, [
            'price'    => 1250.5,
            'day'      => '2026-07-15',
            'starts'   => '2026-07-15T18:00',
            'teacher'  => $olena,
            'speakers' => [ $olena, $draft, $ivan, $maria, $petro ],
            'venue'    => [ 'city' => 'Lviv', 'slots' => [ [ 'time' => '10:00' ], [ 'time' => '12:00' ], [ 'time' => '14:00' ] ] ],
            'modules'  => [ [ 'title' => 'A' ], [ 'title' => 'B' ] ],
        ] );

        $this->assertSame( 'L=For beginners; P=1,250.5; D=15 July 2026; S=15 July 2026 18:00; T=Olena; G=Olena, Ivan, Maria…; C=Lviv; M=2; V=3.', $this->description( $id ),
            'never-set choice: its default as its label; drafts skipped, three named; a repeater inside a group counts its rows too' );
        $page = $this->head( $id );
        $this->assertStringStartsWith( 'Negotiation — Lviv', $page->title() );
        $this->assertStringContainsString( self::$site_name, $page->title() );

        $this->set( $id, [ 'level' => 'pro', 'venue' => [ 'city' => 'Kyiv' ], 'teacher' => $draft ] );
        $text = $this->description( $id );
        $this->assertStringContainsString( 'L=For pros;', $text, 'changed on the next view' );
        $this->assertStringContainsString( 'C=Kyiv;', $text );
        $this->assertStringContainsString( 'T=;', $text, 'a draft is not named' );
        $this->assertStringStartsWith( 'Negotiation — Kyiv', $this->head( $id )->title() );
    }

    public function test_a_less_than_sign_in_a_field_does_not_cut_the_title(): void {
        $id = $this->record( self::TYPE, 'Kids course' );
        $this->set( $id, [ 'venue' => [ 'city' => 'Kids <12 & more' ] ] );
        $page = $this->head( $id );
        // What a browser shows: "<" and "&" as typed, the rest of the title still there.
        $this->assertStringContainsString( 'Kids <12 & more', $page->title(), 'not cut at "<"' );
        $this->assertStringContainsString( self::$site_name, $page->title(), 'the rest of the title stays' );
        $this->assertMatchesRegularExpression( '/og:title" content="[^"]*Kids &lt;12 &amp; more/', $page->html, 'og:title escaped once, not cut' );
    }

    public function test_empty_fields_give_no_words(): void {
        $id = $this->record( self::TYPE, 'Empty' );
        $this->assertSame( 'L=For beginners; P=; D=; S=; T=; G=; C=; M=; V=.', $this->description( $id ), 'nothing set: only the default' );

        $this->set( $id, [ 'modules' => [], 'speakers' => [], 'venue' => [ 'city' => '' ] ] );
        $text = $this->description( $id );
        $this->assertStringContainsString( 'M=;', $text, 'no rows: dropped, not "0"' );
        $this->assertStringContainsString( 'G=;', $text );
        $this->assertStringContainsString( 'C=;', $text );
        $this->assertStringNotContainsString( '%%', $text );
        $this->assertStringStartsWith( 'Empty', $this->head( $id )->title() );
    }

    public function test_picked_records_are_named_only_while_published(): void {
        [ $olena, $ivan, $maria ] = [ $this->teacher( 'Olena P' ), $this->teacher( 'Ivan P' ), $this->teacher( 'Maria P' ) ];
        $draft = $this->teacher( 'Draft P', 'draft' );
        $id    = $this->record( self::TYPE, 'Picked' );

        $this->set( $id, [ 'speakers' => [ $olena, $ivan, $maria ] ] );
        $this->assertStringContainsString( 'G=Olena P, Ivan P, Maria P;', $this->description( $id ), 'exactly three: no "…"' );
        $this->set( $id, [ 'speakers' => [ $olena, $draft, $ivan, $maria ], 'teacher' => $ivan ] );
        $this->assertStringContainsString( 'G=Olena P, Ivan P, Maria P;', $this->description( $id ), 'four picked, three public: no "…"' );
        $this->assertStringContainsString( 'T=Ivan P;', $this->description( $id ) );

        $this->mcp( 'set-content-status', [ 'contentId' => $ivan, 'status' => 'trash' ] );
        $text = $this->description( $id );
        $this->assertStringContainsString( 'T=;', $text, 'trashed' );
        $this->assertStringContainsString( 'G=Olena P, Maria P;', $text );

        $this->mcp( 'delete-content', [ 'contentId' => $olena, 'force' => true ] );
        $this->assertStringContainsString( 'G=Maria P;', $this->description( $id ), 'deleted' );
    }

    public function test_a_record_behind_a_password_says_nothing_of_its_fields(): void {
        $olena = $this->teacher( 'Olena L' );
        $id    = $this->record( self::TYPE, 'Locked' );
        $this->set( $id, [ 'venue' => [ 'city' => 'Secret town' ], 'teacher' => $olena ] );
        $this->assertStringContainsString( 'Secret town', $this->head( $id )->title() );

        $this->site()->wp( 'post', 'update', (string) $id, '--post_password=x' );
        $page = $this->head( $id );
        $this->assertStringNotContainsString( 'Secret town', $page->title() );
        $this->assertStringNotContainsString( 'Secret town', $page->description() );
        $this->assertStringNotContainsString( 'Olena L', $page->description() );
        $this->assertStringStartsWith( 'Locked', $page->title(), 'the title itself is public' );
    }

    public function test_options_page_variables_read_the_page_not_the_record_and_leave_with_their_field(): void {
        $phone = self::field( 'phone', 'text' );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-seo', 'expectedRevision' => 0, 'page' => [ 'title' => 'QA' ], 'fields' => [ $phone, self::field( 'old', 'text' ) ] ] );
        $this->mcp( 'set-seo-defaults', [ 'postType' => 'page', 'metaDescTemplate' => 'Call %%fabopt_qa-seo__phone%%. Old %%fabopt_qa-seo__old%%.' ] );
        $page = $this->record( 'page', 'Contacts' );

        $text = $this->description( $page );
        $this->assertStringNotContainsString( '%%', $text, 'not filled yet: nothing' );
        $this->assertStringNotContainsString( '+380', $text );

        $this->mcp( 'set-field-values', [ 'options' => 'qa-seo', 'values' => [ 'phone' => '+380 44 000 00 00', 'old' => 'Retired words' ] ] );
        $text = $this->description( $page );
        $this->assertStringContainsString( 'Call +380 44 000 00 00.', $text, 'a page with no such field reads the options page' );
        $this->assertStringContainsString( 'Retired words', $text );

        $this->mcp( 'save-options-page', [ 'slug' => 'qa-seo', 'expectedRevision' => (int) $this->mcp( 'get-options-page', [ 'slug' => 'qa-seo' ] )['revision'], 'fields' => [ $phone ] ] );
        $text = $this->description( $page );
        $this->assertStringNotContainsString( 'Retired words', $text, 'a removed field has no variable' );
        $this->assertStringNotContainsString( 'fabopt', $text );
        $this->assertStringContainsString( 'Call +380 44 000 00 00.', $text );

        $locked = $this->record( 'page', 'Locked contacts' );
        $this->site()->wp( 'post', 'update', (string) $locked, '--post_password=x' );
        $this->assertStringContainsString( '+380 44 000 00 00', $this->head( $locked )->description(), 'site-wide words are not behind a record password' );
        $this->mcp( 'set-seo-defaults', [ 'postType' => 'page', 'metaDescTemplate' => null ] );
    }
}
