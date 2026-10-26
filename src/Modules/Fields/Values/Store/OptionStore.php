<?php

namespace Fabrement\Modules\Fields\Values\Store;

use RuntimeException;

/**
 * Values of an options page: ONE `wp_options` row per page and language, holding the same flat
 * map a record keeps in its meta rows (`fab_phone`, `fab_steps_0_title`, `__rid`). A page read on every request (header, footer) autoloads: one row with the rest of the
 * options, instead of a query per field; a big page read on a few screens turns that off
 * (`page.autoload`). A save locks and replaces that one row.
 *
 * The $id is the row's option name ({@see \Fabrement\Modules\Fields\Values\FieldObject::options()}).
 *
 * No row lock: an options page is edited by one person or one agent at a time; the last save
 * wins, as for any other option. The row is read fresh inside the save's
 * transaction ({@see lock()}) and written with update_option(), so the option hooks run as for
 * any other option; its caches are cleared again once the transaction has ended.
 */
final class OptionStore implements ValueStore {

    public function exists( $id ): bool {
        $missing = new \stdClass();
        return get_option( (string) $id, $missing ) !== $missing;
    }

    /** Publish the complete seed at once. A concurrent creator must never replace the winner. */
    public function create( $id, array $map ): bool {
        global $wpdb;
        $autoload = OptionRows::autoloads( OptionRows::slug_of( (string) $id ) ) ? 'yes' : 'no';
        // add_option() can overwrite on a duplicate-key race. The unique option_name is our
        // existence marker: on conflict change nothing, including an already empty version.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic insert-only creation; the option caches are invalidated below.
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_name = option_name",
            (string) $id, maybe_serialize( $map ), $autoload
        ) );
        $this->clean( $id, $result !== false );
        if ( $result === false ) {
            throw new RuntimeException( 'Could not create options values.' );
        }
        return $result === 1;
    }

    public function load( $id ): array {
        $map = get_option( (string) $id, [] );
        return is_array( $map ) ? $map : [];
    }

    /** The row as it is in the database, not a cached copy; [] before the first save. */
    public function lock( $id ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read fresh inside the save transaction; caches are cleared after it.
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", (string) $id ) );
        $map = $raw === null ? [] : maybe_unserialize( $raw );
        return is_array( $map ) ? $map : [];
    }

    public function save( $id, array $remove, array $insert, array $locked ): void {
        foreach ( $remove as $key ) {
            unset( $locked[ $key ] );
        }
        $map = array_merge( $locked, $insert );
        // update_option() decides between UPDATE and INSERT from the cache: a stale copy (or a
        // stale "does not exist") would compare against the wrong row or INSERT a duplicate.
        $this->clean( $id, false );
        // update_option() creates the row on the first save. It answers false for "unchanged" as
        // well as for a failure: only a row that still differs is a failed write.
        if ( ! update_option( (string) $id, $map, OptionRows::autoloads( OptionRows::slug_of( (string) $id ) ) ) && $this->lock( $id ) !== $map ) {
            throw new RuntimeException( 'Could not store field values.' );
        }
    }

    /** One option holds the whole map: a value reads back as it was written. */
    public function stored( array $insert ): array {
        return $insert;
    }

    public function clean( $id, bool $committed ): void {
        $id = (string) $id;
        wp_cache_delete( $id, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        $missing = wp_cache_get( 'notoptions', 'options' );
        if ( is_array( $missing ) && isset( $missing[ $id ] ) ) {
            unset( $missing[ $id ] );
            wp_cache_set( 'notoptions', $missing, 'options' );
        }
    }
}
