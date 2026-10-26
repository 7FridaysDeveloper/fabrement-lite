<?php

namespace Fabrement\Tests\Site\Support;

/**
 * Real HTTP tests of retained fields and Filter APIs rendered by trusted test-theme PHP.
 * MCP assigns templates and writes content; it never installs these PHP fixtures.
 *
 * The trusted theme fixtures have one testing convention: render.php
 * prints what the page knows (its rows, $this->filter()'s counts and links, a second list) as JSON
 * in <script type="application/json" id="qa-state">, so a test reads exact numbers and lists from
 * the HTML a visitor gets instead of guessing them from markup.
 *
 * Answers are asked with "asks" — data, written into the theme fixture's page.php by var_export():
 *   [ 'posts' ] / [ 'posts', window ]     titles of $filter->posts()
 *   [ 'query', window ]                   { titles, found } of $filter->query( window )
 *   [ 'found' ]                           $filter->found()
 *   [ 'counts', name ] / [ 'meta_counts', key ] / [ 'term_counts', taxonomy, children? ]
 *                                         [ [ value, n ], … ] in the filter's order; terms by slug
 *   [ 'values', name ] / [ 'meta_values', key ]
 *   [ 'active' ]                          the chips
 *   [ 'chosen', param ]                   "term:<slug>", "post:<title>" or the value
 *   [ 'is', param, value ] / [ 'toggle', param, value ] / [ 'url', set ]
 *   [ 'without', [ names ], ask ]         an ask of $filter->without( …names )
 *   [ 'terms', param, taxonomy ]          every term: { slug, name, is, toggle } — a filter bar's links
 * A value [ '@term' => 'ai' ] is that WP_Term of qa_topic (or of 'taxonomy'), [ '@post' => 12 ]
 * that WP_Post — so a test can hand the page a term or a post, as page code does.
 *
 * A probe is [ 'args' => filter keys, 'ask' => [ asks ] ] run over $this->content()->filter( args ),
 * or [ 'source' => 'posts', 'args' => … ] — the titles of $this->content()->posts( args ).
 */
abstract class PageBundleTestCase extends SiteTestCase {

    /** @var array<string,mixed> What this class made once: the frame, the probe page… */
    private static array $made = [];
    private static array $theme_fixtures = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$made = [];
    }

    public static function tearDownAfterClass(): void {
        foreach ( array_keys( self::$theme_fixtures ) as $path ) {
            Site::get( static::SITE )->exec( 'rm', '-f', '--', $path );
        }
        self::$theme_fixtures = [];
        self::$made = [];
        parent::tearDownAfterClass();
    }

    // ── the frame: a header pattern and templates ───────────────────────────────────────────

    /**
     * A header synced pattern composed from ordinary core blocks.
     */
    protected function header(): int {
        if ( ! isset( self::$made['header'] ) ) {
            self::$made['header'] = (int) $this->mcp( 'save-synced-pattern', [ 'mode' => 'create', 'title' => 'QA header', 'kind' => 'header', 'blocks' => [
                [ 'markup' => '<!-- wp:paragraph --><p class="qa-header">QA header</p><!-- /wp:paragraph -->' ],
            ] ] )['patternId'];
        }
        return self::$made['header'];
    }


    /**
     * A template whose content slot draws these theme fixtures: [ context => theme fixture path ].
     *
     * @return int Its id.
     */
    protected function template( string $title, array $rules ): int {
        $saved = $this->mcp( 'save-template', self::frame( $title, $this->header(), 0, [
            'contentSource' => [ 'rules' => array_map( static fn( string $context, string $file ): array => [ 'context' => $context, 'file' => $file ], array_keys( $rules ), $rules ) ],
        ] ) );
        $this->assertSame( '', $saved['template']['problem'], 'nothing is wrong with a template that has its header' );
        return (int) $saved['template']['id'];
    }

    /** The address of an archive view, as list-archives gives it. */
    protected function archive_url( string $context ): string {
        foreach ( (array) $this->mcp( 'list-archives' )['archives'] as $archive ) {
            if ( ( $archive['context'] ?? '' ) === $context ) {
                return (string) $archive['url'];
            }
        }
        $this->fail( "list-archives has no $context" );
    }

    // ── theme fixtures ─────────────────────────────────────────────────────────────────────────────

    /**
     * Save an archive theme fixture (whole files) and put it on its views: a template with a rule per
     * context, bound to each. @return string The theme fixture's path.
     */
    protected function archive( string $name, array $contexts, string $page, string $render, string $listing ): string {
        $path     = $this->install_theme_source( 'archive', $name, [ 'page' => $page, 'php' => $render, 'listing' => $listing ], $contexts );
        $template = $this->template( 'QA ' . $name, array_fill_keys( $contexts, $path ) );
        foreach ( $contexts as $context ) {
            $bound = $this->mcp( 'assign-template-archive', [ 'context' => $context, 'templateId' => $template ] );
            $this->assertTrue( $bound['bound'] );
        }
        return $path;
    }

    /**
     * Save a single-page theme fixture and make it the body of every record of $post_type (the type's
     * default template). @return string The theme fixture's path.
     */
    protected function single( string $name, string $post_type, string $page, string $render ): string {
        $path     = $this->install_theme_source( 'single', $name, [ 'page' => $page, 'php' => $render ], [ 'singular:' . $post_type ] );
        $template = $this->template( 'QA ' . $name, [ 'singular:' . $post_type => $path ] );
        $this->mcp( 'set-default-template', [ 'templateId' => $template, 'postType' => $post_type ] );
        return $path;
    }

    /** Install trusted theme fixtures; this is test setup, never an MCP code writer. */
    protected function install_theme_source( string $kind, string $name, array $files, array $contexts = [] ): string {
        if ( ! preg_match( '/^qa-[a-z0-9-]+$|^blog-archive$/', $name ) ) {
            throw new \InvalidArgumentException( 'Unexpected QA fixture name.' );
        }
        $root = (string) $this->site_runs( 'return get_stylesheet_directory();' );
        if ( ! str_starts_with( $root, '/var/www/html/wp-content/themes/' ) ) {
            throw new \RuntimeException( 'Not an isolated test theme.' );
        }
        $dir = $root . '/qa-fixtures';
        $this->site()->exec( 'mkdir', '-p', $dir );
        $support = (string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/theme-query.php' );
        $this->site()->write( $dir . '/runtime.php', $support );
        self::$theme_fixtures[ $dir . '/runtime.php' ] = true;
        foreach ( $files as $part => $source ) {
            $path = $dir . '/' . $name . '-' . $part . '.php';
            $this->site()->write( $path, $source );
            self::$theme_fixtures[ $path ] = true;
        }
        if ( $kind === 'archive' ) {
            self::$made['archiveFixtures'][ $name ] = $contexts;
            $this->site_code( 'qa-theme-queries', '$qaContexts = ' . var_export( self::$made['archiveFixtures'], true ) . ";\n" . <<<'PHP'
add_action('pre_get_posts', static function (WP_Query $query) use ($qaContexts): void {
    if (is_admin() || !$query->is_main_query()) { return; }
    foreach ($qaContexts as $name => $contexts) {
        foreach ($contexts as $context) {
            $parts = explode(':', $context);
            $matches = ($context === 'blog-home' && $query->is_home())
                || ($parts[0] === 'archive' && $query->is_post_type_archive($parts[1]))
                || ($parts[0] === 'tax' && $query->is_tax($parts[1]));
            if (!$matches) { continue; }
            $dir = get_stylesheet_directory() . '/qa-fixtures';
            require_once $dir . '/runtime.php';
            $page = require $dir . '/' . $name . '-page.php';
            $page->apply($query);
            $GLOBALS['qa_theme_queries'][$name] = $page;
            return;
        }
    }
}, 30);
PHP
            );
        }
        $body = "<?php\n/** Fabrement: " . ($kind === 'archive' ? 'archive' : 'singular') . " */\n"
            . 'require_once dirname(__DIR__) . "/qa-fixtures/runtime.php";' . "\n"
            . '$qa = $GLOBALS["qa_theme_queries"][' . var_export($name, true) . '] ?? (require dirname(__DIR__) . ' . var_export('/qa-fixtures/' . $name . '-page.php', true) . ');' . "\n"
            . '$qa->draw(dirname(__DIR__) . ' . var_export('/qa-fixtures/' . $name . '-php.php', true)
            . (isset($files['listing']) ? ', dirname(__DIR__) . ' . var_export('/qa-fixtures/' . $name . '-listing.php', true) : '') . ');';
        return $this->theme_source( 'qa-source-' . $name, $body );
    }

    // ── what the visitor reads ──────────────────────────────────────────────────────────────

    /**
     * A page as a visitor gets it, with the JSON its theme fixture printed.
     *
     * @return array{status:int, body:string, state:array}
     */
    protected function visit( string $url, array $query = [], int $status = 200 ): array {
        if ( $query !== [] ) {
            $url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $query );
        }
        $page = $this->get( $url );
        $this->assertSame( $status, $page['status'], $url );
        return [ 'status' => $page['status'], 'body' => $page['body'], 'state' => $status === 200 ? self::state( $page['body'], $url ) : [] ];
    }

    /** The JSON a theme fixture printed in #qa-state; fails, with the page, when it printed none. */
    protected static function state( string $html, string $where = '' ): array {
        if ( preg_match( '#<script\b(?=[^>]*\btype="application/json")(?=[^>]*\bid="qa-state")[^>]*>(.*?)</script>#s', $html, $m ) !== 1 ) {
            self::fail( "No #qa-state on $where — the theme fixture did not draw:\n" . Site::get( static::SITE )->debug_log() . "\n" . substr( strip_tags( preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#s', '', $html ) ), -2500 ) );
        }
        $state = json_decode( $m[1], true );
        self::assertIsArray( $state, 'the state is JSON' );
        return $state;
    }

    /** [ [ value, n ], … ] as value => n, in order. */
    protected static function counts( array $pairs ): array {
        $out = [];
        foreach ( $pairs as [ $value, $n ] ) {
            $out[ (string) $value ] = (int) $n;
        }
        return $out;
    }

    /** Term counts by slug, sorted by slug (their order is the database's). */
    protected static function by_slug( array $pairs ): array {
        $out = self::counts( $pairs );
        ksort( $out, SORT_STRING );
        return $out;
    }

    // ── a page of probes: $this->content()->filter( … ) in a page's own code ─────────────────

    /**
     * Write the probes into the probe page's theme fixture and open the page with $query, as a visitor.
     *
     * @param array<string,array> $probes name => probe
     * @return array<string,mixed> name => its answers (a list in the order asked; titles for a 'posts' source)
     */
    /** Install a trusted test-theme fixture; Lite itself never authors PHP. */
    protected function theme_source( string $name, string $source ): string {
        if ( ! preg_match( '/^qa-[a-z0-9-]+$/', $name ) ) {
            throw new \InvalidArgumentException( 'Only named QA theme fixtures are allowed.' );
        }
        $root = (string) $this->site_runs( 'return get_stylesheet_directory();' );
        if ( ! str_starts_with( $root, '/var/www/html/wp-content/themes/' ) ) {
            throw new \RuntimeException( 'Not an isolated Docker test theme.' );
        }
        $path = $root . '/templates/' . $name . '.php';
        $this->site()->exec( 'mkdir', '-p', dirname( $path ) );
        $this->site()->write( $path, $source );
        self::$theme_fixtures[ $path ] = true;
        return $name . '.php';
    }

    protected function probe( array $probes, array $query = [] ): array {
        $source = "<?php\n/** Fabrement: singular:page */\n"
            . '$qa = new class {' . "\n"
            . "    public function answers(): array {\n        \$out = [];\n        foreach ( " . var_export( $probes, true ) . " as \$name => \$probe ) { \$out[\$name] = \$this->qa_answer(\$probe); }\n        return \$out;\n    }\n"
            . self::INTERPRETER . "};\n?>\n"
            . '<script type="application/json" id="qa-state"><?php echo wp_json_encode($qa->answers(), JSON_HEX_TAG | JSON_HEX_AMP); ?></script>';
        $path = $this->theme_source( 'qa-probe', $source );
        if ( ! isset( self::$made['probe'] ) ) {
            $template = $this->template( 'QA probe', [ 'singular:page' => $path ] );
            $id = $this->record( 'page', 'QA probe' );
            $this->mcp( 'assign-template-pages', [ 'templateId' => $template, 'pageIds' => [ $id ], 'mode' => 'add' ] );
            self::$made['probe'] = (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
        }
        return $this->visit( self::$made['probe'], $query )['state'];
    }

    /** The address of the probe page (after a first probe()). */
    protected function probe_url(): string {
        return (string) self::$made['probe'];
    }

    // ── the logs ────────────────────────────────────────────────────────────────────────────

    /**
     * What the site wrote to debug.log so far, and the log emptied — for a step whose point is a
     * warning (a mistake in the page's code), which would otherwise fail the test at its end.
     */
    protected function take_debug_log(): string {
        $log = $this->site()->debug_log();
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        return $log;
    }

    // ── the code the theme fixtures share ──────────────────────────────────────────────────────────

    /**
     * Private helpers of a page.php that answer asks and probes (see the class comment). Plain
     * calls only: the save refuses a method named by a variable.
     */
    protected const INTERPRETER = <<<'PHP'
    private function qa_answer( array $probe ) {
        $args = (array) $this->qa_value( (array) ( $probe['args'] ?? [] ) );
        if ( ( $probe['source'] ?? '' ) === 'posts' ) {
            return $this->qa_titles( \Fabrement\Modules\Query\Filter::from( $args )->posts() );
        }
        $filter = \Fabrement\Modules\Query\Filter::from( $args );
        $out    = [];
        foreach ( (array) ( $probe['ask'] ?? [ [ 'posts' ] ] ) as $ask ) {
            $out[] = $this->qa_ask( $filter, (array) $ask );
        }
        return $out;
    }

    private function qa_asks( $filter, array $asks ): array {
        $out = [];
        foreach ( $asks as $key => $ask ) {
            $out[ $key ] = $this->qa_ask( $filter, (array) $ask );
        }
        return $out;
    }

    private function qa_ask( $filter, array $ask ) {
        switch ( (string) ( $ask[0] ?? 'posts' ) ) {
            case 'posts':
                return $this->qa_titles( isset( $ask[1] ) ? $filter->posts( (array) $ask[1] ) : $filter->posts() );
            case 'query':
                $q = $filter->query( (array) ( $ask[1] ?? [] ) );
                return [ 'titles' => $this->qa_titles( $q->posts ), 'found' => (int) $q->found_posts ];
            case 'found':
                return $filter->found();
            case 'counts':
                return $this->qa_pairs( $filter->counts( (string) $ask[1] ), '' );
            case 'meta_counts':
                return $this->qa_pairs( $filter->meta_counts( (string) $ask[1] ), '' );
            case 'term_counts':
                return $this->qa_pairs( $filter->term_counts( (string) $ask[1], (bool) ( $ask[2] ?? true ) ), (string) $ask[1] );
            case 'values':
                return $this->qa_strings( $filter->values( (string) $ask[1] ) );
            case 'meta_values':
                return $this->qa_strings( $filter->meta_values( (string) $ask[1] ) );
            case 'active':
                return $filter->active();
            case 'chosen':
                $chosen = [];
                foreach ( $filter->chosen( (string) $ask[1] ) as $one ) {
                    $chosen[] = $this->qa_one( $one );
                }
                return $chosen;
            case 'is':
                return $filter->is( (string) $ask[1], $this->qa_value( $ask[2] ) );
            case 'toggle':
                return $filter->toggle( (string) $ask[1], $this->qa_value( $ask[2] ) );
            case 'url':
                return $filter->url( (array) $this->qa_value( $ask[1] ) );
            case 'without':
                return $this->qa_ask( $filter->without( ...array_values( (array) $ask[1] ) ), (array) $ask[2] );
            case 'terms':
                $links = [];
                foreach ( get_terms( [ 'taxonomy' => (string) $ask[2], 'hide_empty' => false ] ) as $term ) {
                    $links[] = [ 'slug' => $term->slug, 'name' => $term->name, 'is' => $filter->is( (string) $ask[1], $term ), 'toggle' => $filter->toggle( (string) $ask[1], $term ) ];
                }
                return $links;
        }
        return null;
    }

    private function qa_value( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        if ( isset( $value['@term'] ) ) {
            $term = get_term_by( 'slug', (string) $value['@term'], (string) ( $value['taxonomy'] ?? 'qa_topic' ) );
            return $term instanceof WP_Term ? $term : null;
        }
        if ( isset( $value['@post'] ) ) {
            return get_post( (int) $value['@post'] );
        }
        $out = [];
        foreach ( $value as $key => $one ) {
            $out[ $key ] = $this->qa_value( $one );
        }
        return $out;
    }

    private function qa_titles( array $posts ): array {
        $out = [];
        foreach ( $posts as $post ) {
            $out[] = $post instanceof WP_Post ? $post->post_title : '?';
        }
        return $out;
    }

    private function qa_strings( array $values ): array {
        $out = [];
        foreach ( $values as $value ) {
            $out[] = (string) $value;
        }
        return $out;
    }

    private function qa_pairs( array $counts, string $taxonomy ): array {
        $out = [];
        foreach ( $counts as $key => $n ) {
            if ( $taxonomy !== '' ) {
                $term = get_term( (int) $key, $taxonomy );
                $key  = $term instanceof WP_Term ? $term->slug : '#' . $key;
            }
            $out[] = [ (string) $key, (int) $n ];
        }
        return $out;
    }

    private function qa_one( $item ): string {
        if ( $item instanceof WP_Term ) {
            return 'term:' . $item->slug;
        }
        if ( $item instanceof WP_Post ) {
            return 'post:' . $item->post_title;
        }
        return (string) $item;
    }

PHP;

    /**
     * An archive theme fixture's page.php: filters() from $rules (the misuse switch adds what an archive
     * must not take), sort() picked by ?sorting from $sorts, per_page() from ?per, query() with
     * the base rules a page can add (?qa_leave_out=<id>, ?qa_two, ?qa_own_or), and the report its
     * render.php prints: asks over $this->filter(), probes over $this->content()->filter().
     * $more_rules: PHP lines of filters() that change $rules by the address before it is returned.
     */
    protected static function archive_page( array $rules, array $sorts, string $default_sort, int $per_page, array $report, array $probes = [], string $more_rules = '' ): string {
        return "<?php\ndefined( 'ABSPATH' ) || exit;\n\nreturn new class extends \\QaThemeQuery {\n\n"
            . "    public function filters(): array {\n"
            . '        $rules = ' . var_export( $rules, true ) . ";\n"
            . "        if ( \$this->request()->has( 'qa_misuse' ) ) {\n            \$rules['type'] = 'post';\n            \$rules['page'] = 3;\n        }\n"
            . $more_rules
            . "        return \$rules;\n    }\n\n"
            . "    public function sort(): array {\n"
            . '        $sorts = ' . var_export( $sorts, true ) . ";\n"
            . "        return \$sorts[ \$this->request()->choice( 'sorting', array_keys( \$sorts ), " . var_export( $default_sort, true ) . " ) ];\n    }\n\n"
            . "    public function per_page(): int {\n        return \$this->request()->int( 'per', $per_page );\n    }\n\n"
            . "    public function query( \\WP_Query \$q ): void {\n"
            . "        \$leave = \$this->request()->int( 'qa_leave_out', 0 );\n"
            . "        if ( \$leave > 0 ) {\n            \$q->set( 'post__not_in', [ \$leave ] );\n            \$q->set( 'posts_per_page', 1 );\n        }\n"
            . "        if ( \$this->request()->has( 'qa_two' ) ) {\n            \$q->set( 'posts_per_page', 2 );\n        }\n"
            . "        if ( \$this->request()->has( 'qa_own_or' ) ) {\n"
            . "            \$q->set( 'meta_query', [ 'relation' => 'OR', [ 'key' => 'fab_city', 'value' => 'Kyiv' ], [ 'key' => 'fab_city', 'value' => 'Odesa' ] ] );\n        }\n"
            . "    }\n\n"
            . "    private function qa_report(): array {\n        return " . var_export( $report, true ) . ";\n    }\n\n"
            . "    private function qa_probes(): array {\n        return " . var_export( $probes, true ) . ";\n    }\n\n"
            . self::INTERPRETER . "};\n";
    }

    /**
     * An archive theme fixture's render.php: the heading, the record's blocks or the rows, and #qa-state —
     * the rows' titles, found(), the report, a second list of $second_type paging by ?list_page, the
     * page links, and request()->url() writing a list.
     */
    protected static function archive_render( string $header, string $second_type ): string {
        return "<?php\n/**\n * Fabrement: $header\n */\ndefined( 'ABSPATH' ) || exit;\n\n"
            . "\$qa_titles = [];\nforeach ( \$this->loop() as \$qa_post ) {\n    \$qa_titles[] = \$qa_post->post_title;\n}\n"
            . "\$qa_second = \$this->content()->posts_query( [ 'type' => " . var_export( $second_type, true ) . ", 'limit' => 2, 'page' => \$this->request()->int( 'list_page', 1 ), 'sort' => [ 'title' => 'ASC' ] ] );\n"
            . "\$qa_probed = [];\nforeach ( \$this->qa_probes() as \$qa_name => \$qa_probe ) {\n    \$qa_probed[ \$qa_name ] = \$this->qa_answer( \$qa_probe );\n}\n"
            . "\$qa_state = [\n"
            . "    'titles'             => \$qa_titles,\n"
            . "    'found'              => \$this->found(),\n"
            . "    'empty'              => \$this->is_empty(),\n"
            . "    'report'             => \$this->qa_asks( \$this->filter(), \$this->qa_report() ),\n"
            . "    'probes'             => \$qa_probed,\n"
            . "    'second'             => \$this->qa_titles( \$qa_second->posts ),\n"
            . "    'requestUrl'         => \$this->request()->url( [ 'level' => [ 'a', 'b' ] ] ),\n"
            . "];\n?>\n"
            . "<div class=\"qa-archive\">\n"
            . "    <h1 class=\"qa-archive-title\"><?php echo esc_html( is_tax() ? single_term_title( '', false ) : post_type_archive_title( '', false ) ); ?></h1>\n"
            . "    <p class=\"qa-found\" data-found=\"<?php echo (int) \$this->found(); ?>\"><?php echo (int) \$this->found(); ?></p>\n"
            . "    <?php echo \$this->content_blocks() ?: \$this->listing(); ?>\n"
            . "    <script type=\"application/json\" id=\"qa-state\"><?php echo wp_json_encode( \$qa_state, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>\n"
            . "</div>\n";
    }

    /** An archive theme fixture's listing.php: a card per row, the empty state, the page links. */
    protected static function archive_listing(): string {
        return <<<'PHP'
<?php
defined( 'ABSPATH' ) || exit;
?>
<div class="qa-list">
    <?php if ( $this->is_empty() ) : ?>
        <p class="qa-empty"><?php esc_html_e( 'Nothing here.', 'fabrement-site' ); ?></p>
    <?php else : ?>
        <?php foreach ( $this->loop() as $i => $post ) : ?>
            <article class="qa-card" data-id="<?php echo (int) $post->ID; ?>">
                <a href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
            </article>
        <?php endforeach; ?>

    <?php endif; ?>
</div>
PHP;
    }

    /** How many cards the listing drew. */
    protected static function cards( string $html ): int {
        return substr_count( $html, '<article class="qa-card"' );
    }
}
