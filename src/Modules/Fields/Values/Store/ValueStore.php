<?php

namespace Fabrement\Modules\Fields\Values\Store;

/**
 * Where one owner kind keeps its field values: WordPress meta tables for records, one
 * `wp_options` row for an options page. Every store hands ValueRepository the same flat map —
 * storage path => value, laid out by StorageLayout — so everything above it is the same for all.
 *
 * lock() and save() run inside the transaction ValueRepository opens; clean() after it ends.
 */
interface ValueStore {

    /**
     * The stored map, through WordPress's cache (reads for templates, forms and checks).
     *
     * @param int|string $id
     * @return array<string, mixed>
     */
    public function load( $id ): array;

    /**
     * Read what a save replaces as it is in the database (a meta store locks the record first).
     *
     * @param int|string $id
     * @return array<string, mixed>
     * @throws \RuntimeException When the object is gone.
     */
    public function lock( $id ): array;

    /**
     * Replace differing keys of the locked map: drop $remove, then write $insert.
     * Unchanged keys have already been omitted by ValueRepository.
     *
     * @param int|string           $id
     * @param string[]             $remove Keys to drop.
     * @param array<string, mixed> $insert Keys to write.
     * @param array<string, mixed> $locked The map lock() returned.
     * @throws \RuntimeException
     */
    public function save( $id, array $remove, array $insert, array $locked ): void;

    /**
     * $insert as lock() would read it back once saved — what a save compares with the map it
     * locked to tell whether anything changed.
     *
     * @param array<string, mixed> $insert
     * @return array<string, mixed>
     */
    public function stored( array $insert ): array;

    /**
     * Drop cached copies after the transaction, committed or not.
     *
     * @param int|string $id
     */
    public function clean( $id, bool $committed ): void;
}
