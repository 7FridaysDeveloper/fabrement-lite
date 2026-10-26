<?php

namespace Fabrement\Modules\Fields\Values;

use Fabrement\Modules\Fields\Types\FieldTypes;
use Fabrement\Modules\Fields\Types\ListType;
use InvalidArgumentException;

/**
 * Three shapes of one value:
 *
 *   editor  keyed by field key; a repeater is [{id: row_…, values: {…}}]. What REST and the
 *           form exchange, and what the store works with.
 *   public  keyed by field name; a repeater is a plain list of rows. What templates read.
 *   meta    flat `path => string` pairs laid out by StorageLayout.
 *
 * Retired sub-fields are part of the editor shape inside the store, so their values travel with
 * their rows, and are stripped before anything leaves it.
 *
 * What differs per type lives in the type's class (Types\FieldType); this class walks the
 * shared steps and asks the field's type.
 */
final class ValueCodec {

    /** Records or images one relationship / gallery holds: what a template can show (fabrement()->posts()). */
    public const MAX_LIST = 50;
    /**
     * The largest number a field holds exactly: 2^53 - 1. Past it a PHP integer cast wraps to an
     * unrelated number, a float drops digits, and the editor's JavaScript does both. Numbers,
     * phones and codes that long are text.
     */
    public const NUMBER_LIMIT = 9007199254740991;

    /** A repeater row's identity in the public shape: MCP and fabrement()->fields() give it; the template functions do not. */
    public const ROW_KEY = '_row';

    /**
     * The empty EDITOR value of a field, IGNORING its default — what "cleared" means where a missing
     * row would mean something else (a translation's field, which the original seeds while it
     * has no row): "" for words, dates and choices, null for a number, 0 for a picked item, []
     * for lists and rows, an empty link, null for an unset switch. flatten() encodes empty values
     * as a present null; containers retain their structure and clear their children individually.
     */
    public static function blank_value( array $field ) {
        return FieldTypes::of( $field )->blank_value( $field );
    }

    public static function empty_value( array $field ) {
        if ( array_key_exists( 'default', $field ) ) {
            return $field['default'];
        }
        return FieldTypes::of( $field )->empty_value( $field );
    }

    /**
     * Validate and sanitize an editor-shape value against ACTIVE fields. Retired children must
     * not be sent; the store puts them back.
     *
     * @param string $at Path of the value in the caller's input, by field names (`sources[1].link`);
     *                   prefixed to messages so an agent knows which value to fix.
     * @param array|null $was [stored editor value] when this place already holds one, else null.
     *                   What the record already holds is not re-judged by today's rules: the shape
     *                   of every value is always checked, but whether a picked record is still
     *                   available, a choice still offered, a number still in range or a required
     *                   value still missing is checked only for what changes. A record trashed
     *                   after it was picked must not block saving the fields next to it.
     * @throws InvalidArgumentException With the path (when given) and field label in the message.
     */
    public static function normalize( array $field, $value, string $at = '', ?array $was = null ) {
        try {
            return self::checked( $field, $value, $at, $was );
        } catch ( FieldValueError $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception object built elsewhere is thrown on; nothing is printed here.
            throw $e->at( $field, $at );
        }
    }

    private static function checked( array $field, $value, string $at, ?array $was ) {
        $type = FieldTypes::of( $field );
        $value = $value === null ? self::blank_value( $field ) : $value;
        $previous = $was === null ? null : $was[0];
        $kept = $was !== null && $type->same( $value, $previous );
        $value = $type->normalize( $field, $value, $previous, $kept, $at );
        $items = $type->counted_items( $value );
        if ( $items !== null ) {
            // Only `max` is enforced. `min` is a wish for the content, shown in the edit form: a
            // list filled one item at a time, or a draft, must save — and one short list must not
            // block every other field saved with it.
            $count = count( $items );
            $over = isset( $field['max'] ) && $count > $field['max'];
            // A count already over (the limit lowered later) may stay or shrink, as long as nothing
            // new is added; anything else is judged by today's limit.
            $before = is_array( $previous ) ? $previous : null;
            $adds = $before === null || array_diff( $items, $type->counted_items( $before ) );
            $toward = $before !== null && $count <= count( $before );
            if ( $over && ( $adds || ! $toward ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
                throw new FieldValueError( FieldValueError::TOO_MANY, self::limits( $field ) + [ 'count' => $count ] );
            }
        }
        if ( ! empty( $field['required'] ) && ! $kept && $type->is_empty( $value ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::REQUIRED );
        }
        return $value;
    }

    public static function limits( array $field ): array {
        return array_intersect_key( $field, [ 'min' => 0, 'max' => 0 ] );
    }

    /** " (min–max)", " (at least min)", " (at most max)" or "", for a range message. */
    public static function range( array $field ): string {
        if ( isset( $field['min'], $field['max'] ) ) {
            return ' (' . $field['min'] . '–' . $field['max'] . ')';
        }
        return isset( $field['min'] ) ? ' (at least ' . $field['min'] . ')' : ( isset( $field['max'] ) ? ' (at most ' . $field['max'] . ')' : '' );
    }

    /** Whether a submitted value is the one already stored (numbers compared by value: 2 is 2.0). */
    public static function same( string $type, $value, $stored ): bool {
        return FieldTypes::get( $type )->same( $value, $stored );
    }

    /** @return array<string, array> Active (non-retired) fields keyed by key. */
    public static function active( array $fields ): array {
        $out = [];
        foreach ( $fields as $field ) {
            if ( empty( $field['retired'] ) ) {
                $out[ $field['key'] ] = $field;
            }
        }
        return $out;
    }

    /**
     * Carry retired sub-field values from the stored value into a new one: by row identity in a
     * repeater, by key in a group. `$next` is normalized against active fields only.
     */
    public static function with_retired( array $field, $next, $previous ) {
        return FieldTypes::of( $field )->with_retired( $field, $next, $previous );
    }

    /**
     * Editor value → flat meta. Every compound level writes its marker or row count, every row
     * its `__rid`. Empty leaf values share one representation: a present null (SQL NULL in meta,
     * null inside an options map). Retired children missing from a row simply write nothing.
     *
     * @param array<string, mixed> $meta Filled in place.
     */
    public static function flatten( array $field, $value, string $path, array &$meta ): void {
        if ( count( $meta ) > 20000 ) {
            throw new InvalidArgumentException( 'Field data exceeds the supported storage size.' );
        }
        $type = FieldTypes::of( $field );
        if ( ! $type->is_container() && $type->is_empty( $value ) ) {
            $meta[ $path ] = null;
            return;
        }
        // A group still owns its children (including retired ones); a repeater still owns its
        // rows. Their structural markers must not collapse just because the public value is null.
        $type->flatten( $field, $value, $path, $meta );
    }

    /**
     * Flat meta → editor value, retired children included. A missing key reads as the field's
     * empty value; `$present` receives every path that actually exists, for callers (WPML
     * merging) that must tell "never written" from "written empty".
     *
     * @param array<string, mixed> $meta Unserialized meta values keyed by meta key.
     * @param array<string, true>  $present Filled in place.
     */
    public static function inflate( array $field, string $path, array $meta, array &$present = [] ) {
        if ( ! array_key_exists( $path, $meta ) ) {
            return self::empty_value( $field );
        }
        $present[ $path ] = true;
        $type = FieldTypes::of( $field );
        $raw  = $meta[ $path ];
        // Rows under one key are a list's alone: anything else written twice (by hand, another
        // plugin) reads as its first row, as it always has.
        if ( ! $type instanceof ListType && is_array( $raw ) && $raw !== [] && array_values( $raw ) === $raw ) {
            $raw = $raw[0];
        }
        if ( $raw === null && ! $type->is_container() ) {
            return self::blank_value( $field );
        }
        return $type->inflate( $field, $raw, $path, $meta, $present );
    }

    /**
     * Editor value → what templates read: keyed by name, retired dropped. Rows carry no identity
     * unless `$row_ids`, which adds `_row` to every repeater row for callers that write rows back
     * (MCP) and must keep each row's identity when inserting or reordering.
     */
    public static function public_value( array $field, $value, bool $row_ids = false ) {
        return FieldTypes::of( $field )->public_value( $field, $value, $row_ids );
    }

    /**
     * A public value as a template on this page should see it: stored ids are the originals
     * ({@see \Fabrement\Modules\Fields\Types\Records::canonical()}), and here each becomes the READER's version — an image or file its
     * translated attachment (else the original), a record its version in the current language as
     * WP_Query shows it (published, private to who may read it), or nothing where it shows none (a
     * post_object then reads 0 and a relationship skips it). Links are left as stored.
     */
    public static function reader_value( array $field, $value ) {
        return FieldTypes::of( $field )->reader_value( $field, $value );
    }

    /** The modern API/MCP value: every empty field is null; numeric zero and false are values. */
    public static function api_value( array $field, $value ) {
        $type = FieldTypes::of( $field );
        if ( $type->is_empty( $value ) ) {
            return null;
        }
        $value = $type->api_value( $field, $value );
        return $type->is_empty( $value ) ? null : $value;
    }

    /** Editor value with retired children removed; what leaves the store for REST. */
    public static function editor_value( array $field, $value ) {
        return FieldTypes::of( $field )->editor_value( $field, $value );
    }

    /**
     * A public (name-keyed) value → editor shape. Which stored row a given row IS:
     *   - a row carrying `_row` (from get-field-values) is that stored row, wherever it now sits;
     *   - a row without `_row` is a NEW row, so rows of which none carries `_row` replace
     *     every stored row;
     *   - a stored row no sent row names is removed.
     * No row is matched by position: identity is what keeps a row joined to its translations.
     *
     * @param mixed  $previous The stored editor value, for row identities.
     * @param string $at       Input path by names, for error messages.
     */
    public static function from_public( array $field, $value, $previous, string $at = '' ) {
        if ( $value === null ) {
            return self::blank_value( $field );
        }
        return FieldTypes::of( $field )->from_public( $field, $value, $previous, $at );
    }

    /** "Unknown field" with the closest real name, so a typo is fixed in one try. */
    public static function unknown( string $name, array $names ): string {
        $best = null;
        $distance = PHP_INT_MAX;
        foreach ( $names as $candidate ) {
            $d = levenshtein( $name, (string) $candidate );
            if ( $d < $distance ) {
                $best = $candidate;
                $distance = $d;
            }
        }
        $hint = $best !== null && $distance <= max( 2, intdiv( strlen( $name ), 3 ) ) ? ' Did you mean "' . esc_html( (string) $best ) . '"?' : '';
        return 'Unknown field.' . $hint . ' Fields here: ' . esc_html( implode( ', ', $names ) ) . '.';
    }

    /** Public field definition with retired children omitted. */
    public static function without_retired( array $field ): array {
        if ( isset( $field['sub_fields'] ) ) {
            $field['sub_fields'] = array_values( array_map( [ self::class, 'without_retired' ], ValueCodec::active( $field['sub_fields'] ) ) );
        }
        unset( $field['retired'] );
        return $field;
    }
}
