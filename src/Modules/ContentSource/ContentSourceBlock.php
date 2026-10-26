<?php

namespace Fabrement\Modules\ContentSource;

/** Existing template slot: page content or an existing, confined theme template. */
final class ContentSourceBlock {
    public const NAME = 'fabrement/content-source';
    private static bool $rendering = false;

    public static function register(): void {
        register_block_type( __DIR__ . '/block.json', [ 'render_callback' => [ self::class, 'render' ] ] );
    }

    /**
     * Returns markup this block does not build itself and adds nothing to: core's password form,
     * core's own core/post-content render, the posts page's blocks rendered by core, or the
     * output of a template file of the active theme (the theme escapes what it prints, as in any
     * theme template). Escaping it again here would break the blocks' and the theme's own HTML.
     */
    public static function render( array $attributes, string $content, \WP_Block $block ): string {
        if ( self::$rendering || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return ''; }
        self::$rendering = true;
        try {
            $context = \Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding::current_context();
            $queried = get_queried_object();
            if ( is_singular() && $queried instanceof \WP_Post && post_password_required( $queried ) ) { return get_the_password_form( $queried ); }
            if ( $context === '' && $queried instanceof \WP_Post && is_singular() ) { $context = 'singular:' . $queried->post_type; }
            foreach ( \Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding::context_chain( $context ) as $candidate ) {
                foreach ( self::rules_of( (int) ( $attributes['templateId'] ?? 0 ) ) as $rule ) {
                    if ( ! is_array( $rule ) || ( $rule['context'] ?? '' ) !== $candidate || ! is_string( $rule['file'] ?? null ) ) { continue; }
                    $file = ThemeTemplateFiles::resolve( $rule['file'] );
                    if ( $file === null || ! RenderKindHeader::fits( ThemeTemplateFiles::declares_of( $rule['file'] ), $context ) ) { continue; }
                    $rendered = self::theme_file( $file, $block );
                    if ( $rendered !== null ) { return $rendered; }
                }
            }
            if ( is_archive() || is_home() ) { return ArchiveOwnContent::render(); }
            if ( is_search() || is_404() ) { return ''; }
            $queried = get_queried_object();
            $id = $queried instanceof \WP_Post ? (int) $queried->ID : (int) ( $block->context['postId'] ?? 0 );
            if ( get_post_type( $id ) === 'fabrement_template' ) { return ''; }
            return self::content_of( $id, $attributes );
        } finally { self::$rendering = false; }
    }

    /**
     * The theme-file choices an administrator saved for this template (its shared JSON manifest).
     * Never the block's own markup: a slot pasted into a post only names a template, and then
     * that template's saved choices apply.
     *
     * @return list<array{context:string,file:string}>
     */
    private static function rules_of( int $template_id ): array {
        if ( $template_id <= 0 || get_post_type( $template_id ) !== \Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType::POST_TYPE ) {
            return [];
        }
        return \Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig::read( $template_id )['rules'];
    }

    /** Delegate password handling, block rendering and recursive-content protection to core. */
    public static function content_of( int $post_id, array $attributes = [] ): string {
        if ( $post_id <= 0 ) { return ''; }
        $block = new \WP_Block( [ 'blockName' => 'core/post-content', 'attrs' => $attributes ], [ 'postId' => $post_id, 'postType' => get_post_type( $post_id ) ] );
        return $block->render( [ 'dynamic' => true ] );
    }

    /**
     * The path has just been resolved beneath the active/parent theme's templates directory.
     * Loaded the way WordPress loads any theme template part (get_template_part()); the file
     * gets this block as `$args['block']`.
     */
    private static function theme_file( string $file, \WP_Block $block ): ?string {
        $previous = $GLOBALS['post'] ?? null;
        $level = ob_get_level();
        ob_start();
        try {
            load_template( $file, false, [ 'block' => $block ] );
            return (string) ob_get_contents();
        } catch ( \Throwable $error ) {
            \Fabrement\Framework\Log::debug( '[fabrement][templates] Theme content template failed: ' . $error->getMessage() );
            return null;
        } finally {
            while ( ob_get_level() > $level ) { ob_end_clean(); }
            $GLOBALS['post'] = $previous;
            if ( $previous instanceof \WP_Post ) { setup_postdata( $previous ); }
        }
    }
}
