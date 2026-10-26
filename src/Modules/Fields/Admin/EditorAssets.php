<?php
namespace Fabrement\Modules\Fields\Admin;

use Fabrement\Framework\App;
use Fabrement\Modules\Translation\Translation;

/** Registers the shared editor bundle used by the fields box and the template editor (Content Source). */
final class EditorAssets {
    public static function register(): void {
        if ( wp_script_is( 'fabrement-editor-plugin', 'registered' ) ) {
            return;
        }
        $rel = 'assets/editor-plugin/build/editor-plugin.js';
        $path = App::path( $rel );
        if ( ! is_readable( $path ) ) { return; }
        wp_register_script( 'fabrement-editor-plugin', App::url( $rel ), [ 'wp-element', 'wp-components', 'wp-blocks', 'wp-block-editor', 'wp-data', 'wp-editor', 'wp-plugins', 'wp-i18n', 'wp-hooks', 'wp-api-fetch' ], (string) filemtime( $path ), true );
        wp_set_script_translations( 'fabrement-editor-plugin', 'fabrement-lite', App::path( 'languages' ) );
        $sheet = 'assets/editor-plugin/build/editor-plugin.css';
        if ( is_readable( App::path( $sheet ) ) ) {
            wp_register_style( 'fabrement-editor-plugin', App::url( $sheet ), [ 'wp-components' ], (string) filemtime( App::path( $sheet ) ) );
        }
        $bridge = Translation::bridge();
        $post = get_post();
        $language = $post && Translation::is_multilingual()
            ? Translation::live_language_of( (int) $post->ID, (string) $post->post_type )
            : $bridge->current_language();
        wp_add_inline_script( 'fabrement-editor-plugin', 'window.fabrementEditor = ' . ( wp_json_encode( [
            'languages' => $bridge->languages(), 'currentLanguage' => $language,
            'themeTemplates' => is_admin() && current_user_can( 'manage_options' ) ? \Fabrement\Modules\ContentSource\ThemeTemplateFiles::all() : [],
        ] ) ?: '{}' ) . ';', 'before' );
    }
}
