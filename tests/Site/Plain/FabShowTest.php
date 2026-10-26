<?php

namespace Fabrement\Tests\Site\Plain;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * fabrement() on a site with one language: a record is shown as it is, when published. There is no other
 * language to show, so `lang` and `missing` are ignored — a block made on a multilingual site of the
 * project works here as it is.
 */
final class FabShowTest extends SiteTestCase {

    protected const SITE = 'plain';

    /** Site code: $ids( list<Post> ) gives their ids, in order. */
    private const IDS = '$ids = static fn( array $posts ): array => array_map( static fn( \Fabrement\Api\Content\Post $p ): int => $p->id(), $posts );';

    /** Site code: $echo( fn() => … ) gives [ what the call returned, what it printed ]. */
    private const PRINTED = '$echo = static function ( callable $print ): array { ob_start(); $returned = $print(); return [ $returned, ob_get_clean() ]; };';

    /** Site code: $shown( fn() => … ) gives what the call printed. */
    private const SHOWN = '$shown = static function ( callable $draw ): string { ob_start(); $draw(); return (string) ob_get_clean(); }; $wrap = static function ( $value, array $options, string $content = \'<i></i>\' ): string { ob_start(); $element = fab_r(...$options)->open($value); echo $content; $element->close(); return (string) ob_get_clean(); };';

    private function take_log(): string {
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        return $log;
    }

    public function test_records_addresses_and_languages_on_a_single_language_site(): void {
        $a     = $this->record( 'page', 'Shown page' );
        $draft = $this->record( 'page', 'Draft page', 'draft' );
        [ $post, $posts, $url, $link, $current, $languages, $in_french, $wrong ] = $this->site_runs( self::IDS . " return [
            fabrement()->post( $a )->to_array(),
            \$ids( fabrement()->posts( [ $a, $draft, $a, 99999999 ] ) ),
            fabrement()->post( $a )->url(),
            fabrement()->link( [ 'postId' => $a, 'url' => 'https://example.org/stale/' ] )->url(),
            fabrement()->language(),
            fabrement()->languages(),
            fabrement()->post( $a, lang: 'fr' )->id(),
            fabrement()->post( $a, lang: 'pl' )->id(),
        ];" );
        $log = $this->take_log();
        $this->assertSame( $a, $post['id'] );
        $this->assertSame( 'Shown page', $post['title'] );
        $this->assertSame( [ $a ], $posts, 'a draft and a missing id are left out; a repeat is shown once, as WP_Query shows it' );
        $this->assertSame( $post['url'], $url );
        $this->assertSame( $url, $link, 'a link to a record gives its address, not the stored one' );
        $this->assertNull( $current, 'one language: null' );
        $this->assertIsArray( $languages );
        $this->assertSame( $a, $in_french, 'lang is ignored: the record as it is' );
        $this->assertSame( $a, $wrong, 'whatever language' );
        $this->assertSame( '', $log, 'and nothing is said about either' );
    }

    /**
     * viewed_post() is the record a singular page shows, whatever loop runs on it and whatever its
     * status — WordPress has already decided it is shown; on an archive, the home page, a 404 or with
     * no page drawn it is an empty Post.
     */
    public function test_viewed_post_is_the_viewed_record_of_a_singular_page(): void {
        $page  = $this->record( 'page', 'Viewed page' );
        $other = $this->record( 'page', 'Looped page' );
        $draft = $this->record( 'page', 'Viewed draft', 'draft' );
        $query = static fn( string $args ): string => "\$GLOBALS['wp_query'] = new WP_Query( $args ); \$GLOBALS['wp_the_query'] = \$GLOBALS['wp_query']; ";
        $this->assertSame( [ false, 0 ], $this->site_runs( 'return [ fabrement()->viewed_post()->not_empty(), fabrement()->viewed_post()->id() ];' ), 'no page drawn: nothing' );
        $this->assertSame( [ $page, 'Viewed page' ], $this->site_runs( $query( "[ 'page_id' => $page ]" ) . 'return [ fabrement()->viewed_post()->id(), fabrement()->viewed_post()->title() ];' ), 'a singular page' );
        $this->assertSame( [ $page, $other ], $this->site_runs( $query( "[ 'page_id' => $page ]" ) . "
            \$q = new WP_Query( [ 'post_type' => 'page', 'post__in' => [ $other ] ] );
            \$q->the_post();
            \$seen = [ fabrement()->viewed_post()->id(), fabrement()->loop_post()->id() ];
            wp_reset_postdata();
            return \$seen;" ), 'a loop on the page does not change the viewed record' );
        $this->assertSame( 0, $this->site_runs( $query( "[ 'post_type' => 'page', 'post__in' => [ $page, $other ] ]" ) . 'return fabrement()->viewed_post()->id();' ), 'an archive: nothing' );
        $this->assertSame( $draft, $this->site_runs( $query( "[ 'page_id' => $draft, 'post_status' => 'draft' ]" ) . 'return fabrement()->viewed_post()->id();' ), 'a draft the page shows (a preview) is the viewed record' );
        $this->assertSame( 0, $this->site_runs( $query( "[ 'page_id' => $draft ]" ) . "if ( ! have_posts() ) { \$GLOBALS['wp_query']->set_404(); } return fabrement()->viewed_post()->id();" ), 'a draft a visitor asks for is a 404: nothing' );
    }

    /**
     * loop_post() is the post of the innermost loop being drawn: the main query's, a secondary
     * WP_Query's inside it, then the outer one's again; nothing outside a loop or once it has ended.
     * viewed_post() stays empty on an archive, inside a loop too.
     */
    public function test_loop_post_is_the_post_of_the_innermost_loop(): void {
        $a     = $this->record( 'page', 'Loop A' );
        $b     = $this->record( 'page', 'Loop B' );
        $inner = $this->record( 'page', 'Loop inner' );
        $seen = $this->site_runs( "\$GLOBALS['wp_query'] = new WP_Query( [ 'post_type' => 'page', 'post__in' => [ $a, $b ], 'orderby' => 'post__in' ] );
            \$GLOBALS['wp_the_query'] = \$GLOBALS['wp_query'];
            \$steps = [ 'before' => [ fabrement()->loop_post()->id(), fabrement()->loop_post()->not_empty() ] ];
            while ( have_posts() ) {
                the_post();
                \$step = [ fabrement()->loop_post()->id(), fabrement()->viewed_post()->id() ];
                if ( get_the_ID() === $a ) {
                    \$q = new WP_Query( [ 'post_type' => 'page', 'post__in' => [ $inner ] ] );
                    while ( \$q->have_posts() ) {
                        \$q->the_post();
                        \$step[] = fabrement()->loop_post()->id();
                        \$step[] = fabrement()->loop_post()->title();
                    }
                    wp_reset_postdata();
                    \$step[] = fabrement()->loop_post()->id();
                }
                \$steps[] = \$step;
            }
            \$steps['after'] = [ fabrement()->loop_post()->id(), fabrement()->viewed_post()->id() ];
            return \$steps;" );
        $this->assertSame( [
            'before' => [ 0, false ],
            0        => [ $a, 0, $inner, 'Loop inner', $a ],
            1        => [ $b, 0 ],
            'after'  => [ 0, 0 ],
        ], $seen, 'the main loop, a secondary loop inside it, the outer post again; nothing after the loop' );
        $this->assertSame( [ $a, $b ], $this->site_runs( "\$GLOBALS['wp_query'] = new WP_Query( [ 'post_type' => 'page', 'post__in' => [ $a, $b ], 'orderby' => 'post__in' ] );
            \$GLOBALS['wp_the_query'] = \$GLOBALS['wp_query'];
            \$seen = [];
            while ( have_posts() ) {
                the_post();
                if ( get_the_ID() === $a ) {
                    \$q = new WP_Query( [ 'post_type' => 'page', 'post__in' => [ $inner ] ] );
                    while ( \$q->have_posts() ) {
                        \$q->the_post();
                        break;
                    }
                    wp_reset_postdata();
                }
                \$seen[] = fabrement()->loop_post()->id();
            }
            return \$seen;" ), 'a nested loop left with break: the outer post again, not the nested one' );
        $draft = $this->record( 'page', 'Loop draft', 'draft' );
        $this->assertSame( $draft, $this->site_runs( "\$q = new WP_Query( [ 'post_type' => 'page', 'post__in' => [ $draft ], 'post_status' => 'any' ] );
            \$q->the_post();
            \$id = fabrement()->loop_post()->id();
            wp_reset_postdata();
            return \$id;" ), 'a draft the loop shows is the loop post' );
    }

    /**
     * What fabrement()->post / posts / url / link give for a draft, in the request the code runs in.
     *
     * @return array{0: int, 1: int, 2: bool, 3: list<int>, 4: string} [post()->id(), post( status: 'publish' )->id(),
     *         url() !== '', posts(), link()->url()] for $draft
     */
    private function draft_seen( int $draft, string $setup ): array {
        return $this->site_runs( self::IDS . ' ' . $setup . "
            return [
                fabrement()->post( $draft )->id(),
                fabrement()->post( $draft, status: 'publish' )->id(),
                fabrement()->post( $draft )->url() !== '',
                \$ids( fabrement()->posts( [ $draft ] ) ),
                fabrement()->link( [ 'postId' => $draft ] )->url() !== '' ? 'href' : '',
            ];" );
    }

    /**
     * Left out, `status` is what WP_Query shows in that request: published on the site — to an
     * administrator too — and also what is on its way there wherever WordPress counts as wp-admin
     * (its screens, admin-post, admin-ajax). Named, it is exactly that, everywhere.
     */
    public function test_wp_admin_and_agent_previews_show_drafts_unless_status_says_otherwise(): void {
        $draft  = $this->record( 'page', 'Draft in preview', 'draft' );
        $page   = $this->record( 'page', 'Previewed page', 'draft' );
        $shown  = [ $draft, 0, true, [ $draft ], 'href' ];
        $hidden = [ 0, 0, false, [], '' ];
        $admin  = 'wp_set_current_user( 1 ); ';
        $screen = "set_current_screen( 'dashboard' ); ";
        $content_token = "parse_str( (string) wp_parse_url( \\Fabrement\\Modules\\ContentPreview\\ContentPreview::url( $page ), PHP_URL_QUERY ), \$q ); \$_GET = \$q; ";
        $rest         = "define( 'REST_REQUEST', true ); ";
        $ajax         = "add_filter( 'wp_doing_ajax', '__return_true' ); ";
        $wp_preview    = "\$GLOBALS['wp_query']->is_preview = true; ";

        foreach ( [
            'a visitor on the site'                              => [ '', $hidden ],
            'an administrator on the site'                       => [ $admin, $hidden ],
            'an administrator in wp-admin'                       => [ $admin . $screen, $shown ],
            'nobody signed in on an admin screen (admin-post)'   => [ $screen, $shown ],
            'a visitor\'s admin-ajax call from a page'           => [ $screen . $ajax, $shown ],
            'an administrator\'s admin-ajax call'                => [ $admin . $screen . $ajax, $shown ],
            'a preview token alone, its page not opened'         => [ $content_token, $hidden ],
            'a preview link whose token is not valid'            => [ "\$_GET['fabrement_preview_token'] = str_repeat( 'a', 32 ); ", $hidden ],
            'WordPress\'s own preview'                           => [ $admin . $wp_preview, $hidden ],
            'the block editor\'s preview (REST, an editor)'      => [ $admin . $rest, $hidden ],
            'a REST request of a visitor'                        => [ $rest, $hidden ],
        ] as $where => [ $setup, $expected ] ) {
            $this->assertSame( $expected, $this->draft_seen( $draft, $setup ), $where );
        }
    }

    /**
     * A theme-created preview link can carry explicit statuses through ContentPreview::url(): on the page
     * it was made for, records fabrement() reads where the code names no status take them; the same
     * token on another address, a link that asked for none, and code that names its statuses do not.
     */
    public function test_a_preview_link_reads_records_in_the_statuses_it_asked_for_on_its_own_page(): void {
        $draft  = $this->record( 'page', 'Listed draft', 'draft' );
        $page   = $this->record( 'page', 'Previewed with a list', 'draft' );
        $other  = $this->record( 'page', 'Another page' );
        $this->site_code( 'qa-preview-list', "add_action( 'template_redirect', static function (): void {
            if ( isset( \$_GET['qa_list'] ) ) {
                wp_send_json( [
                    array_map( static fn( \$p ): int => \$p->id(), fabrement()->posts( [ $draft ] ) ),
                    array_map( static fn( \$p ): int => \$p->id(), fabrement()->posts( [ $draft ], status: 'publish' ) ),
                ] );
            }
        } );" );
        $list = function ( string $url ): array {
            $response = $this->get( $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'qa_list=1' );
            $this->assertSame( 200, $response['status'], $response['body'] );
            return json_decode( $response['body'], true );
        };
        $asked = (string) $this->site_runs( "return \\Fabrement\\Modules\\ContentPreview\\ContentPreview::url( $page, [ 'publish', 'draft' ] );" );
        $this->assertSame( [ [ $draft ], [] ], $list( $asked ), 'its own page: the draft listed; code that names publish keeps publish' );
        parse_str( (string) parse_url( $asked, PHP_URL_QUERY ), $query );
        $foreign = $this->site_runs( "return get_permalink( $other );" ) . '?fabrement_preview_token=' . $query['fabrement_preview_token'];
        $this->assertSame( [ [], [] ], $list( $foreign ), 'the same token on another page opens nothing' );
        $plain = (string) $this->mcp( 'preview', [ 'target' => 'content', 'id' => $page ] )['url'];
        $this->assertSame( [ [], [] ], $list( $plain ), 'a link that asked for no statuses: as the site will show it' );
        $this->assertNotSame( '', $this->mcp_error( 'preview', [ 'target' => 'content', 'id' => $page, 'statuses' => [ 'draft' ] ] ), 'Lite MCP does not expose status overrides.' );
    }

    /**
     * Records and terms are read from the types ACF lists for its record fields: every type and
     * taxonomy but WordPress's own internal ones (built in and not public), attachments and
     * Fabrement's own blocks and templates — a site's own type that is not public included.
     */
    public function test_only_types_and_taxonomies_people_keep_records_in_are_read(): void {
        $this->site_code( 'qa-hidden-type', "add_action( 'init', static function (): void {
            register_post_type( 'qa_hidden', [ 'public' => false, 'show_ui' => true, 'label' => 'QA hidden' ] );
            register_taxonomy( 'qa_hidden_tax', 'qa_hidden', [ 'public' => false, 'show_ui' => true, 'label' => 'QA hidden tax' ] );
        } );" );
        $page = $this->record( 'page', 'A page beside them' );
        [ $hidden, $pattern, $item, $menu, $hidden_term ] = $this->site_runs( "
            \$menu = (int) wp_create_nav_menu( 'QA menu ' . wp_generate_password( 6, false ) );
            return [
                (int) wp_insert_post( [ 'post_type' => 'qa_hidden', 'post_title' => 'Hidden kind', 'post_status' => 'publish' ] ),
                (int) wp_insert_post( [ 'post_type' => 'wp_block', 'post_title' => 'A pattern', 'post_status' => 'publish' ] ),
                (int) wp_update_nav_menu_item( \$menu, 0, [ 'menu-item-title' => 'Item', 'menu-item-url' => 'https://example.org/', 'menu-item-status' => 'publish' ] ),
                \$menu,
                (int) wp_insert_term( 'Hidden term', 'qa_hidden_tax' )['term_id'],
            ];" );
        $this->assertSame( [ $hidden, 0, 0, [ $page, $hidden ], 0, $hidden_term, [ $hidden_term ], [] ], $this->site_runs( self::IDS . " return [
            fabrement()->post( $hidden )->id(),
            fabrement()->post( $pattern )->id(),
            fabrement()->post( $item )->id(),
            \$ids( fabrement()->posts( [ $page, $pattern, $hidden, $item ] ) ),
            fabrement()->term( $menu )->id(),
            fabrement()->term( $hidden_term )->id(),
            array_map( static fn( \$t ): int => \$t->id(), fabrement()->terms( taxonomy: 'qa_hidden_tax', hide_empty: false ) ),
            fabrement()->terms( taxonomy: 'nav_menu' ),
        ];" ) );
        $this->assertStringContainsString( 'taxonomy: there is no taxonomy "nav_menu"', $this->take_log() );
    }

    /** terms() given ids, none of them a term id (they may come from an address): nothing, and nothing said. */
    public function test_terms_given_only_wrong_ids_give_nothing_quietly(): void {
        $this->take_log();
        $five = (int) $this->site_runs( "return (int) wp_insert_term( 'Ids check ' . wp_generate_password( 6, false ), 'category' )['term_id'];" );
        [ $all, $none, $one ] = $this->site_runs( "\$ids = static fn( array \$terms ): array => array_map( static fn( \$t ): int => \$t->id(), \$terms );
            return [
                count( fabrement()->terms( taxonomy: 'category', hide_empty: false ) ),
                \$ids( fabrement()->terms( [ 0, -3, 'x' ], taxonomy: 'category' ) ),
                \$ids( fabrement()->terms( [ 0, $five ], taxonomy: 'category' ) ),
            ];" );
        $this->assertGreaterThan( 1, $all, 'no ids: every category' );
        $this->assertSame( [], $none, 'only wrong ids: nothing, not every category' );
        $this->assertSame( [ $five ], $one );
        $log = $this->take_log();
        $this->assertSame( 1, substr_count( $log, '[Fabrement]' ), 'one line, for the one that is not a number' );
        $this->assertStringContainsString( 'Fabrement::terms(): give an id (a number), not "x".', $log );
    }

    /** A named status is the same everywhere; a private record to whoever may read it. */
    public function test_a_named_status_is_the_same_in_every_request(): void {
        $draft   = $this->record( 'page', 'Named draft', 'draft' );
        $private = $this->record( 'page', 'Named private' );
        $this->site()->wp( 'post', 'update', (string) $private, '--post_status=private', '--user=admin' );
        $calls = "return [
            fabrement()->post( $draft, status: [ 'draft' ] )->id(),
            fabrement()->post( $draft, status: 'publish' )->id(),
            fabrement()->post( $private )->id(),
        ];";
        $this->assertSame( [ $draft, 0, 0 ], $this->site_runs( $calls ), 'a visitor on the site' );
        $this->assertSame( [ $draft, 0, $private ], $this->site_runs( "wp_set_current_user( 1 ); set_current_screen( 'dashboard' ); " . $calls ), 'an administrator in wp-admin' );
    }

    public function test_a_named_status_shows_exactly_those_statuses_private_included(): void {
        $private = $this->record( 'page', 'Named only private' );
        $this->site()->wp( 'post', 'update', (string) $private, '--post_status=private', '--user=admin' );
        $calls = "return [
            fabrement()->post( $private, status: [ 'publish', 'private' ] )->id(),
            fabrement()->post( $private, status: [ 'publish', 'draft' ] )->id(),
            fabrement()->post( $private )->id(),
        ];";
        $this->assertSame( [ $private, 0, 0 ], $this->site_runs( $calls ), 'a visitor gets a private record only when the code names private' );
        $this->assertSame( [ $private, 0, $private ], $this->site_runs( 'wp_set_current_user( 1 ); ' . $calls ), 'an administrator: a named status without private shows no private record' );
    }

    /** 'trash' by name; 'all' — every status a record has, private included, to everyone. */
    public function test_trash_and_all(): void {
        $trashed = $this->record( 'page', 'Trashed page', 'trash' );
        $private = $this->record( 'page', 'All private' );
        $this->site()->wp( 'post', 'update', (string) $private, '--post_status=private', '--user=admin' );
        $draft = $this->record( 'page', 'All draft', 'draft' );
        $this->assertSame( [ 0, $trashed, [ $trashed, $private, $draft ], [ $trashed, $private, $draft ], [ $trashed ] ], $this->site_runs( self::IDS . " return [
            fabrement()->post( $trashed )->id(),
            fabrement()->post( $trashed, status: 'trash' )->id(),
            \$ids( fabrement()->posts( [ $trashed, $private, $draft ], status: 'all' ) ),
            \$ids( fabrement()->posts( [ $trashed, $private, $draft ], status: [ 'trash', 'private', 'draft' ] ) ),
            \$ids( fabrement()->posts( [ $trashed, $private, $draft ], status: [ 'trash' ] ) ),
        ];" ), 'a visitor' );
    }

    public function test_a_status_the_api_does_not_show_is_a_mistake(): void {
        $id = $this->record( 'page', 'Status mistake' );
        $this->assertSame( [ [], false, false ], $this->site_runs( "return [
            fabrement()->posts( [ $id ], status: [] ),
            fabrement()->post( $id, status: 'auto-draft' )->not_empty(),
            fabrement()->post( $id, status: [ 'publish', 'all' ] )->not_empty(),
        ];" ) );
        $log = $this->take_log();
        $this->assertStringContainsString( 'status: give at least one', $log );
        $this->assertStringContainsString( 'status: "auto-draft"', $log );
        $this->assertStringContainsString( '\'all\' stands alone', $log );
    }

    /**
     * Version is chosen once per request: the same answer while nothing changes, a new one after
     * WordPress cleans the post's cache (every wp_update_post does).
     */
    public function test_the_version_is_remembered_until_the_post_cache_is_cleaned(): void {
        $id = $this->record( 'page', 'Cached page' );
        $this->assertSame( [ $id, $id, 0, $id, '' ], $this->site_runs( "global \$wpdb;
            \$first = fabrement()->post( $id )->id();
            \$wpdb->update( \$wpdb->posts, [ 'post_status' => 'draft' ], [ 'ID' => $id ] );
            wp_cache_delete( $id, 'posts' );
            \$remembered = fabrement()->post( $id )->id();
            clean_post_cache( $id );
            \$cleaned = fabrement()->post( $id )->id();
            wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
            \$published = fabrement()->post( $id )->id();
            wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
            return [ \$first, \$remembered, \$cleaned, \$published, fabrement()->post( $id )->url() ];" ) );
    }

    /**
     * A Term: its id, slug, taxonomy, count and parent (a Term, empty at the top); terms() keeps the
     * order given and leaves out what is not there; an empty Term answers emptily.
     */
    public function test_a_term_its_parent_and_a_list_of_terms(): void {
        $post = $this->record( 'post', 'Post in a child term' );
        [ $parent, $child, $one, $list, $none ] = $this->site_runs( "
            \$top = wp_insert_term( 'QA Parent topic', 'category', [ 'slug' => 'qa-parent-topic' ] );
            \$sub = wp_insert_term( 'QA Child topic', 'category', [ 'slug' => 'qa-child-topic', 'parent' => (int) \$top['term_id'], 'description' => 'Below' ] );
            wp_set_object_terms( $post, [ (int) \$sub['term_id'] ], 'category' );
            \$t = fabrement()->term( (int) \$sub['term_id'] );
            return [
                (int) \$top['term_id'],
                (int) \$sub['term_id'],
                [
                    \$t->not_empty(),
                    \$t->id(),
                    \$t->slug(),
                    \$t->taxonomy(),
                    \$t->count(),
                    \$t->parent()->id(),
                    \$t->parent()->name(),
                    \$t->parent()->parent()->not_empty(),
                    \$t->to_array(),
                    \$t->link()->to_array() === [ 'url' => get_term_link( (int) \$sub['term_id'] ), 'text' => 'QA Child topic', 'new_tab' => false, 'post' => 0 ],
                    \$t->link()->post()->not_empty(),
                ],
                array_map( static fn( \\Fabrement\\Api\\Content\\Term \$x ): int => \$x->id(), fabrement()->terms( [ (int) \$sub['term_id'], 99999999, (int) \$top['term_id'], '7', 0, null ] ) ),
                [ fabrement()->term( 99999999 )->not_empty(), fabrement()->term( 99999999 )->id(), fabrement()->term( 99999999 )->slug(), fabrement()->term( 99999999 )->parent()->not_empty(), fabrement()->term( 99999999 )->to_array(), fabrement()->terms( [] ), fabrement()->term( 99999999 )->link()->not_empty() ],
            ];" );
        $this->assertSame( [ true, $child, 'qa-child-topic', 'category', 1, $parent, 'QA Parent topic', false ], array_slice( $one, 0, 8 ) );
        $this->assertSame( [ 'id', 'name', 'slug', 'taxonomy', 'url', 'description', 'count', 'parent' ], array_keys( $one[8] ) );
        $this->assertSame( [ $child, 'QA Child topic', '<p>Below</p>', $parent ], [ $one[8]['id'], $one[8]['name'], $one[8]['description'], $one[8]['parent'] ] );
        $this->assertSame( [ true, false ], array_slice( $one, 9 ), 'its link: its address and name, no record' );
        $this->assertSame( [ $child, $parent ], $list, 'in the order given; a missing id and a wrong one quietly (ids may come from an address)' );
        $this->assertSame( [ false, 0, '', false, [], [], false ], $none );
        $this->assertStringNotContainsString( 'Fabrement::terms()', $this->take_log() );
    }

    /** The terms of a post: published posts only, as the post itself; lang ignored on one language. */
    public function test_post_terms_of_a_published_post(): void {
        $page  = $this->record( 'post', 'Post with terms' );
        $draft = $this->record( 'post', 'Draft with terms', 'draft' );
        [ $terms, $from_draft, $ignored, $same, $wrong ] = $this->site_runs( "
            \$t = wp_insert_term( 'QA Topic', 'category' );
            wp_set_object_terms( $page, [ (int) \$t['term_id'] ], 'category' );
            wp_set_object_terms( $draft, [ (int) \$t['term_id'] ], 'category' );
            \$names = static fn( array \$terms ): array => array_map( static fn( \\Fabrement\\Api\\Content\\Term \$x ): string => \$x->name(), \$terms );
            return [
                \$names( fabrement()->post( $page )->terms( 'category' ) ),
                fabrement()->post( $draft )->terms( 'category' ),
                \$names( fabrement()->post( $page, lang: 'fr' )->terms( 'category' ) ),
                fabrement()->post( $page )->terms( 'category' )[0]->to_array() === fabrement()->term( (int) \$t['term_id'] )->to_array(),
                fabrement()->post( $page )->terms( 'qa_no_such_tax' ),
            ];" );
        $this->assertSame( [ 'QA Topic' ], $terms );
        $this->assertSame( [], $from_draft, 'a draft post is not shown, nor its terms' );
        $this->assertSame( [ 'QA Topic' ], $ignored );
        $this->assertTrue( $same, 'each a Term as fabrement()->term() gives it' );
        $this->assertSame( [], $wrong );
        $this->assertStringContainsString( 'Post::terms(): there is no taxonomy "qa_no_such_tax".', $this->take_log() );
    }

    public function test_attachments_keep_the_order_each_once_and_filter_by_kind(): void {
        $png = $this->image( 'qa-fab-kinds.png' );
        [ $mp4, $pdf ] = $this->site_runs( "
            \$make = static fn( string \$file, string \$mime ): int => (int) wp_insert_attachment( [ 'post_title' => \$file, 'post_mime_type' => \$mime, 'post_status' => 'inherit' ], \$file );
            return [ \$make( 'qa-fab-kinds.mp4', 'video/mp4' ), \$make( 'qa-fab-kinds.pdf', 'application/pdf' ) ];" );
        [ $all, $media, $one, $wrong, $empty ] = $this->site_runs( " return [
            array_map( static fn( \$a ): string => \$a->kind(), fabrement()->attachments( [ $pdf, $png, $mp4, $png, 99999999 ] ) ),
            array_map( static fn( \$a ): int => \$a->id(), fabrement()->attachments( [ $pdf, $png, $mp4 ], kind: [ 'image', 'video' ] ) ),
            [ get_class( fabrement()->attachment( $pdf, kind: 'image' ) ), fabrement()->attachment( $pdf, kind: [ 'image', 'video' ] )->kind() ],
            fabrement()->attachments( [ $png ], kind: 'photo' ),
            fabrement()->attachment( $png, kind: [] )->not_empty(),
        ];" );
        $log = $this->take_log();
        $this->assertSame( [ 'document', 'image', 'video' ], $all, 'in the order given, each once (as ACF reads a gallery), a missing id left out' );
        $this->assertSame( [ $png, $mp4 ], $media );
        $this->assertSame( [ 'Fabrement\Api\Media\Image', '' ], $one, 'not of the kind asked: an empty one of that kind, or of none' );
        $this->assertSame( [], $wrong );
        $this->assertFalse( $empty );
        $this->assertStringContainsString( 'Fabrement::attachments(): kind: "photo" is not a media kind', $log );
        $this->assertStringContainsString( 'Fabrement::attachment(): kind: give a media kind', $log );
    }

    public function test_a_value_without_an_id_takes_its_kind_from_wordpress_types(): void {
        $this->assertSame( [ 'Image', 'File', 'Video', 'File' ], $this->site_runs( " return array_map( static fn( \$v ): string => ( new \ReflectionClass( \Fabrement\Api\Media\Attachment::of( \$v ) ) )->getShortName(), [
            [ 'id' => 0, 'url' => 'https://cdn.test/logo.svg' ],
            [ 'id' => 0, 'url' => 'https://cdn.test/logo.svg', 'label' => 'Logo' ],
            [ 'id' => 0, 'url' => 'https://cdn.test/clip.mp4', 'label' => 'Clip' ],
            [ 'id' => 0, 'url' => 'https://cdn.test/get?file=4', 'label' => 'Price list' ],
        ] );" ), 'svg is not a WordPress type: a media value stays an image, a download value a file' );
    }

    public function test_an_attachment_is_the_class_its_mime_says(): void {
        $png = $this->image( 'qa-fab-class.png' );
        [ $classes, $kinds, $poster, $as_file, $binned, $common ] = $this->site_runs( "
            \$make = static fn( string \$file, string \$mime ): int => (int) wp_insert_attachment( [ 'post_title' => \$file, 'post_mime_type' => \$mime, 'post_status' => 'inherit' ], \$file );
            \$svg = \$make( 'qa-fab-class.svg', 'image/svg+xml' );
            \$mp4 = \$make( 'qa-fab-class.mp4', 'video/mp4' );
            \$mp3 = \$make( 'qa-fab-class.mp3', 'audio/mpeg' );
            \$pdf = \$make( 'qa-fab-class.pdf', 'application/pdf' );
            \$old = \$make( 'qa-fab-class-old.png', 'image/png' );
            set_post_thumbnail( \$mp4, $png );
            wp_trash_post( \$old );
            \$all = array_map( static fn( int \$id ) => \Fabrement\Api\Media\Attachment::of( \$id ), [ $png, \$svg, \$mp4, \$mp3, \$pdf ] );
            return [
                array_map( static fn( \$a ): string => ( new \ReflectionClass( \$a ) )->getShortName(), \$all ),
                array_map( static fn( \$a ): string => \$a->kind(), \$all ),
                \$all[2]->poster()->id(),
                [ get_class( \Fabrement\Api\Media\File::from( $png ) ), \Fabrement\Api\Media\File::from( $png )->name() ],
                [ \Fabrement\Api\Media\Attachment::of( \$old )->not_empty(), \Fabrement\Api\Media\Attachment::of( [ 'id' => \$old, 'url' => 'https://cdn.test/kept.png' ] )->url() ],
                array_map( static fn( \$a ): array => [ \$a->name(), \$a->ext(), \$a->mime(), \$a->title() ], \$all ),
            ];" );
        $this->assertSame( [ 'Image', 'Image', 'Video', 'Audio', 'File' ], $classes );
        $this->assertSame( [ 'image', 'image', 'video', 'audio', 'document' ], $kinds );
        $this->assertSame( $png, $poster, 'the poster of a video is its own featured image' );
        $this->assertSame( [ 'Fabrement\Api\Media\File', 'qa-fab-class.png' ], $as_file, 'File::from still reads any attachment as a file' );
        $this->assertTrue( $binned[0], 'a trashed attachment is still read by its id, as WordPress reads it' );
        $this->assertStringEndsWith( 'qa-fab-class-old.png', $binned[1], 'the address in the library, not the stored one' );
        $this->assertSame( [ 'qa-fab-class.pdf', 'PDF', 'application/pdf', 'qa-fab-class.pdf' ], $common[4] );
        $this->assertSame( 'video/mp4', $common[2][2] );
    }

    public function test_posts_reads_known_ids_in_one_go_and_takes_nothing_but_them(): void {
        $type = 'qa_fab_known';
        $this->post_type( $type, [ 'supports' => [ 'title', 'editor' ] ] );
        $ids = [];
        foreach ( [ 'One', 'Two', 'Three', 'Four', 'Five', 'Six' ] as $title ) {
            $ids[] = $this->record( $type, $title );
        }
        $draft = $this->record( $type, 'Draft', 'draft' );
        $list  = implode( ', ', array_merge( array_reverse( $ids ), [ $draft ] ) );
        [ $shown, $queries ] = $this->site_runs( self::IDS . " global \$wpdb;
            wp_cache_flush();
            \$before = \$wpdb->num_queries;
            \$posts  = fabrement()->posts( [ $list ] );
            \$count  = \$wpdb->num_queries - \$before;
            wp_cache_flush();
            \$before = \$wpdb->num_queries;
            fabrement()->posts( [ {$ids[0]} ] );
            return [ \$ids( \$posts ), [ \$count, \$wpdb->num_queries - \$before ] ];" );
        $this->assertSame( array_reverse( $ids ), $shown, 'in the order given, the draft left out for a visitor' );
        $this->assertSame( $queries[1], $queries[0], 'seven records cost what one does, as WP_Query reads a list: not one query per id' );

        $this->assertSame( [ [], [] ], $this->site_runs( " return [
            fabrement()->posts( type: '$type', limit: 5 ),
            fabrement()->posts(),
        ];" ) );
        $log = $this->take_log();
        $this->assertStringContainsString( 'Fabrement::posts(): unknown option "type"', $log );
        $this->assertStringContainsString( 'Fabrement::posts(): unknown option "limit"', $log );
    }

    public function test_an_options_page_asked_for_in_a_language_is_the_page_itself(): void {
        $this->mcp( 'save-options-page', [
            'slug'   => 'qa-show', 'expectedRevision' => 0,
            'page'   => [ 'title' => 'QA show', 'menuTitle' => 'QA show', 'description' => 'Test page', 'position' => 6 ],
            'fields' => [ self::field( 'phone', 'text' ) ],
            'layout' => [ 'tabs' => [ [ 'label' => 'Contacts', 'items' => [ [ 'field' => 'phone' ] ] ] ] ],
        ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-show', 'values' => [ 'phone' => '+1 555' ] ] );
        $this->assertSame( [ '+1 555', '+1 555' ], $this->site_runs( "return [ fabrement()->options( 'qa-show' )->get( 'phone' ), fabrement()->options( 'qa-show', 'fr' )->get( 'phone' ) ];" ) );
    }

    /**
     * terms( [] ) is an empty picker: nothing, quietly, a taxonomy named or not; terms( taxonomy: … )
     * with no ids lists the taxonomy; terms() with neither is a mistake: one line, nothing.
     */
    public function test_terms_of_an_empty_picker_a_taxonomy_or_neither(): void {
        $this->take_log();
        $id = (int) $this->site_runs( "return (int) wp_insert_term( 'Picker check ' . wp_generate_password( 6, false ), 'category' )['term_id'];" );
        [ $empty, $listed ] = $this->site_runs( "\$ids = static fn( array \$terms ): array => array_map( static fn( \$t ): int => \$t->id(), \$terms );
            return [
                [ fabrement()->terms( [] ), fabrement()->terms( [], taxonomy: 'category' ), fabrement()->terms( [], taxonomy: 'qa_no_such_tax' ), fabrement()->terms( [], taxonomy: 'category', hide_empty: false ) ],
                [ in_array( $id, \$ids( fabrement()->terms( taxonomy: 'category', hide_empty: false ) ), true ), in_array( $id, \$ids( fabrement()->terms( taxonomy: 'category' ) ), true ), in_array( $id, \$ids( fabrement()->terms( null, taxonomy: 'category', hide_empty: false ) ), true ) ],
            ];" );
        $this->assertSame( [ [], [], [], [] ], $empty, 'an empty picker: nothing, whatever the taxonomy' );
        $this->assertSame( [ true, false, true ], $listed, 'ids left out: the taxonomy\'s terms, empty ones as hide_empty says' );
        $this->assertStringNotContainsString( '[Fabrement]', $this->take_log(), 'an empty picker says nothing' );

        $this->assertSame( [ [], [] ], $this->site_runs( "return [ fabrement()->terms(), fabrement()->terms( taxonomy: '' ) ];" ) );
        $log = $this->take_log();
        $this->assertSame( 2, substr_count( $log, '[Fabrement]' ), 'one line for each call with neither' );
        $this->assertStringContainsString( 'Fabrement::terms(): name a taxonomy, or give term ids', $log );
    }

    /** Site code: a public type with a public and a non-public taxonomy, each with a term (one on a post). */
    private function taxed_type(): array {
        static $made = null;
        if ( $made !== null ) {
            return $made;
        }
        $this->site_code( 'qa-taxed-type', "add_action( 'init', static function (): void {
            register_post_type( 'qa_taxed', [ 'public' => true, 'label' => 'QA taxed' ] );
            register_taxonomy( 'qa_tax_open', 'qa_taxed', [ 'public' => true, 'labels' => [ 'name' => 'QA Open & topics', 'singular_name' => 'QA Open topic' ] ] );
            register_taxonomy( 'qa_tax_closed', 'qa_taxed', [ 'public' => false, 'show_ui' => true, 'label' => 'QA Closed topics' ] );
        } );" );
        return $made = $this->site_runs( "
            \$post = (int) wp_insert_post( [ 'post_type' => 'qa_taxed', 'post_title' => 'Taxed', 'post_status' => 'publish' ] );
            \$used = (int) wp_insert_term( 'Used open', 'qa_tax_open' )['term_id'];
            \$idle = (int) wp_insert_term( 'Idle open', 'qa_tax_open' )['term_id'];
            \$closed = (int) wp_insert_term( 'Closed one', 'qa_tax_closed' )['term_id'];
            wp_set_object_terms( \$post, [ \$used ], 'qa_tax_open' );
            return [ \$post, \$used, \$idle, \$closed ];" );
    }

    /**
     * taxonomies() lists Taxonomy objects as get_object_taxonomies() lists the type's: of a record, a
     * type or the viewed record; public: true only public ones, false only the others, null/'' all.
     */
    public function test_taxonomies_of_a_type_public_or_not(): void {
        $this->take_log();
        [ $post ] = $this->taxed_type();
        [ $lists, $objects, $wrong ] = $this->site_runs( "\$names = static fn( array \$list ): array => array_map( static fn( \\Fabrement\\Api\\Content\\Taxonomy \$t ): string => \$t->name(), \$list );
            return [
                [
                    \$names( fabrement()->taxonomies( 'qa_taxed' ) ),
                    \$names( fabrement()->taxonomies( 'qa_taxed', public: true ) ),
                    \$names( fabrement()->taxonomies( 'qa_taxed', public: false ) ),
                    \$names( fabrement()->taxonomies( 'qa_taxed', public: null ) ),
                    \$names( fabrement()->taxonomies( 'qa_taxed', public: '' ) ),
                    \$names( fabrement()->taxonomies( $post ) ),
                    \$names( fabrement()->taxonomies( '$post', public: true ) ),
                    \$names( fabrement()->taxonomies( fabrement()->post( $post ) ) ),
                    \$names( fabrement()->taxonomies( get_post( $post ), public: false ) ),
                    \$names( fabrement()->taxonomies( 'post', public: true ) ),
                ],
                array_map( 'get_class', fabrement()->taxonomies( 'qa_taxed' ) ),
                [ fabrement()->taxonomies( 'qa_taxed', public: 'yes' ), fabrement()->taxonomies( 'qa_taxed', public: 1 ), fabrement()->taxonomies( 'qa_no_such_type' ), fabrement()->taxonomies( 99999999 ), fabrement()->taxonomies( fabrement()->post( 99999999 ) ) ],
            ];" );
        $both = [ 'qa_tax_open', 'qa_tax_closed' ];
        $this->assertSame( [ $both, [ 'qa_tax_open' ], [ 'qa_tax_closed' ], $both, $both, $both, [ 'qa_tax_open' ], $both, [ 'qa_tax_closed' ], [ 'category', 'post_tag', 'post_format' ] ], $lists );
        $this->assertSame( [ \Fabrement\Api\Content\Taxonomy::class, \Fabrement\Api\Content\Taxonomy::class ], $objects );
        $this->assertSame( [ [], [], [], [], [] ], $wrong );
        $log = $this->take_log();
        $this->assertSame( 3, substr_count( $log, '[Fabrement]' ), 'a public: that is not a bool and a type that is not there; a missing record quietly' );
        $this->assertStringContainsString( 'Fabrement::taxonomies(): public: true or false, not string.', $log );
        $this->assertStringContainsString( 'Fabrement::taxonomies(): public: true or false, not int.', $log );
        $this->assertStringContainsString( 'Fabrement::taxonomies(): there is no post type "qa_no_such_type".', $log );

        $this->site_code( 'qa-taxed-page', "add_action( 'template_redirect', static function (): void {
            if ( isset( \$_GET['qa_taxonomies'] ) ) {
                wp_send_json( array_map( static fn( \$t ): string => \$t->name(), fabrement()->taxonomies( public: true ) ) );
            }
        }, 1 );" );
        $viewed = $this->get( "/?post_type=qa_taxed&p=$post&qa_taxonomies=1" ); // before redirect_canonical: no rewrite rules for a type of site code
        $this->assertSame( 200, $viewed['status'] );
        $this->assertSame( [ 'qa_tax_open' ], json_decode( $viewed['body'], true ), 'left out: the viewed record\'s' );
    }

    /** What every loop test below starts with: the ids asked, and the global post set as a template would. */
    private const LOOPS = "
        \$L = static fn(): int => fabrement()->loop_post()->id();
        \$main = static function ( array \$ids ): void {
            \$GLOBALS['wp_query'] = new WP_Query( [ 'post_type' => 'page', 'post__in' => \$ids, 'orderby' => 'post__in' ] );
            \$GLOBALS['wp_the_query'] = \$GLOBALS['wp_query'];
        };
        \$list = static fn( array \$ids ): WP_Query => new WP_Query( [ 'post_type' => 'page', 'post__in' => \$ids, 'orderby' => 'post__in' ] );
        \$show = static function ( int \$id ): void { \$GLOBALS['post'] = get_post( \$id ); setup_postdata( \$GLOBALS['post'] ); };
        \$kept = static fn(): int => count( ( new ReflectionProperty( \\Fabrement\\Modules\\Query\\Version::class, 'loops' ) )->getValue() );
    ";

    /**
     * Inside a running loop the global post may be another record for a moment — the archive's own
     * content is drawn that way, a card may set up a related record. loop_post() is nothing then,
     * and the loop's record again once it is the current post again: in that step and in every
     * step after it.
     */
    public function test_loop_post_survives_the_global_post_set_to_another_record_and_back(): void {
        [ $a, $b, $c, $x ] = [ $this->record( 'page', 'Swap A' ), $this->record( 'page', 'Swap B' ), $this->record( 'page', 'Swap C' ), $this->record( 'page', 'Swap other' ) ];

        // A secondary list: asked while swapped, restored by the query; swapped unasked, restored by hand; asked twice.
        $seen = $this->site_runs( self::LOOPS . "
            \$q = \$list( [ $a, $b, $c ] );
            \$steps = [];
            while ( \$q->have_posts() ) {
                \$q->the_post();
                \$step = [ \$L() ];
                if ( get_the_ID() === $a ) {
                    \$show( $x );
                    \$step[] = [ \$L(), get_the_ID() ];
                    \$q->reset_postdata();
                    \$step[] = \$L();
                }
                if ( get_the_ID() === $b ) {
                    \$previous = \$GLOBALS['post'];
                    \$show( $x );
                    \$GLOBALS['post'] = \$previous; setup_postdata( \$previous );
                    \$step[] = \$L();
                    \$show( $x ); \$L(); \$L();
                    \$GLOBALS['post'] = null;
                    \$step[] = \$L();
                    \$GLOBALS['post'] = \$previous; setup_postdata( \$previous );
                    \$step[] = \$L();
                }
                \$step[] = fabrement()->loop_post()->title();
                \$steps[] = \$step;
            }
            \$steps['ended'] = \$L();
            \$show( $c );
            \$steps['its last record shown again, no loop'] = \$L();
            return \$steps;" );
        $this->assertSame( [
            0       => [ $a, [ 0, $x ], $a, 'Swap A' ],
            1       => [ $b, $b, 0, $b, 'Swap B' ],
            2       => [ $c, 'Swap C' ],
            'ended' => 0,
            'its last record shown again, no loop' => 0,
        ], $seen, 'nothing while another record is the current post; the loop\'s own again after, in this step and the next ones' );

        // The main query, the way the archive's own content is drawn inside the listing: saved, swapped, put back.
        $seen = $this->site_runs( self::LOOPS . "
            \$main( [ $a, $b, $c ] );
            \$steps = [];
            while ( have_posts() ) {
                the_post();
                \$previous = \$GLOBALS['post'];
                \$show( $x );
                \$during = \$L();
                \$GLOBALS['post'] = \$previous; setup_postdata( \$previous );
                \$steps[] = [ \$during, \$L(), get_the_ID() ];
            }
            \$steps[] = \$L();
            return \$steps;" );
        $this->assertSame( [ [ 0, $a, $a ], [ 0, $b, $b ], [ 0, $c, $c ], 0 ], $seen, 'every card of the listing keeps its record' );

        // A singular page: one the_post(), never ended.
        $seen = $this->site_runs( self::LOOPS . "
            \$GLOBALS['wp_query'] = new WP_Query( [ 'page_id' => $a ] ); \$GLOBALS['wp_the_query'] = \$GLOBALS['wp_query'];
            the_post();
            \$out = [ \$L() ];
            \$show( $x );
            \$out[] = \$L();
            wp_reset_postdata();
            \$out[] = [ \$L(), fabrement()->viewed_post()->id() ];
            return \$out;" );
        $this->assertSame( [ $a, 0, [ $a, $a ] ], $seen, 'the viewed record is the loop post again for the rest of the page' );
    }

    /** Nested loops with the global post moved about: the answer is always a loop whose post is the current one, the innermost such. */
    public function test_loop_post_in_nested_loops_follows_the_current_post(): void {
        [ $a, $b, $i1, $i2, $x ] = [ $this->record( 'page', 'Nest A' ), $this->record( 'page', 'Nest B' ), $this->record( 'page', 'Nest inner 1' ), $this->record( 'page', 'Nest inner 2' ), $this->record( 'page', 'Nest other' ) ];

        $seen = $this->site_runs( self::LOOPS . "
            \$main( [ $a, $b ] );
            \$steps = [];
            while ( have_posts() ) {
                the_post();
                \$step = [ 'outer' => \$L() ];
                if ( get_the_ID() === $a ) {
                    \$q = \$list( [ $i1, $i2, $a ] );
                    while ( \$q->have_posts() ) {
                        \$q->the_post();
                        \$inner = [ \$L() ];
                        if ( get_the_ID() === $i1 ) {
                            \$show( $x );  \$inner[] = \$L();   // a third record: no loop is on it
                            \$show( $a );  \$inner[] = \$L();   // the outer loop's record: the outer loop answers
                            \$q->reset_postdata(); \$inner[] = \$L();
                        }
                        \$step['inner'][] = \$inner;
                    }
                    wp_reset_postdata();
                    \$step['after inner'] = \$L();
                }
                \$steps[] = \$step;
            }
            \$steps['after'] = \$L();
            return \$steps;" );
        $this->assertSame( [
            0       => [ 'outer' => $a, 'inner' => [ [ $i1, 0, $a, $i1 ], [ $i2 ], [ $a ] ], 'after inner' => $a ],
            1       => [ 'outer' => $b ],
            'after' => 0,
        ], $seen );

        // Left with break, with and without putting the outer post back; the list run a second time.
        $seen = $this->site_runs( self::LOOPS . "
            \$main( [ $a, $b ] );
            \$steps = [];
            while ( have_posts() ) {
                the_post();
                \$q = \$list( [ $i1, $i2 ] );
                while ( \$q->have_posts() ) { \$q->the_post(); break; }
                \$step = [ 'left, not reset' => [ \$L(), get_the_ID() ] ];
                wp_reset_postdata();
                \$step['reset'] = \$L();
                \$show( $x );
                \$step['other'] = \$L();
                wp_reset_postdata();
                \$step['back'] = \$L();
                \$again = [];
                \$q->rewind_posts();
                while ( \$q->have_posts() ) { \$q->the_post(); \$again[] = \$L(); }
                wp_reset_postdata();
                \$step['the list once more'] = \$again;
                \$step['outer after it'] = \$L();
                \$steps[] = \$step;
            }
            \$steps['after'] = \$L();
            return \$steps;" );
        $step = static fn( int $outer ): array => [ 'left, not reset' => [ $i1, $i1 ], 'reset' => $outer, 'other' => 0, 'back' => $outer, 'the list once more' => [ $i1, $i2 ], 'outer after it' => $outer ];
        $this->assertSame( [ 0 => $step( $a ), 1 => $step( $b ), 'after' => 0 ], $seen, 'a loop left with break never answers for the outer one' );
    }

    /**
     * \$this->loop() of a page bundle is a generator that rewinds its query when left — after the
     * last post or a break. Lists that have ended are not kept: a page of many lists holds only
     * the ones still running.
     */
    public function test_loops_that_ended_are_let_go_and_a_list_can_run_again(): void {
        [ $a, $b, $c ] = [ $this->record( 'page', 'Kept A' ), $this->record( 'page', 'Kept B' ), $this->record( 'page', 'Kept C' ) ];
        $seen = $this->site_runs( self::LOOPS . "
            // As ArchivePage::loop() walks a query.
            \$walk = static function ( WP_Query \$q ): Generator {
                \$q->rewind_posts();
                try {
                    while ( \$q->have_posts() ) { \$q->the_post(); yield get_post(); }
                } finally {
                    \$q->rewind_posts();
                    wp_reset_postdata();
                }
            };
            \$out = [ 'start' => \$kept() ];
            \$q = \$list( [ $a, $b, $c ] );
            foreach ( \$walk( \$q ) as \$post ) { \$out['cards'][] = \$L(); }
            \$out['after the list'] = \$L();
            foreach ( \$walk( \$q ) as \$post ) { \$out['left'][] = \$L(); break; }
            \$out['after break'] = \$L();
            foreach ( \$walk( \$q ) as \$post ) { \$out['again'][] = \$L(); }

            // Ten lists drawn to their end, nothing asked in between: none is kept when the next one starts.
            for ( \$n = 0; \$n < 10; \$n++ ) {
                \$one = \$list( [ $a, $b ] );
                while ( \$one->have_posts() ) { \$one->the_post(); }
            }
            \$outer = \$list( [ $a ] ); \$outer->the_post();
            \$inner = \$list( [ $b, $c ] ); \$inner->the_post();
            \$out['kept: two running'] = \$kept();
            \$out['innermost'] = \$L();
            while ( \$inner->have_posts() ) { \$inner->the_post(); }
            \$outer->reset_postdata();
            \$out['outer again'] = \$L();
            \$out['kept: one running'] = \$kept();

            // A query nothing holds any more is let go with its posts.
            \$gone = \$list( [ $c ] ); \$gone->the_post();
            \$out['running'] = \$L();
            \$gone = null; \$outer = null; \$inner = null; \$one = null;
            gc_collect_cycles();
            \$out['gone'] = [ \$L(), \$kept() ];
            return \$out;" );
        $this->assertSame( [
            'start'             => 0,
            'cards'             => [ $a, $b, $c ],
            'after the list'    => 0,
            'left'              => [ $a ],
            'after break'       => 0,
            'again'             => [ $a, $b, $c ],
            'kept: two running' => 2,
            'innermost'         => $b,
            'outer again'       => $a,
            'kept: one running' => 1,
            'running'           => $c,
            'gone'              => [ 0, 0 ],
        ], $seen );
    }
}
