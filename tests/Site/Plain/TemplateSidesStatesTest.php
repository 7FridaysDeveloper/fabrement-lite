<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\TemplateSides;

/** Readiness, nested refs and usage of core-block synced patterns. */
final class TemplateSidesStatesTest extends SiteTestCase {
    use TemplateSides;

    private function dressed( string $title, array $sides ): array {
        $ref      = static fn( int $id ): array => $id > 0 ? [ [ 'name' => 'core/block', 'attributes' => [ 'ref' => $id ] ] ] : [];
        $template = (int) $this->mcp( 'save-template', [ 'mode' => 'create', 'title' => $title, 'blocks' => array_merge(
            $ref( (int) ( $sides['headerPatternId'] ?? 0 ) ),
            $sides['beforeSlot'] ?? [],
            [ [ 'name' => 'fabrement/content-source' ] ],
            $sides['afterSlot'] ?? [],
            $ref( (int) ( $sides['footerPatternId'] ?? 0 ) )
        ) ] )['template']['id'];
        $page     = $this->record( 'page', $title . ' page' );
        $this->mcp( 'assign-template-pages', [ 'templateId' => $template, 'pageIds' => [ $page ], 'mode' => 'add' ] );
        return [ $template, (string) $this->mcp( 'get-content', [ 'contentId' => $page ] )['url'] ];
    }

    private function listed( int $template ): array {
        return $this->listed_with_sides( $template );
    }

    private function content_of( int $post ): string {
        return (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $post ] );
    }

    private function set_status( int $pattern, string $status ): void {
        $this->site_runs( 'wp_update_post( [ "ID" => ' . $pattern . ', "post_status" => ' . var_export( $status, true ) . ' ] ); return true;' );
    }

    public function test_an_agent_places_only_a_global_block_that_is_drawn_and_one_is_found_however_it_is_written(): void {
        $own  = $this->own_global_block( 'header', 'Draft placed' );
        $slot = [ 'name' => 'fabrement/content-source' ];
        $this->set_status( $own, 'draft' );

        // A draft draws nothing: refused by name, as markup, and inside another block.
        $this->assertStringContainsString( 'is not published (draft)', $this->mcp_error( 'save-template', self::frame( 'Draft placed', $own ) ) );
        $nested = [ 'markup' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:block {"ref":' . $own . '} /--></div><!-- /wp:group -->' ];
        $this->assertStringContainsString( 'is not published (draft)', $this->mcp_error( 'save-template', [ 'mode' => 'create', 'title' => 'Draft nested', 'blocks' => [ $nested, $slot ] ] ) );
        $this->assertSame( 0, (int) $this->site()->value( 'SELECT COUNT(*) FROM wp_posts WHERE post_type = "fabrement_template" AND post_title LIKE "Draft %"' ), 'nothing was saved' );

        // Published, it is placed — here with another attribute before the ref — and guarded all the same.
        $this->set_status( $own, 'publish' );
        $written  = [ 'markup' => '<!-- wp:block {"metadata":{"name":"Top"},"ref":' . $own . '} /-->' ];
        $template = (int) $this->mcp( 'save-template', [ 'mode' => 'create', 'title' => 'Draft later', 'blocks' => [ $written, $slot ] ] )['template']['id'];
        $this->assertStringContainsString( 'is placed in 1 template(s)', $this->mcp_error( 'delete-synced-pattern', [ 'patternId' => $own ] ) );
        $this->assertContains( $template, array_column( $this->mcp( 'get-synced-pattern', [ 'patternId' => $own ] )['templates'], 'id' ) );

        // It goes to draft afterwards: said, and the template is still edited — what it placed is carried.
        $this->set_status( $own, 'draft' );
        $read = $this->mcp( 'get-template', [ 'templateId' => $template ] )['template'];
        $this->assertStringContainsString( 'The global block "Draft placed" is not published (draft)', $read['problem'] );
        $saved = $this->mcp( 'save-template', [ 'mode' => 'edit', 'templateId' => $template, 'expectedHash' => $read['hash'], 'edits' => [
            [ 'op' => 'insert', 'at' => 1, 'block' => [ 'markup' => '<!-- wp:paragraph --><p>Kept editing</p><!-- /wp:paragraph -->' ] ],
        ] ] )['template'];
        $this->assertStringContainsString( 'Kept editing', $this->content_of( $template ) );
        $this->assertStringContainsString( 'not published', $saved['problem'] );
    }

    public function test_an_agent_makes_a_free_global_block_a_draft_and_publishes_it_again(): void {
        $own  = $this->own_global_block( 'header', 'Status' );
        $slot = [ 'name' => 'fabrement/content-source' ];
        $this->assertSame( 'publish', $this->mcp( 'get-synced-pattern', [ 'patternId' => $own ] )['status'] );

        // Free, it is taken out of use; as a draft a template does not take it, and is told how to publish it.
        $this->assertSame( 'draft', $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $own, 'status' => 'draft' ] )['status'] );
        $this->assertSame( 'draft', $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $own ] ) );
        $refusal = $this->mcp_error( 'save-template', self::frame( 'Status', $own ) );
        $this->assertStringContainsString( 'is not published (draft)', $refusal );
        $this->assertStringContainsString( 'save-synced-pattern {patternId: ' . $own . ', mode: "replace", status: "publish"}', $refusal );

        // Published with nothing else sent: its words are as they were.
        $before = $this->content_of( $own );
        $this->assertSame( 'publish', $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $own, 'status' => 'publish' ] )['status'] );
        $this->assertSame( $before, $this->content_of( $own ) );
        $template = (int) $this->mcp( 'save-template', self::frame( 'Status', $own ) )['template']['id'];

        // Placed, it is not made a draft — in a template, and on a page.
        $refusal = $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $own, 'status' => 'draft' ] );
        $this->assertStringContainsString( 'is placed in 1 record(s): ' . $template . ' "Status"', $refusal );
        $this->assertSame( 'publish', $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $own ] ) );
        $section = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Status section', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Section</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];
        $page    = $this->record( 'page', 'Status page' );
        $this->mcp( 'save-content', [ 'contentId' => $page, 'mode' => 'replace', 'blocks' => [ [ 'name' => 'core/block', 'attributes' => [ 'ref' => $section ] ] ] ] );
        $this->assertStringContainsString( 'As a draft it would draw nothing there', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $section, 'status' => 'draft' ] ) );

        // A new one may be made a draft at once.
        $drafted = $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Status drafted', 'status' => 'draft', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Later</p><!-- /wp:paragraph -->' ] ] ] );
        $this->assertSame( 'draft', $drafted['status'] );
    }

    public function test_a_section_is_filed_under_wordpresss_pattern_categories_and_found_by_them(): void {
        $body = [ [ 'markup' => '<!-- wp:paragraph --><p>Filed</p><!-- /wp:paragraph -->' ] ];
        $find = fn( string $category ): array => array_column( $this->mcp( 'list-synced-patterns', [ 'category' => $category ] )['patterns'], 'id' );

        $saved = $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Filed reviews', 'categories' => [ 'testimonials', 'call-to-action' ], 'blocks' => $body ] );
        $id    = (int) $saved['patternId'];
        $this->assertSame( [ 'call-to-action', 'testimonials' ], $saved['categories'] );
        $this->assertSame( 'section', $saved['kind'] );
        $this->assertSame( [ 'Call to action', 'Testimonials' ], array_column( $this->site()->rows( 'SELECT t.name FROM wp_terms t JOIN wp_term_taxonomy x ON x.term_id = t.term_id JOIN wp_term_relationships r ON r.term_taxonomy_id = x.term_taxonomy_id WHERE x.taxonomy = "wp_pattern_category" AND r.object_id = ? ORDER BY t.name', [ $id ] ), 'name' ), 'under the names WordPress shows' );
        $this->assertContains( $id, $find( 'testimonials' ) );
        $this->assertNotContains( $id, $find( 'team' ) );
        $this->assertSame( [ 'call-to-action', 'testimonials' ], $this->mcp( 'get-synced-pattern', [ 'patternId' => $id ] )['categories'] );

        // A header keeps being a header whatever it is also filed under, and the other way round.
        $header = $this->own_global_block( 'header', 'Filed header' );
        $this->assertSame( [ 'header', [ 'navigation' ] ], array_values( array_intersect_key( $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $header, 'categories' => [ 'navigation' ] ] ), [ 'kind' => 1, 'categories' => 1 ] ) ) );
        $this->assertSame( [ 'footer', [ 'navigation' ] ], array_values( array_intersect_key( $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $header, 'kind' => 'footer' ] ), [ 'kind' => 1, 'categories' => 1 ] ) ) );

        // A category a person made in the editor is shown, and found by; a save that names none keeps all.
        $this->site_runs( 'wp_set_object_terms( ' . $id . ', [ "Seasonal" ], "wp_pattern_category", true ); return true;' );
        $this->assertSame( [ 'call-to-action', 'seasonal', 'testimonials' ], $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $id, 'title' => 'Filed reviews renamed' ] )['categories'] );
        $this->assertContains( $id, $find( 'seasonal' ) );

        // The list sent is the whole of it: the person's own is kept by sending it, and goes when left out.
        $this->assertSame( [ 'seasonal', 'team' ], $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $id, 'categories' => [ 'seasonal', 'team' ] ] )['categories'] );
        $this->assertSame( [ 'team' ], $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $id, 'categories' => [ 'team' ] ] )['categories'] );
        $this->assertSame( [], $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $id, 'categories' => [] ] )['categories'] );
        $this->assertNotNull( $this->site()->value( 'SELECT term_id FROM wp_terms WHERE slug = "seasonal"' ), 'the category itself stays on the site' );

        // Asking for a category the site does not have is refused with the ones it has — the owner's own among them.
        $unknown = $this->mcp_error( 'list-synced-patterns', [ 'category' => 'testimonial' ] );
        $this->assertStringContainsString( '"testimonial" is not a pattern category on this site', $unknown );
        $this->assertStringContainsString( 'testimonials', $unknown );
        $this->assertStringContainsString( 'seasonal', $unknown );
        $this->assertSame( [], $find( 'audio' ), 'a category nothing is filed under is an empty list, not a refusal' );

        // Not a category of this site, and header is not one here.
        $this->assertStringContainsString( 'is not a pattern category on this site', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $id, 'categories' => [ 'made-up' ] ] ) );
        $this->assertStringContainsString( 'A header or footer is `kind`', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $header, 'categories' => [ 'header' ] ] ) );
        $this->assertSame( [ 'navigation' ], $this->mcp( 'get-synced-pattern', [ 'patternId' => $header ] )['categories'], 'nothing was written' );
    }

    public function test_a_global_block_is_found_where_it_stands_however_its_placement_is_written(): void {
        $own     = $this->own_global_block( 'header', 'Written header' );
        $section = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Written section', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Written</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];
        $slot    = [ 'name' => 'fabrement/content-source' ];

        // As the editor writes one a person locked or named: other attributes before the ref.
        $template = (int) $this->mcp( 'save-template', [ 'mode' => 'create', 'title' => 'Written', 'blocks' => [
            [ 'markup' => '<!-- wp:block {"lock":{"move":true,"remove":true},"ref":' . $own . '} /-->' ],
            $slot,
        ] ] )['template']['id'];
        $page = $this->record( 'page', 'Written page' );
        $this->site_runs( 'wp_update_post( wp_slash( [ "ID" => ' . $page . ', "post_content" => "<!-- wp:group --><div class=\"wp-block-group\"><!-- wp:block {\"metadata\":{\"name\":\"Mine\"},\"ref\":' . $section . '} /--></div><!-- /wp:group -->" ] ) ); return true;' );
        // A navigation block naming a menu by the same number is no placement.
        $other = $this->record( 'page', 'Written navigation' );
        $this->site_runs( 'wp_update_post( wp_slash( [ "ID" => ' . $other . ', "post_content" => "<!-- wp:navigation {\"ref\":' . $section . '} /-->" ] ) ); return true;' );

        // Counted, in the list (one search for every row) and one by one.
        $listed = array_column( $this->mcp( 'list-synced-patterns', [ 'search' => 'Written' ] )['patterns'], null, 'id' );
        $this->assertSame( [ 1, 1 ], [ $listed[ $own ]['usedBy'], $listed[ $section ]['usedBy'] ] );
        $this->assertSame( [ $template ], array_column( $listed[ $own ]['templates'], 'id' ) );
        $this->assertSame( [ $page ], array_column( $this->mcp( 'get-synced-pattern', [ 'patternId' => $section ] )['usedBy'], 'id' ) );

        // So neither is made a draft, deleted, or re-marked while it stands there.
        $this->assertStringContainsString( 'is placed in 1 record(s): ' . $template . ' "Written"', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $own, 'status' => 'draft' ] ) );
        $this->assertStringContainsString( 'is placed in 1 record(s): ' . $page . ' "Written page"', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $section, 'status' => 'draft' ] ) );
        $this->assertStringContainsString( 'is placed in 1 template(s)', $this->mcp_error( 'delete-synced-pattern', [ 'patternId' => $own ] ) );
        $this->assertStringContainsString( 'is placed as a section in 1 record(s)', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $section, 'kind' => 'header' ] ) );
        $this->assertSame( [ 'publish', 'publish' ], [ $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $own ] ), $this->site()->value( 'SELECT post_status FROM wp_posts WHERE ID = ?', [ $section ] ) ] );
    }

    public function test_an_edit_that_changes_which_global_block_an_entry_places_is_checked_like_a_new_one(): void {
        $header  = $this->own_global_block( 'header', 'Swapped header' );
        $first   = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Swapped first', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];
        $second  = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Swapped second', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];
        $page    = $this->record( 'page', 'Swapped page' );
        $this->mcp( 'save-content', [ 'contentId' => $page, 'mode' => 'replace', 'blocks' => [ [ 'name' => 'core/block', 'attributes' => [ 'ref' => $first ] ] ] ] );
        $update  = fn( int $ref ): array => [ 'contentId' => $page, 'mode' => 'edit', 'edits' => [ [ 'op' => 'update', 'index' => 0, 'name' => 'core/block', 'attributes' => [ 'ref' => $ref ] ] ] ];
        $content = fn(): string => (string) $this->site()->value( 'SELECT post_content FROM wp_posts WHERE ID = ?', [ $page ] );

        // A header is no more placed on a page by an update than by a new block, and neither is what is not there.
        $this->assertStringContainsString( 'is a header', $this->mcp_error( 'save-content', $update( $header ) ) );
        $this->assertStringContainsString( 'does not exist', $this->mcp_error( 'save-content', $update( 999999 ) ) );
        $this->assertStringContainsString( '"ref":' . $first . '}', $content(), 'nothing was written' );

        // Another section is.
        $this->mcp( 'save-content', $update( $second ) );
        $this->assertStringContainsString( '"ref":' . $second . '}', $content() );
    }

    public function test_categories_sent_as_a_json_string_are_read_and_what_is_no_list_is_refused(): void {
        $id = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'Stringly', 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Stringly</p><!-- /wp:paragraph -->' ] ] ] )['patternId'];

        // A client holding a schema from before `categories` sends the list as text.
        $this->assertSame( [ 'team', 'testimonials' ], $this->mcp( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $id, 'categories' => '["testimonials","team"]' ] )['categories'] );
        $this->assertStringContainsString( 'is not a pattern category on this site', $this->mcp_error( 'save-synced-pattern', [ 'mode' => 'replace', 'patternId' => $id, 'categories' => '["made-up"]' ] ) );
        $this->assertSame( [ 'team', 'testimonials' ], $this->mcp( 'get-synced-pattern', [ 'patternId' => $id ] )['categories'], 'nothing was written' );
    }
}
