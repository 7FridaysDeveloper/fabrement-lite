<?php
namespace Fabrement\Modules\SiteTemplate;

use Fabrement\Framework\App;

/** Registers the editor bundle of the template editor: Content Source and the template panels. */
final class EditorAssets {
    public static function register(): void {
        if ( wp_script_is( 'fabrement-editor-plugin', 'registered' ) ) {
            return;
        }
        $rel = 'assets/editor-plugin/build/editor-plugin.js';
        $path = App::path( $rel );
        if ( ! is_readable( $path ) ) { return; }
        wp_register_script( 'fabrement-editor-plugin', App::url( $rel ), [ 'wp-element', 'wp-components', 'wp-blocks', 'wp-block-editor', 'wp-data', 'wp-editor', 'wp-plugins', 'wp-i18n', 'wp-hooks', 'wp-api-fetch' ], (string) filemtime( $path ), true );
        wp_set_script_translations( 'fabrement-editor-plugin', 'fabrement-lite' );
        wp_add_inline_script( 'fabrement-editor-plugin', 'window.fabrementEditor = ' . ( wp_json_encode( [
            'themeTemplates' => is_admin() && current_user_can( \Fabrement\Capabilities::TEMPLATES ) ? \Fabrement\Modules\ContentSource\ThemeTemplateFiles::all() : [],
        ] ) ?: '{}' ) . ';', 'before' );
    }
}
