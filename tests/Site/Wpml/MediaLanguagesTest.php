<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/**
 * The media library across languages, as an agent uses it over MCP on a site where WPML copies
 * uploads per language: an image is ONE row in list-media with every copy and its alt text under
 * `languages`; delete-media takes one language's copy or the whole image. WPML's Media Translation
 * settings are set as its settings screen saves them (WPML's own Option class, run as site code).
 */
final class MediaLanguagesTest extends SiteTestCase {

    protected const SITE = 'wpml';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function wpml(): WpmlSite {
        return new WpmlSite( $this->site() );
    }

    /** WPML → Media Translation: copy each new upload into every language (or not), handled by hand (or automatically). */
    private function media_settings( bool $duplicate, bool $auto = false ): void {
        $this->site()->php( sprintf(
            "\\WPML\\Media\\Option::setNewContentSettings( [ 'always_translate_media' => %s, 'duplicate_media' => %s, 'duplicate_featured' => %s ] ); \\WPML\\Media\\Option::setShouldHandleMediaAuto( %s );",
            $duplicate ? 'true' : 'false', $duplicate ? 'true' : 'false', $duplicate ? 'true' : 'false', $auto ? 'true' : 'false'
        ) );
    }

    private function upload( string $filename, string $alt = 'Meeting room' ): int {
        return (int) $this->mcp( 'upload-media', [ 'base64' => self::PNG, 'filename' => $filename, 'altText' => $alt ] )['id'];
    }

    private function alt( int $id ): string {
        return (string) ( $this->site()->meta( $id, '_wp_attachment_image_alt' )[0] ?? '' );
    }

    private function exists( int $id ): bool {
        return $this->site()->value( 'SELECT ID FROM wp_posts WHERE ID = ?', [ $id ] ) !== null;
    }

    private function listed( string $search ): array {
        return $this->mcp( 'list-media', [ 'search' => $search ] );
    }

    public function test_an_upload_is_copied_into_every_language_and_listed_as_one_image(): void {
        $this->media_settings( true );
        $image  = $this->upload( 'qa-media-room.png' );
        $copies = $this->wpml()->group( $image, 'attachment' );
        $this->assertSame( [ 'en', 'fr', 'pl' ], array_keys( $copies ), 'WPML copies each upload with this site\'s settings' );
        $this->assertSame( $image, $copies['en'] );
        $this->assertSame( 'Meeting room', $this->alt( $copies['fr'] ), 'WPML copied the alt text with the image' );

        // A person writes the French alt text in the media library.
        $this->site()->php( "update_post_meta( {$copies['fr']}, '_wp_attachment_image_alt', 'Salle de réunion' );" );

        $listed = $this->listed( 'qa-media-room' );
        $this->assertSame( 1, $listed['total'], 'one image, not one row per copy' );
        $row = $listed['items'][0];
        $this->assertSame( $image, $row['id'], 'the original\'s id, which works on a page of any language' );
        $this->assertSame( 'Meeting room', $row['altText'] );
        $this->assertEquals( [
            'en' => [ 'id' => $image, 'altText' => 'Meeting room' ],
            'fr' => [ 'id' => $copies['fr'], 'altText' => 'Salle de réunion' ],
            'pl' => [ 'id' => $copies['pl'], 'altText' => 'Meeting room' ],
        ], $row['languages'] );

        $this->assertSame( [ $image ], array_column( $this->listed( 'Salle de réunion' )['items'], 'id' ), 'a copy\'s alt text finds the image' );
        $this->mcp_error( 'list-media', [ 'language' => 'fr' ] );   // no per-language listing any more
    }

    public function test_images_without_a_language_or_only_in_another_one_are_listed_too(): void {
        $this->media_settings( false );
        $loose = $this->upload( 'qa-media-loose.png' );
        // As an image uploaded before WPML was installed: WPML holds no language row for it.
        $this->site()->php( "global \$wpdb; \$wpdb->delete( \$wpdb->prefix . 'icl_translations', [ 'element_id' => $loose, 'element_type' => 'post_attachment' ] ); wp_cache_flush();" );
        $this->assertNull( $this->wpml()->language( $loose, 'attachment' ) );
        // Uploaded in French: WPML files an upload under the admin-language cookie, which WP-CLI has not,
        // so the import sets the language as WPML documents it.
        $french = (int) trim( $this->site()->php( "\$id = (int) wp_insert_attachment( [ 'post_title' => 'qa-media-french', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ] );
            do_action( 'wpml_set_element_language_details', [ 'element_id' => \$id, 'element_type' => 'post_attachment', 'trid' => apply_filters( 'wpml_element_trid', null, \$id, 'post_attachment' ), 'language_code' => 'fr', 'source_language_code' => null ] );
            echo \$id;" ) );
        $this->assertSame( 'fr', $this->wpml()->language( $french, 'attachment' ) );

        $rows = $this->listed( 'qa-media-loose' )['items'];
        $this->assertSame( [ $loose ], array_column( $rows, 'id' ) );
        $this->assertEmpty( $rows[0]['languages'], 'no language: every page shows it as it is' );

        $rows = $this->listed( 'qa-media-french' )['items'];
        $this->assertSame( [ $french ], array_column( $rows, 'id' ), 'uploaded in French only, and still found' );
        $this->assertSame( [ 'fr' ], array_keys( (array) $rows[0]['languages'] ) );
    }

    public function test_the_list_is_one_row_per_image_across_pages(): void {
        $this->media_settings( true );
        for ( $i = 0; $i < 13; $i++ ) {
            $this->upload( 'qa-media-page-' . $i . '.png' );
        }
        $this->assertSame( '39', $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'attachment' AND post_title LIKE 'qa-media-page-%'" ), '13 images in three languages' );
        $first = $this->listed( 'qa-media-page' );
        $this->assertSame( 13, $first['total'], 'one row per image' );
        $this->assertSame( 1, $first['pages'] );
        $this->assertCount( 13, $first['items'] );
        $this->assertSame( [], $this->mcp( 'list-media', [ 'search' => 'qa-media-page', 'page' => 2 ] )['items'] );
    }

    public function test_with_media_handled_automatically_an_upload_has_no_copies_yet(): void {
        $this->media_settings( true, true );
        $image = $this->upload( 'qa-media-auto.png' );
        $rows  = $this->listed( 'qa-media-auto' )['items'];
        $this->assertSame( [ $image ], array_column( $rows, 'id' ), 'listed, though no language has a copy' );
        $this->assertSame( [ 'en' ], array_keys( (array) $rows[0]['languages'] ), 'only its own language: others show the original\'s alt' );
    }

    public function test_delete_media_takes_one_language_copy_or_the_whole_image(): void {
        $this->media_settings( false );
        $lone = $this->upload( 'qa-media-delete-lone.png' );
        $this->media_settings( true );
        $image  = $this->upload( 'qa-media-delete.png' );
        $copies = $this->wpml()->group( $image, 'attachment' );

        // One language's copy: the Polish one goes, the image and its French copy stay.
        $gone = $this->mcp( 'delete-media', [ 'mediaId' => $image, 'language' => 'pl' ] );
        $this->assertSame( $copies['pl'], $gone['id'], 'the Polish copy' );
        $this->assertSame( [], $gone['alsoDeleted'] );
        $this->assertFalse( $this->exists( $copies['pl'] ) );
        $this->assertTrue( $this->exists( $image ), 'the original stays' );
        $this->assertTrue( $this->exists( $copies['fr'] ) );

        // What cannot be taken alone is refused, and nothing is deleted.
        foreach ( [ [ $image, 'en' ], [ $image, 'pl' ], [ $image, 'xx' ], [ $lone, 'fr' ] ] as [ $id, $lang ] ) {
            $this->mcp_error( 'delete-media', [ 'mediaId' => $id, 'language' => $lang ] );
        }
        foreach ( [ $image, $copies['fr'], $lone ] as $id ) {
            $this->assertTrue( $this->exists( $id ), "$id is still there" );
        }
        $rows = array_column( $this->listed( 'qa-media-delete' )['items'], null, 'id' );
        $this->assertSame( [ $image, $lone ], array_keys( $rows ), 'newest first' );
        $this->assertEquals( [ 'en' => [ 'id' => $image, 'altText' => 'Meeting room' ], 'fr' => [ 'id' => $copies['fr'], 'altText' => 'Meeting room' ] ], $rows[ $image ]['languages'] );

        // The whole image, while a page still shows its French copy: every copy goes, and the page is named.
        $page = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'Page with the photo', 'blocks' => [
            [ 'markup' => '<!-- wp:image {"id":' . $copies['fr'] . '} --><figure class="wp-block-image"><img src="https://example.org/x.png" alt="" class="wp-image-' . $copies['fr'] . '"/></figure><!-- /wp:image -->' ],
        ] ] )['contentId'];
        $gone = $this->mcp( 'delete-media', [ 'mediaId' => $image ] );
        $this->assertSame( [ $copies['fr'] ], $gone['alsoDeleted'], 'every copy goes with the original' );
        $this->assertFalse( $this->exists( $image ) );
        $this->assertFalse( $this->exists( $copies['fr'] ) );
        $this->assertStringContainsString( '(id ' . $page . ')', implode( ' ', $gone['usedBy'] ), 'and says which page lost it' );
        $this->assertSame( 0, $this->listed( 'qa-media-delete.png' )['total'] );
    }
}
