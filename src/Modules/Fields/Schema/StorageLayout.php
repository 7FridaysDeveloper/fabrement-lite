<?php

namespace Fabrement\Modules\Fields\Schema;

use Fabrement\Framework\Keys;

/**
 * The one place that knows how a field's value is laid out in meta. ACF's layout, with two
 * differences: a top-level field's key is its name behind the `fab_` prefix, and every repeater
 * row carries its identity in `__rid`.
 *
 *   fab_client                    scalar field
 *   fab_details         = 1       group marker
 *   fab_details_city              group child
 *   fab_milestones      = 2       repeater row count
 *   fab_milestones_0__rid = row_… row identity
 *   fab_milestones_0_title        row child
 *
 * A key follows from the name: `fab_<name>` at the top, the bare name below it. So a name is
 * the field's identity: a new name is a new field. No segment of a name is only digits and no
 * name contains `__`, so a key can never be mistaken for a row index or for `__rid`. Sibling
 * names must not reach into a sibling group's keys (`details_city` next to group `details`):
 * SchemaValidator refuses that.
 */
final class StorageLayout {

    /**
     * Whether a meta key belongs to Fabrement fields: a field's storage, or our service keys.
     *
     * Asked for every meta key WordPress and WPML look at, so the prefix answers first: a key that
     * does not start with `fab_` is never ours, without reading a single field file. Only `fab_`
     * keys go on to the exact regex, which keeps another plugin's `fab_something` out.
     */
    public static function owns( string $meta_key ): bool {
        if ( strpos( $meta_key, Keys::META_FIELDS_PREFIX ) === 0 ) {
            return true;
        }
        if ( strpos( $meta_key, Keys::FIELD_META_PREFIX ) !== 0 ) {
            return false;
        }
        return preg_match( self::owned_keys(), $meta_key ) === 1;
    }

    /**
     * One regex for every key any field file owns. Built once per request and again only after a
     * field file is written (SchemaRepository::generation()), not on every call: owns() runs for
     * every meta key WordPress and WPML look at.
     */
    private static function owned_keys(): string {
        static $built = [ 'generation' => null, 'pattern' => '/(?!)/' ];
        if ( $built['generation'] !== SchemaRepository::generation() ) {
            $parts = [];
            foreach ( SchemaRepository::all() as $document ) {
                // An options page keeps its values in wp_options, not in meta.
                if ( ( $document['type'] ?? '' ) === 'options' ) {
                    continue;
                }
                foreach ( $document['fields'] as $field ) {
                    $parts[] = substr( StorageLayout::owned_pattern( $field ), 2, -3 );
                }
            }
            $built = [ 'generation' => SchemaRepository::generation(), 'pattern' => $parts ? '/^(?:' . implode( '|', array_unique( $parts ) ) . ')$/D' : '/(?!)/' ];
        }
        return $built['pattern'];
    }

    public const ROW_ID = '__rid';
    /** A row's identity: row_ + 12 hex; rows made before 1.5 carry 32 and keep them. */
    public const ROW_ID_RE = '/^row_(?:[a-f0-9]{12}|[a-f0-9]{32})$/D';
    public const MAX_KEY_LENGTH = 191;

    /** A name is at most this long. */
    public const NAME_MAX = 24;

    /**
     * Whether a logical field name may be used: `^[a-z][a-z0-9_]*$`, no `__`, no digit-only segment,
     * and not starting with `fab_` — its key would read as another field's key (`fab_x` is the key
     * of field `x`), so a selector that accepts either could reach the wrong field.
     */
    public static function valid_name( string $name ): bool {
        if ( strlen( $name ) > self::NAME_MAX || preg_match( '/^[a-z][a-z0-9_]*$/D', $name ) !== 1 || strpos( $name, Keys::FIELD_META_PREFIX ) === 0 ) {
            return false;
        }
        foreach ( explode( '_', $name ) as $segment ) {
            if ( $segment === '' || ctype_digit( $segment ) ) {
                return false;
            }
        }
        return true;
    }

    /** The key of a field called `$name`: `fab_<name>` at the top level, the name itself below. */
    public static function key_for( string $name, bool $top_level ): string {
        return $top_level ? Keys::field_meta( $name ) : $name;
    }

    /** Whether a stored field's key is the one its name gives. */
    public static function valid_key( string $key, string $name, bool $top_level ): bool {
        return self::valid_name( $name ) && $key === self::key_for( $name, $top_level );
    }

    /**
     * A sibling whose key reaches into a sibling group's keys: next to group `details`, a field
     * `details_city` would be stored as `fab_details_city` — the group's `city`. Repeater rows
     * cannot clash (`fab_items_0_…` needs a digit-only segment, which no name has).
     *
     * @param array[] $siblings Fields of one level, retired ones included.
     * @return array{0: string, 1: string}|null [the clashing name, the group's name].
     */
    public static function clash( array $siblings ): ?array {
        foreach ( $siblings as $group ) {
            if ( ( $group['type'] ?? '' ) !== 'group' ) {
                continue;
            }
            foreach ( $siblings as $field ) {
                if ( strpos( $field['name'], $group['name'] . '_' ) === 0 ) {
                    return [ $field['name'], $group['name'] ];
                }
            }
        }
        return null;
    }

    /**
     * Every field's storage key => logical path and type, including retired fields.
     * Repeater indexes are {n}: fab_agenda_{n}_room_floor => agenda[].room.floor.
     * One traversal covers fixed keys and row keys for cross-post-type collision checks.
     *
     * @param array[] $fields
     * @return array<string, array{path: string, type: string}>
     */
    public static function keys( array $fields, string $parent_key = '', string $parent_path = '' ): array {
        $out = [];
        foreach ( $fields as $field ) {
            $key = $parent_key === '' ? self::key_for( (string) $field['name'], true ) : self::child( $parent_key, (string) $field['name'] );
            $path = $parent_path === '' ? (string) $field['name'] : $parent_path . '.' . $field['name'];
            $type = (string) $field['type'];
            $out[ $key ] = [ 'path' => $path, 'type' => $type ];
            if ( $type === 'group' || $type === 'repeater' ) {
                $out += self::keys( $field['sub_fields'] ?? [], $key . ( $type === 'repeater' ? '_{n}' : '' ), $path . ( $type === 'repeater' ? '[]' : '' ) );
            }
        }
        return $out;
    }

    public static function new_row_id(): string {
        return 'row_' . bin2hex( random_bytes( 6 ) );
    }

    /** A stable stand-in for a row that has no stored identity yet (legacy or foreign writes). */
    public static function derived_row_id( string $repeater_path, int $index ): string {
        return 'row_' . md5( $repeater_path . ':' . $index );
    }

    public static function child( string $parent_path, string $child_key ): string {
        return $parent_path . '_' . $child_key;
    }

    public static function row_child( string $repeater_path, int $index, string $child_key ): string {
        return $repeater_path . '_' . $index . '_' . $child_key;
    }

    public static function row_id( string $repeater_path, int $index ): string {
        return $repeater_path . '_' . $index . self::ROW_ID;
    }

    /**
     * Exact regex for every meta key a top-level field owns, including retired children.
     * Ownership is never decided by prefix: field `a` must not claim sibling `a_b`.
     */
    public static function owned_pattern( array $field ): string {
        return '/^' . self::fragment( $field ) . '$/D';
    }

    private static function fragment( array $field ): string {
        $self = preg_quote( $field['key'], '/' );
        $children = array_map( [ self::class, 'fragment' ], $field['sub_fields'] ?? [] );
        if ( $field['type'] === 'group' && $children ) {
            return $self . '(?:_(?:' . implode( '|', $children ) . '))?';
        }
        if ( $field['type'] === 'repeater' ) {
            $in_row = array_merge( [ '_rid' ], $children );
            return $self . '(?:_\d+_(?:' . implode( '|', $in_row ) . '))?';
        }
        return $self;
    }

    /** Longest meta key this field can produce (rows counted as up to three digits). */
    public static function longest_path( array $field, string $prefix = '' ): int {
        $path = $prefix . $field['key'];
        $longest = strlen( $path );
        if ( $field['type'] === 'repeater' ) {
            $longest = max( $longest, strlen( $path . '_000' . self::ROW_ID ) );
        }
        foreach ( $field['sub_fields'] ?? [] as $child ) {
            $longest = max( $longest, self::longest_path( $child, $path . ( $field['type'] === 'repeater' ? '_000_' : '_' ) ) );
        }
        return $longest;
    }
}
