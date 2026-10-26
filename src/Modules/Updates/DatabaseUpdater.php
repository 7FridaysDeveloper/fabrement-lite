<?php

namespace Fabrement\Modules\Updates;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Lifecycle\NoticeScreen;
use Fabrement\Modules\Mcp\OAuth\Lifecycle;
use Fabrement\Modules\Mcp\OAuth\Store;

/** Coordinates site-local schema updates, independently of the plugin release version. */
final class DatabaseUpdater {
    // Bump when adding a schema step below, not for ordinary plugin releases.
    // Stays 3: sites have recorded it. 2 and 3 were one-off steps for versions long gone.
    public const VERSION = 3;

    public static function activate(): void {
        self::install_site();
    }

    public static function maybe_upgrade( ?Store $store = null ): bool {
        if ( (int) get_option( Keys::OPTION_DB_VERSION, 0 ) >= self::VERSION ) {
            return true;
        }
        if ( self::waiting_to_retry() ) {
            return false;
        }
        return self::install_site( $store, false );
    }

    public static function install_site( ?Store $store = null, bool $force = true ): bool {
        global $wpdb;
        // Connection-owned locks survive DDL commits and are released even if PHP terminates.
        $lock = 'fabrement-db-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->options ), 0, 48 );
        // SQLite (WordPress Playground, the SQLite Database Integration plugin) has no named locks,
        // and one file database takes no two schema writes at once: the lock is MySQL's.
        $sqlite = defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock (GET_LOCK) around the schema update; no WordPress API, must never be cached.
        $locked = $sqlite ? '1' : $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
        if ( '1' !== (string) $locked ) {
            if ( null === $locked || false === $locked ) {
                self::record_failure( 'Could not acquire the database update lock.' );
            }
            return false;
        }

        try {
            // Another request may have completed the update after our initial version check.
            foreach ( [ Keys::OPTION_DB_VERSION, Keys::OPTION_DB_RETRY_AFTER, 'alloptions', 'notoptions' ] as $key ) {
                wp_cache_delete( $key, 'options' );
            }
            $installed = (int) get_option( Keys::OPTION_DB_VERSION, 0 );
            if ( $installed > self::VERSION || ( ! $force && $installed === self::VERSION ) ) {
                return true;
            }
            if ( ! $force && self::waiting_to_retry() ) {
                return false;
            }

            // The OAuth storage; module installers own their SQL and defaults.
            // Re-run on activation too: creating the table is a repair for a site that lost it.
            if ( $installed < 3 || $force ) {
                Lifecycle::install_site( $store );
            }
            update_option( Keys::OPTION_DB_VERSION, self::VERSION, true );
            if ( (int) get_option( Keys::OPTION_DB_VERSION ) !== self::VERSION ) {
                throw new \RuntimeException( 'Could not record the database version.' );
            }
            delete_option( Keys::OPTION_DB_UPDATE_ERROR );
            delete_option( Keys::OPTION_DB_RETRY_AFTER );
            return true;
        } catch ( \Throwable $error ) {
            if ( $force && ( $installed ?? 0 ) === self::VERSION ) {
                delete_option( Keys::OPTION_DB_VERSION );
            }
            self::record_failure( $error->getMessage() );
            return false;
        } finally {
            if ( ! $sqlite ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the MySQL named lock taken above; no WordPress API, must never be cached.
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
            }
        }
    }

    private static function waiting_to_retry(): bool {
        $retry = get_option( Keys::OPTION_DB_RETRY_AFTER, [] );
        return is_array( $retry ) && ( $retry['version'] ?? null ) === self::VERSION
            && (int) ( $retry['after'] ?? 0 ) > time();
    }

    private static function record_failure( string $message ): void {
        update_option( Keys::OPTION_DB_UPDATE_ERROR, $message, false );
        update_option( Keys::OPTION_DB_RETRY_AFTER, [ 'version' => self::VERSION, 'after' => time() + 300 ], false );
    }

    public static function notice(): void {
        if ( ! current_user_can( 'manage_options' ) || ! NoticeScreen::here() || ! get_option( Keys::OPTION_DB_UPDATE_ERROR ) ) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__( 'Fabrement Lite could not finish updating its database. Check the database permissions. Automatic updates retry after five minutes; deactivate and reactivate Fabrement Lite to retry immediately. Existing plugin data is preserved.', 'fabrement-lite' )
        );
    }

    public static function uninstall(): void {
        foreach ( [ Keys::OPTION_DB_VERSION, Keys::OPTION_DB_UPDATE_ERROR, Keys::OPTION_DB_RETRY_AFTER ] as $option ) {
            delete_option( $option );
        }
    }
}
