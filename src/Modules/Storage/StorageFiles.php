<?php

namespace Fabrement\Modules\Storage;

/**
 * The disk work every store under {@see FabrementStorage} shares: writing a file so a crash
 * cannot leave half of one, reading and writing JSON, and guarding a folder against direct
 * HTTP access.
 */
final class StorageFiles {

    private const WRITE_TEMP_PREFIX = '.fabrement-write-';

    private static ?\WP_Filesystem_Direct $filesystem = null;

    /**
     * Atomically replace one local file after verifying its complete contents.
     * Each writer uses a private temporary file in the target directory; the last
     * successful rename wins. This does not make a group of files transactional.
     *
     * @param callable|null $verify Given the bytes actually written, returns false to abort.
     *                              Used for JSON, where "written" and "valid" are not the same.
     */
    public static function write( string $path, string $contents, ?callable $verify = null ): bool {
        try {
            $token = bin2hex( random_bytes( 16 ) );
        } catch ( \Exception $e ) {
            return false;
        }
        $tmp = dirname( $path ) . '/' . self::WRITE_TEMP_PREFIX . $token . '.tmp';
        $filesystem = self::filesystem();
        $mode = $filesystem->is_file( $path )
            ? octdec( $filesystem->getchmod( $path ) )
            : ( defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : ( 0666 & ~umask() ) );
        if ( ! $mode ) {
            return false;
        }

        try {
            if ( ! $filesystem->put_contents( $tmp, $contents, $mode ) ) {
                return false;
            }

            $written = $filesystem->get_contents( $tmp );
            if ( $written !== $contents || ( $verify !== null && ! $verify( $written ) ) ) {
                return false;
            }
            if ( ! $filesystem->chmod( $tmp, $mode ) ) {
                return false;
            }

            // Keep replacement atomic; WP_Filesystem_Direct::move() deletes the target first.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Failure returns false; cleanup runs below.
            return @rename( $tmp, $path );
        } finally {
            self::delete( $tmp );
        }
    }

    public static function delete( string $path ): bool {
        if ( ! is_file( $path ) && ! is_link( $path ) ) {
            return true;
        }

        return wp_delete_file( $path );
    }

    /** Local paths must use the direct transport without replacing WordPress's global instance. */
    private static function filesystem(): \WP_Filesystem_Direct {
        if ( self::$filesystem !== null ) {
            return self::$filesystem;
        }
        if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }

        self::$filesystem = new \WP_Filesystem_Direct( null );
        return self::$filesystem;
    }

    /** Decode a JSON file. Null when it is missing, empty or not valid JSON. */
    public static function read_json( string $path ): ?array {
        if ( ! is_readable( $path ) ) {
            return null;
        }

        $raw = file_get_contents( $path );
        if ( $raw === false || trim( $raw ) === '' ) {
            return null;
        }

        $data = json_decode( $raw, true );

        return is_array( $data ) ? $data : null;
    }

    /** Write a JSON file atomically, refusing to promote a temp that will not decode. */
    public static function write_json( string $path, array $data ): bool {
        $payload = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $payload ) ) {
            return false;
        }

        return self::write(
            $path,
            $payload . "\n",
            static function ( string $written ): bool {
                return is_array( json_decode( $written, true ) );
            }
        );
    }

    /**
     * Drop an index.php + .htaccess into a folder. On nginx the .htaccess does nothing: the same
     * rule belongs in the server config.
     */
    public static function guards( string $dir ): void {
        // Skip guard writes when the directory is unavailable.
        if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
            return;
        }

        $index = $dir . '/index.php';
        if ( ! is_file( $index ) ) {
            self::write( $index, "<?php // Silence is golden.\n" );
        }

        $htaccess = $dir . '/.htaccess';
        if ( is_file( $htaccess ) ) {
            return;
        }

        self::write(
            $htaccess,
            "# Fabrement definitions: read by PHP, never served.\nRequire all denied\n"
        );
    }

    /** Create a folder and guard it. False when it could not be made writable. */
    public static function ensure_dir( string $dir ): bool {
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
            return false;
        }

        self::guards( $dir );

        return true;
    }
}
