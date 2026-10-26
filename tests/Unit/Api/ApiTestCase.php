<?php

namespace {
    // In-memory serialization boundary, shared by field API and storage tests.
    if ( ! function_exists( 'maybe_serialize' ) ) {
        function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
    }
    if ( ! function_exists( 'maybe_unserialize' ) ) {
        function maybe_unserialize( $value ) {
            return is_string( $value ) && preg_match( '/^(?:a|O|s|i|b|d):|^N;$/', $value ) ? unserialize( $value, [ 'allowed_classes' => false ] ) : $value;
        }
    }
    if ( ! function_exists( 'wp_cache_get' ) ) {
        function wp_cache_get( $key, $group = '' ) { return false; }
    }
    if ( ! function_exists( 'wp_cache_set' ) ) {
        function wp_cache_set( $key, $value, $group = '', $expire = 0 ) { return true; }
    }
    if ( ! function_exists( 'get_metadata' ) ) {
        function get_metadata( $type, $id, $key = '', $single = false ) {
            $values = $type === 'post' ? ( \Fabrement\Tests\WordPressState::$post_meta[ $id ] ?? [] ) : [];
            if ( $key !== '' ) {
                return array_key_exists( $key, $values ) ? ( $single ? $values[ $key ] : [ $values[ $key ] ] ) : ( $single ? '' : [] );
            }
            return array_map( static fn( $value ) => [ maybe_serialize( $value ) ], $values );
        }
    }
    if ( ! function_exists( '_x' ) ) {
        function _x( $text, $context, $domain = null ) { return $text; }
    }
    if ( ! function_exists( 'wp_basename' ) ) {
        function wp_basename( $path, $suffix = '' ) { return basename( (string) $path, $suffix ); }
    }
    if ( ! function_exists( 'size_format' ) ) {
        function size_format( $bytes, $decimals = 0 ) {
            return $bytes >= 1048576 ? number_format( $bytes / 1048576, $decimals ) . ' MB' : number_format( $bytes / 1024, $decimals ) . ' KB';
        }
    }
    if ( ! function_exists( 'wp_get_mime_types' ) ) {
        function wp_get_mime_types() {
            return [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'mp4|m4v' => 'video/mp4', 'mp3|m4a|m4b' => 'audio/mpeg', 'pdf' => 'application/pdf', 'zip' => 'application/zip' ];
        }
    }
    if ( ! function_exists( 'wp_check_filetype' ) ) {
        function wp_check_filetype( $filename, $mimes = null ) {
            foreach ( $mimes ?? wp_get_mime_types() as $ext_preg => $mime_match ) {
                if ( preg_match( '!\.(' . $ext_preg . ')$!i', (string) $filename, $m ) ) {
                    return [ 'ext' => $m[1], 'type' => $mime_match ];
                }
            }
            return [ 'ext' => false, 'type' => false ];
        }
    }
    if ( ! function_exists( 'wp_get_upload_dir' ) ) {
        function wp_get_upload_dir() { return [ 'baseurl' => 'https://example.test/wp-content/uploads' ]; }
    }
    if ( ! function_exists( 'is_post_type_viewable' ) ) {
        function is_post_type_viewable( $type ) { return true; }
    }
    if ( ! function_exists( 'post_type_exists' ) ) {
        function post_type_exists( $type ) { return in_array( $type, [ 'post', 'page' ], true ); }
    }
    if ( ! function_exists( 'wp_is_post_revision' ) ) {
        function wp_is_post_revision( $post ) { $post = get_post( $post ); return $post && $post->post_type === 'revision' ? (int) $post->post_parent : false; }
    }
}

namespace Fabrement\Tests\Api {

    use Fabrement\Modules\Storage\FabrementStorage;
    use Fabrement\Modules\Translation\Bridge\NullBridge;
    use Fabrement\Modules\Translation\Translation;
    use Fabrement\Tests\WordPressState;
    use PHPUnit\Framework\TestCase;

    require_once dirname( __DIR__, 2 ) . '/Support/ConnectionSettingsRender.php';
    require_once dirname( __DIR__, 3 ) . '/src/Api/helpers.php';
    require_once dirname( __DIR__, 3 ) . '/src/Modules/Fields/helpers.php';

    /**
     * fabrement() on a single-language site without WordPress loaded: what it returns, prints and
     * writes to the error log when called from a block's render.php.
     */
    abstract class ApiTestCase extends TestCase {

        protected function setUp(): void {
            if ( ! defined( 'WP_CONTENT_DIR' ) ) {
                define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/fabrement-tests-' . getmypid() );
            }
            // Field read mistakes go to the debug log; logged() reads them from there.
            defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );
            WordPressState::reset();
            Translation::set( new NullBridge() );
        }

        protected function tearDown(): void {
            WordPressState::reset();
            Translation::set( null );
        }

        protected function page( int $id, string $title, string $status = 'publish' ): void {
            WordPressState::$posts[ $id ] = new \WP_Post( (object) [
                'ID'          => $id,
                'post_title'  => $title,
                'post_type'   => 'page',
                'post_status' => $status,
            ] );
        }

        /** @return array{0: mixed, 1: string} */
        protected static function printed( callable $fn ): array {
            ob_start();
            $returned = $fn();
            return [ $returned, (string) ob_get_clean() ];
        }

        /** @return array{0: mixed, 1: list<string>} */
        protected static function logged( callable $fn ): array {
            $file = tempnam( sys_get_temp_dir(), 'fabrement-log' );
            $old  = ini_set( 'error_log', $file );
            try {
                $returned = $fn();
            } finally {
                ini_set( 'error_log', (string) $old );
            }
            $lines = [];
            foreach ( preg_split( '/\R(?=\[)/', trim( (string) file_get_contents( $file ) ) ) ?: [] as $line ) {
                $at = strpos( $line, '[fabrement][fields] ' );
                if ( $at !== false ) {
                    $lines[] = substr( $line, $at + 20 );
                }
            }
            unlink( $file );
            return [ $returned, array_values( array_unique( $lines ) ) ];
        }
    }
}
