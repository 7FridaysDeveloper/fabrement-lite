<?php

namespace Fabrement\Modules\Lifecycle;

/**
 * Fabrement runs on a single WordPress install, never on a network.
 *
 * What it stores is per install: the OAuth table is created from the current site's prefix, and
 * nothing in it was built or tested for a network.
 *
 * So the plugin refuses the situation outright instead of half-working in it. Two guards, because
 * there are two ways in: activating it on a network, and a single site that is turned into one
 * later (or a network activation, where the activation hook runs once but the plugin still loads
 * for every site).
 */
final class SingleSiteOnly {

    /** Whether this install is one Fabrement will run on at all. */
    public static function supported(): bool {
        return ! is_multisite();
    }

    /** The one sentence every guard says, so the reason never differs between them. */
    public static function message(): string {
        return __(
            'Fabrement Lite does not support WordPress multisite. It stores its templates, field definitions and connection credentials per install, so it cannot run on a network. Use it on a single-site WordPress install instead.',
            'fabrement-lite'
        );
    }

    /**
     * Stops activation on a network. Deactivates first so WordPress does not record the plugin as
     * active behind the wp_die() screen — without that, a network admin who hits Back sees it
     * listed as active while none of its hooks ever run.
     */
    public static function refuse_activation(): void {
        if ( self::supported() ) {
            return;
        }

        if ( function_exists( 'deactivate_plugins' ) ) {
            deactivate_plugins( plugin_basename( FABREMENT_BLOCKS_FILE ) );
        }

        wp_die(
            esc_html( self::message() ),
            esc_html__( 'Fabrement Lite cannot be activated', 'fabrement-lite' ),
            [ 'back_link' => true, 'response' => 200 ]
        );
    }

    /**
     * Tells an admin why nothing is there, for an install that got here without activating.
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
