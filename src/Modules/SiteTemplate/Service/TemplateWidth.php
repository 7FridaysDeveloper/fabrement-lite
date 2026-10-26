<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;

/**
 * A full-width template: its sections reach the edges and stack flush, on the site and in the
 * editor alike. Section blocks hold their own inner column, so the frame adds none.
 *
 * A content-width template is left to the theme: its column, its gaps, its editor canvas.
 */
final class TemplateWidth {

    /** The CSS a full-width canvas adds; the editor's width switch adds and removes the same rules. */
    private const EDITOR_CSS = '.editor-styles-wrapper .wp-block{max-width:none;}.editor-styles-wrapper :where(.is-layout-flow) > *{margin-block-start:0;}';

    /** @var array{contentSize:string,wideSize:string,paddingLeft:string,paddingRight:string}|null The theme's own canvas, before it is widened. */
    private static ?array $theme = null;

    public static function init(): void {
        // Last, after the theme's global styles, so it wins them on source order.
        add_action( 'wp_enqueue_scripts', [ self::class, 'front_end_css' ], PHP_INT_MAX );
        add_filter( 'block_editor_settings_all', [ self::class, 'editor_settings' ], 10, 2 );
        // The editor takes the canvas layout from global styles, preloaded in the same request:
        // the editor settings alone lose to it.
        add_filter( 'wp_theme_json_data_theme', [ self::class, 'editor_theme_json' ] );
    }

    /** No gap between the sections of a full-width template. */
    public static function front_end_css(): void {
        $post = is_admin() ? null : get_queried_object();
        if ( ! $post instanceof \WP_Post || ! self::is_full( $post ) ) {
            return;
        }
        wp_register_style( 'fabrement-template-flow', false, wp_style_is( 'global-styles', 'registered' ) ? [ 'global-styles' ] : [], FABREMENT_BLOCKS_VERSION );
        wp_enqueue_style( 'fabrement-template-flow' );
        wp_add_inline_style( 'fabrement-template-flow', ':where(.is-layout-flow) > *{margin-block-start:0}' );
    }

    /**
     * @param array<string,mixed>      $settings
     * @param \WP_Block_Editor_Context $context  Its post is null in the site editor.
     * @return array<string,mixed>
     */
    public static function editor_settings( $settings, $context ) {
        $post = $context->post ?? null;
        if ( ! $post instanceof \WP_Post ) {
            return $settings;
        }
        if ( $post->post_type === SiteTemplatePostType::POST_TYPE ) {
            // What the width switch in the template editor returns the canvas to, without a reload.
            $settings['fabrementTemplateWidth'] = [ 'theme' => self::$theme ?? self::theme_canvas( \WP_Theme_JSON_Resolver::get_theme_data()->get_data() ), 'css' => self::EDITOR_CSS ];
        }
        if ( ! self::is_full( $post ) ) {
            return $settings;
        }
        $settings['__experimentalFeatures']                          ??= [];
        $settings['__experimentalFeatures']['layout']                ??= [];
        $settings['__experimentalFeatures']['layout']['contentSize'] = '100%';
        $settings['__experimentalFeatures']['layout']['wideSize']    = '100%';
        // Classic themes cap every top-level block at 840px in the editor; lift it, and drop the
        // gap between sections as the front end does.
        $settings['styles']   ??= [];
        $settings['styles'][]   = [ 'css' => self::EDITOR_CSS ];
        return $settings;
    }

    /**
     * @param \WP_Theme_JSON_Data $theme_json
     * @return \WP_Theme_JSON_Data
     */
    public static function editor_theme_json( $theme_json ) {
        if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
            return $theme_json;
        }
        $screen = get_current_screen();
        $post   = $screen && $screen->base === 'post' ? get_post() : null;
        if ( ! $post instanceof \WP_Post ) {
            return $theme_json;
        }
        self::$theme ??= self::theme_canvas( $theme_json->get_data() );
        if ( ! self::is_full( $post ) ) {
            return $theme_json;
        }
        return $theme_json->update_with(
            [
                'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
                'settings' => [ 'layout' => [ 'contentSize' => '100%', 'wideSize' => '100%' ] ],
                // No root padding: the sections touch the canvas edges, as on the front end.
                'styles'   => [ 'spacing' => [ 'padding' => [ 'left' => '0px', 'right' => '0px' ] ] ],
            ]
        );
    }

    /**
     * The content column and root padding a theme.json sets, as CSS values.
     *
     * @param array<string,mixed> $data
     * @return array{contentSize:string,wideSize:string,paddingLeft:string,paddingRight:string}
     */
    private static function theme_canvas( array $data ): array {
        // "var:preset|spacing|50" is theme.json's spelling of var(--wp--preset--spacing--50).
        $css = static fn( $value ): string => is_string( $value ) && str_starts_with( $value, 'var:' )
            ? 'var(--wp--' . str_replace( '|', '--', substr( $value, 4 ) ) . ')'
            : ( is_string( $value ) ? $value : '' );
        return [
            'contentSize'  => $css( $data['settings']['layout']['contentSize'] ?? '' ),
            'wideSize'     => $css( $data['settings']['layout']['wideSize'] ?? '' ),
            'paddingLeft'  => $css( $data['styles']['spacing']['padding']['left'] ?? '' ),
            'paddingRight' => $css( $data['styles']['spacing']['padding']['right'] ?? '' ),
        ];
    }

    /** Whether the template that frames this record (or is this record) is a full-width one. */
    private static function is_full( \WP_Post $post ): bool {
        $template = self::template_of( $post );
        return $template !== null && $template->content_width === 'full';
    }

    /** The template itself, the one the record picked, or its type's default. */
    private static function template_of( \WP_Post $post ): ?SiteTemplateRecord {
        if ( $post->post_type === SiteTemplatePostType::POST_TYPE ) {
            return SiteTemplateRegistry::find_any( (int) $post->ID );
        }
        $slug = (string) get_page_template_slug( $post->ID );
        if ( $slug !== '' && $slug !== 'default' ) {
            return SiteTemplateRegistry::find_by_slug( $slug );
        }
        $default = SiteTemplatePostType::default_template_id_for( $post->post_type );
        return $default > 0 ? SiteTemplateRegistry::find( $default ) : null;
    }
}
