<?php

namespace Fabrement\Tests\Wp;

use WP_Error;
use WP_UnitTestCase;

/**
 * list-media and delete-media on a single-language site: no languages anywhere, and an image is
 * deleted even while a page still shows it — the answer says which.
 */
final class MediaToolsTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    /** @return mixed */
    private function tool( string $name, array $input = [] ) {
        return wp_get_ability( 'fabrement/' . $name )->execute( $input );
    }

    private function image( string $title, string $alt = '' ): int {
        $id = self::factory()->attachment->create_object( [ 'file' => $title . '.png', 'post_title' => $title, 'post_mime_type' => 'image/png' ] );
        if ( $alt !== '' ) {
            update_post_meta( $id, '_wp_attachment_image_alt', $alt );
        }
        return $id;
    }

    public function test_list_media_lists_images_newest_first_without_languages(): void {
        $older = $this->image( 'qa-media-one', 'Meeting room' );
        $newer = $this->image( 'qa-media-two' );
        wp_update_post( [ 'ID' => $older, 'post_date' => '2020-01-01 00:00:00' ] );

        $listed = $this->tool( 'list-media', [ 'search' => 'qa-media' ] );
        $this->assertIsArray( $listed, $listed instanceof WP_Error ? $listed->get_error_message() : '' );
        $this->assertSame( 2, $listed['total'] );
        $this->assertSame( [ $newer, $older ], array_column( $listed['items'], 'id' ) );
        $this->assertArrayNotHasKey( 'languages', $listed['items'][0], 'no languages on a single-language site' );

        $this->assertSame( [ $older ], array_column( $this->tool( 'list-media', [ 'search' => 'Meeting' ] )['items'], 'id' ), 'found by alt text' );
        $this->assertSame( [ $older ], array_column( array_slice( $this->tool( 'list-media' )['items'], -1 ), 'id' ), 'without a search: by date, oldest last' );
        $this->assertInstanceOf( WP_Error::class, $this->tool( 'list-media', [ 'language' => 'en' ] ), 'no language input' );
    }

    public function test_images_uploaded_in_the_same_second_keep_one_order(): void {
        $ids = [ $this->image( 'qa-media-a' ), $this->image( 'qa-media-b' ), $this->image( 'qa-media-c' ) ];
        foreach ( $ids as $id ) {
            wp_update_post( [ 'ID' => $id, 'post_date' => '2024-05-05 10:00:00' ] );
        }
        $this->assertSame( array_reverse( $ids ), array_column( $this->tool( 'list-media' )['items'], 'id' ), 'newest id first, so pages never overlap' );
    }

    public function test_delete_media_deletes_an_image_a_page_still_shows_and_names_the_page(): void {
        $id   = $this->image( 'qa-media-used' );
        $page = self::factory()->post->create( [ 'post_title' => 'QA page', 'post_content' => '<!-- wp:image {"id":' . $id . '} /-->' ] );
        set_post_thumbnail( self::factory()->post->create(), $id );

        $this->assertInstanceOf( WP_Error::class, $this->tool( 'delete-media', [ 'mediaId' => $id, 'language' => 'en' ] ), 'no language input' );

        $gone = $this->tool( 'delete-media', [ 'mediaId' => $id ] );
        $this->assertIsArray( $gone, $gone instanceof WP_Error ? $gone->get_error_message() : '' );
        $this->assertTrue( $gone['deleted'] );
        $this->assertNull( get_post( $id ) );
        $this->assertArrayNotHasKey( 'alsoDeleted', $gone );
        $used = implode( ' | ', $gone['usedBy'] );
        $this->assertStringContainsString( 'content of "QA page" (id ' . $page . ')', $used );
        $this->assertStringContainsString( 'the featured image of', $used );
    }
}
