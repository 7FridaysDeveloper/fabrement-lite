<?php

namespace Fabrement\Tests\Api;

use Fabrement\Api\Content\Post;
use Fabrement\Api\Content\Term;
use Fabrement\Api\Fabrement;
use Fabrement\Api\FieldsException;
use Fabrement\Api\Markup\Link;
use Fabrement\Api\Media\File;
use Fabrement\Api\Media\Image;
use Fabrement\Api\Media\MediaValue;
use Fabrement\Tests\WordPressState;

require_once __DIR__ . '/ApiTestCase.php';

/**
 * Values fabrement() reads and formats: media values, formatting through the bridge on a
 * single-language site, the site's own words and how the scanners find them, bad ids logged.
 */
final class ValuesTest extends ApiTestCase {

    public function test_media_values_are_read_strictly(): void {
        self::assertSame( [ 0, '', '' ], MediaValue::media( null ) );
        self::assertSame( [ 7, '', '' ], MediaValue::media( 7 ) );
        self::assertSame( [ 7, '/a.png', '' ], MediaValue::media( [ 'id' => 7, 'url' => '/a.png' ] ) );
        self::assertSame( [ 0, '/a.png', 'A cat' ], MediaValue::media( [ 'id' => 0, 'url' => '/a.png', 'alt' => 'A cat' ] ) );
        self::assertSame( [ 'id' => 4, 'url' => '', 'filename' => '', 'label' => 'L', 'fileSize' => 0, 'mimeType' => '' ], MediaValue::download( [ 'id' => 4, 'label' => 'L', 'extra' => true ] ) );
        foreach ( [
            static fn() => MediaValue::media( -1 ),
            static fn() => MediaValue::media( '7' ),
            static fn() => MediaValue::media( [ 'id' => '7' ] ),
            static fn() => MediaValue::download( [ 'fileSize' => '10' ] ),
            static fn() => MediaValue::download( [ 'label' => 5 ] ),
        ] as $i => $bad ) {
            try {
                $bad();
                self::fail( 'case ' . $i . ' was accepted' );
            } catch ( FieldsException $e ) {
                self::assertNotSame( '', $e->getMessage() );
            }
        }
    }

    public function test_a_bad_media_value_is_an_empty_object_and_one_log_line(): void {
        [ $image, $log ] = self::logged( static fn() => Fabrement::instance()->attachment( [ 'id' => 'x' ] ) );
        self::assertFalse( $image->not_empty() );
        self::assertSame( [ 'Fabrement::attachment(): a media value is {id, url} — id an attachment id, url an address; got id string, url string.' ], $log );
        [ $file, $log ] = self::logged( static fn() => Fabrement::instance()->attachment( -3 ) );
        self::assertFalse( $file->not_empty() );
        self::assertSame( [], $log, 'no id, as from an address: nothing, quietly' );
    }

    public function test_dates_in_the_sites_format_and_timezone(): void {
        WordPressState::$options['date_format'] = 'j.n.Y';
        WordPressState::$options['time_format'] = 'H:i';
        WordPressState::$options['timezone_string'] = 'Europe/Kyiv';
        $fmt = Fabrement::instance()->format();
        self::assertSame( '4.10.2026', $fmt->date( '2026-10-04' ) );
        self::assertSame( '04.10.2026', $fmt->date( '2026-10-04', format: 'd.m.Y' ) );
        self::assertSame( '4.10.2026 09:30', $fmt->date( '2026-10-04T09:30' ) );
        self::assertSame( '4.10.2026 09:30', $fmt->date( '2026-10-04 09:30:00' ) );
        self::assertSame( '11:00', $fmt->date( '2026-10-04T08:00:00Z', format: 'H:i' ), 'an offset is shown in the site\'s timezone' );
        self::assertSame( '', $fmt->date( null ) );
        self::assertSame( '', $fmt->date( '' ) );
        [ $out, $log ] = self::logged( static fn() => $fmt->date( '2026-02-30' ) );
        self::assertSame( '', $out );
        self::assertStringStartsWith( 'Format::date(): "2026-02-30" is not a date', $log[0] );
        [ , $log ] = self::logged( static fn() => $fmt->date( '2026-10-04', format: '' ) );
        self::assertStringStartsWith( 'Format::date(): format:', $log[0] );
    }

    public function test_file_sizes(): void {
        $fmt = Fabrement::instance()->format();
        self::assertSame( '2 KB', $fmt->filesize( 2048 ) );
        self::assertSame( '1.5 MB', $fmt->filesize( 1572864 ) );
        self::assertSame( '', $fmt->filesize( null ) );
        [ $out, $log ] = self::logged( static fn() => $fmt->filesize( -1 ) );
        self::assertSame( '', $out );
        self::assertCount( 1, $log );
    }


    public function test_ids_read_as_wordpress_reads_them_and_only_a_non_number_is_logged(): void {
        $this->page( 5, 'Five' );
        $api = Fabrement::instance();
        [ $posts, $log ] = self::logged( static fn() => $api->posts( [ 5, '5', 5.0, 0, null, false, '', -1, '-2', 404 ], status: 'publish' ) );
        self::assertSame( [ 5 ], array_map( static fn( $p ) => $p->id(), $posts ), "'5' and 5.0 are 5, as WordPress reads them; each once" );
        self::assertSame( [], $log, 'no id, or one that does not exist: nothing, not a mistake' );
        [ $one, $log ] = self::logged( static fn() => [ $api->post( '5', status: 'publish' )->id(), $api->post( null )->id(), $api->post( '' )->id(), $api->post( 404 )->id() ] );
        self::assertSame( [ [ 5, 0, 0, 0 ], [] ], [ $one, $log ] );
        [ $kept, $log ] = self::logged( static fn() => $api->posts( [ 5, 'about', [ 5 ], 7.5 ], status: 'publish' ) );
        self::assertSame( [ 5 ], array_map( static fn( $p ) => $p->id(), $kept ), 'a non-number is left out, the rest shown' );
        self::assertSame( [
            'Fabrement::posts(): give an id (a number), not "about".',
            'Fabrement::posts(): give an id (a number), not array.',
            'Fabrement::posts(): give an id (a number), not 7.5.',
        ], $log );
        [ $none, $log ] = self::logged( static fn() => $api->post( 'about' )->id() );
        self::assertSame( [ 0, [ 'Fabrement::post(): give an id (a number), not "about".' ] ], [ $none, $log ] );
        [ $none, $log ] = self::logged( static fn() => $api->posts( '5', status: 'publish' ) );
        self::assertSame( [ [], [ 'Fabrement::posts(): give a list of ids, not string.' ] ], [ $none, $log ] );
        [ $none, $log ] = self::logged( static fn() => $api->post( 5, lang: 5 )->id() );
        self::assertSame( [ 0, [ 'Fabrement::post(): lang: give a language code, not int.' ] ], [ $none, $log ] );
        [ $one, $log ] = self::logged( static fn() => $api->post( 5, lang: null, status: 'publish' )->id() );
        self::assertSame( [ 5, [] ], [ $one, $log ], 'lang: null is the page language' );
        [ $none, $log ] = self::logged( static fn() => $api->terms( taxonomy: 'category', hide_empty: 'yes' ) );
        self::assertSame( [ [], [ 'Fabrement::terms(): hide_empty: true or false, not string.' ] ], [ $none, $log ] );
        [ $none, $log ] = self::logged( static fn() => $api->terms() );
        self::assertSame( [], $none );
        self::assertStringStartsWith( 'Fabrement::terms(): name a taxonomy, or give term ids', $log[0] ?? '' );
        [ $none, $log ] = self::logged( static fn() => [ $api->taxonomies( 404 ), $api->taxonomies( 0 ), $api->taxonomies( [ 1 ] ) ] );
        self::assertSame( [ [ [], [], [] ], [ 'Fabrement::taxonomies(): give an id (a number), not array.' ] ], [ $none, $log ] );
    }

    public function test_or_gives_the_first_that_exists_of_the_same_kind(): void {
        $this->page( 5, 'Five' );
        $api = Fabrement::instance();
        $five = $api->post( 5, status: 'publish' );
        self::assertSame( 5, Post::none()->or( $five )->id() );
        self::assertSame( 5, $five->or( Post::none() )->id() );
        self::assertFalse( Post::none()->or( Post::none() )->not_empty() );
        self::assertSame( '/a.png', Image::none()->or( Image::from( 0, '/a.png' ) )->or( Image::from( 0, '/c.png' ) )->url() );
        self::assertSame( '/a.png', Image::from( 0, '/a.png' )->or( Image::from( 0, '/b.png' ) )->url() );
        self::assertSame( 'https://example.test/x', Link::none()->or( $api->link( 'https://example.test/x' ) )->url() );
        self::assertFalse( File::none()->or( File::none() )->not_empty() );
        self::assertFalse( Term::none()->or( Term::none() )->not_empty() );
        [ $kept, $log ] = self::logged( static fn() => Image::none()->or( $five ) );
        self::assertFalse( $kept->not_empty() );
        [ , $more ] = self::logged( static fn() => $five->or( null ) );
        self::assertSame( [ 'Image::or(): give another Attachment (Post given); kept the first.', 'Post::or(): give another Post (null given); kept the first.' ], array_merge( $log, $more ) );
    }

    public function test_a_post_gives_its_1_4_array(): void {
        $this->page( 5, 'Five' );
        self::assertSame( [], Fabrement::instance()->post( 6, status: 'publish' )->to_array() );
        self::assertSame( 'Five', Fabrement::instance()->post( 5, status: 'publish' )->title() );
    }
}
