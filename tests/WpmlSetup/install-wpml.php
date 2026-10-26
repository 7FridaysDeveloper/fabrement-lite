<?php
/**
 * What the WPML setup wizard does, done by code on the fresh test database — in processes of its
 * own, before the tests load WordPress: WPML decides what to switch on (set up or not, the URL
 * converter, the admin or front-end classes) at the moment sitepress.php is loaded.
 *
 *   php install-wpml.php <config> reset      an empty database (the library drops only WordPress's
 *                                             own tables, WPML's would stay)
 *   php install-wpml.php <config> activate   the plugins go into `active_plugins`
 *   php install-wpml.php <config> setup      WPML's tables, en (default) + fr + pl, the URL mode,
 *                                             translatable types, the manual editor, wpml-config.xml
 *
 * Run by tests/WpmlSetup/bootstrap.php; exits non-zero with the reason when the result is not a
 * working three-language site.
 */
if ( PHP_SAPI !== 'cli' || ! isset( $argv[1], $argv[2] ) ) {
    exit( 1 );
}
$step = $argv[2];

if ( $step === 'reset' ) {
    require $argv[1];
    [ $host, $port ] = array_pad( explode( ':', DB_HOST, 2 ), 2, '3306' );
    $db = @mysqli_connect( $host, DB_USER, DB_PASSWORD, '', (int) $port );
    if ( ! $db ) {
        fwrite( STDERR, 'No database at ' . DB_HOST . " — composer test:wpml:db.
" );
        exit( 1 );
    }
    mysqli_query( $db, 'DROP DATABASE IF EXISTS `' . DB_NAME . '`' );
    mysqli_query( $db, 'CREATE DATABASE `' . DB_NAME . '` DEFAULT CHARACTER SET utf8mb4' );
    mysqli_close( $db );
    exit( 0 );
}

define( 'DISABLE_WP_CRON', true );
if ( $step === 'setup' ) {
    // The wizard runs in wp-admin: WPML loads its admin and Translation Management classes there.
    define( 'WP_ADMIN', true );
}
$_SERVER['HTTP_HOST']   = 'example.org';
$_SERVER['SERVER_NAME'] = 'example.org';
$_SERVER['REQUEST_URI'] = $step === 'setup' ? '/wp-admin/' : '/';
$_SERVER['SERVER_PORT'] = '80';
$PHP_SELF               = $GLOBALS['PHP_SELF'] = $_SERVER['PHP_SELF'] = $step === 'setup' ? '/wp-admin/index.php' : '/index.php';

require $argv[1];
require ABSPATH . 'wp-settings.php';

$fail = static function ( string $why ): void {
    fwrite( STDERR, "WPML setup: $why\n" );
    exit( 1 );
};

if ( $step === 'activate' ) {
    $plugins = require __DIR__ . '/plugins.php';
    foreach ( $plugins as $file ) {
        if ( ! is_file( WP_PLUGIN_DIR . '/' . $file ) ) {
            $fail( 'missing ' . WP_PLUGIN_DIR . '/' . $file . ' — run composer test:wpml:runtime.' );
        }
    }
    update_option( 'active_plugins', $plugins );
    exit( 0 );
}

if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || ! isset( $GLOBALS['sitepress'] ) ) {
    $fail( 'WPML did not load.' );
}
global $sitepress, $wpdb;

// What activation would do: the tables of each plugin.
icl_sitepress_activate();
if ( function_exists( 'wpseo_activate' ) ) {
    wpseo_activate( false );
}
if ( function_exists( 'wpcf7_install' ) ) {
    wpcf7_install();
}

// The wizard: default language, active languages, then done.
$installation = wpml_get_setup_instance();
$installation->finish_step1( 'en' );
$installation->finish_step2( [ 'en', 'fr', 'pl' ] );
$installation->finish_step3();

// Pretty permalinks, as on a real site: WPML puts the language in them (/fr/…).
update_option( 'permalink_structure', '/%postname%/' );

$mode = [ 'directory' => 1, 'domain' => 2, 'parameter' => 3 ][ FABREMENT_WPML_URL_MODE ] ?? 1;
$sitepress->set_setting( 'language_negotiation_type', $mode, true );
$urls                                    = (array) $sitepress->get_setting( 'urls', [] );
$urls['directory_for_default_language'] = 0;
$sitepress->set_setting( 'urls', $urls, true );
if ( $mode === 2 ) {
    $sitepress->set_setting( 'language_domains', [ 'fr' => 'fr.example.org', 'pl' => 'pl.example.org' ], true );
}

// Pages, posts, global blocks and categories translatable; the manual editor, which is what
// translate-content writes into (WpmlBridge::writes_translations_manually).
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
$tm['doc_translation_method'] = 0;
$sitepress->set_setting( 'translation-management', $tm, true );

$installation->finish_installation();

// String Translation's own tables (strings packages, batches, …): what its upgrade does on the next
// admin page after activation.
if ( class_exists( 'WPML_Package_Translation_Schema' ) ) {
    WPML_Package_Translation_Schema::run_update();
}
if ( class_exists( 'WPML_ST_Upgrade' ) ) {
    ( new WPML_ST_Upgrade( $sitepress, new WPML_ST_Upgrade_Command_Factory( $wpdb, $sitepress ) ) )->run();
}

// Every active plugin's wpml-config.xml into WPML's settings (custom fields, post types, blocks).
if ( function_exists( 'wpml_load_core_tm' ) ) {
    wpml_load_core_tm();
}
WPML_Config::load_config_run();

// Is it a working three-language site?
wp_cache_flush();
$settings = get_option( 'icl_sitepress_settings' );
$active   = $wpdb->get_col( "SELECT code FROM {$wpdb->prefix}icl_languages WHERE active = 1 ORDER BY code" );
if ( empty( $settings['setup_complete'] ) ) {
    $fail( 'setup_complete is not set.' );
}
if ( $active !== [ 'en', 'fr', 'pl' ] ) {
    $fail( 'active languages are ' . implode( ',', $active ) . ', not en,fr,pl.' );
}
if ( ( $settings['default_language'] ?? '' ) !== 'en' ) {
    $fail( 'default language is ' . ( $settings['default_language'] ?? '?' ) . '.' );
}
$fields = (array) ( $settings['translation-management']['custom_fields_translation'] ?? [] );
if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}icl_string_packages'" ) ) {
    $fail( 'String Translation tables are missing (icl_string_packages).' );
}
if ( ! isset( $fields['_yoast_wpseo_title'] ) ) {
    $fail( 'wpml-config.xml of the plugins was not read (no _yoast_wpseo_title in custom_fields_translation).' );
}
echo 'WPML ' . ICL_SITEPRESS_VERSION . ' set up: en (default), fr, pl; URL mode ' . FABREMENT_WPML_URL_MODE . ".\n";
exit( 0 );
