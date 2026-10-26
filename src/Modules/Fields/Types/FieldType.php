<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Fields\Translation\TranslationPolicy;
use Fabrement\Modules\Fields\Values\ValueCodec;

/**
 * Everything one field TYPE knows, in one place: how its definition is checked, what its empty
 * value is, how a value is validated, stored, read back, shown to a template, what it points at
 * and which of its words a translator sees.
 *
 * The rest of the module never branches on a type name: ValueCodec, TranslationPolicy and the
 * schema validator walk the field tree and ask the field's type (FieldTypes::of()). A new type is
 * one class and one line in FieldTypes. Defaults here are the plain case — a scalar kept as text
 * with no options — and each type overrides what it does differently.
 *
 * Values are in the EDITOR shape unless a method says otherwise (see ValueCodec for the shapes).
 */
abstract class FieldType {

    /** The `type` name in a field definition. */
    abstract public function name(): string;

    // ------------------------------------------------------------------------------------------
    // Definition (save-fields)
    // ------------------------------------------------------------------------------------------

    /** Its value is words: translated per language unless the definition says otherwise. */
    public function is_words(): bool {
        return false;
    }

    /** May be marked required: only a value that can be empty. */
    public function allows_required(): bool {
        return true;
    }

    /** Holds other fields (group, repeater). */
    public function is_container(): bool {
        return false;
    }

    /**
     * The options only some types take, checked in a fixed order so an error names the first
     * wrong one: format, min/max, choices, postTypes. Each is refused here when sent to a type
     * that does not take it; a type that does takes it by overriding the hook.
     *
     * @param array|null $stored The stored definition this one replaces, when there is one.
     * @throws SchemaError
     */
    public function check_definition( array $raw, array &$field, string $at, ?array $stored ): void {
        $this->check_format( $raw, $field, $at, $stored );
        $this->check_bounds( $raw, $field, $at );
        $this->check_choices( $raw, $field, $at );
        $this->check_post_types( $raw, $field, $at );
    }

    protected function check_format( array $raw, array &$field, string $at, ?array $stored ): void {
        if ( array_key_exists( 'format', $raw ) ) {
            throw new SchemaError( esc_html( $at . '.format' ), 'Only number fields have a format.' );
        }
    }

    protected function check_bounds( array $raw, array &$field, string $at ): void {
        foreach ( [ 'min', 'max' ] as $bound ) {
            if ( array_key_exists( $bound, $raw ) ) {
                throw new SchemaError( esc_html( $at . '.' . $bound ), 'Invalid ' . esc_html( $bound ) . ' for a ' . esc_html( $field['type'] ) . ' field.' );
            }
        }
    }

    protected function check_choices( array $raw, array &$field, string $at ): void {
        if ( array_key_exists( 'choices', $raw ) ) {
            throw new SchemaError( esc_html( $at . '.choices' ), 'Only select fields have choices.' );
        }
    }

    protected function check_post_types( array $raw, array &$field, string $at ): void {
        if ( array_key_exists( 'postTypes', $raw ) ) {
            throw new SchemaError( esc_html( $at . '.postTypes' ), 'Only post_object and relationship fields have postTypes.' );
        }
    }

    /**
     * The `default` a definition sends, checked against the finished definition.
     *
     * @return mixed
     * @throws SchemaError
     */
    public function check_default( $value, array $field, string $at ) {
        throw new SchemaError( esc_html( $at ), 'A ' . esc_html( $field['type'] ) . ' field takes no default.' );
    }

    // ------------------------------------------------------------------------------------------
    // Values
    // ------------------------------------------------------------------------------------------

    /** The value of an unfilled field, when its definition has no default. */
    public function empty_value( array $field ) {
        return '';
    }

    /** The empty value IGNORING the default: what "cleared" stores. */
    public function blank_value( array $field ) {
        unset( $field['default'] );
        return ValueCodec::empty_value( $field );
    }

    /** What `required` refuses. */
    public function is_empty( $value ): bool {
        return $value === null || $value === '' || $value === [];
    }

    /** Whether a submitted value is the one already stored. */
    public function same( $value, $stored ): bool {
        return wp_json_encode( $value ) === wp_json_encode( $stored );
    }

    /**
     * Check and clean one value. `$kept`: the record already holds exactly this value here, so
     * only its shape is judged, not today's rules (a choice since removed, a record since trashed).
     *
     * @param mixed                  $previous The stored value, or null when there is none.
     * @return mixed
     */
    abstract public function normalize( array $field, $value, $previous, bool $kept, string $at );

    /**
     * The identities a list counts against its `max` (ids, row ids); null for a type that is
     * not a counted list.
     *
     * @return array|null
     */
    public function counted_items( $value ): ?array {
        return null;
    }

    // ------------------------------------------------------------------------------------------
    // Storage (StorageLayout paths)
    // ------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $meta Filled in place. */
    public function flatten( array $field, $value, string $path, array &$meta ): void {
        $meta[ $path ] = (string) $value;
    }

    /**
     * The value from its stored row (present), children included for a container.
     *
     * @param mixed               $raw     The row at $path.
     * @param array<string, true> $present Filled in place with every path read.
     */
    public function inflate( array $field, $raw, string $path, array $meta, array &$present ) {
        return is_scalar( $raw ) ? (string) $raw : '';
    }

    // ------------------------------------------------------------------------------------------
    // Shapes
    // ------------------------------------------------------------------------------------------

    /** Editor → public (names, no retired). */
    public function public_value( array $field, $value, bool $row_ids ) {
        return $value;
    }

    /** Public → editor. @param mixed $previous The stored editor value. */
    public function from_public( array $field, $value, $previous, string $at ) {
        return $value;
    }

    /** Editor value with retired children removed. */
    public function editor_value( array $field, $value ) {
        return $value;
    }

    /** Retired children carried from the stored value into a new one. */
    public function with_retired( array $field, $next, $previous ) {
        return $next;
    }

    /**
     * A public value as fabrement()->fields() gives it: what is stored (record and media ids the originals
     * of their translation groups); an empty link is null.
     */
    public function api_value( array $field, $value ) {
        return $value;
    }

    /** A public value as the reader of this page sees it (ids in the reader's language). */
    public function reader_value( array $field, $value ) {
        return $value;
    }

    /**
     * What a query looks for in the meta row, for values given to it (an address, code): stored
     * the way normalize() stores. Values as they are, unless the type stores them otherwise.
     *
     * @param list<mixed> $values
     * @return list<mixed>
     */
    public function search_values( array $field, array $values ): array {
        return $values;
    }

    /**
     * The records and media a value points at, for the editor's refs.
     *
     * @param array<int, true> $posts Filled in place.
     * @param array<int, true> $media Filled in place.
     */
    public function references( array $field, $value, array &$posts, array &$media ): void {
    }

    // ------------------------------------------------------------------------------------------
    // Translation (called through TranslationPolicy, which applies the policy first)
    // ------------------------------------------------------------------------------------------

    /** A value the translation's value follows the original for (its own `copy`, or container rows). */
    public function follows_original( array $field ): bool {
        return $field['translation'] === 'copy';
    }

    /** @see TranslationPolicy::merge() */
    public function merge( array $field, $source, $target, bool $target_has, string $path, ?array $present ) {
        return $field['translation'] === 'copy' || ! $target_has ? $source : $target;
    }

    /** @see TranslationPolicy::assert_editable() */
    public function assert_editable( array $field, $submitted, $source, string $at, $current ): void {
        $unchanged = $current !== null && wp_json_encode( $submitted ) === wp_json_encode( $current );
        if ( $field['translation'] === 'copy' && ! $unchanged && wp_json_encode( $submitted ) !== wp_json_encode( $source ) ) {
            throw new \InvalidArgumentException( esc_html( $at ) . ': ' . esc_html( $field['label'] ) . ': This value is copied from the original language: change it on the original.' );
        }
    }

    /** @see TranslationPolicy::with_original() */
    public function with_original( array $field, $submitted, $source ) {
        return $field['translation'] === 'copy' ? $source : $submitted;
    }

    /**
     * @see TranslationPolicy::locks()
     * @param array{fields: string[], rows: string[]} $locks Filled in place.
     */
    public function locks( array $field, string $path, array &$locks ): void {
        if ( $field['translation'] === 'copy' ) {
            $locks['fields'][] = $path;
        }
    }

    /** @see TranslationPolicy::word_locks() @param string[] $out */
    public function word_locks( array $field, string $path, array &$out ): void {
        if ( $field['translation'] === 'translate' ) {
            $out[] = $path;
        }
    }

    /**
     * The words of a `translate` value a translator sees. Only called for `translate`.
     *
     * @param string[] $path Token path of this value.
     * @return array<string, array{text: string, label: string, html: bool}>
     */
    public function texts( array $field, $value, array $path, string $label ): array {
        return [];
    }

    /** Translated words put back. Only called for `translate`. */
    public function apply_texts( array $field, $value, array $texts, array $path ) {
        return $value;
    }

    public function clear_texts( array $field, $value, ?array $source, array $job, array $path, array &$cleared ) {
        return $value;
    }
}
