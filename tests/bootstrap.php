<?php

// Load only core value objects, capability mapping and REST controllers, never wp-load.php.
if ( PHP_SAPI !== 'cli' ) {
    exit;
}

$wp_root = getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 4 );
if ( ! is_file( $wp_root . '/wp-includes/capabilities.php' ) ) {
    throw new RuntimeException( 'Set WP_ROOT to a WordPress checkout. No database or site setup is needed.' );
}
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ABSPATH', rtrim( $wp_root, '/\\' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DB_NAME', 'fabrement_tests' );

foreach ( [
    'compat.php', 'class-wp-error.php', 'class-wp-user.php', 'class-wp-post.php', 'capabilities.php',
    'class-wp-http-response.php', 'rest-api/class-wp-rest-response.php',
    'rest-api/class-wp-rest-request.php', 'rest-api/endpoints/class-wp-rest-controller.php',
    'rest-api/fields/class-wp-rest-meta-fields.php', 'rest-api/fields/class-wp-rest-post-meta-fields.php',
    'rest-api/endpoints/class-wp-rest-posts-controller.php',
] as $file ) {
    require $wp_root . '/wp-includes/' . $file;
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/Api/helpers.php';
require __DIR__ . '/Support/WordPressState.php';
require __DIR__ . '/Support/OAuthDatabase.php';
require __DIR__ . '/Support/BlockImportBoundaries.php';

function __( $text, $domain = null ) { return $text; }
// Admin screen for notices: a test sets $GLOBALS['current_screen'] to an object with an id.
function get_current_screen() { return $GLOBALS['current_screen'] ?? null; }

// Filesystem helpers the block file writer leans on. Real WP normalises separators and
// creates parent directories; nothing else about them matters to a test.
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
    return [ 'basedir' => ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : sys_get_temp_dir() . '/fabrement-tests-' . getmypid() ) . '/uploads' ];
}
function wp_is_writable( $path ) { return is_writable( $path ); }
function wp_delete_file( $file ) {
    $file = apply_filters( 'wp_delete_file', $file );
    return ! empty( $file ) && @unlink( $file );
}
// Core's filesystem calls these; mbstring function overloading was removed in PHP 8.
function mbstring_binary_safe_encoding( $reset = false ) {}
function reset_mbstring_encoding() {}
function esc_html__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( $hook, ...$args ) {}
function did_action( $hook ) { return 0; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function has_filter( ...$args ) { return false; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function is_multisite() { return Fabrement\Tests\WordPressState::$multisite; }
function wp_is_file_mod_allowed( $context ) { return Fabrement\Tests\WordPressState::$file_mods; }
function wp_get_current_user() { return Fabrement\Tests\WordPressState::$user; }
function get_current_user_id() { return wp_get_current_user()->ID; }
function get_userdata( $id ) { return wp_get_current_user()->ID === $id ? wp_get_current_user() : false; }
function rest_authorization_required_code() { return get_current_user_id() ? 403 : 401; }
function wp_get_registered_image_subsizes() { return array_fill_keys( [ 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ], [] ); }
function get_post_type_object( $type ) { return Fabrement\Tests\WordPressState::$types[ $type ] ?? null; }
function get_post( $id = null ) { $id = $id ?? ( $GLOBALS['post'] ?? null ); return $id instanceof WP_Post ? $id : ( $id === null ? null : ( Fabrement\Tests\WordPressState::$posts[ $id ] ?? null ) ); }
function is_post_type_hierarchical( $type ) { return (bool) ( Fabrement\Tests\WordPressState::$types[ $type ]->hierarchical ?? false ); }
// Core's get_post_ancestors(), line for line over the stubbed posts: parent first, root last;
// nothing for a post that is its own parent; stops BEFORE the starting post or a repeat when the
// data already holds a loop.
function get_post_ancestors( $id ) {
    $post = get_post( $id );
    if ( ! $post || empty( $post->post_parent ) || (int) $post->post_parent === (int) $post->ID ) {
        return [];
    }
    $ancestors = [];
    $ancestors[] = (int) $post->post_parent;
    $next        = (int) $post->post_parent;
    while ( $ancestor = get_post( $next ) ) {
        $up = (int) ( $ancestor->post_parent ?? 0 );
        if ( $up === 0 || $up === (int) $post->ID || in_array( $up, $ancestors, true ) ) {
            break;
        }
        $ancestors[] = $up;
        $next        = $up;
    }
    return $ancestors;
}
// A record's type, read straight off the stubbed post — what the caption helper asks for when a
// link is named after the page it points at. (get_the_title lives in BlockImportBoundaries.)
function get_post_type( $id = null ) { $p = get_post( $id ); return $p ? ( $p->post_type ?? 'post' ) : false; }
// Whether a record may be shown to a visitor — what the link helpers ask before naming a record
// or sending anyone to it. Core answers from the registered status object
// (`publicly_queryable || (_builtin && public)`), which among the built-in statuses is true for
// `publish` ALONE: `future` is scheduled and 404s for a logged-out reader, `private` is private,
// and the rest are drafts. A stub that were any more generous would let a test pass on behaviour
// the site does not have.
function get_post_status( $id = null ) { $p = get_post( $id ); return $p ? ( $p->post_status ?? 'publish' ) : false; }
function is_post_publicly_viewable( $id = null ) { return get_post_status( $id ) === 'publish'; }
function get_permalink( $post = 0 ) { $p = get_post( $post ); return $p ? 'https://example.org/?p=' . $p->ID : false; }
function get_post_field( $field, $id = null ) { $p = get_post( $id ); return $p ? ( $p->$field ?? '' ) : ''; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_user_logged_in() { return false; }
function is_preview() { return false; }
function get_post_status_object( $status ) { return (object) [ 'public' => $status === 'publish' ]; }
function register_post_type( $name, $args ) {
    $args['name'] = $name;
    $args['cap'] = (object) $args['capabilities'];
    Fabrement\Tests\WordPressState::$types[ $name ] = (object) $args;
}
function wp_die( $message ) { throw new RuntimeException( $message ); }
function check_admin_referer( ...$args ) {
    if ( ! Fabrement\Tests\WordPressState::$valid_nonce ) { wp_die( 'Invalid nonce.' ); }
}
function wp_is_application_passwords_available_for_user( $user ) { return Fabrement\Tests\WordPressState::$passwords_available; }
function wp_date( $format, $timestamp = null, $timezone = null ) {
    if ( $timestamp === null ) { return '2026-09-14 12:00'; }
    return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?? wp_timezone() )->format( $format );
}
function wp_timezone() { return new DateTimeZone( Fabrement\Tests\WordPressState::$options['timezone_string'] ?? 'UTC' ); }
function get_bloginfo( $field ) { return Fabrement\Tests\WordPressState::$options['blogname'] ?? 'Example Studio'; }
function rest_url( $route ) { return 'https://example.test/subsite/wp-json/' . $route; }
// The default prefix, and it must agree with the one inside rest_url() above: the MCP route guard
// compares a REQUEST_URI against '/' . rest_get_url_prefix() . '/' . $route, so a different value
// here would make the guard answer false for the very URL the other stub says it serves.
function rest_get_url_prefix() { return 'wp-json'; }
function home_url( $path = '' ) { return ( Fabrement\Tests\WordPressState::$options['home'] ?? 'https://example.test/subsite' ) . $path; }
function is_ssl() { return true; }
// Mirror WordPress URL normalization without loading http.php, whose network helpers are stubbed.
function wp_parse_url( $url, $component = -1 ) {
    $url = (string) $url;
    $unset = [];
    if ( str_starts_with( $url, '//' ) ) {
        $url = 'placeholder:' . $url;
        $unset = [ 'scheme' ];
    } elseif ( str_starts_with( $url, '/' ) ) {
        $url = 'placeholder://placeholder' . $url;
        $unset = [ 'scheme', 'host' ];
    }
    $parts = parse_url( $url );
    if ( false === $parts ) { return false; }
    foreach ( $unset as $key ) { unset( $parts[ $key ] ); }
    if ( -1 === $component ) { return $parts; }
    $keys = [ PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PORT => 'port',
        PHP_URL_USER => 'user', PHP_URL_PASS => 'pass', PHP_URL_PATH => 'path',
        PHP_URL_QUERY => 'query', PHP_URL_FRAGMENT => 'fragment' ];
    return isset( $keys[ $component ] ) ? ( $parts[ $keys[ $component ] ] ?? null ) : null;
}
function wp_safe_remote_get( $url, $args = [] ) { return wp_remote_get( $url, $args ); }
function wp_set_current_user( $id ) {
    if ( get_current_user_id() !== $id ) { Fabrement\Tests\WordPressState::select_user( $id, [] ); }
    return wp_get_current_user();
}
function admin_url( $route ) { return 'https://example.test/subsite/wp-admin/' . $route; }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_check_invalid_utf8( $value ) { return (string) $value; }
if ( ! function_exists( '_prime_post_caches' ) ) { function _prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ) {} }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $value ) { return trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $value ) ) ); } }
require_once $wp_root . '/wp-includes/kses.php';
function wp_allowed_protocols() { return [ 'http', 'https', 'ftp', 'mailto', 'tel', 'sms' ]; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_safe_redirect( $url ) { throw new Fabrement\Tests\TestRedirect( $url ); }
function set_transient( $key, $value, $ttl ) {
    Fabrement\Tests\WordPressState::$transients[ $key ] = [ 'value' => $value, 'ttl' => $ttl ];
    return true;
}
function get_transient( $key ) { return Fabrement\Tests\WordPressState::$transients[ $key ]['value'] ?? false; }
function delete_transient( $key ) { unset( Fabrement\Tests\WordPressState::$transients[ $key ] ); }
if ( ! function_exists( 'content_url' ) ) {
    function content_url( $path = '' ) { return 'https://example.test/wp-content' . ( $path !== '' ? '/' . ltrim( (string) $path, '/' ) : '' ); }
}
function update_option( $key, $value, $autoload = null ) {
    if ( in_array( $key, Fabrement\Tests\WordPressState::$failed_options, true ) ) { return false; }
    Fabrement\Tests\WordPressState::$options[ $key ] = $value;
    return true;
}
function delete_option( $key ) { unset( Fabrement\Tests\WordPressState::$options[ $key ] ); return true; }
function add_option( $key, $value = '', $deprecated = '', $autoload = null ) {
    if ( array_key_exists( $key, Fabrement\Tests\WordPressState::$options ) ) { return false; }
    Fabrement\Tests\WordPressState::$options[ $key ] = $value;
    return true;
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
// Real WP keeps network options in their own table; one store is faithful enough here. A
// capability check under multisite reaches this on its way to the super-admin list.
function get_site_option( $key, $default = false ) { return Fabrement\Tests\WordPressState::$options[ $key ] ?? $default; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_next_scheduled( $hook ) { return Fabrement\Tests\WordPressState::$scheduled[ $hook ] ?? false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
    if ( Fabrement\Tests\WordPressState::$fail_schedule ) { return false; }
    Fabrement\Tests\WordPressState::$scheduled[ $hook ] = $timestamp;
    return true;
}
function get_post_types( $args = [], $output = 'names' ) {
    $types = [];
    foreach ( [ 'post' => true, 'page' => true, 'attachment' => true, 'revision' => false ] as $name => $public ) {
        $types[ $name ] = $output === 'objects' ? (object) [ 'name' => $name, 'public' => $public, '_builtin' => true ] : $name;
    }
    return $types;
}
if ( ! function_exists( 'get_taxonomies' ) ) {
    function get_taxonomies( $args = [], $output = 'names' ) {
        $taxonomies = [];
        foreach ( [ 'category' => true, 'post_tag' => true, 'nav_menu' => false ] as $name => $public ) {
            $taxonomies[ $name ] = $output === 'objects' ? (object) [ 'name' => $name, 'public' => $public, '_builtin' => true ] : $name;
        }
        return $taxonomies;
    }
}
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public array $posts = [];
        public array $query_vars = [];
        public function get( $key, $default = '' ) { return $this->query_vars[ $key ] ?? $default; }
        public function set( $key, $value ) { $this->query_vars[ $key ] = $value; }
        public function __construct( array $args = [] ) {
            $statuses = (array) ( $args['post_status'] ?? [ 'publish' ] );
            $readable = ( $args['perm'] ?? '' ) === 'readable';
            foreach ( array_values( array_unique( (array) ( $args['post__in'] ?? [] ) ) ) as $id ) {
                $post   = get_post( $id );
                $status = $post ? ( $post->post_status ?? 'publish' ) : '';
                if ( $post && in_array( $status, $statuses, true ) && ! ( $status === 'private' && $readable && ! is_user_logged_in() ) ) {
                    $this->posts[] = $post;
                }
            }
        }
    }
}
// Core reads the uuid that wp_authenticate_application_password() left in this global.
function rest_get_authenticated_app_password() { return $GLOBALS['wp_rest_application_password_uuid'] ?? null; }
class WP_Application_Passwords {
    public static function create_new_application_password( $user_id, $args ) {
        Fabrement\Tests\WordPressState::$created_passwords[] = [ $user_id, $args ];
        return Fabrement\Tests\WordPressState::$password_error ?? [ 'SYNTHETIC_PASSWORD', [] ];
    }
    public static function get_user_application_passwords( $user_id ) {
        return Fabrement\Tests\WordPressState::$passwords[ $user_id ] ?? [];
    }
    public static function get_user_application_password( $user_id, $uuid ) {
        foreach ( self::get_user_application_passwords( $user_id ) as $item ) {
            if ( $item['uuid'] === $uuid ) { return $item; }
        }
        return null;
    }
    public static function delete_application_password( $user_id, $uuid ) {
        Fabrement\Tests\WordPressState::$deleted_passwords[] = [ $user_id, $uuid ];
        return true;
    }
}
