<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The term tools work only in taxonomies with an admin screen. The rest is WordPress plumbing: a
 * menu is a nav_menu term, so delete-term would remove it past delete-menu.
 */
final class TermTaxonomiesTest extends SiteTestCase {

    public function test_term_tools_refuse_taxonomies_without_an_admin_screen(): void {
        $menu = (int) $this->site_runs( "return wp_create_nav_menu( 'QA Plumbing Menu' );" );
        $post = $this->record( 'post', 'QA Plumbing Post' );

        $this->assertStringContainsString( 'delete-menu', $this->mcp_error( 'delete-term', [ 'taxonomy' => 'nav_menu', 'term' => $menu ] ) );
        $this->assertStringContainsString( 'no admin screen', $this->mcp_error( 'update-term', [ 'taxonomy' => 'nav_menu', 'term' => $menu, 'name' => 'QA Renamed' ] ) );
        $this->assertStringContainsString( 'no admin screen', $this->mcp_error( 'create-term', [ 'taxonomy' => 'wp_theme', 'name' => 'qa-theme' ] ) );
        $this->assertStringContainsString( 'no admin screen', $this->mcp_error( 'list-terms', [ 'taxonomy' => 'wp_template_part_area' ] ) );
        $this->assertStringContainsString( 'no admin screen', $this->mcp_error( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'post_format', 'mode' => 'replace', 'terms' => [ 'post-format-aside' ] ] ) );
        $this->assertSame( 'QA Plumbing Menu', $this->site_runs( "return wp_get_nav_menu_object( $menu )->name;" ) );

        $listed = array_column( $this->mcp( 'list-taxonomies' )['taxonomies'], 'slug' );
        $this->assertContains( 'category', $listed );
        $this->assertNotContains( 'post_format', $listed );
        $this->assertNotContains( 'nav_menu', $listed );
    }
}
