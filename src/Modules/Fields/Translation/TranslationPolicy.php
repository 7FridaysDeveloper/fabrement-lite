<?php

namespace Fabrement\Modules\Fields\Translation;

use Fabrement\Modules\Fields\Types\FieldTypes;
use Fabrement\Modules\Fields\Values\ValueCodec;

/**
 * What a translation's value becomes, given the original's, per field policy:
 *
 *   copy       follows the original; edits made in a translation route to the original.
 *   translate  seeded from the original once, then the translation's own; goes to WPML jobs.
 *   copy_once  seeded from the original once, then the translation's own.
 *   ignore     never touched.
 *
 * A repeater under `copy` or `translate` takes its ROWS from the original (added, removed,
 * reordered or cleared there), matched by row identity, so a translated row keeps its text
 * wherever the row moves; each sub-field then follows its own policy. A group or repeater under
 * `copy_once` is copied whole when the translation first gets it and is the translation's own
 * after that, whatever its sub-fields say; under `ignore` it is never touched.
 *
 * The policies `ignore` and container `copy_once` are applied here; everything else is the
 * field's type (Types\FieldType), which recurses back here for its sub-fields.
 *
 * Values are in editor shape (field keys; rows as {id, values}).
 */
final class TranslationPolicy {

    /**
     * @param bool                     $target_has Whether the translation already holds this value.
     * @param string                   $path    The field's storage path in the TARGET (its key at the top level).
     * @param array<string, true>|null $present Storage paths that exist in the target. With it, a
     *                                          sub-field counts as held only when its own row exists,
     *                                          so one added to the schema later is seeded; without
     *                                          it, a sub-field counts as held whenever its parent is.
     */
    public static function merge( array $field, $source, $target, bool $target_has, string $path = '', ?array $present = null ) {
        if ( $field['translation'] === 'ignore' ) {
            return $target_has ? $target : ValueCodec::empty_value( $field );
        }
        // A container under copy_once is the translation's own, whole, once it exists.
        if ( $target_has && self::own_container( $field ) ) {
            return $target;
        }
        return FieldTypes::of( $field )->merge( $field, $source, $target, $target_has, $path, $present );
    }

    /**
     * Refuse edits in a translation to what the original owns: `copy` values, and the rows of a
     * `copy` / `translate` repeater. Refused rather than silently reverted, so nobody's work vanishes.
     *
     * A `copy` value is refused only when it was CHANGED: it differs from the original AND from what
     * the translation holds now ($current). One the translation holds stale (a sync that failed)
     * comes back unchanged with every save of its group or row, and must not block the save of the
     * words next to it; with_original() then replaces it with the original's.
     *
     * @param mixed $current The translation's stored editor value; null when unknown.
     * @throws \InvalidArgumentException
     */
    public static function assert_editable( array $field, $submitted, $source, string $at, $current = null ): void {
        if ( $field['translation'] === 'ignore' || self::own_container( $field ) ) {
            return;
        }
        FieldTypes::of( $field )->assert_editable( $field, $submitted, $source, $at, $current );
    }

    /**
     * A translation's value to store, with every `copy` value (at any depth) taken from the original:
     * what it holds stale is healed by its own save, the way the original's save would.
     *
     * @param mixed $submitted Editor value, already checked by assert_editable().
     * @param mixed $source    The original's editor value.
     * @return mixed
     */
    public static function with_original( array $field, $submitted, $source ) {
        if ( $field['translation'] === 'ignore' || self::own_container( $field ) ) {
            return $submitted;
        }
        return FieldTypes::of( $field )->with_original( $field, $submitted, $source );
    }

    /**
     * What the site's own code, writing a translation, changes in its original: the `copy` values it
     * changed, and — of a repeater whose rows follow the original — the rows it added (with all they
     * hold, their words too until translated), removed or moved. Compared with what the translation
     * held before, so a translation that fell behind its original does not push its stale values back:
     * whatever the code did not change stays as the original has it, rows the original added since
     * included. The translation's own values (translate, copy_once, ignore) are not the original's.
     *
     * @param mixed $submitted The translation's new editor value.
     * @param mixed $before    What the translation held (editor value).
     * @param mixed $original  The original's editor value now.
     * @return mixed The original's new editor value.
     */
    public static function to_original( array $field, $submitted, $before, $original ) {
        if ( $field['translation'] === 'ignore' || self::own_container( $field ) ) {
            return $original;
        }
        $type = FieldTypes::of( $field );
        if ( ! $type->is_container() ) {
            return $field['translation'] === 'copy' && ! $type->same( $submitted, $before ) ? $submitted : $original;
        }
        $children = ValueCodec::active( $field['sub_fields'] );
        $merge = static function ( array $s, array $b, array $o ) use ( $children ): array {
            foreach ( $children as $key => $child ) {
                $o[ $key ] = self::to_original(
                    $child,
                    array_key_exists( $key, $s ) ? $s[ $key ] : ValueCodec::empty_value( $child ),
                    array_key_exists( $key, $b ) ? $b[ $key ] : ValueCodec::empty_value( $child ),
                    array_key_exists( $key, $o ) ? $o[ $key ] : ValueCodec::empty_value( $child )
                );
            }
            return $o;
        };
        if ( $field['type'] === 'group' ) {
            return $merge( (array) $submitted, (array) $before, is_array( $original ) ? $original : [] );
        }
        $rows_of = static function ( $rows ): array {
            $out = [];
            foreach ( is_array( $rows ) ? $rows : [] as $row ) {
                $out[ $row['id'] ] = $row['values'];
            }
            return $out;
        };
        $sent = $rows_of( $submitted );
        $held = $rows_of( $before );
        $orig = $rows_of( $original );
        $value = static fn( string $id ): array => [ 'id' => $id, 'values' => isset( $orig[ $id ] )
            ? $merge( $sent[ $id ], $held[ $id ] ?? $sent[ $id ], $orig[ $id ] )
            : $sent[ $id ] ];
        // The row list as the code left it, or — when it did not touch the rows — the original's.
        if ( array_keys( $sent ) === array_keys( $held ) ) {
            $out = [];
            foreach ( $orig as $id => $values ) {
                $out[] = isset( $sent[ $id ] ) ? $value( (string) $id ) : [ 'id' => (string) $id, 'values' => $values ];
            }
            return $out;
        }
        $out = [];
        foreach ( array_keys( $sent ) as $id ) {
            $id = (string) $id;
            // A row the translation held that the original has since removed stays removed.
            if ( isset( $orig[ $id ] ) || ! isset( $held[ $id ] ) ) {
                $out[] = $value( $id );
            }
        }
        // Rows the original added that the translation never saw keep their place after the row
        // that preceded them there.
        $previous = null;
        foreach ( array_keys( $orig ) as $id ) {
            $id = (string) $id;
            if ( ! isset( $held[ $id ] ) && ! isset( $sent[ $id ] ) ) {
                $at = 0;
                foreach ( $out as $i => $row ) {
                    if ( $row['id'] === $previous ) {
                        $at = $i + 1;
                    }
                }
                array_splice( $out, $at, 0, [ [ 'id' => $id, 'values' => $orig[ $id ] ] ] );
            }
            $previous = $id;
        }
        return $out;
    }

    public static function original_selection( array $field, $selected, $value, $original ) {
        if ( $selected === false || $field['translation'] === 'ignore' || self::own_container( $field ) ) {
            return false;
        }
        if ( ! FieldTypes::of( $field )->is_container() ) {
            return $field['translation'] === 'copy' ? $selected : false;
        }
        $children = static function ( $selection, array $values, array $before ) use ( $field ): array {
            $out = [];
            foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                $out[ $key ] = self::original_selection( $child, $selection === true ? true : ( $selection[ $key ] ?? false ), $values[ $key ], $before[ $key ] ?? null );
            }
            return $out;
        };
        if ( $field['type'] === 'group' ) {
            return $children( $selected, $value, (array) $original );
        }
        $out = [];
        $held = array_column( (array) $original, 'values', 'id' );
        foreach ( $value as $row ) {
            // A row newly routed to the original initializes all its values, including words.
            // Existing rows select only copied children and keep the original's own words.
            $out[ $row['id'] ] = isset( $held[ $row['id'] ] )
                ? $children( $selected === true ? true : ( $selected[ $row['id'] ] ?? [] ), $row['values'], $held[ $row['id'] ] )
                : true;
        }
        return $out;
    }

    /**
     * Whether a translation's stored value goes with the original's: cleared when the original
     * has none (a `copy` value, the rows of a repeater that follow the original).
     */
    public static function follows_original( array $field ): bool {
        return $field['translation'] !== 'ignore' && FieldTypes::of( $field )->follows_original( $field );
    }

    /**
     * Shared `copy` fields (by key path, rows ignored) and repeaters whose rows follow the original.
     * The editor uses these paths to explain that edits are routed to the original.
     *
     * @return array{fields: string[], rows: string[]}
     */
    public static function locks( array $fields, string $prefix = '' ): array {
        $locks = [ 'fields' => [], 'rows' => [] ];
        foreach ( ValueCodec::active( $fields ) as $key => $field ) {
            if ( $field['translation'] === 'ignore' || self::own_container( $field ) ) {
                continue;
            }
            FieldTypes::of( $field )->locks( $field, $prefix === '' ? $key : $prefix . '/' . $key, $locks );
        }
        return $locks;
    }

    /**
     * Where WPML's translation editor owns a translation, the words it carries (texts()): paths of
     * every `translate` field, at any depth outside an `ignore` one, for the form to lock. The rest
     * — copy_once and ignore values, which no translation job carries — stays editable here.
     *
     * @return string[]
     */
    public static function word_locks( array $fields, string $prefix = '' ): array {
        $out = [];
        foreach ( ValueCodec::active( $fields ) as $key => $field ) {
            // A copy_once repeater is the translation's own: its own rows are written here (no job
            // carries them), the words of rows it shares with the original are refused on save.
            if ( $field['translation'] === 'ignore' || ( self::own_container( $field ) && $field['type'] === 'repeater' ) ) {
                continue;
            }
            FieldTypes::of( $field )->word_locks( $field, $prefix === '' ? $key : $prefix . '/' . $key, $out );
        }
        return $out;
    }

    /**
     * The words a translator should see for one field of the original: every `translate` text,
     * textarea, wysiwyg, and a link's caption (plus its address when it is not a picked record).
     *
     * @return array<string, array{text: string, label: string, html: bool}> Keyed by a stable token.
     */
    public static function texts( array $field, $value, array $path = [], string $label = '' ): array {
        $path[] = $field['key'];
        $label = $label === '' ? $field['label'] : $label . ' / ' . $field['label'];
        $type = FieldTypes::of( $field );
        if ( $field['translation'] === 'ignore' || ( ! $type->is_container() && $field['translation'] !== 'translate' ) ) {
            return [];
        }
        return $type->texts( $field, $value, $path, $label );
    }

    /** Put translated words back into a translation's value. Unknown tokens (a row deleted since) are skipped. */
    public static function apply_texts( array $field, $value, array $texts, array $path = [] ) {
        $path[] = $field['key'];
        $type = FieldTypes::of( $field );
        if ( $field['translation'] === 'ignore' || ( ! $type->is_container() && $field['translation'] !== 'translate' ) ) {
            return $value;
        }
        return $type->apply_texts( $field, $value, $texts, $path );
    }

    public static function clear_texts( array $field, $value, ?array $source, array $job, array $path = [], array &$cleared = [] ) {
        $path[] = $field['key'];
        $type = FieldTypes::of( $field );
        if ( $field['translation'] === 'ignore' || ( ! $type->is_container() && $field['translation'] !== 'translate' ) ) {
            return $value;
        }
        return $type->clear_texts( $field, $value, $source, $job, $path, $cleared );
    }

    public static function lost_on_next_job( array $field, $value, $current, ?array $source ): array {
        $job = $source === null ? [] : array_fill_keys( array_keys( self::texts( $field, $source[0] ) ), true );
        $lost = [];
        $had = [];
        self::clear_texts( $field, $value, $source, $job, [], $lost );
        self::clear_texts( $field, $current, $source, $job, [], $had );
        return array_keys( array_filter( $lost, static fn( $text, string $token ): bool => ( $had[ $token ] ?? null ) !== $text, ARRAY_FILTER_USE_BOTH ) );
    }

    /** Stable and short, however deep the repeater: WPML stores the job field name. */
    public static function token( array $path ): string {
        return 'fabrement-field-' . hash( 'sha256', implode( '/', $path ) );
    }

    /**
     * Rows of a record that joined a translation group holding rows of its own (two posts made
     * separately, then connected): none of their ids is the original's, so they are matched to
     * the original's by position, once, as WPML and ACFML match rows. After that they follow the
     * original by id like any translation's rows. Nested repeaters, in groups or rows, likewise.
     *
     * @param mixed $source The original's editor value.
     * @param mixed $target The translation's editor value.
     * @return mixed $target with row ids taken from the original where it had none of them.
     */
    public static function adopt_rows( array $field, $source, $target ) {
        if ( $field['translation'] === 'ignore' || self::own_container( $field ) || ! is_array( $source ) || ! is_array( $target ) ) {
            return $target;
        }
        $children = ValueCodec::active( $field['sub_fields'] ?? [] );
        if ( $field['type'] === 'group' ) {
            foreach ( $children as $key => $child ) {
                if ( array_key_exists( $key, $target ) ) {
                    $target[ $key ] = self::adopt_rows( $child, $source[ $key ] ?? null, $target[ $key ] );
                }
            }
            return $target;
        }
        if ( $field['type'] !== 'repeater' || ! FieldTypes::of( $field )->follows_original( $field ) ) {
            return $target;
        }
        $source_ids = array_column( $source, 'id' );
        $source_rows = array_column( $source, 'values', 'id' );
        $by_position = array_intersect( array_column( $target, 'id' ), $source_ids ) === [];
        $out = [];
        foreach ( array_values( $target ) as $i => $row ) {
            if ( $by_position && isset( $source_ids[ $i ] ) ) {
                $row['id'] = $source_ids[ $i ];
            }
            if ( isset( $source_rows[ $row['id'] ] ) && is_array( $row['values'] ?? null ) ) {
                foreach ( $children as $key => $child ) {
                    if ( array_key_exists( $key, $row['values'] ) ) {
                        $row['values'][ $key ] = self::adopt_rows( $child, $source_rows[ $row['id'] ][ $key ] ?? null, $row['values'][ $key ] );
                    }
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * A WPML duplicate's value: the original's, except what is `ignore` at any depth, which stays
     * the duplicate's own. A repeater's rows are the original's; a row keeps the ignore values it
     * held under the same row id.
     *
     * @param mixed $source The original's editor value.
     * @param mixed $target The duplicate's editor value.
     */
    public static function mirror( array $field, $source, $target, bool $target_has ) {
        if ( $field['translation'] === 'ignore' ) {
            return $target_has ? $target : ValueCodec::empty_value( $field );
        }
        $children = ValueCodec::active( $field['sub_fields'] ?? [] );
        if ( $field['type'] === 'group' ) {
            $out = [];
            foreach ( $children as $key => $child ) {
                $held = $target_has && is_array( $target ) && array_key_exists( $key, $target );
                $has_source = is_array( $source ) && array_key_exists( $key, $source );
                if ( $child['translation'] === 'ignore' ? ! $held : ( ! $has_source && ( ! $held || $child['type'] !== 'group' ) ) ) {
                    continue;
                }
                $out[ $key ] = self::mirror( $child, $has_source ? $source[ $key ] : [], $held ? $target[ $key ] : null, $held );
            }
            return $out;
        }
        if ( $field['type'] === 'repeater' ) {
            $held = $target_has && is_array( $target ) ? array_column( $target, 'values', 'id' ) : [];
            return array_map( static function ( array $row ) use ( $children, $held ): array {
                $own = $held[ $row['id'] ] ?? null;
                $values = [];
                foreach ( $children as $key => $child ) {
                    $has = is_array( $own ) && array_key_exists( $key, $own );
                    $has_source = array_key_exists( $key, $row['values'] );
                    if ( $child['translation'] === 'ignore' ? ! $has : ( ! $has_source && ( ! $has || $child['type'] !== 'group' ) ) ) {
                        continue;
                    }
                    $values[ $key ] = self::mirror( $child, $has_source ? $row['values'][ $key ] : [], $has ? $own[ $key ] : null, $has );
                }
                return [ 'id' => $row['id'], 'values' => $values ];
            }, is_array( $source ) ? $source : [] );
        }
        return $source;
    }

    /** A group or repeater under copy_once: the translation's own, whole, once it has it. */
    private static function own_container( array $field ): bool {
        return $field['translation'] === 'copy_once' && FieldTypes::of( $field )->is_container();
    }
}
