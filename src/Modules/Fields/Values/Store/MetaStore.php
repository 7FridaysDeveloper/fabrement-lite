<?php

namespace Fabrement\Modules\Fields\Values\Store;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Schema\StorageLayout;
use RuntimeException;

/**
 * Values in the post meta table: one row per storage path of a post —
 * and a LIST (relationship, gallery) one row per item under its path, in order, so a meta query
 * finds "the list holds 5" with a plain = / IN on an indexed column ({@see rows()}). An empty list
 * is one NULL row: written empty, not never written. Old empty-string rows remain readable.
 *
 * Writes go through the metadata API (delete_metadata / add_metadata), so everything that
 * listens to meta changes (search indexes, caches, audit logs, WPML) hears a field save like any
 * other. What the API cannot do is the save's shape, so ValueRepository wraps it: a transaction,
 * a row lock on the record (SELECT … FOR UPDATE, {@see lock()}) so two saves cannot interleave.
 * The API writes through the same connection,
 * so a failed save rolls back whole. ValueRepository omits unchanged keys. Changed keys use
 * delete and add rather than update, so a stale classic meta box cannot restore their old values
 * by meta_id; unchanged rows keep their IDs.
 */
final class MetaStore implements ValueStore {

    private const TABLES = [
        'post'    => [ 'meta' => 'postmeta', 'column' => 'post_id', 'object' => 'posts', 'id' => 'ID', 'row' => 'meta_id' ],
    ];

    private string $type;
    /** @var array{meta: string, column: string, object: string, id: string, row: string} */
    private array $table;

    public function __construct( string $type ) {
        if ( ! isset( self::TABLES[ $type ] ) ) {
            throw new RuntimeException( 'Unsupported owner type.' );
        }
        $this->type = $type;
        $this->table = self::TABLES[ $type ];
    }

    public function load( $id ): array {
        $meta = [];
        foreach ( get_metadata( $this->type, (int) $id ) ?: [] as $key => $items ) {
            // Only field storage and our own keys are ever decoded; everything else a record carries
            // (page-builder blobs, SEO arrays) is left packed — it would be unserialized for nothing
            // on every field read.
            if ( strncmp( (string) $key, Keys::FIELD_META_PREFIX, strlen( Keys::FIELD_META_PREFIX ) ) === 0 || strncmp( (string) $key, Keys::META_FIELDS_PREFIX, strlen( Keys::META_FIELDS_PREFIX ) ) === 0 ) {
                // WordPress caches a record's rows in the order they were written (by meta id).
                $meta[ $key ] = self::read( array_values( (array) $items ) );
            }
        }
        return $meta;
    }

    /**
     * The rows of one key as a value: one row as it is, several as the list they hold, in order.
     *
     * @param list<string> $rows
     * @return mixed
     */
    private static function read( array $rows ) {
        return count( $rows ) > 1 ? array_map( 'maybe_unserialize', $rows ) : maybe_unserialize( array_key_exists( 0, $rows ) ? $rows[0] : '' );
    }

    /**
     * How a value is written: a list one row per item, a null one SQL NULL row, anything else — a
     * text, a link's keyed array — one row.
     *
     * @param mixed $value
     * @return list<mixed>
     */
    private static function rows( $value ): array {
        if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
            return [ $value ];
        }
        // An empty list is cleared, like any cleared value: one NULL row. A list type sends null
        // itself; [] comes from a row 1.4.0 or another plugin stored as an empty array, carried
        // over untouched while a sibling of its group or row is saved.
        return $value === [] ? [ null ] : $value;
    }

    /**
     * Values as they read back once saved — what a save compares with the rows it locked: a list
     * of one is that one row, an empty list its NULL row.
     */
    public function stored( array $insert ): array {
        foreach ( $insert as $key => $value ) {
            $insert[ $key ] = self::read( array_map( static fn( $row ) => is_array( $row ) ? maybe_serialize( $row ) : $row, self::rows( $value ) ) );
        }
        return $insert;
    }

    public function lock( $id ): array {
        global $wpdb;
        $table = $this->table;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- row lock inside the transaction; table and id column come from $wpdb.
        $locked = $wpdb->get_var( $wpdb->prepare( "SELECT {$table['id']} FROM {$wpdb->{$table['object']}} WHERE {$table['id']} = %d FOR UPDATE", (int) $id ) );
        if ( ! $locked ) {
            throw new RuntimeException( 'The record no longer exists.' );
        }
        // In the order written, as the cache holds them: a list reads the same both ways.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the rows as they are under the lock, not a cached copy; table and column names come from $wpdb.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->{$table['meta']}} WHERE {$table['column']} = %d ORDER BY {$table['row']}", (int) $id ), ARRAY_A ) ?: [];
        $by_key = [];
        foreach ( $rows as $row ) {
            $by_key[ $row['meta_key'] ][] = $row['meta_value'];
        }
        return array_map( [ self::class, 'read' ], $by_key );
    }

    public function save( $id, array $remove, array $insert, array $locked ): void {
        $id = (int) $id;
        foreach ( array_unique( $remove ) as $key ) {
            // delete_metadata() answers false for a key that was never there as well as for a
            // failure, so only a key the locked read saw can have failed.
            if ( ! delete_metadata( $this->type, $id, (string) $key ) && array_key_exists( $key, $locked ) ) {
                throw new RuntimeException( 'Could not clear previous field values.' );
            }
        }
        foreach ( $insert as $key => $value ) {
            foreach ( self::rows( $value ) as $row ) {
                // The API unslashes what it is given; the values are stored as the editor sent them.
                if ( add_metadata( $this->type, $id, (string) $key, wp_slash( $row ) ) === false ) {
                    throw new RuntimeException( 'Could not store field values.' );
                }
            }
        }
    }

    public function clean( $id, bool $committed ): void {
        // The API dropped the record's meta cache as it wrote, but inside the transaction: another
        // request could cache the old rows again before COMMIT, and after a ROLLBACK the cache and
        // the "last changed" stamps describe writes that never happened. Both are reset once the
        // transaction has ended. clean_post_cache() also fires `clean_post_cache`, which page-cache
        // plugins purge on.
        wp_cache_delete( (int) $id, $this->type . '_meta' );
        clean_post_cache( (int) $id );
    }
}
