<?php

namespace Fabrement\Framework;

/**
 * The interface face (Manrope, OFL), served from the plugin: no admin screen asks a font host for it.
 */
final class UiFonts {

    private const HANDLE = 'fabrement-ui-fonts';
    private const REL    = 'assets/fabrement-ui-fonts.css';

    /** Register + enqueue the @font-face sheet. Safe to call from every screen: WP de-duplicates. */
    public static function enqueue(): void {
        $path = App::path( self::REL );
        if ( ! is_readable( $path ) ) {
            return;
        }

        wp_enqueue_style( self::HANDLE, App::url( self::REL ), [], (string) filemtime( $path ) );
    }
}
