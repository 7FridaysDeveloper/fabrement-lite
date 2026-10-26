<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * Navigation menus as an agent makes them on a real site: create-menu, list-menus, update-menu,
 * delete-menu; the menu's rows in the database; and the menu as a visitor gets it where the theme
 * prints it (a theme rule, wp_nav_menu() behind a shortcode — Twenty Twenty-Five has no classic
 * menu location). Labels are saved as WordPress's menu screen saves them: as typed by someone who
 * may post unfiltered HTML, through kses for anyone else.
 */
final class MenusTest extends SiteTestCase {

    private static bool $ready = false;

    protected function setUp(): void {
        parent::setUp();
        if ( ! self::$ready ) {
            // What a theme does with a menu: prints it. No fallback, so a missing menu prints nothing.
            $this->site_code( 'qa-theme-menu', "add_shortcode( 'qa_menu', static fn( \$atts ) => '<div class=\"qa-menu\">' . wp_nav_menu( [ 'menu' => (int) ( \$atts['id'] ?? 0 ), 'echo' => false, 'fallback_cb' => false, 'container' => false ] ) . '</div>' );" );
            self::$ready = true;
        }
    }

    public static function tearDownAfterClass(): void {
        self::$ready = false;
        parent::tearDownAfterClass();
    }

    /** @return array|null The menu as list-menus gives it. */
    private function menu( string $name ): ?array {
        return array_column( $this->mcp( 'list-menus' )['menus'], null, 'name' )[ $name ] ?? null;
    }

    private function labels( string $name ): array {
        return array_column( (array) ( $this->menu( $name )['items'] ?? [] ), 'label' );
    }

    /** The menu's item rows in the database, in menu order. */
    private function rows( int $menu ): array {
        return $this->site()->rows(
            "SELECT p.ID, p.post_title, p.menu_order FROM wp_posts p
             JOIN wp_term_relationships r ON r.object_id = p.ID
             JOIN wp_term_taxonomy t ON t.term_taxonomy_id = r.term_taxonomy_id
             WHERE t.term_id = ? AND t.taxonomy = 'nav_menu' AND p.post_type = 'nav_menu_item' ORDER BY p.menu_order",
            [ $menu ]
        );
    }

    /** A published page that prints the menu as the theme would. @return string Its address. */
    private function page_showing( string $title, int $menu ): string {
        $id = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'title' => $title, 'blocks' => [
            [ 'markup' => '<!-- wp:shortcode -->[qa_menu id="' . $menu . '"]<!-- /wp:shortcode -->' ],
        ] ] )['contentId'];
        return $this->mcp( 'set-content-status', [ 'contentId' => $id, 'status' => 'publish' ] )['url'];
    }

    /** @return array<string,string> href => the link's words, from the printed menu. */
    private function links( string $url ): array {
        $page = $this->get( $url );
        $this->assertSame( 200, $page['status'] );
        preg_match( '#<div class="qa-menu">(.*?)</div>#s', $page['body'], $menu );
        preg_match_all( '#<a[^>]*href="([^"]*)"[^>]*>(.*?)</a>#s', $menu[1] ?? '', $m, PREG_SET_ORDER );
        $links = [];
        foreach ( $m as $a ) {
            $links[ html_entity_decode( $a[1] ) ] = html_entity_decode( $a[2], ENT_QUOTES | ENT_HTML5 );
        }
        return $links;
    }

    public function test_a_menu_lives_from_creation_through_the_visitor_to_deletion(): void {
        // Made, nested, one item in a new tab.
        $made = $this->mcp( 'create-menu', [ 'name' => 'QA main', 'items' => [
            [ 'label' => 'Work', 'url' => '/qa-work/', 'children' => [
                [ 'label' => 'Cases', 'url' => '/qa-work/cases/' ],
            ] ],
            [ 'label' => 'Shop', 'url' => 'https://shop.example.org/', 'newTab' => true ],
        ] ] );
        $id = (int) $made['menu']['id'];
        $this->assertSame( 'QA main', $this->site()->value( 'SELECT name FROM wp_terms WHERE term_id = ?', [ $id ] ) );
        $this->assertSame( [ 'Work', 'Cases', 'Shop' ], array_column( $this->rows( $id ), 'post_title' ) );

        $listed = $this->menu( 'QA main' );
        $this->assertSame( $id, $listed['id'] );
        $this->assertSame( [ 'Work', 'Shop' ], array_column( $listed['items'], 'label' ) );
        $this->assertSame( 'Cases', $listed['items'][0]['children'][0]['label'] );
        $this->assertTrue( $listed['items'][1]['newTab'] );
        $this->assertArrayNotHasKey( 'newTab', $listed['items'][0], 'omitted where it does not apply' );

        // The visitor gets it nested, the new-tab link opening a new tab.
        $url  = $this->page_showing( 'QA menu page', $id );
        $body = $this->get( $url )['body'];
        $this->assertMatchesRegularExpression( '#<a[^>]*href="/qa-work/"[^>]*>Work</a>\s*<ul class="sub-menu">.*?<a[^>]*href="/qa-work/cases/"[^>]*>Cases</a>#s', $body );
        $this->assertMatchesRegularExpression( '#<a[^>]*target="_blank"[^>]*href="https://shop.example.org/"|<a[^>]*href="https://shop.example.org/"[^>]*target="_blank"#', $body );

        // A list sent as one string is refused (WordPress's own input check); the menu stays as it was.
        $before = $this->rows( $id );
        $this->assertStringContainsString( 'input[items]', $this->mcp_error( 'update-menu', [ 'menuId' => $id, 'items' => 'Work, Contact' ] ) );
        $this->assertSame( $before, $this->rows( $id ) );
        $this->assertSame( [ '/qa-work/', '/qa-work/cases/', 'https://shop.example.org/' ], array_keys( $this->links( $url ) ) );

        // Renamed alone: the items stay.
        $this->mcp( 'update-menu', [ 'menuId' => $id, 'name' => 'QA primary' ] );
        $this->assertNull( $this->menu( 'QA main' ) );
        $this->assertSame( [ 'Work', 'Shop' ], $this->labels( 'QA primary' ) );
        $this->assertSame( $before, $this->rows( $id ), 'the same item rows' );

        // Replaced with the full new tree: the visitor sees the new tree.
        $this->mcp( 'update-menu', [ 'menuId' => $id, 'items' => [
            [ 'label' => 'About', 'url' => '/qa-about/' ],
            [ 'label' => 'Contact', 'url' => '/qa-contact/' ],
        ] ] );
        $this->assertSame( [ 'About', 'Contact' ], $this->labels( 'QA primary' ) );
        $this->assertSame( [ 'About', 'Contact' ], array_column( $this->rows( $id ), 'post_title' ), 'the old items are gone from the database' );
        $this->assertSame( [ '/qa-about/' => 'About', '/qa-contact/' => 'Contact' ], $this->links( $url ) );

        // An empty list empties it.
        $this->mcp( 'update-menu', [ 'menuId' => $id, 'items' => [] ] );
        $this->assertSame( [], $this->labels( 'QA primary' ) );
        $this->assertSame( [], $this->rows( $id ) );

        // Deleted: gone for the agent, the database and the visitor.
        $this->mcp( 'update-menu', [ 'menuId' => $id, 'items' => [ [ 'label' => 'Last', 'url' => '/qa-last/' ] ] ] );
        $gone = $this->mcp( 'delete-menu', [ 'menuId' => $id ] );
        $this->assertTrue( $gone['deleted'] );
        $this->assertNull( $this->menu( 'QA primary' ) );
        $this->assertNull( $this->site()->value( 'SELECT term_id FROM wp_terms WHERE term_id = ?', [ $id ] ) );
        $this->assertSame( [], $this->rows( $id ) );
        $this->assertSame( [], $this->links( $url ), 'the theme prints nothing for it' );
        $this->mcp_error( 'update-menu', [ 'menuId' => $id, 'name' => 'QA back' ] );
        $this->assertNull( $this->menu( 'QA back' ) );
    }

    public function test_labels_keep_what_was_typed(): void {
        $id = (int) $this->mcp( 'create-menu', [ 'name' => 'QA menu words', 'items' => [
            [ 'label' => 'Kids <12 & more', 'url' => '/kids' ],
            [ 'label' => 'Child <&>', 'url' => '/child' ],
            [ 'label' => 'A\\B', 'url' => '/ab' ],
        ] ] )['menu']['id'];
        $this->assertSame( [ 'Kids <12 & more', 'Child <&>', 'A\\B' ], $this->labels( 'QA menu words' ) );
        $links = $this->links( $this->page_showing( 'QA words page', $id ) );
        $this->assertSame( 'A\\B', $links['/ab'], 'the backslash reaches the visitor' );
        $this->assertStringStartsWith( 'Kids ', $links['/kids'] );
    }

    public function test_labels_of_someone_without_unfiltered_html_are_kept_safe_and_read_as_words(): void {
        // A hardening rule of the site (a security plugin): nobody posts unfiltered HTML.
        $this->site_code( 'qa-no-unfiltered-html', "add_filter( 'map_meta_cap', static fn( \$caps, \$cap ) => \$cap === 'unfiltered_html' ? [ 'do_not_allow' ] : \$caps, 10, 2 );" );
        try {
            $id = (int) $this->mcp( 'create-menu', [ 'name' => 'QA menu safe', 'items' => [
                [ 'label' => 'Kids <12 & more', 'url' => '/kids' ],
                [ 'label' => '<img src=x onerror=alert(1)>Go', 'url' => '/go' ],
            ] ] )['menu']['id'];
            [ $kids, $go ] = $this->labels( 'QA menu safe' );
            $this->assertSame( 'Kids <12 & more', $kids, 'read back as the words it shows' );
            $this->assertStringNotContainsString( 'onerror', $go );
            $this->assertStringNotContainsString( 'onerror', implode( ' ', array_column( $this->rows( $id ), 'post_title' ) ), 'stored safe' );

            $url  = $this->page_showing( 'QA safe page', $id );
            $body = $this->get( $url )['body'];
            $this->assertStringNotContainsString( 'onerror', $body, 'nothing runs for the visitor' );
            $this->assertSame( 'Kids <12 & more', $this->links( $url )['/kids'], 'the visitor reads the same words' );
        } finally {
            $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/qa-no-unfiltered-html.php' );
        }
    }

    /** Items attached to a page, a term or an archive; a refused tree leaves the menu as it was. */
    public function test_items_attach_to_what_they_link_and_a_refusal_changes_nothing(): void {
        $type = 'qa_menu_show';
        $this->post_type( $type, [ 'hasArchive' => true, 'rewrite' => 'qa-menu-shows' ] );
        $show = $this->record( $type, 'QA menu show' );
        $page = $this->record( 'page', 'QA menu about' );
        $term = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'QA menu news' ] )['termId'];
        $made = $this->mcp( 'create-menu', [ 'name' => 'QA attached', 'items' => [
            [ 'postId' => $page ],
            [ 'label' => 'Shows', 'archive' => $type, 'children' => [ [ 'label' => 'One show', 'postId' => $show ] ] ],
            [ 'label' => 'News', 'termId' => $term ],
            [ 'label' => 'Out', 'url' => 'https://example.org/' ],
        ] ] )['menu'];
        $items = $made['items'];
        $this->assertSame( [ 'QA menu about', $page ], [ $items[0]['label'], $items[0]['postId'] ], 'no label: the page\'s title' );
        $this->assertSame( $type, $items[1]['archive'] );
        $this->assertSame( $show, $items[1]['children'][0]['postId'] );
        $this->assertSame( $term, $items[2]['termId'] );
        $this->assertArrayNotHasKey( 'postId', $items[3] );

        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $page, 'title' => 'QA menu renamed', 'slug' => 'qa-menu-renamed' ] );
        $after = $this->menu( 'QA attached' )['items'];
        $this->assertSame( 'QA menu renamed', $after[0]['label'], 'the title follows the page' );
        $this->assertStringContainsString( '/qa-menu-renamed', $after[0]['url'], 'and so does its address' );
        $this->assertSame( $after, $this->mcp( 'update-menu', [ 'menuId' => $made['id'], 'items' => $after ] )['menu']['items'], 'a list-menus tree sent back stays attached' );

        $id = $made['id'];
        $this->assertStringContainsString( 'one of postId, termId or archive', $this->mcp_error( 'update-menu', [ 'menuId' => $id, 'items' => [ [ 'label' => 'Both', 'postId' => $page, 'termId' => $term ] ] ] ) );
        $this->assertStringContainsString( 'not a page or record', $this->mcp_error( 'update-menu', [ 'menuId' => $id, 'items' => [ [ 'label' => 'Ok', 'url' => '/ok' ], [ 'label' => 'Gone', 'postId' => 999999 ] ] ] ) );
        $this->assertStringContainsString( 'not the address of its postId', $this->mcp_error( 'update-menu', [ 'menuId' => $id, 'items' => [ [ 'label' => 'Mixed', 'postId' => $page, 'url' => '/elsewhere' ] ] ] ) );
        $this->assertStringContainsString( 'not a post type with an archive', $this->mcp_error( 'create-menu', [ 'name' => 'QA refused', 'items' => [ [ 'label' => 'X', 'archive' => 'no_such_type' ] ] ] ) );
        $this->assertSame( $after, $this->menu( 'QA attached' )['items'], 'a refused tree leaves the menu as it was' );
        $this->assertNull( $this->menu( 'QA refused' ), 'and a refused create makes no menu' );
    }

    /** The current item as WordPress marks it, in wp_nav_menu() and fabrement()->menu() alike. */
    public function test_the_current_item_is_marked_by_wordpress_rule(): void {
        $type = 'qa_menu_event';
        $this->post_type( $type, [ 'hasArchive' => true, 'rewrite' => 'qa-menu-events' ] );
        $parent = $this->record( 'page', 'QA menu parent' );
        $child  = $this->record( 'page', 'QA menu child' );
        $id = (int) $this->mcp( 'create-menu', [ 'name' => 'QA current', 'items' => [
            [ 'label' => 'Parent', 'postId' => $parent, 'children' => [ [ 'label' => 'Child', 'postId' => $child ] ] ],
            [ 'label' => 'Typed', 'url' => '/qa-menu-events/' ],
        ] ] )['menu']['id'];
        $this->site_code( 'qa-menu-marks', <<<PHP
add_action( 'wp_footer', static function (): void {
    \$marks = static function ( array \$t ) use ( &\$marks ): array {
        \$o = [];
        foreach ( \$t as \$n ) {
            if ( \$n['current'] || \$n['currentAncestor'] ) { \$o[ \$n['label'] ] = ( \$n['current'] ? 'current' : '' ) . ( \$n['currentAncestor'] ? 'ancestor' : '' ); }
            \$o += \$marks( \$n['children'] ?? [] );
        }
        return \$o;
    };
    \$manual = fabrement()->menu( [ 'source' => 'manual', 'items' => [ [ 'label' => 'Typed here', 'url' => home_url( '/qa-menu-events/' ) ] ] ] );
    \$html = (string) wp_nav_menu( [ 'menu' => $id, 'echo' => false, 'container' => false, 'fallback_cb' => false ] );
    preg_match_all( '#class="([^"]*current-menu-item[^"]*)"[^>]*>\s*<a[^>]*>([^<]*)#', \$html, \$m );
    echo '<script id="qa-marks">' . wp_json_encode( [ 'menu' => \$marks( fabrement()->menu( [ 'source' => 'wp', 'menuId' => $id ] ) ), 'manual' => \$marks( \$manual ), 'nav' => array_map( 'trim', \$m[2] ) ] ) . '</script>';
} );
PHP );
        $marks = function ( string $url ): array {
            preg_match( '#<script id="qa-marks">(.*?)</script>#s', $this->get( $url )['body'], $m );
            return json_decode( $m[1] ?? 'null', true ) ?? [];
        };
        $on_child = $marks( (string) $this->site_runs( "return get_permalink( $child );" ) );
        $this->assertSame( [ 'Parent' => 'ancestor', 'Child' => 'current' ], $on_child['menu'] );
        $this->assertSame( [ 'Child' ], $on_child['nav'] );
        $on_archive = $marks( '/qa-menu-events/' );
        $this->assertSame( [ 'Typed' => 'current' ], $on_archive['menu'] );
        $this->assertSame( [ 'Typed' ], $on_archive['nav'] );
        $this->assertSame( [ 'Typed here' => 'current' ], $on_archive['manual'], 'typed links: the custom-link rule' );
    }
}
