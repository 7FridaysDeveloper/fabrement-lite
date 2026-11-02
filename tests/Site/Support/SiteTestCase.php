<?php

namespace Fabrement\Tests\Site\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test on one of the real test sites. Every class starts from the site as it was right after
 * install (the snapshot); its tests run in order on that state, so each makes what it needs under
 * names of its own. After every test the site's debug.log must be empty: a notice the site raised
 * fails the test that caused it.
 *
 * In a test: $this->mcp( 'tool', [...] ) is the agent, $this->get( '/path' ) the visitor,
 * $this->site()->meta() / ->option() / ->rows() read the database, ->php( '...' ) is the site's own
 * code with no one signed in (cron, an import, WP-CLI).
 */
abstract class SiteTestCase extends TestCase {

    /** Which site: 'plain' (one language) or 'wpml' (en, fr, pl). */
    protected const SITE = 'plain';

    private ?McpClient $client = null;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        Site::get( static::SITE )->restore();
    }

    protected function tearDown(): void {
        $log = self::problems( $this->site()->debug_log() );
        $this->site()->exec( 'sh', '-c', 'rm -f /var/www/html/wp-content/debug.log' );
        if ( $log !== '' ) {
            $this->fail( "The site wrote to debug.log during this test:\n" . $log );
        }
        parent::tearDown();
    }

    /**
     * What in debug.log is a problem of this plugin's making: every warning, notice, fatal error and
     * database error — and a deprecation only from Fabrement's own code. A deprecation in WordPress
     * core or another plugin (core's class-ftp.php under PHP 8.2, loaded by WPML on every request)
     * is not this plugin's to fix, and would fail every test of that site.
     */
    protected static function problems( string $log ): string {
        $kept = [];
        foreach ( preg_split( '/\R/', $log ) as $line ) {
            if ( trim( $line ) === '' ) {
                continue;
            }
            if ( str_contains( $line, 'PHP Deprecated:' ) && ! str_contains( $line, '/plugins/fabrement/' ) ) {
                continue;
            }
            // Informational lines other plugins write there (WPML String Translation's status runs)
            // are no problem; a PHP error, a database error or anything Fabrement wrote is.
            $own = str_contains( $line, 'PHP ' ) || str_contains( $line, 'WordPress database error' ) || stripos( $line, 'fabrement' ) !== false;
            if ( ! $own && preg_match( '/^\[[^\]]+\] /', $line ) === 1 ) {
                continue;
            }
            $kept[] = $line;
        }
        return implode( "\n", $kept );
    }

    protected function site(): Site {
        return Site::get( static::SITE );
    }

    /**
     * Run an MCP tool as the agent. On success its answer; a refusal fails the test with the text
     * the agent saw (use mcp_error() when a refusal is the point).
     *
     * @return mixed
     */
    protected function mcp( string $tool, array $arguments = [] ) {
        $answer = $this->client()->call( $tool, $arguments );
        if ( $answer['error'] ) {
            $this->fail( "$tool refused: " . $answer['text'] );
        }
        return $answer['data'] ?? $answer['text'];
    }

    /** The message the agent reads when a tool refuses; fails when it does not refuse. */
    protected function mcp_error( string $tool, array $arguments = [] ): string {
        $answer = $this->client()->call( $tool, $arguments );
        if ( ! $answer['error'] ) {
            $this->fail( "$tool did not refuse: " . $answer['text'] );
        }
        return $answer['text'];
    }

    protected function client(): McpClient {
        return $this->client ??= new McpClient( $this->site() );
    }

    /** @return array{status:int, body:string, headers:array<string,string>} A page as a visitor gets it. */
    protected function get( string $path_or_url, array $headers = [] ): array {
        $url = str_starts_with( $path_or_url, 'https://' ) ? $path_or_url : $this->site()->url( $path_or_url );
        return Http::request( $this->site(), 'GET', $url, $headers );
    }

    // ── templates: set up through the services behind the Templates screen's REST routes ─────

    /** A template's frame as stored: a header global block, $before, the content slot, a footer global block. */
    protected static function frame( int $header = 0, int $footer = 0, string $before = '' ): string {
        return ( $header > 0 ? '<!-- wp:block {"ref":' . $header . '} /-->' : '' ) . $before
            . '<!-- wp:fabrement/content-source /-->'
            . ( $footer > 0 ? '<!-- wp:block {"ref":' . $footer . '} /-->' : '' );
    }

    /** One template service call on the site, with JSON-able arguments in order. */
    private function template_service( string $call, array $args ) {
        return $this->site_runs( sprintf( 'return \\Fabrement\\Modules\\SiteTemplate\\%s( ...json_decode( %s, true ) );', $call, var_export( json_encode( $args ), true ) ) );
    }

    /** Save a template as the Templates screen does. @return array Its summary, or [ 'error' => code ]. */
    protected function save_template( array $input ): array {
        return (array) $this->template_service( 'Service\\SiteTemplateWriter::save', [ $input ] );
    }

    /** A new template with these sides. @return int Its id. */
    protected function new_template( string $title, int $header = 0, int $footer = 0, string $before = '' ): int {
        $saved = $this->save_template( [ 'title' => $title, 'content' => self::frame( $header, $footer, $before ) ] );
        $this->assertArrayHasKey( 'id', $saved, (string) json_encode( $saved ) );
        return (int) $saved['id'];
    }

    /** A template's summary with how it is given out (pageCount, archiveContexts); [] when there is none. */
    protected function template_row( int $template ): array {
        return (array) $this->site_runs( sprintf(
            '$r = \\Fabrement\\Modules\\SiteTemplate\\Service\\SiteTemplateRegistry::find_any( %1$d ); if ( $r === null ) { return []; }'
            . ' return $r->to_summary( \\Fabrement\\Modules\\SiteTemplate\\PostType\\SiteTemplatePostType::type_defaults() )'
            . ' + [ "pageCount" => \\Fabrement\\Modules\\SiteTemplate\\Service\\SiteTemplatePages::counts()[ $r->slug ] ?? 0,'
            . ' "archiveContexts" => array_keys( array_filter( \\Fabrement\\Modules\\SiteTemplate\\Service\\ArchiveTemplateBinding::all(), static fn( $id ) => (int) $id === %1$d ) ) ];',
            $template
        ) );
    }

    /** Give pages a template, as the Templates screen does. */
    protected function assign_template( int $template, array $page_ids ): void {
        $this->site_runs( sprintf(
            'return \\Fabrement\\Modules\\SiteTemplate\\Service\\SiteTemplatePages::reconcile( \\Fabrement\\Modules\\SiteTemplate\\PostType\\SiteTemplatePostType::slug_for( %d ), json_decode( %s, true ), "add" );',
            $template,
            var_export( json_encode( array_values( $page_ids ) ), true )
        ) );
    }

    /** Make a template the default of a post type, as the Templates screen does. */
    protected function default_template( int $template, string $post_type ): void {
        $this->template_service( 'PostType\\SiteTemplatePostType::set_default_for', [ $post_type, $template ] );
    }

    /** Bind a template to an archive view, as the Templates screen does. */
    protected function bind_archive( string $context, int $template ): void {
        $this->assertTrue( (bool) $this->template_service( 'Service\\ArchiveTemplateBinding::set', [ $context, $template ] ), $context );
    }

    /** Delete a template, as the Templates screen does. @return mixed The pages it cleared, or [ 'error' => code ]. */
    protected function delete_template( int $template ) {
        return $this->template_service( 'Service\\SiteTemplateDeleter::delete', [ $template ] );
    }

    /** The address of an archive view: "blog-home", "archive:<postType>" or "tax:<taxonomy>:<termId>". */
    protected function archive_url( string $context ): string {
        $parts = explode( ':', $context );
        $code  = match ( $parts[0] ) {
            'blog-home' => '$page = (int) get_option( "page_for_posts" ); return $page > 0 ? get_permalink( $page ) : home_url( "/" );',
            'archive'   => 'return get_post_type_archive_link( ' . var_export( $parts[1] ?? '', true ) . ' );',
            default     => 'return get_term_link( ' . (int) ( $parts[2] ?? 0 ) . ', ' . var_export( $parts[1] ?? '', true ) . ' );',
        };
        $url = $this->site_runs( $code );
        $this->assertIsString( $url, $context );
        return $url;
    }

    /** Open a template's language version, as the Templates screen does. @return int Its id. */
    protected function translate_template( int $template, string $language ): int {
        $opened = (array) $this->template_service( 'Service\\SiteTemplateTranslator::open', [ $template, $language ] );
        $this->assertArrayHasKey( 'id', $opened, (string) json_encode( $opened ) );
        return (int) $opened['id'];
    }

    // ── what an agent does, through the tools ────────────────────────────────────────────────


    /**
     * A post type of the site, registered by its own code: Lite makes none. $extra takes singular,
     * plural, public, hasArchive, hierarchical, supports, rewrite, taxonomies, showInRest and, on a
     * site with languages, translationMode ("none" | "translate" | "fallback").
     */
    protected function post_type( string $slug, array $extra = [] ): void {
        $singular = (string) ( $extra['singular'] ?? ucfirst( str_replace( '_', ' ', $slug ) ) );
        $args     = [
            'labels'       => [ 'name' => (string) ( $extra['plural'] ?? $singular . 's' ), 'singular_name' => $singular ],
            'public'       => (bool) ( $extra['public'] ?? true ),
            'show_ui'      => true,
            'has_archive'  => (bool) ( $extra['hasArchive'] ?? true ),
            'hierarchical' => (bool) ( $extra['hierarchical'] ?? false ),
            'supports'     => (array) ( $extra['supports'] ?? [ 'title', 'editor', 'thumbnail' ] ),
            'show_in_rest' => (bool) ( $extra['showInRest'] ?? true ),
            'rewrite'      => [ 'slug' => (string) ( $extra['rewrite'] ?? $slug ), 'with_front' => false ],
            'taxonomies'   => (array) ( $extra['taxonomies'] ?? [] ),
        ];
        $this->site_code( 'qa-type-' . $slug, "add_action( 'init', static function () { register_post_type( " . var_export( $slug, true ) . ', ' . var_export( $args, true ) . ' ); }, 9 );' );

        // WPML's own setting, as its Settings screen writes it: 0 not translated, 1 translated, 2 shown as translated.
        $mode = [ 'none' => 0, 'translate' => 1, 'fallback' => 2 ][ $extra['translationMode'] ?? '' ] ?? null;
        $this->site_runs(
            ( $mode === null ? '' : 'global $sitepress; $sync = (array) $sitepress->get_setting( "custom_posts_sync_option", [] ); $sync[ ' . var_export( $slug, true ) . ' ] = ' . $mode . '; $sitepress->set_setting( "custom_posts_sync_option", $sync, true );' )
            . ' flush_rewrite_rules( false ); return true;'
        );
    }

    /**
     * A taxonomy of the site, registered by its own code before the post types. $extra takes
     * singular, plural, postTypes, hierarchical, public, rewrite and translationMode.
     */
    protected function taxonomy( string $slug, array $extra = [] ): void {
        $singular = (string) ( $extra['singular'] ?? ucfirst( str_replace( '_', ' ', $slug ) ) );
        $args     = [
            'labels'       => [ 'name' => (string) ( $extra['plural'] ?? $singular . 's' ), 'singular_name' => $singular ],
            'public'       => (bool) ( $extra['public'] ?? true ),
            'hierarchical' => (bool) ( $extra['hierarchical'] ?? false ),
            'show_in_rest' => true,
            'rewrite'      => [ 'slug' => (string) ( $extra['rewrite'] ?? $slug ), 'with_front' => false ],
        ];
        $this->site_code( 'qa-tax-' . $slug, "add_action( 'init', static function () { register_taxonomy( " . var_export( $slug, true ) . ', ' . var_export( (array) ( $extra['postTypes'] ?? [] ), true ) . ', ' . var_export( $args, true ) . ' ); }, 8 );' );

        $mode = [ 'none' => 0, 'translate' => 1, 'fallback' => 2 ][ $extra['translationMode'] ?? '' ] ?? null;
        $this->site_runs(
            ( $mode === null ? '' : 'global $sitepress; $sync = (array) $sitepress->get_setting( "taxonomies_sync_option", [] ); $sync[ ' . var_export( $slug, true ) . ' ] = ' . $mode . '; $sitepress->set_setting( "taxonomies_sync_option", $sync, true );' )
            . ' flush_rewrite_rules( false ); return true;'
        );
    }

    /** A record, made with save-content and published (or left as $status). @return int Its id. */
    protected function record( string $post_type, string $title, string $status = 'publish' ): int {
        $id = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => $post_type, 'title' => $title, 'blocks' => [] ] )['contentId'];
        if ( $status === 'publish' || $status === 'trash' ) {
            $this->mcp( 'set-content-status', [ 'contentId' => $id, 'status' => 'publish' ] );
        }
        if ( $status === 'trash' ) {
            $this->mcp( 'set-content-status', [ 'contentId' => $id, 'status' => 'trash' ] );
        }
        return $id;
    }

    /** An image uploaded to the media library, as an agent uploads one. @return int Its id. */
    protected function image( string $filename ): int {
        // A 1×1 PNG: real bytes, so the library takes it as an image.
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        return (int) $this->mcp( 'upload-media', [ 'base64' => $png, 'filename' => $filename, 'altText' => 'A test image, ' . $filename . '.' ] )['id'];
    }

    /** Code of the site itself — a rule another plugin or the theme would add — as a must-use plugin. */
    protected function site_code( string $name, string $php ): void {
        $this->site()->write( '/var/www/html/wp-content/mu-plugins/' . $name . '.php', "<?php
" . $php . "
" );
    }

    /**
     * Run code of the site itself (WordPress loaded, no user signed in: a cron job, an import, WP-CLI)
     * and get back what its body returns. A WP_Error comes back as [ 'error' => its code ].
     *
     * @return mixed
     */
    protected function site_runs( string $body ) {
        $out  = $this->site()->php( '$fabrement_result = ( static function () { ' . $body . ' } )();'
            . ' $fabrement_plain = static function ( $value ) use ( &$fabrement_plain ) {'
            . '     if ( is_wp_error( $value ) ) { return [ "error" => $value->get_error_code() ]; }'
            . '     return is_array( $value ) ? array_map( $fabrement_plain, $value ) : $value;'
            . ' };'
            . ' echo "\n@@" . wp_json_encode( $fabrement_plain( $fabrement_result ) );' );
        $json = substr( $out, (int) strrpos( $out, '@@' ) + 2 );
        return json_decode( $json, true );
    }

    /**
     * Save a template's theme-file choices the way wp-admin does: MCP cannot set them.
     *
     * @return mixed What the writer returns ([ 'rules' => … ], or [ 'error' => code ]).
     */
    protected function set_sources( int $template_id, array $rules ) {
        return $this->site_runs( sprintf(
            'return \\Fabrement\\Modules\\SiteTemplate\\Service\\ContentSourceConfig::write( %d, [ "rules" => json_decode( %s, true ) ] );',
            $template_id,
            var_export( json_encode( $rules ), true )
        ) );
    }

    /** @return array[] The JSON-LD nodes of every ld+json block in $html. */
    protected static function json_ld( string $html ): array {
        $nodes = [];
        preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#s', $html, $m );
        foreach ( $m[1] as $json ) {
            $data = json_decode( html_entity_decode( $json ), true );
            foreach ( (array) ( $data['@graph'] ?? [ $data ] ) as $node ) {
                if ( is_array( $node ) ) {
                    $nodes[] = $node;
                }
            }
        }
        return $nodes;
    }

    /** @param array[] $nodes @return array|null The first node of that @type. */
    protected static function node( array $nodes, string $type ): ?array {
        foreach ( $nodes as $node ) {
            if ( in_array( $type, (array) ( $node['@type'] ?? [] ), true ) ) {
                return $node;
            }
        }
        return null;
    }
}
