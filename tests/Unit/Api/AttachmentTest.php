<?php

namespace Fabrement\Tests\Api;

use Fabrement\Api\Media\Attachment;
use Fabrement\Api\Media\Audio;
use Fabrement\Api\Media\File;
use Fabrement\Api\Media\Image;
use Fabrement\Api\Media\Video;
use Fabrement\Modules\Media\MediaKind;

require_once __DIR__ . '/ApiTestCase.php';

/**
 * Media kinds and the classes an attachment value becomes: the kind follows the mime, a block's
 * media value is an image, a download value is what its mime says, and every class answers the
 * same common questions so code written for one kind does not fail on another.
 */
final class AttachmentTest extends ApiTestCase {

    public function test_the_kind_follows_the_mime(): void {
        self::assertSame(
            [ 'image', 'image', 'video', 'audio', 'document', 'document', 'document', 'document' ],
            array_map( [ MediaKind::class, 'of' ], [ 'image/jpeg', 'image/svg+xml', 'video/mp4', 'audio/mpeg', 'application/pdf', 'application/zip', 'font/woff2', '' ] )
        );
    }

    public function test_a_kind_list_is_read_strictly(): void {
        self::assertSame( [ 'image' ], MediaKind::parse( 'image' ) );
        self::assertSame( [ 'image', 'video' ], MediaKind::parse( [ 'image', 'video' ] ) );
        foreach ( [ 'photo', [], [ 'image', 'image' ], [ 'image', 3 ], [ 'a' => 'image' ], 5 ] as $wrong ) {
            try {
                MediaKind::parse( $wrong );
                self::fail( 'accepted ' . var_export( $wrong, true ) );
            } catch ( \InvalidArgumentException $e ) {
                self::assertNotSame( '', $e->getMessage() );
            }
        }
    }

    public function test_a_value_without_an_id_becomes_the_class_its_shape_says(): void {
        $image = Attachment::of( [ 'id' => 0, 'url' => 'https://picsum.photos/800/600' ] );
        $video = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/clip.mp4', 'mimeType' => 'video/mp4' ] );
        $audio = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/talk.mp3', 'mimeType' => 'audio/mpeg' ] );
        $file  = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/plan.pdf', 'filename' => 'plan.pdf', 'fileSize' => 2048, 'label' => 'The plan' ] );

        self::assertInstanceOf( Image::class, $image );
        self::assertInstanceOf( Video::class, $video );
        self::assertInstanceOf( Audio::class, $audio );
        self::assertInstanceOf( File::class, $file );
        self::assertSame( [ 'image', 'video', 'audio', 'document' ], [ $image->kind(), $video->kind(), $audio->kind(), $file->kind() ] );
        self::assertSame( [ 'plan.pdf', 'PDF', 2048, 'The plan' ], [ $file->name(), $file->ext(), $file->bytes(), $file->text() ] );
        self::assertSame( 'https://cdn.test/clip.mp4', $video->url() );
    }

    public function test_nothing_is_an_empty_file_of_no_kind(): void {
        foreach ( [ null, 0, [ 'id' => 0, 'url' => '' ] ] as $nothing ) {
            $empty = Attachment::of( $nothing );
            self::assertInstanceOf( File::class, $empty );
            self::assertFalse( $empty->not_empty() );
            self::assertSame( '', $empty->kind() );
        }
    }

    public function test_every_class_answers_the_common_questions(): void {
        $values = [
            Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.png' ] ),
            Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.mp4', 'mimeType' => 'video/mp4' ] ),
            Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.mp3', 'mimeType' => 'audio/mpeg' ] ),
            Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.pdf', 'mimeType' => 'application/pdf' ] ),
        ];
        foreach ( $values as $value ) {
            self::assertIsString( $value->url() );
            self::assertIsString( $value->name() );
            self::assertIsString( $value->ext() );
            self::assertIsString( $value->mime() );
            self::assertIsString( $value->title() );
            self::assertIsString( $value->caption() );
            self::assertIsString( $value->alt() );
            self::assertIsInt( $value->bytes() );
            self::assertIsInt( $value->width() );
            self::assertIsInt( $value->height() );
            self::assertIsArray( $value->to_array() );
        }
    }

    public function test_a_wrong_argument_is_logged_not_fatal_on_every_kind(): void {
        $video = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.mp4', 'mimeType' => 'video/mp4' ] );
        $audio = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.mp3', 'mimeType' => 'audio/mpeg' ] );
        [ $out, $log ] = self::logged( static fn() => [ $video->width( foo: 1 ), $audio->height( 'large', 'x' ), $video->to_array( 'large', 'x' ) !== [] ] );
        self::assertSame( [ 0, 0, true ], $out );
        self::assertSame( [
            'Video::width(): unknown option "foo" — it takes size; left out.',
            'Audio::height(): an argument too many — it takes size; left out.',
            'Video::to_array(): an argument too many — it takes size; left out.',
        ], $log );
    }

    public function test_a_spoilt_value_gives_an_empty_one_of_the_kind_asked(): void {
        $api = \Fabrement\Api\Fabrement::instance();
        [ $out, $log ] = self::logged( static fn() => [
            get_class( $api->attachment( [ 'id' => '12' ], kind: 'image' ) ),
            $api->attachment( [ 'id' => '12' ], kind: 'image' )->link()->not_empty(),
            get_class( $api->attachment( [ 'id' => '12' ], kind: 'video' ) ),
            get_class( $api->attachment( [ 'id' => '12' ], kind: [ 'image', 'video' ] ) ),
            get_class( $api->attachment( [ 'id' => '12' ] ) ),
            get_class( $api->attachment( [ 'id' => '12' ], kind: 'photo' ) ),
        ] );
        self::assertSame( [ Image::class, false, Video::class, File::class, File::class, File::class ], $out );
        self::assertStringContainsString( 'Fabrement::attachment(): a media value is {id, url}', implode( "
", $log ) );
        self::assertStringContainsString( 'Fabrement::attachment(): kind: "photo" is not a media kind', implode( "
", $log ) );
    }

    public function test_every_kind_gives_the_same_core_as_an_array(): void {
        $core = [ 'id', 'kind', 'url', 'mime', 'name', 'title', 'caption', 'alt', 'width', 'height', 'bytes' ];
        $keys = static fn( array $a ): array => array_slice( array_keys( $a ), 0, count( $core ) );
        $image = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.png' ] );
        $video = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.mp4', 'mimeType' => 'video/mp4' ] );
        $audio = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.mp3', 'mimeType' => 'audio/mpeg' ] );
        $file  = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.pdf', 'filename' => 'a.pdf', 'fileSize' => 10, 'label' => 'Plan' ] );
        foreach ( [ $image, $video, $audio, $file ] as $one ) {
            self::assertSame( $core, $keys( $one->to_array() ) );
        }
        self::assertSame( [ 'image', 'video', 'audio', 'document' ], array_map( static fn( $a ): string => $a->to_array()['kind'], [ $image, $video, $audio, $file ] ) );
        self::assertSame( [ 'Plan', 'PDF', 10 ], [ $file->to_array()['label'], $file->to_array()['ext'], $file->to_array()['bytes'] ] );
        self::assertArrayHasKey( 'poster', $video->to_array() );
    }

    public function test_kind_applies_to_values_without_an_id(): void {
        $api   = \Fabrement\Api\Fabrement::instance();
        $photo = [ 'id' => 0, 'url' => 'https://cdn.test/a.png' ];
        $clip  = [ 'id' => 0, 'url' => 'https://cdn.test/a.mp4', 'mimeType' => 'video/mp4' ];
        [ $out, $log ] = self::logged( static fn() => [
            [ get_class( $api->attachment( $photo, kind: 'video' ) ), $api->attachment( $photo, kind: 'video' )->not_empty() ],
            [ get_class( $api->attachment( $clip ) ), $api->attachment( $clip, kind: 'video' )->url() ],
            [ get_class( $api->attachment( $clip, kind: 'image' ) ), $api->attachment( $clip, kind: 'image' )->not_empty() ],
        ] );
        self::assertSame( [
            [ Video::class, false ],
            [ Video::class, 'https://cdn.test/a.mp4' ],
            [ Image::class, false ],
        ], $out, 'a media value is an image, a download value is what its mime says, and kind filters both' );
        self::assertSame( [], $log );
    }

    public function test_an_image_without_an_id_keeps_the_alt_it_was_saved_with(): void {
        $api   = \Fabrement\Api\Fabrement::instance();
        $photo = [ 'id' => 0, 'url' => 'https://cdn.test/team.png', 'alt' => 'The team <b>' ];
        [ $out, $log ] = self::logged( static fn() => [
            $api->attachment( $photo )->alt(),
            $api->attachment( [ 'id' => 0, 'url' => 'https://cdn.test/a.png', 'alt' => 5 ] )->not_empty(),
        ] );
        self::assertSame( 'The team <b>', $out[0] );
        self::assertFalse( $out[1] );
        self::assertStringContainsString( 'Fabrement::attachment(): a media value is {id, url, alt} — alt is text, not int.', implode( "
", $log ) );
    }

    public function test_a_value_without_an_id_or_a_mime_takes_its_kind_from_the_extension(): void {
        $read = static fn( array $value ): array => [ ( new \ReflectionClass( Attachment::of( $value ) ) )->getShortName(), Attachment::of( $value )->mime() ];
        self::assertSame( [ 'Video', 'video/mp4' ], $read( [ 'id' => 0, 'url' => 'https://cdn.test/clip.mp4' ] ) );
        self::assertSame( [ 'Audio', 'audio/mpeg' ], $read( [ 'id' => 0, 'url' => 'https://cdn.test/talk.mp3' ] ) );
        self::assertSame( [ 'File', 'application/pdf' ], $read( [ 'id' => 0, 'url' => 'https://cdn.test/plan.pdf' ] ) );
        self::assertSame( [ 'Video', 'video/mp4' ], $read( [ 'id' => 0, 'url' => 'https://cdn.test/CLIP.MP4?v=2' ] ), 'the address path, any case' );
        self::assertSame( [ 'Image', '' ], $read( [ 'id' => 0, 'url' => 'https://picsum.photos/800/600' ] ), 'no extension: a media value stays an image' );
        self::assertSame( [ 'Image', 'image/jpeg' ], $read( [ 'id' => 0, 'url' => 'https://cdn.test/photo.jpg', 'label' => 'Photo' ] ) );
        self::assertSame( [ 'File', 'application/pdf' ], $read( [ 'id' => 0, 'url' => 'https://cdn.test/files/plan', 'filename' => 'plan.pdf' ] ), 'the file name tells the type' );
        self::assertSame( [ 'Video', 'video/mp4' ], $read( [ 'id' => 0, 'url' => 'https://cdn.test/clip.mp4', 'label' => 'Clip' ] ) );
        self::assertSame( [ 'File', '' ], $read( [ 'id' => 0, 'url' => 'https://cdn.test/get?id=4', 'label' => 'Price list' ] ), 'no extension: a download value stays a file' );
        self::assertSame( [ 'Audio', 'audio/mpeg' ], $read( [ 'id' => 0, 'url' => 'https://cdn.test/a.pdf', 'mimeType' => 'audio/mpeg' ] ), 'a stored mime wins over the extension' );
    }

    public function test_or_takes_any_attachment_as_the_fallback(): void {
        $photo = Attachment::of( [ 'id' => 0, 'url' => 'https://cdn.test/a.png' ] );
        $none  = Attachment::of( [ 'id' => 0, 'url' => '', 'mimeType' => 'video/mp4' ] );
        self::assertSame( $photo, $none->or( $photo ), 'no video, the photo' );
        self::assertSame( $photo, $photo->or( $none ) );
        [ $kept, $log ] = self::logged( static fn() => $photo->or( 'nope' ) );
        self::assertSame( $photo, $kept );
        self::assertStringContainsString( 'or()', implode( "
", $log ) );
    }

    public function test_an_unknown_value_shape_is_refused(): void {
        foreach ( [ [ 'id' => '12' ], 'photo.png', 1.5, [ 'id' => 0, 'url' => 'x.pdf', 'fileSize' => '20' ] ] as $wrong ) {
            try {
                Attachment::of( $wrong );
                self::fail( 'accepted ' . var_export( $wrong, true ) );
            } catch ( \Fabrement\Api\FieldsException $e ) {
                self::assertNotSame( '', $e->getMessage() );
            }
        }
    }
}
