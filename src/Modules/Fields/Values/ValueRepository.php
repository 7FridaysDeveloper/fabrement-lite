<?php

namespace Fabrement\Modules\Fields\Values;

use Fabrement\Modules\Fields\Schema\StorageLayout;
use Fabrement\Modules\Fields\Values\Store\MetaStore;
use Fabrement\Modules\Fields\Values\Store\OptionStore;
use Fabrement\Modules\Fields\Values\Store\ValueStore;
use RuntimeException;

/**
 * Stored values of one object. No permissions, no WPML, no hooks: the service above decides who
 * may write, and CLI or import code can use this directly. Where the values live is the store's
 * business (Store\MetaStore for records, Store\OptionStore for options pages); what a save
 * does is the same for all and lives here.
 *
 * A write is one InnoDB transaction: read fresh storage (locking the record for post meta),
 * project the selected fields, then replace only keys whose stored values differ. Stores use
 * WordPress write APIs so metadata/option hooks still run; caches are cleared after the transaction.
 */
final class ValueRepository {

    /** The store an owner kind keeps its values in. */
    public static function store( string $type ): ValueStore {
        return $type === 'options' ? new OptionStore() : new MetaStore( $type );
    }

    /**
     * Values of the given top-level fields (retired children included), read through WordPress's
     * cache, which it primes for whole loops.
     *
     * @param int|string $id      A record id; for options, the options row ({@see OptionStore}).
     * @param array[]    $fields  Top-level fields to read.
     * @return array{values: array<string, mixed>, exists: array<string, bool>, present: array<string, true>}
     */
    public static function read( string $type, $id, array $fields ): array {
        return self::decode( $fields, self::store( $type )->load( $id ) );
    }

    /**
     * Replace the values of some top-level fields.
     *
     * @param int|string           $id
     * @param array[]              $fields   Every top-level field of the owner, retired included, by key.
     * @param array<string, mixed> $changes  field key => new editor value, normalized against active fields.
     * @param string[]             $deletes  field keys to clear entirely.
     * @return bool Whether anything stored is different now. Only the values: a record's date and
     *              the hooks of a post save are the caller's (as with update_post_meta() or ACF's
     *              update_field(): the edit form and the MCP tools save the record; the site's own code
     *              calls wp_update_post() itself when a change should date it).
     */
    public static function write( string $type, $id, array $fields, array $changes, array $deletes, array $selection = [] ): bool {
        global $wpdb;
        $store = self::store( $type );
        if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
            $wpdb->send_reads_to_masters();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one transaction: a field tree is written whole or not at all; caches are cleared after COMMIT.
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
            throw new RuntimeException( 'Could not start a database transaction.' );
        }
        try {
            $meta = $store->lock( $id );

            $patch = self::patch( $fields, $meta, $changes, $deletes, $selection );
            $remove = $patch['remove'];
            $insert = $patch['insert'];
            $before = self::comparable( array_intersect_key( $meta, array_flip( $remove ) ) );
            $after = self::comparable( $store->stored( $insert ) );
            foreach ( $after as $key => $value ) {
                // Presence matters: absent -> null/default is a write; null -> null is not.
                // Compare lists as ordered values of ONE key, including duplicate items.
                if ( array_key_exists( $key, $before ) && $before[ $key ] === $value ) {
                    unset( $before[ $key ], $insert[ $key ] );
                }
            }
            $remove = array_keys( $before );
            $changed = $remove !== [] || $insert !== [];
            if ( $changed ) {
                $store->save( $id, $remove, $insert, $meta );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one transaction: a field tree is written whole or not at all; caches are cleared after COMMIT.
            if ( $wpdb->query( 'COMMIT' ) === false ) {
                throw new RuntimeException( 'Could not commit field values.' );
            }
        } catch ( \Throwable $error ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one transaction: a field tree is written whole or not at all; caches are cleared after COMMIT.
            $wpdb->query( 'ROLLBACK' );
            $store->clean( $id, false );
            throw $error;
        }
        // Even a no-op may have compared against newer data than the request's cached read.
        $store->clean( $id, true );
        return $changed;
    }

    /** The same storage projection for updating an object and publishing a new options version. */
    public static function patch( array $fields, array $meta, array $changes, array $deletes = [], array $selection = [] ): array {
        $remove = [];
        $insert = [];
        foreach ( array_unique( array_merge( array_keys( $changes ), $deletes ) ) as $key ) {
            $field = $fields[ $key ];
            $pattern = StorageLayout::owned_pattern( $field );
            foreach ( array_keys( $meta ) as $meta_key ) {
                if ( preg_match( $pattern, (string) $meta_key ) === 1 ) {
                    $remove[] = $meta_key;
                }
            }
            if ( array_key_exists( $key, $changes ) ) {
                $previous = ValueCodec::inflate( $field, $key, $meta );
                $value = ValueCodec::with_retired( $field, $changes[ $key ], $previous );
                ValueCodec::flatten( $field, $value, $key, $insert );
                if ( array_key_exists( $key, $selection ) ) {
                    ValueSelection::preserve( $field, $value, $selection[ $key ], $key, $key, $meta, $insert );
                }
            }
        }
        return [ 'remove' => $remove, 'insert' => $insert ];
    }

    /** Stored values as the database holds them: every scalar a string, keys in one order. */
    private static function comparable( array $values ): array {
        ksort( $values );
        foreach ( $values as $key => $value ) {
            if ( is_array( $value ) ) {
                $values[ $key ] = self::comparable( $value );
            } elseif ( is_bool( $value ) ) {
                $values[ $key ] = $value ? '1' : '';
            } elseif ( $value !== null ) {
                $values[ $key ] = (string) $value;
            }
        }
        return $values;
    }

    /**
     * `present` holds every storage path that exists, sub-fields and rows included: what tells a
     * sub-field never written (no row, e.g. added to the schema later) from one written empty
     * (null, or an older type-specific empty representation).
     *
     * @return array{values: array<string, mixed>, exists: array<string, bool>, present: array<string, true>}
     */
    public static function decode( array $fields, array $meta ): array {
        $values = [];
        $exists = [];
        $present = [];
        foreach ( $fields as $key => $field ) {
            $values[ $key ] = ValueCodec::inflate( $field, $key, $meta, $present );
            $exists[ $key ] = array_key_exists( $key, $meta );
        }
        return [ 'values' => $values, 'exists' => $exists, 'present' => $present ];
    }
}
