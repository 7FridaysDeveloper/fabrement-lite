<?php
/**
 * WPML's setup wizard, done by code on the freshly installed WPML test site: en (default), fr, pl,
 * languages in directories (/fr/), pages / posts / global blocks / categories translatable, the
 * manual translation editor, String Translation's tables and every plugin's wpml-config.xml.
 * Run by install.sh inside the container, as wp-admin (WPML loads its admin classes there).
 */
define( 'WP_ADMIN', true );
define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST']   = 'example.org';
$_SERVER['SERVER_NAME'] = 'example.org';
$_SERVER['REQUEST_URI'] = '/wp-admin/';
$_SERVER['HTTPS']       = 'on';
$PHP_SELF               = $GLOBALS['PHP_SELF'] = $_SERVER['PHP_SELF'] = '/wp-admin/index.php';
require '/var/www/wp-config.php';

$fail = static function ( string $why ): void {
    fwrite( STDERR, "WPML setup: $why\n" );
    exit( 1 );
};
if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || ! isset( $GLOBALS['sitepress'] ) ) {
    $fail( 'WPML did not load.' );
}
global $sitepress, $wpdb;
wp_set_current_user( 1 );

$installation = wpml_get_setup_instance();
$installation->finish_step1( 'en' );
$installation->finish_step2( [ 'en', 'fr', 'pl' ] );
$installation->finish_step3();

$sitepress->set_setting( 'language_negotiation_type', 1, true );   // directories: /fr/…
$urls                                    = (array) $sitepress->get_setting( 'urls', [] );
$urls['directory_for_default_language'] = 0;
$sitepress->set_setting( 'urls', $urls, true );

$types = (array) $sitepress->get_setting( 'custom_posts_sync_option', [] );
foreach ( [ 'post', 'page', 'wp_block' ] as $type ) {
    $types[ $type ] = 1;
}
$sitepress->set_setting( 'custom_posts_sync_option', $types, true );
$taxonomies = (array) $sitepress->get_setting( 'taxonomies_sync_option', [] );
foreach ( [ 'category', 'post_tag' ] as $taxonomy ) {
    $taxonomies[ $taxonomy ] = 1;
}
$sitepress->set_setting( 'taxonomies_sync_option', $taxonomies, true );
$tm                           = (array) $sitepress->get_setting( 'translation-management', [] );
$tm['doc_translation_method'] = 0;   // the manual editor, which translate-content writes into
$sitepress->set_setting( 'translation-management', $tm, true );

$installation->finish_installation();

if ( class_exists( 'WPML_Package_Translation_Schema' ) ) {
    WPML_Package_Translation_Schema::run_update();
}
if ( class_exists( 'WPML_ST_Upgrade' ) ) {
    ( new WPML_ST_Upgrade( $sitepress, new WPML_ST_Upgrade_Command_Factory( $wpdb, $sitepress ) ) )->run();
}
if ( function_exists( 'wpml_load_core_tm' ) ) {
    wpml_load_core_tm();
}
WPML_Config::load_config_run();

wp_cache_flush();
$settings = get_option( 'icl_sitepress_settings' );
$active   = $wpdb->get_col( "SELECT code FROM {$wpdb->prefix}icl_languages WHERE active = 1 ORDER BY code" );
if ( empty( $settings['setup_complete'] ) || $active !== [ 'en', 'fr', 'pl' ] || ( $settings['default_language'] ?? '' ) !== 'en' ) {
    $fail( 'not a three-language site: ' . implode( ',', $active ) );
}
