<?php

namespace Fabrement\Modules\Fields\Values;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Schema\FieldKind;

/**
 * Every WP_Query on the site looks for a Fabrement field's value the way the field stores it.
 *
 * A record or media field stores the ORIGINAL of what was picked, one id for every language
 * ({@see \Fabrement\Modules\Fields\Types\Records::canonical()}), while code that asks — a
 * template's WP_Query, another plugin — holds the id of the version it was
 * shown: 6 for the French hall stored as 5. WPML adjusts post__in and tax_query this way
 * for the language being viewed; a meta value it cannot know is an id, so this does it for ours,
 * in any language and in the admin as well.
 *
 * meta_query (nested groups included) and meta_key / meta_value, compared with = != IN NOT IN;
 * every value of one key in one question. And a number or date compared as one never takes a
 * field left empty (its empty row) for 0. A query naming no fab_ key costs a walk of its array.
 *
 * On a list field (a row per item) != and NOT IN keep WordPress's meaning — "has some other item".
 *
 * `fabrement_meta_null => 'fab_city'` additionally requires a stored SQL NULL for that exact key.
 * This explicit PHP query argument works with suppress_filters too, without changing WPML's
 * language scope. It is an AND condition, independent of the existing meta_query's relation.
 */
final class FieldMetaQuery {

    private const COMPARE = [ '=', '!=', 'IN', 'NOT IN' ];

    private const NULL_QUERY = '_fabrement_value_is_null';

    public static function init(): void {
        // Late: after the code that builds the query has said its last word.
        add_action( 'pre_get_posts', [ self::class, 'adjust' ], 1000 );
        add_filter( 'get_meta_sql', [ self::class, 'null_sql' ], 10, 6 );
    }

    public static function adjust( \WP_Query $q ): void {
        $meta = $q->get( 'meta_query' );
        // WP_Query can be reused after set(). Unwrap only our own previous outer condition.
        $wrapped = is_array( $meta ) && ( $meta[ self::NULL_QUERY ] ?? false ) === true;
        if ( $wrapped ) {
            $meta = $meta[0];
        }
        $null_key = $q->get( 'fabrement_meta_null' );
        if ( $null_key !== '' && $null_key !== null ) {
            if ( ! is_string( $null_key ) ) {
                throw new \InvalidArgumentException( 'fabrement_meta_null must be a metadata key string.' );
            }
            // Ensure WP_Meta_Query runs even when there are no other meta conditions. Keep the
            // caller's tree first so named clauses and the primary meta sort retain their aliases.
            $meta = [ 'relation' => 'AND', self::NULL_QUERY => true,
                is_array( $meta ) ? $meta : [],
                self::null_clause( $null_key ),
            ];
            $q->set( 'meta_query', $meta );
        } elseif ( $wrapped ) {
            $q->set( 'meta_query', $meta );
        }
        $key  = (string) $q->get( 'meta_key' );
        $has  = is_array( $meta ) && $meta !== [] && self::names_ours( $meta );
        $one  = str_starts_with( $key, Keys::FIELD_META_PREFIX ) && $q->get( 'meta_value' ) !== '' && in_array( strtoupper( (string) ( $q->get( 'meta_compare' ) ?: '=' ) ), self::COMPARE, true );
        if ( ! $has && ! $one ) {
            return;
        }

        // Every value per key first, so a key's values go to its field in one question.
        $values = [];
        if ( $has ) {
            self::collect( $meta, $values );
        }
        if ( $one ) {
            foreach ( (array) $q->get( 'meta_value' ) as $value ) {
                $values[ $key ][] = $value;
            }
        }
        $values = array_map( static fn( array $list ): array => array_values( array_filter( $list, 'is_scalar' ) ), $values );
        $map    = [];
        foreach ( FieldKind::search_values_many( $values ) as $meta_key => $stored ) {
            foreach ( $stored as $i => $as_stored ) {
                $map[ $meta_key ][ (string) $values[ $meta_key ][ $i ] ] = $as_stored;
            }
        }

        if ( $has ) {
            $q->set( 'meta_query', self::replace( $meta, $map ) );
        }
        if ( $one ) {
            $q->set( 'meta_value', self::mapped( $q->get( 'meta_value' ), $map[ $key ] ?? [] ) );
        }
    }

    /**
     * @internal A stored-value NULL check, ANDed with the caller's other meta conditions.
     * EXISTS requires a real row, so a missing key cannot satisfy either NULL predicate.
     */
    public static function null_clause( string $key, bool $is_null = true ): array {
        return [ 'key' => $key, 'compare' => 'EXISTS', self::NULL_QUERY => $is_null ? 'IS NULL' : 'IS NOT NULL' ];
    }

    /** Add NULL predicates to their exact EXISTS aliases, without parsing SQL text. */
    public static function null_sql( array $sql, array $queries, string $type, string $table, string $column, $context ): array {
        if ( $type !== 'post' || ! $context instanceof \WP_Query || ! $context->meta_query instanceof \WP_Meta_Query ) {
            return $sql;
        }
        foreach ( $context->meta_query->get_clauses() as $clause ) {
            $predicate = $clause[ self::NULL_QUERY ] ?? null;
            if ( in_array( $predicate, [ 'IS NULL', 'IS NOT NULL' ], true ) ) {
                // EXISTS requires the key itself; IS NULL must not include a missing LEFT JOIN row.
                // This hook is not suppressed by WP_Query's suppress_filters argument.
                $sql['where'] .= ' AND ' . $clause['alias'] . '.meta_value ' . $predicate;
            }
        }
        return $sql;
    }

    /** Whether any clause of $meta names a fab_ key — the walk that is all a foreign query costs. */
    private static function names_ours( array $meta ): bool {
        foreach ( $meta as $clause ) {
            if ( ! is_array( $clause ) ) {
                continue;
            }
            if ( isset( $clause['key'] ) && is_string( $clause['key'] ) && str_starts_with( $clause['key'], Keys::FIELD_META_PREFIX ) ) {
                return true;
            }
            if ( ! isset( $clause['key'] ) && self::names_ours( $clause ) ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,list<mixed>> $values filled with key => the values its clauses compare */
    private static function collect( array $meta, array &$values ): void {
        foreach ( $meta as $clause ) {
            if ( ! is_array( $clause ) ) {
                continue;
            }
            if ( ! isset( $clause['key'] ) ) {
                self::collect( $clause, $values );
                continue;
            }
            if ( self::adjustable( $clause ) ) {
                foreach ( (array) $clause['value'] as $value ) {
                    $values[ (string) $clause['key'] ][] = $value;
                }
            }
        }
    }

    /** @param array<string,array<string,mixed>> $map key => given value => stored value */
    private static function replace( array $meta, array $map ): array {
        foreach ( $meta as $i => $clause ) {
            if ( ! is_array( $clause ) ) {
                continue;
            }
            if ( ! isset( $clause['key'] ) ) {
                $meta[ $i ] = self::replace( $clause, $map );
                continue;
            }
            if ( self::cast_empty( $clause ) ) {
                // A field left empty is an empty row: CAST, it is 0 and a date of zeros, and
                // "price up to 100" would list every post without a price. Not a value: no match —
                // as the sort and the counts read it.
                $meta[ $i ] = [ 'relation' => 'AND', $clause, [ 'key' => $clause['key'], 'value' => '', 'compare' => '!=' ] ];
                if ( isset( $map[ $clause['key'] ] ) && self::adjustable( $clause ) ) {
                    $meta[ $i ][0]['value'] = self::mapped( $clause['value'], $map[ $clause['key'] ] );
                }
                continue;
            }
            if ( ! self::adjustable( $clause ) || ! isset( $map[ $clause['key'] ] ) ) {
                continue;
            }
            $meta[ $i ]['value'] = self::mapped( $clause['value'], $map[ $clause['key'] ] );
        }
        return $meta;
    }

    /**
     * A value or list with each given value replaced by what is stored for it.
     *
     * @param mixed                $value
     * @param array<string, mixed> $to given value => stored value
     * @return mixed
     */
    private static function mapped( $value, array $to ) {
        $one = static fn( $v ) => is_scalar( $v ) ? ( $to[ (string) $v ] ?? $v ) : $v;
        return is_array( $value ) ? array_map( $one, $value ) : $one( $value );
    }

    /** A clause on our key that CASTs (a number, a date) and would take an empty row for a value. */
    private static function cast_empty( array $clause ): bool {
        return is_string( $clause['key'] ?? null )
            && str_starts_with( $clause['key'], Keys::FIELD_META_PREFIX )
            && array_key_exists( 'value', $clause )
            && preg_match( '/^(NUMERIC|DECIMAL|SIGNED|UNSIGNED|DATE|DATETIME|TIME)/i', (string) ( $clause['type'] ?? '' ) ) === 1
            && in_array( strtoupper( (string) ( $clause['compare'] ?? '=' ) ), [ '=', '>', '>=', '<', '<=', 'IN', 'BETWEEN' ], true );
    }

    private static function adjustable( array $clause ): bool {
        return is_string( $clause['key'] ?? null )
            && str_starts_with( $clause['key'], Keys::FIELD_META_PREFIX )
            && array_key_exists( 'value', $clause )
            && in_array( strtoupper( (string) ( $clause['compare'] ?? ( is_array( $clause['value'] ) ? 'IN' : '=' ) ) ), self::COMPARE, true );
    }
}
