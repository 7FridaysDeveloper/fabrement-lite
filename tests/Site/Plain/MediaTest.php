<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The media library as an agent uses it on a single-language site: upload-media puts a file a
 * visitor can fetch, list-media finds it (newest first, by title, file name or alt text, no
 * languages anywhere), and delete-media deletes an image even while a page still shows it —
 * naming where it was shown — after which the file is gone for the visitor too.
 */
final class MediaTest extends SiteTestCase {

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @return array The upload's answer (id, url, filename, altText…). */
    private function upload( string $filename, string $alt, string $title = '' ): array {
        return $this->mcp( 'upload-media', [ 'base64' => self::PNG, 'filename' => $filename, 'altText' => $alt ] + ( $title !== '' ? [ 'title' => $title ] : [] ) );
    }

    /** What a host's WP-CLI does to backdate an upload. */
    private function dated( int $id, string $date ): void {
        $this->site()->wp( 'post', 'update', (string) $id, '--post_date=' . $date, '--post_date_gmt=' . $date );
    }

    private function listed( array $input = [] ): array {
        return array_column( $this->mcp( 'list-media', $input )['items'], 'id' );
    }

    public function test_an_upload_is_fetched_by_the_visitor_and_found_newest_first_without_languages(): void {
        $older = $this->upload( 'qa-media-one.png', 'Meeting room', 'qa-media-one' );
        $newer = $this->upload( 'qa-media-two.png', 'A plain square', 'qa-media-two' );
        $this->assertSame( 'image/png', $older['mimeType'], 'detected from the bytes' );
        $this->assertSame( 'Meeting room', $older['altText'] );
        $this->assertSame( [ 'Meeting room' ], $this->site()->meta( $older['id'], '_wp_attachment_image_alt' ) );
        $this->assertSame( 'attachment', $this->site()->value( 'SELECT post_type FROM wp_posts WHERE ID = ?', [ $older['id'] ] ) );

        $file = $this->get( $older['url'] );
        $this->assertSame( 200, $file['status'], 'the visitor fetches the file' );
        $this->assertStringStartsWith( "\x89PNG", $file['body'] );

        $this->dated( $older['id'], '2020-01-01 00:00:00' );

        $search = $this->mcp( 'list-media', [ 'search' => 'qa-media' ] );
        $this->assertSame( 2, $search['total'] );
        $this->assertSame( [ $newer['id'], $older['id'] ], array_column( $search['items'], 'id' ) );
        $this->assertArrayNotHasKey( 'languages', $search['items'][0], 'no languages on a single-language site' );

        $this->assertSame( [ $older['id'] ], $this->listed( [ 'search' => 'Meeting' ] ), 'found by alt text' );
        $this->assertSame( [ $older['id'] ], array_slice( $this->listed(), -1 ), 'without a search: by date, oldest last' );
        $this->assertSame( [], $this->listed( [ 'search' => 'qa-media', 'kind' => 'document' ] ), 'an image is not a document' );

        $this->assertStringContainsString( 'language', $this->mcp_error( 'list-media', [ 'language' => 'en' ] ), 'no language input here' );
    }

    public function test_a_featured_image_is_refused_where_the_theme_draws_none_and_nothing_is_written(): void {
        $image = $this->upload( 'qa-media-theme.png', 'Theme test' )['id'];
        $kept  = $this->upload( 'qa-media-kept.png', 'Kept' )['id'];
        $page  = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'QA themed page', 'featuredImage' => $kept, 'blocks' => [] ] )['contentId'];
        $this->assertSame( $kept, (int) $this->mcp( 'get-content', [ 'contentId' => $page ] )['featuredImage']['id'] );

        $this->site_code( 'qa-no-thumbnails', "add_action( 'after_setup_theme', static function () { remove_theme_support( 'post-thumbnails' ); }, 99 );" );
        try {
            $pages = $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page'" );
            $this->assertStringContainsString( 'does not support featured images', $this->mcp_error( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'QA refused page', 'featuredImage' => $image, 'blocks' => [] ] ) );
            $this->assertSame( $pages, $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page'" ), 'no record left behind' );

            $this->assertStringContainsString( 'does not support featured images', $this->mcp_error( 'save-content', [ 'mode' => 'add', 'contentId' => $page, 'featuredImage' => $image, 'blocks' => [] ] ) );
            $this->assertSame( [ (string) $kept ], $this->site()->meta( $page, '_thumbnail_id' ), 'the image it had stays' );
        } finally {
            $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/qa-no-thumbnails.php' );
        }
    }

    public function test_an_image_wordpress_will_not_set_leaves_the_record_saved_and_says_so(): void {
        $ghost = (int) $this->upload( 'qa-media-ghost.png', 'Ghost' )['id'];
        $this->site_code( 'qa-no-image-src', "add_filter( 'wp_get_attachment_image_src', static fn( \$src, \$id ) => (int) \$id === $ghost ? false : \$src, 10, 2 );" );
        try {
            $answer = $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'QA ghost cover', 'featuredImage' => $ghost, 'blocks' => [] ] );
        } finally {
            $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/qa-no-image-src.php' );
        }
        $this->assertGreaterThan( 0, (int) $answer['contentId'], 'the record is saved and named, so a retry edits it instead of making a second' );
        $this->assertSame( 0, (int) $answer['featuredImage']['id'] );
        $this->assertStringContainsString( "did not set image $ghost", $answer['note'] );
        $this->assertSame( '1', $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page' AND post_title = 'QA ghost cover'" ) );
    }

    public function test_a_featured_image_id_is_a_number_not_a_string(): void {
        $image = (int) $this->upload( 'qa-media-string.png', 'String id' )['id'];
        $this->assertStringContainsString( 'featuredImage', $this->mcp_error( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'QA string cover', 'featuredImage' => (string) $image, 'blocks' => [] ] ) );
        $this->assertSame( '0', $this->site()->value( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page' AND post_title = 'QA string cover'" ) );
    }

    public function test_images_uploaded_in_the_same_second_keep_one_order(): void {
        $ids = [];
        foreach ( [ 'a', 'b', 'c' ] as $letter ) {
            $ids[] = $this->upload( 'qa-same-' . $letter . '.png', 'Square ' . $letter )['id'];
        }
        foreach ( $ids as $id ) {
            $this->dated( $id, '2024-05-05 10:00:00' );
        }
        $this->assertSame( array_reverse( $ids ), array_values( array_intersect( $this->listed(), $ids ) ), 'newest id first, so pages never overlap' );
        $this->assertSame( array_reverse( $ids ), $this->listed( [ 'search' => 'qa-same' ] ) );
    }

    public function test_an_image_a_page_still_shows_is_deleted_and_the_answer_names_where(): void {
        $image = $this->upload( 'qa-media-used.png', 'Used image' );
        $id    = (int) $image['id'];

        // Shown in a page's content and as a post's featured image.
        $page = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'title' => 'QA page', 'blocks' => [
            [ 'markup' => '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . $image['url'] . '" alt="Used image" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->' ],
        ] ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $page, 'status' => 'publish' ] );
        $post = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'post', 'title' => 'QA post with a picture', 'featuredImage' => $id, 'blocks' => [] ] )['contentId'];
        $post_url = $this->mcp( 'set-content-status', [ 'contentId' => $post, 'status' => 'publish' ] )['url'];

        $this->assertSame( [ (string) $id ], $this->site()->meta( $post, '_thumbnail_id' ) );
        $file = basename( (string) parse_url( $image['url'], PHP_URL_PATH ) );
        $this->assertStringContainsString( $file, $this->get( $post_url )['body'], 'the visitor sees the featured image' );
        $this->assertSame( 200, $this->get( $image['url'] )['status'] );

        // A language is no input on this site: refused, and the image is still there.
        $this->mcp_error( 'delete-media', [ 'mediaId' => $id, 'language' => 'en' ] );
        $this->assertSame( 'attachment', $this->site()->value( 'SELECT post_type FROM wp_posts WHERE ID = ?', [ $id ] ) );
        $this->assertSame( 200, $this->get( $image['url'] )['status'] );

        $tool = array_column( $this->client()->tools(), null, 'name' )['fabrement-delete-media'];
        $this->assertTrue( $tool['annotations']['destructiveHint'] ?? false, 'marked destructive for the agent' );

        $gone = $this->mcp( 'delete-media', [ 'mediaId' => $id ] );
        $this->assertTrue( $gone['deleted'] );
        $this->assertArrayNotHasKey( 'alsoDeleted', $gone );
        $used = implode( ' | ', $gone['usedBy'] );
        $this->assertStringContainsString( 'content of "QA page" (id ' . $page . ')', $used );
        $this->assertStringContainsString( 'the featured image of', $used );

        // Gone: the row, the file and the listing; the post still opens.
        $this->assertNull( $this->site()->value( 'SELECT ID FROM wp_posts WHERE ID = ?', [ $id ] ) );
        $this->assertSame( 404, $this->get( $image['url'] )['status'], 'the file is gone for the visitor' );
        $this->assertNotContains( $id, $this->listed( [ 'search' => 'qa-media-used' ] ) );
        $this->assertSame( 200, $this->get( $post_url )['status'] );

        $this->mcp_error( 'delete-media', [ 'mediaId' => $id ] );
    }
}
