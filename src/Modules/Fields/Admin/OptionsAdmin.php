<?php

namespace Fabrement\Modules\Fields\Admin;

use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\FieldRead;
use Fabrement\Modules\Translation\Translation;

/**
 * An options page's form in wp-admin: the same form as a record's "Fabrement fields" box. Each
 * options.<slug>.json is its own admin page (admin.php?page=fabrement-options-<slug>), kept out of
 * the menu and reached through the tabs of Fabrement → Site General Settings
 * ({@see \Fabrement\Page\SiteOptionsPage}).
 *
 * The form is the record form, mounted with the page's slug and language
 * (data-fabrement-options-fields): it talks to /fields/options/<slug> and says what an options
 * page is instead of what a record is.
 */
final class OptionsAdmin {

    public const MENU = 'fabrement-options';
    private const CAPABILITY = 'manage_options';

    /**
     * The page's address in wp-admin, or '' when the current user cannot open it.
     *
     * @param string $lang The language to open it in; '' = the original.
     */
    public static function url( string $slug, string $lang = '' ): string {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return '';
        }
        $url = admin_url( 'admin.php?page=' . self::menu_slug( $slug ) );
        return $lang !== '' ? add_query_arg( 'lang', rawurlencode( $lang ), $url ) : $url;
    }

    /** The language picked on this screen (?lang=), '' when none: what the other pages' tabs keep. */
    public static function picked_language(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation chooses the version; creation only initializes an absent row for an authorized administrator.
        $lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
        return $lang === 'all' ? '' : $lang;
    }

    /** The admin page slug of one options page: fabrement-options-<slug>. */
    public static function menu_slug( string $slug ): string {
        return self::MENU . '-' . $slug;
    }

    /**
     * The language on screen: ?lang= (the switch above the form and WPML's admin bar both set it),
     * else the original. Not the admin's remembered language: the menu entry then opens the
     * original, and another language only when one is picked.
     */
    private static function language(): string {
        $lang = self::picked_language();
        return $lang !== '' ? $lang : Translation::bridge()->default_language();
    }

    /** The form's scripts on the Site General Settings screen, for the options page on screen. */
    public static function enqueue_form( string $slug ): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        $object = FieldObject::options( $slug, self::language() );
        if ( ! $object ) {
            return;
        }
        EditorAssets::register();
        // What the block editor sets up and this screen has not, before the form loads:
        // - a spinner over the page while the form's requests run;
        // - the image and file pickers (MediaUpload) render the media library, which only the
        //   post editor wires in (`editor.MediaUpload`), and MediaUploadCheck shows them only
        //   where the block editor's settings carry `mediaUpload`.
        wp_register_script( 'fabrement-options-admin', false, [ 'wp-api-fetch', 'wp-hooks', 'wp-media-utils', 'wp-data', 'wp-block-editor' ], FABREMENT_BLOCKS_VERSION, true );
        wp_add_inline_script(
            'fabrement-options-admin',
            '(function(){var pending=0;'
                . 'var words=' . wp_json_encode( [ 'GET' => __( 'Loading…', 'fabrement-lite' ), 'PUT' => __( 'Saving…', 'fabrement-lite' ) ] ) . ';'
                . 'var spin=function(on,method){var o=document.querySelector(".fabrement-page-loading");if(!o)return;o.hidden=!on;'
                . 'var t=o.querySelector(".fabrement-page-loading__text");if(on&&t)t.textContent=words[method]||words.GET;};'
                . 'wp.apiFetch.use(function(options,next){'
                . 'if(!(options&&typeof options.path==="string"&&options.path.indexOf("/fabrement/v1/fields/options/")===0))return next(options);'
                . 'pending++;spin(true,String(options.method||"GET").toUpperCase());'
                . 'return next(options).finally(function(){if(--pending===0)spin(false);});});'
                . 'window.addEventListener("load",function(){if(pending===0)spin(false);});'
                . 'wp.hooks.addFilter("editor.MediaUpload","fabrement/options-media-upload",function(){return wp.mediaUtils.MediaUpload;});'
                . 'wp.data.dispatch("core/block-editor").updateSettings({mediaUpload:wp.mediaUtils.uploadMedia});})();'
        );
        $scripts = wp_scripts();
        if ( isset( $scripts->registered['fabrement-editor-plugin'] ) && ! in_array( 'fabrement-options-admin', $scripts->registered['fabrement-editor-plugin']->deps, true ) ) {
            $scripts->registered['fabrement-editor-plugin']->deps[] = 'fabrement-options-admin';
        }
        // The pickers show records in the language on screen, the one whose values are edited — not
        // the admin language WPML remembers, which the editor payload carries.
        if ( $object->lang !== '' ) {
            wp_add_inline_script( 'fabrement-editor-plugin', 'window.fabrementEditor = window.fabrementEditor || {}; window.fabrementEditor.currentLanguage = ' . wp_json_encode( $object->lang ) . ';', 'before' );
        }
        wp_enqueue_script( 'fabrement-editor-plugin' );
        wp_enqueue_style( 'fabrement-editor-plugin' );
        wp_enqueue_style( 'wp-components' );
        wp_enqueue_media();
    }

    /** The language switch, the form and its spinner for one options page (the tab on screen). */
    public static function render_form( array $page ): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        $object = FieldObject::options( $page['slug'], self::language() );
        if ( ! $object ) {
            echo '<p>' . esc_html__( 'This language is not one of the site\'s.', 'fabrement-lite' ) . '</p>';
            return;
        }
        self::language_switch( $page['slug'], $object->lang );
        echo '<div class="postbox fabrement-options-form"><div class="inside">';
        printf( '<div data-fabrement-options-fields="%s" data-fabrement-options-lang="%s"></div>', esc_attr( $page['slug'] ), esc_attr( $object->lang ) );
        self::spinner();
        $state = FieldRead::for_editor( $object );
        if ( is_array( $state ) ) {
            FieldsAdmin::print_state( 'options-' . $page['slug'], $state );
        }
        echo '</div></div>';
    }

    /**
     * The shared full-screen spinner (templates/partials/page-spinner.php): shown from the first
     * paint until the page has loaded, and while the form loads or saves — the apiFetch middleware
     * in enqueue_form() raises and drops it around the form's requests.
     */
    private static function spinner(): void {
        require \Fabrement\Framework\App::path( 'templates/partials/page-spinner.php' );
    }

    /** One link per language, the one on screen marked; nothing on a site with one language. */
    private static function language_switch( string $slug, string $current ): void {
        $languages = Translation::bridge()->languages();
        if ( ! $languages ) {
            return;
        }
        $default = Translation::bridge()->default_language();
        echo '<ul class="subsubsub" style="float:none">';
        $items = [];
        foreach ( $languages as $language ) {
            $url = add_query_arg( [ 'page' => self::menu_slug( $slug ), 'lang' => $language['code'] ], admin_url( 'admin.php' ) );
            $label = esc_html( $language['label'] ) . ( $language['code'] === $default ? ' <span class="count">(' . esc_html__( 'original', 'fabrement-lite' ) . ')</span>' : '' );
            $items[] = '<li>' . ( $language['code'] === $current
                ? '<strong>' . $label . '</strong>'
                : '<a href="' . esc_url( $url ) . '">' . $label . '</a>' ) . '</li>';
        }
        echo implode( ' | ', $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped above.
        echo '</ul>';
    }
}
