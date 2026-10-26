<?php

namespace Fabrement\Modules\Fields\Schema;

use Fabrement\Modules\Fields\Types\FieldTypes;

/**
 * Turns the fields an agent sends into the stored field tree.
 *
 * The agent sends the ACTIVE fields only. A field it leaves out is not deleted: it stays in
 * the tree as `retired`, hidden from the editor and the PHP API, so its stored values keep
 * moving with their repeater rows and its name cannot be taken by something else. Sending the
 * same name again brings it back.
 *
 * Identity is the name; the key follows from it (StorageLayout::key_for). There is no rename: a
 * new name is a new field, and the old one is retired. A field keeps its type and number format
 * for good; everything else may change.
 */
final class SchemaValidator {

    public const TYPES = FieldTypes::NAMES;
    public const TRANSLATION = [ 'translate', 'copy', 'copy_once', 'ignore' ];

    /**
     * The policy a field gets when none is sent: words (text, textarea, wysiwyg, a link's caption)
     * are translated, everything else (numbers, dates, switches, choices, media, records, and the
     * rows of groups and repeaters, whose sub-fields keep their own policy) is copied — the same
     * split ACF Multilingual uses. An existing field keeps the policy it has.
     */
    public static function default_translation( string $type ): string {
        return FieldTypes::get( $type )->is_words() ? 'translate' : 'copy';
    }

    private const PROPERTIES = [
        'key', 'name', 'label', 'description', 'type', 'translation', 'required',
        'format', 'default', 'min', 'max', 'choices', 'sub_fields', 'postTypes',
    ];
    private const MAX_FIELDS = 200;
    private const MAX_DEPTH = 8;
    private const MAX_BYTES = 262144;

    /** @var callable(string): ?array */
    private $shared;
    private int $count = 0;

    /**
     * @param callable(string): ?array $shared Finds a top-level field with this key in ANOTHER
     *                                         owner's file, for a field reused across owners.
     */
    public function __construct( callable $shared ) {
        $this->shared = $shared;
    }

    /**
     * @param mixed $input    The active fields as sent.
     * @param array $previous The stored tree, retired fields included; [] for a new owner.
     * @return array The full tree to store.
     * @throws SchemaError
     */
    public function normalize( $input, array $previous ): array {
        if ( strlen( (string) wp_json_encode( $input ) ) > self::MAX_BYTES ) {
            throw new SchemaError( 'fields', 'The field list exceeds 256 KiB.' );
        }
        $this->count = 0;
        return $this->level( $input, $previous, 'fields', 0 );
    }

    private function level( $input, array $previous, string $at, int $depth ): array {
        if ( ! is_array( $input ) || array_values( $input ) !== $input ) {
            throw new SchemaError( esc_html( $at ), 'Expected a list of fields.' );
        }
        if ( $depth > self::MAX_DEPTH ) {
            throw new SchemaError( esc_html( $at ), 'Fields nest at most ' . (int) self::MAX_DEPTH . ' levels deep.' );
        }
        $before = [];
        foreach ( $previous as $field ) {
            $before[ $field['key'] ] = $field;
        }
        $names = [];
        $out = [];
        foreach ( $input as $i => $raw ) {
            $path = $at . '[' . $i . ']';
            if ( ++$this->count > self::MAX_FIELDS ) {
                throw new SchemaError( esc_html( $path ), 'At most ' . (int) self::MAX_FIELDS . ' fields per owner.' );
            }
            $field = $this->field( $raw, $path, $depth, $before );
            if ( isset( $names[ $field['name'] ] ) ) {
                throw new SchemaError( esc_html( $path . '.name' ), 'Another field on this level is already called "' . esc_html( $field['name'] ) . '".' );
            }
            $names[ $field['name'] ] = true;
            unset( $before[ $field['key'] ] );
            $out[] = $field;
        }
        // Left out = retired, never deleted. Its subtree is kept exactly as stored.
        foreach ( $before as $field ) {
            $field['retired'] = true;
            $out[] = $field;
        }
        if ( $clash = StorageLayout::clash( $out ) ) {
            throw new SchemaError( esc_html( $at ), '"' . esc_html( $clash[0] ) . '" would be stored inside the group "' . esc_html( $clash[1] ) . '" (a retired one counts too). Choose a name that does not start with "' . esc_html( $clash[1] ) . '_".' );
        }
        return $out;
    }

    private function field( $raw, string $at, int $depth, array $before ): array {
        if ( ! is_array( $raw ) || ( $raw && array_values( $raw ) === $raw ) ) {
            throw new SchemaError( esc_html( $at ), 'Expected a field object.' );
        }
        foreach ( array_keys( $raw ) as $property ) {
            if ( ! in_array( $property, self::PROPERTIES, true ) ) {
                throw new SchemaError( esc_html( $at . '.' . $property ), $property === 'retired'
                    ? '"retired" is not sent: leave a retired field out (it stays retired), or send it without "retired" to restore it.'
                    : 'Unknown property "' . esc_html( $property ) . '".' );
            }
        }
        $name = $raw['name'] ?? null;
        if ( ! is_string( $name ) || ! StorageLayout::valid_name( $name ) ) {
            throw new SchemaError( esc_html( $at . '.name' ), 'Use a-z, digits and single underscores, starting with a letter, at most ' . (int) StorageLayout::NAME_MAX . ' characters; no part may be only digits, and it may not start with "fab_".' );
        }
        $type = $raw['type'] ?? null;
        if ( ! in_array( $type, self::TYPES, true ) ) {
            throw new SchemaError( esc_html( $at . '.type' ), 'Unsupported field type. Use one of: ' . esc_html( implode( ', ', self::TYPES ) ) . '.' );
        }

        $kind = FieldTypes::get( $type );

        $key = StorageLayout::key_for( $name, $depth === 0 );
        if ( array_key_exists( 'key', $raw ) && $raw['key'] !== $key ) {
            throw new SchemaError( esc_html( $at . '.key' ), 'The key follows from the name ("' . esc_html( $key ) . '"): leave "key" out. A field cannot be renamed — a new name is a new field.' );
        }
        // Same name = same field: this owner's (retired ones included). At the top level another
        // post type may already have it under the same meta key: then type and number format must
        // match, so one meta query reads both alike. Its sub-fields and policies are its own.
        $own = $before[ $key ] ?? null;
        $stored = $own ?? ( $depth === 0 ? ( $this->shared )( $key ) : null );
        if ( $stored !== null && $stored['type'] !== $type ) {
            throw new SchemaError(
                esc_html( $at . '.type' ),
                $own === null
                    ? '"' . esc_html( $name ) . '" is already a ' . esc_html( $stored['type'] ) . ' field on another post type, stored under the same key. Use the same type there, or another name.'
                    : ( empty( $stored['retired'] )
                        ? 'A field\'s type never changes (' . esc_html( $stored['type'] ) . '). Add a new field with a new name instead.'
                        : '"' . esc_html( $name ) . '" belongs to a retired ' . esc_html( $stored['type'] ) . ' field whose values are kept. Use another name.' ),
                'field_type_locked'
            );
        }

        $field = [
            'key'         => $key,
            'name'        => $name,
            'label'       => self::text( $raw['label'] ?? null, $at . '.label', 200 ),
            'description' => self::text( $raw['description'] ?? null, $at . '.description', 2000 ),
            'type'        => $type,
            'translation' => $raw['translation'] ?? ( $own['translation'] ?? self::default_translation( $type ) ),
            'required'    => $raw['required'] ?? false,
        ];
        if ( ! in_array( $field['translation'], self::TRANSLATION, true ) ) {
            throw new SchemaError( esc_html( $at . '.translation' ), 'Choose translate, copy, copy_once or ignore.' );
        }
        // `translate` means words that go to translators. A number, date, choice, media, record or
        // a container has none: its per-language value is `copy_once`, a container's rows follow
        // the original under `copy`. A stored `translate` from before is carried over to the policy
        // it always behaved as; only a newly sent one is refused.
        if ( $field['translation'] === 'translate' && ! $kind->is_words() ) {
            $container = $kind->is_container();
            if ( array_key_exists( 'translation', $raw ) ) {
                throw new SchemaError(
                    esc_html( $at . '.translation' ),
                    $container
                        ? 'A ' . esc_html( $type ) . ' has no words of its own: use copy (rows follow the original; each sub-field keeps its own policy), copy_once (the rows are seeded once, then each language has its own) or ignore.'
                        : 'A ' . esc_html( $type ) . ' field has no words to translate: use copy (one value for every language) or copy_once (seeded from the original, then each language has its own, e.g. a price or a date that differs).'
                );
            }
            $field['translation'] = $container ? 'copy' : 'copy_once';
        }
        if ( ! is_bool( $field['required'] ) ) {
            throw new SchemaError( esc_html( $at . '.required' ), 'Expected true or false.' );
        }
        if ( $field['required'] && ! $kind->allows_required() ) {
            throw new SchemaError( esc_html( $at . '.required' ), 'Only fields that can be empty may be required.' );
        }

        // format, min/max, choices, postTypes: each type checks what it takes and refuses the rest.
        $kind->check_definition( $raw, $field, $at, $stored );

        if ( $kind->is_container() ) {
            $children = $raw['sub_fields'] ?? null;
            if ( ! is_array( $children ) || ! $children ) {
                throw new SchemaError( esc_html( $at . '.sub_fields' ), 'A ' . esc_html( $type ) . ' needs at least one sub-field.' );
            }
            $field['sub_fields'] = $this->level( $children, $own['sub_fields'] ?? [], $at . '.sub_fields', $depth + 1 );
        } elseif ( array_key_exists( 'sub_fields', $raw ) ) {
            throw new SchemaError( esc_html( $at . '.sub_fields' ), 'Only group and repeater fields have sub-fields.' );
        }

        if ( array_key_exists( 'default', $raw ) ) {
            $field['default'] = $kind->check_default( $raw['default'], $field, $at . '.default' );
        }
        if ( $depth === 0 && StorageLayout::longest_path( $field ) > StorageLayout::MAX_KEY_LENGTH ) {
            throw new SchemaError( esc_html( $at ), 'Nested too deep for WordPress meta keys. Use fewer nested levels.' );
        }
        if ( $own !== null ) {
            self::assert_compatible( $own, $field, $at );
        }
        return $field;
    }

    public static function date( string $value, bool $with_time ): bool {
        $pattern = $with_time ? '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d$/D' : '/^(\d{4})-(\d{2})-(\d{2})$/D';
        return preg_match( $pattern, $value, $m ) === 1 && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /**
     * The storage shape of a kept key must not change: same type and number format, and the
     * same for every child key the two trees share. Labels, names and policies may differ.
     */
    public static function assert_compatible( array $stored, array $field, string $at ): void {
        if ( $stored['type'] !== $field['type'] || ( $stored['format'] ?? null ) !== ( $field['format'] ?? null ) ) {
            throw new SchemaError( esc_html( $at ), 'Field "' . esc_html( $field['key'] ) . '" is stored as ' . esc_html( $stored['type'] ) . ( isset( $stored['format'] ) ? ' (' . esc_html( $stored['format'] ) . ')' : '' ) . ' and cannot change.', 'field_type_locked' );
        }
        $children = [];
        foreach ( $stored['sub_fields'] ?? [] as $child ) {
            $children[ $child['key'] ] = $child;
        }
        foreach ( $field['sub_fields'] ?? [] as $i => $child ) {
            if ( isset( $children[ $child['key'] ] ) ) {
                self::assert_compatible( $children[ $child['key'] ], $child, $at . '.sub_fields[' . $i . ']' );
            }
        }
    }

    private static function text( $value, string $at, int $max ): string {
        if ( ! is_string( $value ) || trim( $value ) === '' || strlen( $value ) > $max ) {
            throw new SchemaError( esc_html( $at ), 'Expected non-empty text, at most ' . (int) $max . ' characters.' );
        }
        return $value;
    }
}
