<?php

namespace Fabrement\Modules\Fields\Schema;

/**
 * A stored fab_ meta key's Fabrement field, so the values of a query are converted to the form
 * the field stores.
 *
 * One key means one field on every post type ({@see SchemaService}), so the key alone answers:
 * a top-level field or a group's child, retired ones included. A key no definition holds — another
 * plugin's meta — is left as given.
 */
final class FieldKind {

    /** @var array<string,array{field:array,names:list<string>}>|null meta key => its field and path, once read in a request. */
    private static ?array $fields = null;
    /** The schema generation the map was read at: a definition saved, deleted or flushed since reads it again. */
    private static int $generation = -1;

    /**
     * Several keys at once: the values of every key of one field type
     * go to that type together — two record fields in one query are one question, not two.
     *
     * @param array<string,list<mixed>> $by_key meta key => values
     * @return array<string,list<mixed>> meta key => what to look for, in the same order
     */
    public static function search_values_many( array $by_key ): array {
        $groups = []; // type => [ field, [ key, offset, count ]… ], values
        $out    = $by_key;
        foreach ( $by_key as $meta_key => $values ) {
            $found = self::fields()[ $meta_key ] ?? null;
            if ( $found === null || $values === [] ) {
                continue;
            }
            $type                          = (string) ( $found['field']['type'] ?? '' );
            $groups[ $type ]['field']    ??= $found['field'];
            $groups[ $type ]['values']   ??= [];
            $groups[ $type ]['parts'][]    = [ (string) $meta_key, count( $groups[ $type ]['values'] ), count( $values ) ];
            $groups[ $type ]['values']     = array_merge( $groups[ $type ]['values'], array_values( $values ) );
        }
        foreach ( $groups as $group ) {
            $stored = \Fabrement\Modules\Fields\Types\FieldTypes::of( $group['field'] )->search_values( $group['field'], $group['values'] );
            foreach ( $group['parts'] as [ $meta_key, $offset, $count ] ) {
                $out[ $meta_key ] = array_slice( $stored, $offset, $count );
            }
        }
        return $out;
    }

    /** @return array<string,array{field:array,names:list<string>}> meta key => field definition and its path of names */
    private static function fields(): array {
        if ( self::$fields === null || self::$generation !== SchemaRepository::generation() ) {
            self::$generation = SchemaRepository::generation();
            self::$fields     = [];
            foreach ( SchemaRepository::all() as $document ) {
                if ( ( $document['type'] ?? '' ) !== 'post' ) {
                    continue; // options pages keep their own rows: no meta key
                }
                self::walk( $document['fields'] ?? [], '', [] );
            }
        }
        return self::$fields;
    }

    /** Top-level fields and group children; repeater children have no fixed queryable key. */
    private static function walk( array $fields, string $parent_key, array $names ): void {
        foreach ( $fields as $field ) {
            $name = (string) ( $field['name'] ?? '' );
            $key  = $parent_key === '' ? StorageLayout::key_for( $name, true ) : StorageLayout::child( $parent_key, $name );
            self::$fields[ $key ] ??= [ 'field' => $field, 'names' => [ ...$names, $name ] ];
            if ( ( $field['type'] ?? '' ) === 'group' ) {
                self::walk( $field['sub_fields'] ?? [], $key, [ ...$names, $name ] );
            }
        }
    }

    /** For tests: forget what this request read (a definition saved in it is picked up by itself). */
    public static function forget(): void {
        self::$fields = null;
    }
}
