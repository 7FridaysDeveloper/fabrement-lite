<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\TemplateSides;

/**
 * A template that is in use — the default of a post type, bound to archives, picked by pages — when
 * its side stops being drawn and comes back, when its rules file is lost, when it is deleted; and
 * what guards its global block outside the tools. Every step is read from the database and from the
 * pages a visitor opens.
 */
final class TemplateSidesUseTest extends SiteTestCase {

    use TemplateSides;

    private function listed( int $template ): array {
        return $this->listed_with_sides( $template );
    }

    private function archive_url( string $context ): string {
        foreach ( (array) $this->mcp( 'list-archives' )['archives'] as $archive ) {
            if ( ( $archive['context'] ?? '' ) === $context ) {
                return (string) $archive['url'];
            }
        }
        $this->fail( "list-archives has no $context" );
    }

    private function url_of( int $record ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $record ] )['url'];
    }

    private function status( int $post ): ?string {
        return $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $post ] );
    }

    private function set_status( int $pattern, string $status ): void {
        $this->site_runs( 'wp_update_post( [ "ID" => ' . $pattern . ', "post_status" => ' . var_export( $status, true ) . ' ] ); return true;' );
    }

    /** The file a template keeps its rules in, inside the site. */
    private function manifest( int $template ): string {
        $key = (string) ( $this->site()->meta( $template, '_fabrement_st_key' )[0] ?? '' );
        $this->assertNotSame( '', $key, 'the template has a rules file' );
        return '/var/www/html/wp-content/uploads/fabrement-lite/templates/' . $key . '.json';
    }

    private function has_file( string $path ): bool {
        return trim( $this->site()->exec( 'sh', '-c', 'test -f ' . escapeshellarg( $path ) . ' && echo yes || echo no' ) ) === 'yes';
    }

    /**
     * A template with an own header, in use four ways: the default of posts, the blog's listing, an
     * archive of a post type, and a page that picked it.
     *
     * @return array{template:int,header:int,mark:string,page:int,urls:array<string,string>}
     */
    private function in_use( string $name ): array {
        $slug   = 'qa_' . strtolower( $name );
        $header = $this->own_global_block( 'header', $name );
        $mark   = 'qa-own-' . strtolower( $name );
        $this->post_type( $slug, [ 'hasArchive' => true ] );
        $template = (int) $this->mcp( 'save-template', self::frame( $name, $header ) )['template']['id'];
        $post     = $this->record( 'post', $name . ' post' );
        $page     = $this->record( 'page', $name . ' page' );
        $this->record( $slug, $name . ' item' );

        $this->mcp( 'set-default-template', [ 'templateId' => $template, 'postType' => 'post' ] );
        $this->assertTrue( $this->mcp( 'assign-template-archive', [ 'context' => 'blog-home', 'templateId' => $template ] )['bound'] );
        $this->assertTrue( $this->mcp( 'assign-template-archive', [ 'context' => 'archive:' . $slug, 'templateId' => $template ] )['bound'] );
        $this->mcp( 'assign-template-pages', [ 'templateId' => $template, 'pageIds' => [ $page ], 'mode' => 'add' ] );

        return [
            'template' => $template,
            'header'   => $header,
            'mark'     => $mark,
            'page'     => $page,
            'urls'     => [
                'a post of the default type' => $this->url_of( $post ),
                'the blog listing'           => $this->archive_url( 'blog-home' ),
                'the archive'                => $this->archive_url( 'archive:' . $slug ),
                'the page that picked it'    => $this->url_of( $page ),
            ],
        ];
    }

    /** What the database and the tools hold of how a template is given out. */
    private function given_out( int $template, int $page ): array {
        $read = $this->mcp( 'get-template', [ 'templateId' => $template ] )['template'];
        return [
            'defaultFor'      => $read['defaultFor'],
            'archiveContexts' => $read['archiveContexts'],
            'pageCount'       => $read['pageCount'],
            'pagePick'        => $this->site()->meta( $page, '_wp_page_template' ),
        ];
    }

    public function test_a_header_that_is_not_drawn_leaves_every_use_of_its_template_in_place(): void {
        $use = $this->in_use( 'Follows' );
        foreach ( $use['urls'] as $what => $url ) {
            $page = $this->get( $url );
            $this->assertSame( 200, $page['status'], $what );
            $this->assertSame( 1, substr_count( $page['body'], $use['mark'] ), $what . ': the header, once' );
        }
        $given = $this->given_out( $use['template'], $use['page'] );
        $this->assertSame( [ 'post' ], $given['defaultFor'] );
        $this->assertSame( [ 'fabrement-template-' . $use['template'] ], $given['pagePick'] );

        // The header is set to draft in the Patterns screen.
        $this->set_status( $use['header'], 'draft' );

        foreach ( $use['urls'] as $what => $url ) {
            $page = $this->get( $url );
            $this->assertSame( 200, $page['status'], $what . ' still opens' );
            $this->assertStringNotContainsString( $use['mark'], $page['body'], $what . ': an empty place where the header was' );
        }
        $this->assertSame( $given, $this->given_out( $use['template'], $use['page'] ), 'nothing of how it is given out was changed' );

        $this->assertStringContainsString( 'The global block "Follows" is not published (draft)', $this->listed( $use['template'] )['problem'] );

        // It is still given out: nothing refuses a template for what one of its sides is.
        $this->assertTrue( $this->mcp( 'assign-template-archive', [ 'context' => 'blog-home', 'templateId' => $use['template'] ] )['bound'] );
        $this->assertSame( $given, $this->given_out( $use['template'], $use['page'] ) );

        // Published again: every use is back, with no other write.
        $this->set_status( $use['header'], 'publish' );
        foreach ( $use['urls'] as $what => $url ) {
            $this->assertSame( 1, substr_count( $this->get( $url )['body'], $use['mark'] ), $what . ': back' );
        }
        $this->assertSame( $given, $this->given_out( $use['template'], $use['page'] ) );
    }

    public function test_a_template_in_use_is_deleted_only_when_told_to_and_leaves_nothing_behind(): void {
        $use      = $this->in_use( 'Removed' );
        $template = $use['template'];
        $second   = $this->record( 'page', 'Removed second page' );
        $this->mcp( 'assign-template-pages', [ 'templateId' => $template, 'pageIds' => [ $second ], 'mode' => 'add' ] );
        $manifest = $this->manifest( $template );
        $this->assertTrue( $this->has_file( $manifest ) );
        $given = $this->given_out( $template, $use['page'] );

        $refusal = $this->mcp_error( 'delete-template', [ 'templateId' => $template ] );

        $this->assertStringContainsString( 'Still used as:', $refusal );
        $this->assertStringContainsString( 'post type "post"', $refusal );
        $this->assertStringContainsString( 'blog-home', $refusal );
        $this->assertStringContainsString( '2 page(s)', $refusal );
        $this->assertSame( 'publish', $this->status( $template ), 'nothing was deleted' );
        $this->assertSame( $given, $this->given_out( $template, $use['page'] ), 'nothing was taken off' );
        $this->assertTrue( $this->has_file( $manifest ) );

        $deleted = $this->mcp( 'delete-template', [ 'templateId' => $template, 'force' => true ] );

        $this->assertTrue( $deleted['deleted'] );
        $this->assertSame( 2, $deleted['clearedPages'] );
        $this->assertNull( $this->status( $template ), 'no row is left, not even in the trash' );
        $this->assertSame( [], $this->site()->rows( 'SELECT meta_id FROM wp_postmeta WHERE post_id = ?', [ $template ] ), 'none of its meta is left' );
        $this->assertFalse( $this->has_file( $manifest ), 'its rules file is gone' );
        foreach ( [ $use['page'], $second ] as $page ) {
            $this->assertNotContains( 'fabrement-template-' . $template, $this->site()->meta( $page, '_wp_page_template' ), 'the page no longer picks it' );
        }
        $this->assertSame( [], array_filter( (array) $this->mcp( 'list-templates' )['templates'], static fn( array $t ): bool => (int) $t['id'] === $template ) );
        foreach ( (array) $this->mcp( 'list-archives' )['archives'] as $archive ) {
            $this->assertNotSame( $template, (int) ( $archive['templateId'] ?? 0 ), ( $archive['context'] ?? '' ) . ' is bound to nothing of it' );
        }
        foreach ( $use['urls'] as $what => $url ) {
            $page = $this->get( $url );
            $this->assertSame( 200, $page['status'], $what );
            $this->assertStringNotContainsString( $use['mark'], $page['body'], $what . ': the theme\'s frame' );
        }

        // What it placed stays, and is free to go now.
        $this->assertSame( 'publish', $this->status( $use['header'] ) );
        $this->mcp( 'delete-synced-pattern', [ 'patternId' => $use['header'] ] );
        $this->assertNull( $this->status( $use['header'] ), 'deleted for good' );
    }

    public function test_a_template_whose_rules_file_is_lost_says_so_until_it_is_saved_again(): void {
        $use      = $this->in_use( 'Rules' );
        $template = $use['template'];
        $manifest = $this->manifest( $template );
        $page     = $use['urls']['the page that picked it'];

        $this->site()->exec( 'rm', '-f', $manifest );

        $row = $this->listed( $template );
        $this->assertSame( "Its rules file is missing, so the content slot shows each page's own content and no list or layout. Save the template again to write it.", $row['problem'] );
        $this->assertSame( 1, substr_count( $this->get( $page )['body'], $use['mark'] ), 'the template still dresses the page' );

        // Two things wrong: both are said, the side first.
        $this->set_status( $use['header'], 'draft' );
        $this->assertSame(
            "The global block \"Rules\" is not published (draft). Publish it, or choose another global block. Its rules file is missing, so the content slot shows each page's own content and no list or layout. Save the template again to write it.",
            $this->listed( $template )['problem']
        );
        $this->set_status( $use['header'], 'publish' );

        // Any save writes the file again.
        $saved = $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $template, 'title' => 'Rules saved' ] );
        $this->assertTrue( $this->has_file( $manifest ) );
        $this->assertSame( '', $saved['template']['problem'] );
        $this->assertSame( 1, substr_count( $this->get( $page )['body'], $use['mark'] ) );
    }

    public function test_a_placed_global_block_is_not_trashed_or_deleted_by_the_sites_own_code(): void {
        $placed   = $this->own_global_block( 'header', 'Guarded' );
        $free     = $this->own_global_block( 'header', 'Unguarded' );
        $template = (int) $this->mcp( 'save-template', self::frame( 'Guarded', $placed ) )['template']['id'];
        $terms    = fn( int $id ): int => (int) $this->site()->value( 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = ?', [ $id ] );
        $before   = $terms( $placed );

        // A cron job, an import, another plugin: WordPress's own functions, no one signed in.
        $answers = $this->site_runs( 'return [ (bool) wp_trash_post( ' . $placed . ' ), (bool) wp_delete_post( ' . $placed . ', true ) ];' );

        $this->assertSame( [ false, false ], $answers );
        $this->assertSame( 'publish', $this->status( $placed ) );
        $this->assertSame( $before, $terms( $placed ), 'its category is kept' );
        $this->assertSame( $placed, $this->side_of( $template, 'header' ) );

        // The agent, with force: the same answer, naming the template and the way out.
        $refusal = $this->mcp_error( 'delete-synced-pattern', [ 'patternId' => $placed, 'force' => true ] );
        $this->assertStringContainsString( 'is placed in 1 template(s)', $refusal );
        $this->assertStringContainsString( 'Guarded', $refusal );
        $this->assertStringContainsString( 'save-template {templateId, mode: "edit"}', $refusal );
        $this->assertSame( 'publish', $this->status( $placed ) );

        // One no template places goes to the trash as any pattern does.
        $this->assertTrue( $this->site_runs( 'return (bool) wp_trash_post( ' . $free . ' );' ) );
        $this->assertSame( 'trash', $this->status( $free ) );

        // Taken off the template, the first one goes too.
        $other = $this->own_global_block( 'header', 'Guarded successor' );
        $this->mcp( 'save-template', [ 'mode' => 'replace', 'templateId' => $template, 'blocks' => self::frame( '', $other )['blocks'] ] );
        $this->assertTrue( $this->site_runs( 'return (bool) wp_delete_post( ' . $placed . ', true );' ) );
        $this->assertNull( $this->status( $placed ) );
    }

    public function test_a_header_in_the_trash_is_said_and_taken_by_no_other_template(): void {
        $own      = $this->own_global_block( 'header', 'Binned' );
        $template = (int) $this->mcp( 'save-template', self::frame( 'Binned', $own ) )['template']['id'];
        // Its status is changed behind the guard (a database import, another plugin).
        $this->site_runs( 'global $wpdb; $wpdb->update( $wpdb->posts, [ "post_status" => "trash" ], [ "ID" => ' . $own . ' ] ); clean_post_cache( ' . $own . ' ); return true;' );

        $row = $this->listed( $template );
        $this->assertSame( 'The global block "Binned" is in the trash. Restore it, or choose another global block.', $row['problem'] );
        $this->assertSame( $own, $this->mcp( 'get-template', [ 'templateId' => $template ] )['template']['blocks'][0]['attributes']['ref'], 'the frame still names it' );
        $this->assertStringContainsString( 'is in the trash', $this->mcp_error( 'save-template', self::frame( 'Binned second', $own ) ) );
        $this->assertStringContainsString( 'is placed in 1 template(s)', $this->mcp_error( 'delete-synced-pattern', [ 'patternId' => $own ] ), 'still guarded while the template names it' );

        $this->site_runs( 'global $wpdb; $wpdb->update( $wpdb->posts, [ "post_status" => "publish" ], [ "ID" => ' . $own . ' ] ); clean_post_cache( ' . $own . ' ); return true;' );
        $this->assertSame( '', $this->listed( $template )['problem'] );
    }



}
