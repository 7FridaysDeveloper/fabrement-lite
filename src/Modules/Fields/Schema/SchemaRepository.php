<?php

namespace Fabrement\Modules\Fields\Schema;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Types\FieldTypes;
use Fabrement\Modules\Storage\FabrementStorage;
use Fabrement\Modules\Storage\StorageFiles;

/**
 * Field files: `uploads/fabrement-lite/fields/<type>.<slug>.json`, one per owner.
 *
 *   { schemaVersion: 1, type, slug, revision, fields: [...], layout: {tabs: [...]} }
 *
 * The file is the only source of truth for field definitions.
 * Hand edits are the site owner's business: a file that does not parse or validate
 * simply switches that owner's fields off, and stored values are left alone.
 */
final class SchemaRepository {

    public const SUBDIR = 'fields';
    public const FORMAT = 1;

    /** @var array<string, array|null> Per-request memo, keyed by file name. */
    private static array $memo = [];
    /** @var array<string, string> Files that exist but could not be read, with the reason. */
    private static array $broken = [];
    /** Bumped whenever a file is written or the memo is dropped: what callers key their own caches on. */
    private static int $generation = 0;

    /** Changes when any field file may have changed in this request. */
    public static function generation(): int {
        return self::$generation;
    }

    public static function dir(): string {
        return FabrementStorage::path( self::SUBDIR );
    }

    /** The stored document, or null when the owner has no fields (or its file is unreadable). */
    public static function get( Owner $owner ): ?array {
        $file = $owner->file();
        if ( ! array_key_exists( $file, self::$memo ) ) {
            self::$memo[ $file ] = self::load( $owner );
        }
        return self::$memo[ $file ];
    }

    /** @return array<string, array> Every readable document, keyed by owner id. */
    public static function all(): array {
        $documents = [];
        foreach ( glob( self::dir() . '/*.json' ) ?: [] as $path ) {
            $owner = Owner::from_file( basename( $path ) );
            if ( $owner && ( $document = self::get( $owner ) ) ) {
                $documents[ $owner->id() ] = $document;
            }
        }
        ksort( $documents );
        return $documents;
    }

    /** @return array<string, string> owner file => why it is switched off. */
    public static function broken(): array {
        self::all();
        return self::$broken;
    }

    /**
     * Read-modify-write. `$change` receives the current document (null when there is none) and
     * returns the new one; the revision is stamped here. No lock: the caller's revision check is
     * the guard, and two saves racing within the same milliseconds are accepted as a rare case.
     *
     * @param callable(?array): array $change May throw; nothing is written then.
     * @return array The stored document.
     */
    public static function update( Owner $owner, callable $change ): array {
        if ( ! StorageFiles::ensure_dir( self::dir() ) ) {
            throw new \RuntimeException( 'uploads/fabrement-lite/fields is not writable.' );
        }
        $path = self::dir() . '/' . $owner->file();
        $current = self::load( $owner );
        if ( $current === null && is_file( $path ) ) {
            throw new \RuntimeException( esc_html( self::$broken[ $owner->file() ] ) . ' Ask the user to fix or remove uploads/fabrement-lite/fields/' . esc_html( $owner->file() ) . '; it cannot be changed through MCP.' );
        }
        $next = $change( $current );
        $next = [
            'schemaVersion' => self::FORMAT,
            'type'          => $owner->type,
            'slug'          => $owner->slug,
            'revision'      => ( $current['revision'] ?? 0 ) + 1,
            'fields'        => array_values( $next['fields'] ?? [] ),
            'layout'        => $next['layout'] ?? [ 'tabs' => [] ],
        ] + ( $owner->type === 'options' ? [ 'page' => $next['page'] ?? [] ] : [] );
        if ( ! StorageFiles::write_json( $path, $next ) ) {
            throw new \RuntimeException( 'Could not write ' . esc_html( $owner->file() ) . '.' );
        }
        self::$memo[ $owner->file() ] = $next;
        unset( self::$broken[ $owner->file() ] );
        self::$generation++;
        return $next;
    }

    /** Forget the per-request memo; for tests and long-running CLI commands. */
    public static function flush(): void {
        self::$memo = [];
        self::$broken = [];
        self::$generation++;
    }

    private static function load( Owner $owner ): ?array {
        $path = self::dir() . '/' . $owner->file();
        if ( ! is_file( $path ) ) {
            return null;
        }
        $data = StorageFiles::read_json( $path );
        $problem = null;
        if ( ! is_array( $data ) ) {
            $problem = $owner->file() . ' is not valid JSON.';
        } elseif ( ( $data['schemaVersion'] ?? null ) !== self::FORMAT || ( $data['type'] ?? null ) !== $owner->type
            || ( $data['slug'] ?? '' ) !== $owner->slug || ! is_int( $data['revision'] ?? null ) || ! is_array( $data['fields'] ?? null ) ) {
            $problem = $owner->file() . ' does not describe ' . $owner->id() . ' fields.';
        } elseif ( ( $error = self::structural( $data['fields'] ) ) !== null ) {
            $problem = $owner->file() . ': ' . $error;
        } elseif ( ( $error = self::layout_problem( $data['layout'] ?? null ) ) !== null ) {
            $problem = $owner->file() . ': ' . $error;
        }
        if ( $problem !== null ) {
            self::$broken[ $owner->file() ] = $problem;
            return null;
        }
        unset( $data['updatedAt'] );
        $data['layout'] = is_array( $data['layout'] ?? null ) ? $data['layout'] : [ 'tabs' => [] ];
        if ( $owner->type === 'options' ) {
            $data['page'] = OptionsPage::read( $data['page'] ?? null, $owner->slug );
        }
        return $data;
    }

    /**
     * What is wrong with a stored layout's shape, or null. save-fields only writes valid ones; a
     * file edited by hand can hold anything, and every screen that draws the form walks
     * tabs[].items[].field — so a broken one switches the fields off with a problem to report,
     * like a broken field list, instead of failing every page that reads it.
     */
    private static function layout_problem( $layout ): ?string {
        if ( $layout === null ) {
            return null;
        }
        if ( ! is_array( $layout ) || ! is_array( $layout['tabs'] ?? [] ) ) {
            return 'layout must be {tabs: [...]}.';
        }
        foreach ( array_values( $layout['tabs'] ?? [] ) as $t => $tab ) {
            if ( ! is_array( $tab ) || ! is_string( $tab['label'] ?? null ) || ! is_array( $tab['items'] ?? null ) ) {
                return sprintf( 'layout.tabs[%d] needs a label and a list of items.', $t );
            }
            foreach ( array_values( $tab['items'] ) as $i => $item ) {
                if ( ! is_array( $item ) || ! is_string( $item['field'] ?? null ) ) {
                    return sprintf( 'layout.tabs[%d].items[%d] needs a field.', $t, $i );
                }
            }
        }
        return null;
    }

    /** Remove an owner's file. Its values are the caller's business (SchemaService::delete_options_page()). */
    public static function delete( Owner $owner ): bool {
        $path = self::dir() . '/' . $owner->file();
        if ( ! is_file( $path ) ) {
            return false;
        }
        if ( ! wp_delete_file( $path ) ) {
            throw new \RuntimeException( 'Could not remove ' . esc_html( $owner->file() ) . '.' );
        }
        unset( self::$memo[ $owner->file() ], self::$broken[ $owner->file() ] );
        self::$generation++;
        return true;
    }

    /**
     * What the runtime needs to trust a hand-edited file: every field has a valid name, the key
     * that name gives, a known type and, if compound, sub-fields; names are unique on each level.
     * Full rules run on save, not on read.
     */
    private static function structural( array $fields ): ?string {
        $error = null;
        $check = static function ( array $list, bool $top_level ) use ( &$check, &$error ): void {
            $names = [];
            foreach ( $list as $field ) {
                if ( $error !== null ) {
                    return;
                }
                if ( ! is_array( $field ) || ! is_string( $field['name'] ?? null ) || ! is_string( $field['key'] ?? null )
                    || ! StorageLayout::valid_key( $field['key'], $field['name'], $top_level ) || ! in_array( $field['type'] ?? null, SchemaValidator::TYPES, true ) ) {
                    $error = 'a field has no valid name, key (' . Keys::FIELD_META_PREFIX . '<name> at the top, the name below) or type.';
                    return;
                }
                if ( isset( $names[ $field['name'] ] ) ) {
                    $error = 'name ' . $field['name'] . ' appears twice on one level.';
                    return;
                }
                $names[ $field['name'] ] = true;
                if ( FieldTypes::get( $field['type'] )->is_container() ) {
                    if ( ! is_array( $field['sub_fields'] ?? null ) ) {
                        $error = $field['key'] . ' has no sub_fields.';
                        return;
                    }
                    $check( $field['sub_fields'], false );
                }
            }
            if ( $error === null && ( $clash = StorageLayout::clash( $list ) ) ) {
                $error = $clash[0] . ' would be stored inside group ' . $clash[1] . '.';
            }
        };
        $check( $fields, true );
        return $error;
    }
}
