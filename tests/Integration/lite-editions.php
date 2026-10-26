<?php
if (!defined('WP_CLI') || !WP_CLI || !str_starts_with(DB_NAME,'fabrement_lite_verify_') || wp_normalize_path(realpath(ABSPATH)) !== 'C:/7fridays/fabrement-fields/runtime/lite-clean-install') { exit(1); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$lite = 'fabrement-lite/fabrement-lite.php';
$pro = 'fabrement-pro-fixture/fabrement.php';
$phase = $args[0] ?? '';
$assert = static function($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
if ($phase === 'preflight') {
    $result = activate_plugin($pro);
    $assert(is_wp_error($result) && $result->get_error_code() === 'fabrement_edition_conflict','Pro activation blocked before code collision');
    $assert(!defined('FABREMENT_TEST_OTHER_EDITION') && is_plugin_active($lite) && !is_plugin_active($pro),'Failed activation leaves Lite working');
    update_option('active_plugins',[$lite,$pro]);
} elseif ($phase === 'lite-first' || $phase === 'pro-first') {
    $assert(defined('FABREMENT_TEST_OTHER_EDITION'),'Other edition booted with both editions marked active');
    $assert(!class_exists('Fabrement\\Framework\\App',false),'Lite shared runtime did not load');
    update_option('active_plugins',$phase === 'lite-first' ? [$pro,$lite] : [$pro]);
} elseif ($phase === 'reverse') {
    $die = static function() { return static function($message) { throw new RuntimeException(strip_tags($message)); }; };
    add_filter('wp_die_handler',$die,PHP_INT_MAX);
    $refused = false;
    try { activate_plugin($lite); } catch (RuntimeException $e) { $refused = str_contains($e->getMessage(),'Another Fabrement edition is active'); }
    remove_filter('wp_die_handler',$die,PHP_INT_MAX);
    $assert($refused && !is_plugin_active($lite),'Lite activation refused while other edition active');
    // Pro's installed copy owns shared state even while inactive.
    update_option('active_plugins',[]);
    global $wpdb;
    $before = $wpdb->get_col('SHOW TABLES');
    update_option('fabrement_archive_pages',['qa'=>'preserved']);
    define('WP_UNINSTALL_PLUGIN',$lite);
    require WP_PLUGIN_DIR . '/' . dirname($lite) . '/uninstall.php';
    $assert($before === $wpdb->get_col('SHOW TABLES') && get_option('fabrement_archive_pages') === ['qa'=>'preserved'],'Uninstall preserves shared state while other edition installed');
    delete_option('fabrement_archive_pages');
    update_option('active_plugins',[$lite]);
} else { throw new RuntimeException('Unknown test phase'); }
WP_CLI::success('Edition guard: ' . $phase);
