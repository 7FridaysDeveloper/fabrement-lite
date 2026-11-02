<?php

namespace Fabrement\Modules\Mcp\OAuth;

defined( 'ABSPATH' ) || exit;

use Fabrement\Framework\Keys;

/** Site-local durable storage. Codes and refresh tokens are consumed with a compare-and-set. */
class Store {
    public const SCHEMA_VERSION = 1;

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . Keys::TABLE_MCP_OAUTH;
    }

    public function ready(): bool {
        global $wpdb;
        return (string) self::SCHEMA_VERSION === (string) get_option( Keys::OPTION_MCP_OAUTH_SCHEMA, '' )
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check of the plugin's own OAuth table; no WordPress API, and it must see the live schema.
            && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table() ) ) ) === $this->table();
    }

    public function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(16) NOT NULL,
            key_hash char(64) NOT NULL,
            payload longtext NOT NULL,
            family char(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            expires bigint(20) unsigned NOT NULL,
            used int(10) unsigned NOT NULL DEFAULT 0,
            revoked tinyint(1) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY key_hash (key_hash),
            KEY expires (expires),
            KEY family (family),
            KEY user_kind (user_id,kind)
        ) {$charset};" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- installation: verifies the plugin's own table right after dbDelta(); no WordPress API, must not be cached.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- false positive: the sniff reads the word "create" in this error message as part of the SHOW TABLES statement above.
            throw new OAuthError( 'server_error', 'Could not create OAuth storage.', 500 );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- installation: verifies the structure dbDelta() left; no WordPress API, must not be cached.
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- installation: verifies the structure dbDelta() left; no WordPress API, must not be cached.
        $indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
        $unique_hash = array_values( array_filter( $indexes ?: [], static fn( array $index ): bool => $index['Key_name'] === 'key_hash' ) );
        if ( array_diff( [ 'id', 'kind', 'key_hash', 'payload', 'family', 'user_id', 'expires', 'used', 'revoked' ], $columns ?: [] )
            || count( $unique_hash ) !== 1 || (int) $unique_hash[0]['Non_unique'] !== 0 || $unique_hash[0]['Column_name'] !== 'key_hash' ) {
            throw new OAuthError( 'server_error', 'Could not prepare the OAuth storage structure.', 500 );
        }
        update_option( Keys::OPTION_MCP_OAUTH_SCHEMA, self::SCHEMA_VERSION, false );
        if ( (string) get_option( Keys::OPTION_MCP_OAUTH_SCHEMA ) !== (string) self::SCHEMA_VERSION ) {
            throw new OAuthError( 'server_error', 'Could not record the OAuth storage version.', 500 );
        }
    }

    public function initialize_options(): void {
        global $wpdb;
        if ( ! $this->ready() ) {
            throw new OAuthError( 'server_error', 'OAuth storage is not ready.', 500 );
        }
        foreach ( [ Keys::OPTION_MCP_OAUTH_GENERATION => bin2hex( random_bytes( 32 ) ), Keys::OPTION_MCP_OAUTH_ENABLED => '1' ] as $name => $value ) {
            // A concurrent settings save must win over activation defaults, including an explicit Off.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- INSERT IGNORE: add_option() would overwrite a concurrent save (ON DUPLICATE KEY UPDATE); the option cache is cleared below.
            $saved = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
                $name, $value
            ) );
            wp_cache_delete( $name, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            if ( false === $saved || null === get_option( $name, null ) ) {
                throw new OAuthError( 'server_error', 'Could not save the OAuth settings.', 500 );
            }
        }
    }

    public function put( string $kind, string $key, array $payload, int $expires, string $family = '', int $user_id = 0 ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own OAuth table; WordPress has no API for it.
        $ok = $wpdb->insert( $this->table(), [
            'kind' => $kind, 'key_hash' => hash( 'sha256', $key ),
            'payload' => wp_json_encode( $payload ), 'expires' => $expires,
            'family' => $family, 'user_id' => $user_id,
        ], [ '%s', '%s', '%s', '%d', '%s', '%d' ] );
        if ( false === $ok ) {
            throw new OAuthError( 'server_error', 'Could not save OAuth state.', 500 );
        }
    }

    public function get( string $kind, string $key ): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth tokens and codes, read live from the plugin's own table: a cached row would outlive its consumption, revocation or expiry.
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM %i WHERE key_hash = %s AND kind = %s AND expires > %d',
            $this->table(), hash( 'sha256', $key ), $kind, time()
        ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }
        $row['payload'] = json_decode( $row['payload'], true );
        return is_array( $row['payload'] ) ? $row : null;
    }

    public function consume( string $kind, string $key ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic compare-and-set on the plugin's own OAuth table; no WordPress API, nothing cached to clear.
        return 1 === $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET used = 1 WHERE key_hash = %s AND kind = %s AND used = 0 AND revoked = 0 AND expires > %d',
            $this->table(), hash( 'sha256', $key ), $kind, time()
        ) );
    }

    public function revoke( string $family ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- revocation in the plugin's own OAuth table (no WordPress API, nothing cached to clear).
        if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked = 1 WHERE family = %s', $this->table(), $family ) ) ) {
            throw new OAuthError( 'server_error', 'Could not revoke OAuth access.', 500 );
        }
    }

    /** Every connection one user authorized: after their password changes. */
    public function revoke_user( int $user_id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- revocation in the plugin's own OAuth table (no WordPress API, nothing cached to clear).
        if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked = 1 WHERE user_id = %d', $this->table(), $user_id ) ) ) {
            throw new OAuthError( 'server_error', 'Could not revoke OAuth access.', 500 );
        }
    }

    /** Moves a live grant's end to $expires: the idle window starts again. */
    public function extend( string $family, int $expires ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own OAuth table (no WordPress API, nothing cached to clear).
        $wpdb->query( $wpdb->prepare(
            "UPDATE %i SET expires = %d WHERE key_hash = %s AND kind = 'grant' AND revoked = 0 AND expires > %d",
            $this->table(), $expires, hash( 'sha256', $family ), time()
        ) );
    }

    public function revoke_all(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- revocation in the plugin's own OAuth table (no WordPress API, nothing cached to clear).
        if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET revoked = 1 WHERE kind IN ('grant', 'pending', 'code', 'access', 'refresh')", $this->table() ) ) ) {
            throw new OAuthError( 'server_error', 'Could not revoke OAuth access.', 500 );
        }
    }

    /** Every live connection on the site, whoever authorized it: what an administrator sees and may revoke. */
    public function grants(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth grants, read live from the plugin's own table: a cached list would still show revoked connections.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT family, payload, expires, user_id FROM %i WHERE kind = 'grant' AND revoked = 0 AND expires > %d ORDER BY id DESC LIMIT 100",
            $this->table(), time()
        ), ARRAY_A );
        return self::decoded( $rows );
    }

    private static function decoded( array $rows ): array {
        foreach ( $rows as &$row ) {
            $row['payload'] = json_decode( $row['payload'], true );
        }
        return $rows;
    }

    public function throttle( string $bucket, int $limit, int $period ): void {
        global $wpdb;
        $window = (int) floor( time() / $period );
        $hash = hash( 'sha256', 'rate:' . $bucket . ':' . $window );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic rate-limit counter in the plugin's own OAuth table; no WordPress API.
        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT INTO %i (kind, key_hash, payload, expires, used) VALUES ('rate', %s, '{}', %d, 1) ON DUPLICATE KEY UPDATE used = used + 1",
            $this->table(), $hash, ( $window + 1 ) * $period
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the counter just incremented above; a cached value would defeat the limit.
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT used FROM %i WHERE key_hash = %s', $this->table(), $hash ) );
        if ( false === $ok || $count < 1 || $count > $limit ) {
            throw new OAuthError( 'temporarily_unavailable', 'Too many requests. Try again later.', 429 );
        }
    }

    public function cleanup(): void {
        global $wpdb;
        if ( ! $this->ready() ) {
            return;
        }
        // Consumed refresh tokens stay for replay detection until they expire or their connection is revoked.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk cleanup of the plugin's own OAuth table (no WordPress API, nothing cached to clear).
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires <= %d OR revoked = 1', $this->table(), time() ) );
    }

    public function uninstall(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall: drops the plugin's own OAuth table.
        $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table() ) );
        delete_option( Keys::OPTION_MCP_OAUTH_ENABLED );
        delete_option( Keys::OPTION_MCP_OAUTH_GENERATION );
        delete_option( Keys::OPTION_MCP_OAUTH_SCHEMA );
        delete_option( Keys::OPTION_MCP_OAUTH_REWRITE );
        // WordPress rebuilds the rewrite rules on the next request, without the well-known ones.
        delete_option( 'rewrite_rules' );
        wp_clear_scheduled_hook( 'fabrement_mcp_oauth_cleanup' );
    }
}
