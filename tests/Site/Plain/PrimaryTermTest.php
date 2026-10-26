<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * A record's main category (Yoast's primary term) as an agent sets it with set-post-terms, and how
 * it follows the terms Fabrement's tools change: a main term the record no longer holds is dropped
 * — from Yoast's meta, from its primary-term table, and its stored record is marked for a rebuild
 * (breadcrumbs). Changes made elsewhere (WP-CLI, the editor, an import) are Yoast's business. Then
 * Yoast switched off: no main term can be chosen and nothing of Yoast's is touched.
 */
final class PrimaryTermTest extends SiteTestCase {

    private const META = '_yoast_wpseo_primary_category';

    private function category( string $name, int $parent = 0 ): int {
        return (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => $name ] + ( $parent ? [ 'parent' => $parent ] : [] ) )['termId'];
    }

    private function terms( int $post, array $terms, string $mode, $primary = null ): array {
        return $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => $terms, 'mode' => $mode ] + ( $primary !== null ? [ 'primary' => $primary ] : [] ) );
    }

    private function refused( int $post, array $terms, string $mode, $primary ): string {
        return $this->mcp_error( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => $terms, 'mode' => $mode, 'primary' => $primary ] );
    }

    /** @return int[] The categories the record holds, read straight. */
    private function held( int $post ): array {
        $ids = array_map( 'intval', array_column( $this->site()->rows(
            "SELECT tt.term_id FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = ? AND tt.taxonomy = 'category' ORDER BY tt.term_id",
            [ $post ]
        ), 'term_id' ) );
        sort( $ids );
        return $ids;
    }

    private function main_meta( int $post ): string {
        return (string) ( $this->site()->meta( $post, self::META )[0] ?? '' );
    }

    /** Yoast's primary-term row for a record, or null. */
    private function primary_row( int $post ): ?int {
        $value = $this->site()->value( "SELECT term_id FROM wp_yoast_primary_term WHERE post_id = ? AND taxonomy = 'category'", [ $post ] );
        return $value === null ? null : (int) $value;
    }

    /** The version of Yoast's stored record for a post (0 = rebuild it on its next read). */
    private function indexable_version( int $post ): int {
        return (int) $this->site()->value( "SELECT version FROM wp_yoast_indexable WHERE object_id = ? AND object_type = 'post'", [ $post ] );
    }

    /** @return int[] The terms on a post's breadcrumb trail as Yoast stored it, nearest first. */
    private function trail( int $post ): array {
        return array_map( 'intval', array_column( $this->site()->rows(
            "SELECT a.object_id FROM wp_yoast_indexable_hierarchy h JOIN wp_yoast_indexable p ON p.id = h.indexable_id JOIN wp_yoast_indexable a ON a.id = h.ancestor_id WHERE p.object_id = ? AND p.object_type = 'post' AND a.object_type = 'term' ORDER BY h.depth",
            [ $post ]
        ), 'object_id' ) );
    }

    private function seo_primary( int $post ): int {
        return (int) ( $this->mcp( 'get-seo', [ 'contentId' => $post ] )['content']['primaryTerms']['category'] ?? -1 );
    }

    /** A post filed under Parent and Child, Parent chosen as its main category. */
    private function filed( string $name ): array {
        $parent = $this->category( $name . ' Parent' );
        $child  = $this->category( $name . ' Child', $parent );
        $post   = $this->record( 'post', $name );
        $out    = $this->terms( $post, [ $parent, $child ], 'replace', $parent );
        $this->assertSame( $parent, $out['primary'] );
        return [ $post, $parent, $child ];
    }

    public function test_the_main_term_is_yoasts_and_leaves_with_the_term_it_names(): void {
        $this->site_runs( 'WPSEO_Options::set( "post_types-post-maintax", "category" ); return true;' );
        [ $post, $parent, $child ] = $this->filed( 'Frontend' );
        $url = $this->mcp( 'get-content', [ 'contentId' => $post ] )['url'];
        $this->assertSame( 200, $this->get( $url )['status'] );   // Yoast builds its record of the post on its first view

        $this->assertSame( (string) $parent, $this->main_meta( $post ), 'in Yoast\'s meta' );
        $this->assertSame( $parent, $this->primary_row( $post ), 'and its table' );
        $this->assertSame( $parent, $this->seo_primary( $post ) );
        $this->assertGreaterThan( 0, $this->indexable_version( $post ), 'an up-to-date record' );
        $this->assertSame( [ $parent ], $this->trail( $post ), 'the trail runs through the main category' );

        $out = $this->terms( $post, [ $parent ], 'remove' );
        $this->assertSame( 0, $out['primary'] );
        $this->assertSame( [ $child ], $this->held( $post ) );
        $this->assertSame( '', $this->main_meta( $post ) );
        $this->assertNull( $this->primary_row( $post ), 'the row is gone: Yoast falls back to the deepest term' );
        $this->assertSame( 0, $this->seo_primary( $post ) );
        $this->assertSame( 200, $this->get( $url )['status'] );
        $this->assertSame( [ $child, $parent ], $this->trail( $post ), 'rebuilt: the deepest category leads the trail' );
    }

    public function test_a_tag_trail_follows_the_tags_set_post_terms_changes(): void {
        $this->site_runs( 'WPSEO_Options::set( "post_types-post-maintax", "post_tag" ); return true;' );
        $old  = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'post_tag', 'name' => 'Trail Old' ] )['termId'];
        $new  = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'post_tag', 'name' => 'Trail New' ] )['termId'];
        $post = $this->record( 'post', 'Tagged trail' );
        $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'post_tag', 'terms' => [ $old ], 'mode' => 'replace' ] );
        $url = $this->mcp( 'get-content', [ 'contentId' => $post ] )['url'];
        $this->assertSame( 200, $this->get( $url )['status'] );
        $this->assertSame( [ $old ], $this->trail( $post ) );

        $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'post_tag', 'terms' => [ $new ], 'mode' => 'replace' ] );
        $this->assertSame( 0, $this->indexable_version( $post ), 'Yoast rebuilds nothing on a tag change: marked for its next read' );
        $this->assertSame( 200, $this->get( $url )['status'] );
        $this->assertSame( [ $new ], $this->trail( $post ) );

        $this->site_runs( 'WPSEO_Options::set( "post_types-post-maintax", 0 ); return true;' );
        $this->assertSame( 200, $this->get( $url )['status'] );
        $this->assertGreaterThan( 0, $this->indexable_version( $post ) );
        $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'post_tag', 'terms' => [ $old ], 'mode' => 'replace' ] );
        $this->assertGreaterThan( 0, $this->indexable_version( $post ), 'tags the trail does not run through leave Yoast\'s record as it is' );
    }

    public function test_replace_without_it_drops_it_and_a_new_main_term_in_the_same_call_stays(): void {
        [ $post, $parent, $child ] = $this->filed( 'Replace' );
        $this->terms( $post, [ $child ], 'replace' );
        $this->assertSame( '', $this->main_meta( $post ) );

        $other = $this->category( 'Replace Other' );
        $this->terms( $post, [ $child ], 'replace', $child );
        $out = $this->terms( $post, [ $other ], 'replace', $other );
        $this->assertSame( $other, $out['primary'], 'the old one is dropped as its term comes off, then the new one is set' );
        $this->assertSame( (string) $other, $this->main_meta( $post ) );
        $this->assertSame( $other, $this->primary_row( $post ) );
    }

    public function test_a_main_term_the_record_still_holds_is_left_alone(): void {
        [ $post, $parent, $child ] = $this->filed( 'Kept' );
        $this->terms( $post, [ $child ], 'remove' );
        $this->terms( $post, [ $this->category( 'Kept Extra' ) ], 'add' );

        $this->assertSame( (string) $parent, $this->main_meta( $post ) );
        $this->assertSame( $parent, $this->seo_primary( $post ) );
    }

    public function test_delete_term_drops_the_main_term_on_every_record_it_leaves(): void {
        [ $post, $parent, $child ] = $this->filed( 'Deleted' );
        $this->terms( $post, [ $parent, $child ], 'replace', $child );
        $other = $this->record( 'post', 'Deleted Other' );
        $this->terms( $other, [ $child ], 'replace', $child );
        $this->assertSame( (string) $child, $this->main_meta( $other ) );

        $this->assertTrue( $this->mcp( 'delete-term', [ 'taxonomy' => 'category', 'term' => $child ] )['deleted'] );
        $this->assertSame( '', $this->main_meta( $post ) );
        $this->assertSame( '', $this->main_meta( $other ) );
        $this->assertNull( $this->primary_row( $other ) );
        $this->assertSame( [ $parent ], $this->held( $post ) );
    }

    public function test_changes_made_elsewhere_are_the_seo_plugins_business(): void {
        [ $post, $parent, $child ] = $this->filed( 'Elsewhere' );
        // A host's WP-CLI (an import, another plugin) takes the main category off.
        $this->site()->wp( 'post', 'term', 'remove', (string) $post, 'category', (string) $parent, '--by=id' );
        $this->assertSame( [ $child ], $this->held( $post ) );
        $this->assertSame( (string) $parent, $this->main_meta( $post ), 'not corrected by Fabrement' );

        $this->terms( $post, [ $child ], 'replace', $child );
        $this->site()->wp( 'term', 'delete', 'category', (string) $child );
        $this->assertSame( (string) $child, $this->main_meta( $post ), 'a term deleted outside Fabrement neither' );
    }

    public function test_the_main_term_must_be_one_the_record_keeps_and_a_refusal_writes_nothing(): void {
        [ $post, $parent, $child ] = $this->filed( 'Refused' );
        $before = $this->held( $post );

        $this->assertStringContainsString( 'is not one of the "category" terms the record would have', $this->refused( $post, [ $child ], 'replace', $parent ) );
        $this->assertStringContainsString( 'nothing was written', $this->refused( $post, [ $parent ], 'remove', $parent ) );
        $this->assertSame( 'No term "No such category" in "category" for `primary` — nothing was written.', $this->refused( $post, [ $child ], 'replace', 'No such category' ) );
        $this->assertStringContainsString( 'only for hierarchical taxonomies', $this->mcp_error( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'post_tag', 'terms' => [], 'mode' => 'add', 'primary' => 'qa' ] ), 'tags are flat' );

        $this->assertSame( $before, $this->held( $post ), 'no call above touched the terms' );
        $this->assertSame( (string) $parent, $this->main_meta( $post ) );

        $clear = $this->terms( $post, [ $parent, $child ], 'replace', 0 );
        $this->assertSame( 0, $clear['primary'], '0 clears it' );
        $this->assertSame( '', $this->main_meta( $post ) );
        $this->assertNull( $this->primary_row( $post ), 'Yoast has none' );
        $this->assertSame( 0, $this->seo_primary( $post ) );
    }

    public function test_a_taxonomy_the_site_took_out_of_yoasts_list_takes_no_main_term(): void {
        [ $post, $parent, $child ] = $this->filed( 'Filtered' );
        // Site code using Yoast's own filter: no main category on this site.
        $this->site_code( 'qa-no-primary-category', "add_filter( 'wpseo_primary_term_taxonomies', static function ( \$taxonomies ) { unset( \$taxonomies['category'] ); return \$taxonomies; } );" );
        try {
            $this->assertStringContainsString( 'only for hierarchical taxonomies', $this->refused( $post, [ $child ], 'replace', $child ) );
            $this->assertSame( [ $parent, $child ], $this->held( $post ), 'refused whole' );
        } finally {
            $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/qa-no-primary-category.php' );
        }
        $this->assertSame( $child, $this->terms( $post, [ $child ], 'replace', $child )['primary'], 'the rule gone, it is allowed again' );
    }

    /** Last in this class: Yoast is switched off for good (the next class restores the snapshot). */
    public function test_without_yoast_no_main_term_is_chosen_and_nothing_of_yoasts_is_touched(): void {
        [ $post, $parent, $child ] = $this->filed( 'Without' );
        $this->site()->wp( 'plugin', 'deactivate', 'wordpress-seo' );

        $this->assertStringContainsString( 'A primary term is a Yoast SEO setting', $this->refused( $post, [ $child ], 'replace', $child ) );
        $this->assertSame( [ $parent, $child ], $this->held( $post ), 'terms untouched' );

        $this->terms( $post, [ $parent ], 'remove' );
        $this->assertSame( [ $child ], $this->held( $post ) );
        $this->assertSame( (string) $parent, $this->main_meta( $post ), 'while Yoast is off, its data is left as it is' );
        $this->assertArrayNotHasKey( 'primaryTerms', $this->mcp( 'get-seo', [ 'contentId' => $post ] )['content'], 'no plugin, no main terms' );
    }
}
