<?php

namespace Fabrement\Tests\Wpml;

use WP_Error;
use WPML\Media\Option;

/**
 * The media library across languages on the real WPML: an image WPML copies per language is ONE
 * row in list-media, with every copy and its alt text under `languages`; delete-media takes the
 * whole image, or one language's copy.
 */
final class MediaTranslationTest extends WpmlTestCase {

    public function set_up(): void {
        parent::set_up();
        // WPML's default for new content: copy each upload into every language at once.
        Option::setNewContentSettings( [ 'always_translate_media' => true, 'duplicate_media' => true, 'duplicate_featured' => true ] );
        Option::setShouldHandleMediaAuto( false );
    }

    /** An image in `$lang` with no copies (WPML may have made some on upload: they go). */
    private function image( string $title, string $lang = 'en' ): int {
        global $sitepress;
        $id   = self::factory()->attachment->create_object( [ 'file' => $title . '.png', 'post_title' => $title, 'post_mime_type' => 'image/png' ] );
        $trid = (int) apply_filters( 'wpml_element_trid', null, $id, 'post_attachment' );
        foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, 'post_attachment' ) as $version ) {
            if ( (int) $version->element_id !== $id ) {
                wp_delete_attachment( (int) $version->element_id, true );
            }
        }
        $sitepress->set_element_language_details( $id, 'post_attachment', null, $lang );
        return $id;
    }

    /** An en image with a fr copy of its own alt text. @return int[] [ original, copy ] */
    private function image_with_french_copy( string $title ): array {
        global $sitepress;
        $original = $this->image( $title );
        $copy     = $this->image( $title );
        $sitepress->set_element_language_details( $copy, 'post_attachment', (int) apply_filters( 'wpml_element_trid', null, $original, 'post_attachment' ), 'fr', 'en' );
        update_post_meta( $original, '_wp_attachment_image_alt', 'Meeting room' );
        update_post_meta( $copy, '_wp_attachment_image_alt', 'Salle de réunion' );
        wp_cache_flush();
        return [ $original, $copy ];
    }

    private function listed( string $search ): array {
        $listed = $this->tool( 'list-media', [ 'search' => $search ] );
        $this->assertIsArray( $listed, $listed instanceof WP_Error ? $listed->get_error_message() : '' );
        return $listed;
    }

    public function test_an_image_copied_per_language_is_one_row_with_every_copy_and_its_alt_text(): void {
        [ $original, $copy ] = $this->image_with_french_copy( 'qa-media-list' );

        $listed = $this->listed( 'qa-media-list' );
        $this->assertSame( 1, $listed['total'], 'one image, not one row per copy' );
        $row = $listed['items'][0];
        $this->assertSame( $original, $row['id'], 'the original\'s id, which works on a page of any language' );
        $this->assertSame( 'Meeting room', $row['altText'] );
        $this->assertEquals(
            (object) [ 'en' => [ 'id' => $original, 'altText' => 'Meeting room' ], 'fr' => [ 'id' => $copy, 'altText' => 'Salle de réunion' ] ],
            $row['languages']
        );

        $this->assertSame( [ $original ], array_column( $this->listed( 'Salle de réunion' )['items'], 'id' ), 'a copy\'s alt text finds the image' );
        $this->assertInstanceOf( WP_Error::class, $this->tool( 'list-media', [ 'language' => 'fr' ] ), 'no per-language listing any more' );
    }

    public function test_images_without_a_language_or_only_in_another_one_are_listed_too(): void {
        global $wpdb;
        $loose = $this->image( 'qa-media-loose' );
        // As an image uploaded before WPML, or by a plugin that bypasses it: no language row at all.
        $wpdb->delete( $wpdb->prefix . 'icl_translations', [ 'element_id' => $loose, 'element_type' => 'post_attachment' ] );
        $french = $this->image( 'qa-media-french', 'fr' );
        wp_cache_flush();

        $row = $this->listed( 'qa-media-loose' )['items'];
        $this->assertSame( [ $loose ], array_column( $row, 'id' ) );
        $this->assertEquals( (object) [], $row[0]['languages'], 'no language: every page shows it as it is' );

        $row = $this->listed( 'qa-media-french' )['items'];
        $this->assertSame( [ $french ], array_column( $row, 'id' ), 'uploaded in French only, and still found' );
        $this->assertSame( [ 'fr' ], array_keys( (array) $row[0]['languages'] ) );
    }

    public function test_the_list_is_one_row_per_image_across_pages(): void {
        for ( $i = 0; $i < 13; $i++ ) {
            $this->image_with_french_copy( 'qa-media-page-' . $i );
        }
        $first  = $this->listed( 'qa-media-page' );
        $second = $this->tool( 'list-media', [ 'search' => 'qa-media-page', 'page' => 2 ] );
        $this->assertSame( 13, $first['total'], '26 attachments, 13 images' );
        $this->assertSame( 1, $first['pages'] );
        $this->assertCount( 13, $first['items'] );
        $this->assertSame( [], $second['items'] );
    }

    public function test_the_alt_text_of_an_upload_reaches_the_copies_wpml_makes(): void {
        $uploaded = $this->tool( 'upload-media', [ 'base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'filename' => 'qa-media-alt.png', 'altText' => 'Meeting room' ] );
        $this->assertIsArray( $uploaded, $uploaded instanceof WP_Error ? $uploaded->get_error_message() : '' );
        $this->assertSame( 'Meeting room', get_post_meta( $uploaded['id'], '_wp_attachment_image_alt', true ) );

        $copy = $this->version( (int) $uploaded['id'], 'fr' );
        $this->assertGreaterThan( 0, $copy, 'WPML copies each upload with this site\'s settings' );
        $this->assertNotSame( (int) $uploaded['id'], $copy );
        $this->assertSame( 'Meeting room', get_post_meta( $copy, '_wp_attachment_image_alt', true ), 'WPML copied the alt text with the image' );
    }

    public function test_with_media_handled_automatically_an_upload_has_no_copies_yet(): void {
        Option::setShouldHandleMediaAuto( true );
        $uploaded = $this->tool( 'upload-media', [ 'base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'filename' => 'qa-media-auto.png', 'altText' => 'Meeting room' ] );
        $this->assertIsArray( $uploaded, $uploaded instanceof WP_Error ? $uploaded->get_error_message() : '' );

        $row = $this->listed( 'qa-media-auto' )['items'];
        $this->assertSame( [ (int) $uploaded['id'] ], array_column( $row, 'id' ), 'listed, though no language has a copy' );
        $this->assertSame( [ 'en' ], array_keys( (array) $row[0]['languages'] ), 'only its own language: others show the original\'s alt' );
    }

    public function test_delete_media_takes_one_language_copy_or_the_whole_image(): void {
        [ $original, $copy ] = $this->image_with_french_copy( 'qa-media-delete' );
        $lone = $this->image( 'qa-media-delete-lone' );

        $this->assertSame( 'is_original', $this->tool( 'delete-media', [ 'mediaId' => $original, 'language' => 'en' ] )->get_error_code() );
        $this->assertSame( 'no_copy', $this->tool( 'delete-media', [ 'mediaId' => $original, 'language' => 'pl' ] )->get_error_code() );
        $this->assertSame( 'invalid_language', $this->tool( 'delete-media', [ 'mediaId' => $original, 'language' => 'xx' ] )->get_error_code() );
        $this->assertSame( 'no_copy', $this->tool( 'delete-media', [ 'mediaId' => $lone, 'language' => 'fr' ] )->get_error_code() );

        $gone = $this->tool( 'delete-media', [ 'mediaId' => $original, 'language' => 'fr' ] );
        $this->assertIsArray( $gone, $gone instanceof WP_Error ? $gone->get_error_message() : '' );
        $this->assertSame( $copy, $gone['id'], 'the French copy' );
        $this->assertSame( [], $gone['alsoDeleted'] );
        $this->assertNull( get_post( $copy ) );
        $this->assertNotNull( get_post( $original ), 'the original stays' );
        wp_cache_flush();
        $rows = array_column( $this->listed( 'qa-media-delete' )['items'], null, 'id' );
        $this->assertSame( [ $lone, $original ], array_keys( $rows ), 'newest first, the id breaking a tie of the same second' );
        $this->assertEquals( (object) [ 'en' => [ 'id' => $original, 'altText' => 'Meeting room' ] ], $rows[ $original ]['languages'] );

        [ $original, $copy ] = $this->image_with_french_copy( 'qa-media-whole' );
        $page = self::factory()->post->create( [ 'post_content' => '<!-- wp:image {"id":' . $copy . '} /-->' ] );
        $gone = $this->tool( 'delete-media', [ 'mediaId' => $original ] );
        $this->assertIsArray( $gone, 'deleted though a page still shows it' );
        $this->assertSame( [ $copy ], $gone['alsoDeleted'], 'every copy goes with the original' );
        $this->assertNull( get_post( $original ) );
        $this->assertNull( get_post( $copy ) );
        $this->assertStringContainsString( '(id ' . $page . ')', implode( ' ', $gone['usedBy'] ), 'and says which page lost it' );
    }
}
