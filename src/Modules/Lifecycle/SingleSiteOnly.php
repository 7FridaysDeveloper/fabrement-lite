<?php

namespace Fabrement\Modules\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrement runs on a single WordPress install, never on a network.
 *
 * What it stores is per install: the OAuth table is created from the current site's prefix, and
 * nothing in it was built or tested for a network.
 *
 * So on a network the plugin activates cleanly but does not boot, and only shows this notice.
 */
final class SingleSiteOnly {

    /** Whether this install is one Fabrement will run on at all. */
    public static function supported(): bool {
        return ! is_multisite();
    }

    public static function message(): string {
        return __(
            'Fabrement Lite does not support WordPress multisite, so it is inactive on this network. It stores its templates and connection credentials per install. Use it on a single-site WordPress install instead.',
            'fabrement-lite'
        );
    }

    /**
     * Tells an admin why the plugin does nothing on a network.
     *
     * Gated on manage_options, the same capability the other plugin notices use — not on
     * activate_plugins, which multisite additionally ties to a network-level permission, so a
     * site administrator who can see the plugin would be shown nothing.
     */
    public static function notice(): void {
        if ( self::supported() || ! current_user_can( 'manage_options' ) || ! NoticeScreen::here() ) {
            return;
        }

        printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( self::message() ) );
    }
}
