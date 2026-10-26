<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Modules\Mcp\Ability\ListSeoAbility;
use Fabrement\Modules\PostType\ArchivePageStore;
use Fabrement\Modules\Seo\Bridge\NullSeoBridge;
use Fabrement\Modules\Seo\Seo;
use WP_Error;
use WP_UnitTestCase;

/**
 * list-seo on a one-language site: where each row's title and description come from, the audit
 * filters, the three kinds — and not a word about languages, which this site does not have.
 */
final class ListSeoTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Yoast SEO is not loaded in this run.' );
        }
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        Seo::set( null );
    }

    public function tear_down(): void {
        Seo::set( null );
        \WPSEO_Options::set( 'metadesc-page', '' );
        \WPSEO_Options::set( 'noindex-qa_lnote', false );
        unregister_post_type( 'qa_lnote' );
        parent::tear_down();
    }

    /** @return array<string,mixed>|WP_Error */
    private function list( array $input ) {
        return wp_get_ability( 'fabrement/list-seo' )->execute( $input );
    }

    /** @return array<int,array<string,mixed>> id → row */
    private function rows( array $input ): array {
        $out = $this->list( $input + [ 'limit' => 200 ] );
        $this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_message() : '' );

        return array_column( $out['items'], null, 'id' );
    }

    private function page( string $title, array $seo = [] ): int {
        $id = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => $title, 'post_status' => 'publish' ] );
        if ( $seo !== [] ) {
            Seo::bridge()->write_post( $id, $seo );
        }
        return $id;
    }

    public function test_rows_say_where_title_and_description_come_from(): void {
        $own  = $this->page( 'Own', [ 'title' => 'Own title', 'metaDesc' => 'Own description' ] );
        $bare = $this->page( 'Bare &amp; plain' );

        $rows = $this->rows( [ 'postType' => 'page' ] );
        $this->assertSame( [ 'title' => 'own', 'metaDesc' => 'own', 'noindex' => false, 'canonical' => '' ], $rows[ $own ]['seo'] );
        $this->assertSame( 'template', $rows[ $bare ]['seo']['title'], 'Yoast\'s page template names it' );
        $this->assertSame( 'none', $rows[ $bare ]['seo']['metaDesc'] );
        $this->assertSame( 'Bare & plain', $rows[ $bare ]['title'], 'as a reader sees it' );

        \WPSEO_Options::set( 'metadesc-page', '%%excerpt%%' );
        $this->assertSame( 'template', $this->rows( [ 'postType' => 'page' ] )[ $bare ]['seo']['metaDesc'], 'a description template counts' );
    }

    public function test_the_filters_find_what_needs_work(): void {
        $done      = $this->page( 'Done', [ 'title' => 'Done', 'metaDesc' => 'Described' ] );
        $bare      = $this->page( 'Bare' );
        $hidden    = $this->page( 'Hidden', [ 'noindex' => true ] );
        $elsewhere = $this->page( 'Elsewhere', [ 'metaDesc' => 'x', 'canonical' => 'https://example.com/other/' ] );
        $self      = $this->page( 'Self', [ 'metaDesc' => 'x' ] );
        Seo::bridge()->write_post( $self, [ 'canonical' => (string) get_permalink( $self ) ] );

        $ids = fn( array $filter ): array => array_keys( $this->rows( [ 'postType' => 'page', 'filter' => $filter ] ) );

        $this->assertEqualsCanonicalizing( [ $bare ], array_intersect( $ids( [ 'noMetaDesc' ] ), [ $done, $bare, $hidden, $elsewhere, $self ] ), 'a noindex page is not a missing description' );
        $this->assertEqualsCanonicalizing( [ $bare, $elsewhere, $self ], array_intersect( $ids( [ 'noCustomTitle' ] ), [ $done, $bare, $hidden, $elsewhere, $self ] ) );
        $this->assertEqualsCanonicalizing( [ $hidden ], array_intersect( $ids( [ 'noindex' ] ), [ $done, $bare, $hidden, $elsewhere, $self ] ) );
        $this->assertEqualsCanonicalizing( [ $elsewhere ], array_intersect( $ids( [ 'canonicalElsewhere' ] ), [ $done, $bare, $hidden, $elsewhere, $self ] ), 'a canonical to itself is not elsewhere' );
        $this->assertSame( [], array_intersect( $ids( [ 'noMetaDesc', 'noCustomTitle' ] ), [ $done, $hidden, $elsewhere, $self ] ), 'filters are all-must-hold' );
    }

    public function test_a_types_noindex_setting_counts(): void {
        register_post_type( 'qa_lnote', [ 'public' => true, 'label' => 'Notes' ] );
        $note = self::factory()->post->create( [ 'post_type' => 'qa_lnote', 'post_status' => 'publish' ] );
        \WPSEO_Options::set( 'noindex-qa_lnote', true );

        $this->assertTrue( $this->rows( [ 'postType' => 'qa_lnote' ] )[ $note ]['seo']['noindex'] );
        Seo::bridge()->write_post( $note, [ 'noindex' => false ] );
        $this->assertFalse( $this->rows( [ 'postType' => 'qa_lnote' ] )[ $note ]['seo']['noindex'], 'the record\'s own setting wins' );
    }

    public function test_archives_are_listed_as_archives_and_terms_as_terms(): void {
        register_post_type( 'qa_lnote', [ 'public' => true, 'has_archive' => 'qa-lnotes', 'label' => 'Notes' ] );
        $record = ArchivePageStore::ensure( 'qa_lnote', 'Notes', 'qa-lnotes' );
        $this->assertGreaterThan( 0, $record );

        $this->assertArrayNotHasKey( $record, $this->rows( [ 'postType' => 'page' ] ), 'the record is its archive, not a page' );
        $archive = $this->rows( [ 'kind' => 'archive' ] )[ $record ] ?? null;
        $this->assertSame( 'qa_lnote', $archive['postType'] ?? null );
        $this->assertSame( 'archive', $archive['kind'] );

        $term = self::factory()->term->create( [ 'taxonomy' => 'category', 'name' => 'QA list' ] );
        Seo::bridge()->write_term( \WP_Term::get_instance( $term ), [ 'metaDesc' => 'About QA' ] );
        $row = $this->rows( [ 'kind' => 'term', 'taxonomy' => 'category' ] )[ $term ] ?? null;
        $this->assertSame( 'own', $row['seo']['metaDesc'] ?? null );
        $this->assertSame( (string) get_term_link( $term ), $row['url'] );
    }

    public function test_one_language_says_nothing_about_languages(): void {
        $this->page( 'Plain' );
        $schema = ( new ListSeoAbility() )->input_schema();
        $this->assertArrayNotHasKey( 'language', $schema['properties'] );
        $this->assertArrayNotHasKey( 'missingIn', $schema['properties'] );
        $this->assertNotContains( 'untranslated', $schema['properties']['filter']['items']['enum'] );
        $this->assertStringNotContainsString( 'language', ( new ListSeoAbility() )->description() );

        foreach ( $this->rows( [ 'postType' => 'page' ] ) as $row ) {
            $this->assertSame( [], array_intersect( array_keys( $row ), [ 'language', 'originalId', 'missingLanguages', 'translation' ] ) );
        }
    }

    public function test_without_an_seo_plugin_it_says_so(): void {
        Seo::set( new NullSeoBridge() );
        $out = $this->list( [] );
        $this->assertInstanceOf( WP_Error::class, $out );
        $this->assertSame( 'yoast_inactive', $out->get_error_code() );
    }

    public function test_limit_and_offset_page_through_the_total(): void {
        foreach ( [ 'A', 'B', 'C' ] as $t ) {
            $this->page( 'QA page ' . $t );
        }
        $all  = $this->list( [ 'postType' => 'page', 'limit' => 200 ] );
        $page = $this->list( [ 'postType' => 'page', 'limit' => 1, 'offset' => 1 ] );
        $this->assertSame( $all['total'], $page['total'] );
        $this->assertSame( [ $all['items'][1]['id'] ], array_column( $page['items'], 'id' ) );
    }

    /** Database queries a call makes; `$like` counts only those whose SQL contains it. */
    private function queries( array $input, string $like = '' ): int {
        global $wpdb;
        $count = 0;
        $spy   = static function ( string $sql ) use ( &$count, $like ): string {
            if ( $like === '' || strpos( $sql, $like ) !== false ) {
                ++$count;
            }
            return $sql;
        };
        add_filter( 'query', $spy );
        $this->list( $input );
        remove_filter( 'query', $spy );
        return $count;
    }

    public function test_the_records_are_read_in_one_go_however_many(): void {
        register_post_type( 'qa_lnote', [ 'public' => true, 'label' => 'Notes' ] );
        $make = function ( int $n ): void {
            for ( $i = 0; $i < $n; $i++ ) {
                $id = self::factory()->post->create( [ 'post_type' => 'qa_lnote', 'post_status' => 'publish' ] );
                Seo::bridge()->write_post( $id, [ 'metaDesc' => 'About ' . $id ] );
            }
        };
        $make( 3 );
        $this->list( [ 'postType' => 'qa_lnote' ] ); // the user's rights, read once per request
        wp_cache_flush();
        $few = $this->queries( [ 'postType' => 'qa_lnote', 'limit' => 1 ] );
        $make( 30 );
        wp_cache_flush();
        $many = $this->queries( [ 'postType' => 'qa_lnote', 'limit' => 1 ] );
        $this->assertSame( $few, $many, 'ten times the records, the same queries' );
        $this->assertSame( 33, $this->list( [ 'postType' => 'qa_lnote', 'limit' => 1 ] )['total'] );
    }

    public function test_the_term_store_is_read_once_for_all_terms(): void {
        foreach ( [ 'A', 'B', 'C', 'D', 'E' ] as $name ) {
            $term = self::factory()->term->create( [ 'taxonomy' => 'category', 'name' => 'QA ' . $name ] );
            Seo::bridge()->write_term( \WP_Term::get_instance( $term ), [ 'metaDesc' => 'About ' . $name ] );
        }
        $this->assertSame( 1, $this->queries( [ 'kind' => 'term', 'taxonomy' => 'category' ], 'wpseo_taxonomy_meta' ) );
    }
}
