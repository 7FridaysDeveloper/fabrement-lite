<?php

namespace Fabrement\Modules\Fields\Schema;

use Fabrement\Modules\Fields\Values\Store\OptionRows;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Translation\OptionVersions;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * What the MCP tools do with field files: read, check, save. Everything is local.
 */
final class SchemaService {

    /** @return array|WP_Error */
    public static function get( string $type, string $slug ) {
        $owner = self::owner( $type, $slug, false );
        if ( is_wp_error( $owner ) ) {
            return $owner;
        }
        $document = SchemaRepository::get( $owner );
        $broken = SchemaRepository::broken()[ $owner->file() ] ?? null;
        return [
            'owner'    => $owner->id(),
            'file'     => 'uploads/fabrement-lite/fields/' . $owner->file(),
            'exists'   => $owner->type === 'options' ? $document !== null : $owner->exists(),
            'revision' => $document['revision'] ?? 0,
            'fields'   => $document['fields'] ?? [],
            'layout'   => $document['layout'] ?? [ 'tabs' => [] ],
        ] + ( $owner->type === 'options' ? [ 'page' => $document['page'] ?? null ] : [] )
            + ( $broken ? [ 'problem' => $broken ] : [] );
    }

    /** Every check runs before the write; a refusal writes nothing and names the input to fix. @return array|WP_Error */
    public static function save( string $type, string $slug, array $input ) {
        $owner = self::owner( $type, $slug, true );
        if ( is_wp_error( $owner ) ) {
            return $owner;
        }
        $autoloaded = $owner->type === 'options' ? ( SchemaRepository::get( $owner )['page']['autoload'] ?? null ) : null;
        $expected = $input['expectedRevision'] ?? null;
        if ( ! is_int( $expected ) || $expected < 0 ) {
            return new WP_Error( 'fields_precondition', 'Send expectedRevision: the revision from ' . self::read_tool( $owner ) . ' (0 when the owner has no fields yet).' );
        }
        try {
            $document = SchemaRepository::update( $owner, static function ( ?array $current ) use ( $owner, $input, $expected ): array {
                if ( ( $current['revision'] ?? 0 ) !== $expected ) {
                    throw new SchemaError( 'expectedRevision', 'The fields changed since you read them (now revision ' . (int) ( $current['revision'] ?? 0 ) . '). Read them again with ' . esc_html( self::read_tool( $owner ) ) . '.', 'fields_conflict' );
                }
                return self::apply( $owner, $current, $input );
            } );
        } catch ( SchemaError $e ) {
            return self::error( $e );
        } catch ( \RuntimeException $e ) {
            return new WP_Error( 'fields_storage', $e->getMessage() );
        }
        if ( $owner->type === 'options' && $autoloaded !== null && $autoloaded !== $document['page']['autoload'] ) {
            OptionRows::set_autoload( $owner->slug, $document['page']['autoload'] );
        }
        do_action( 'fabrement_fields_schema_saved', $owner, $document );
        if ( $owner->type === 'options' && $expected === 0 ) {
            try {
                OptionVersions::ensure( FieldObject::options( $owner->slug, Translation::bridge()->default_language() ) );
            } catch ( \RuntimeException $error ) {
                return new WP_Error( 'fields_storage', 'The schema was saved, but its initial values row could not be created. Read get-options-page again before retrying.', [ 'revision' => $document['revision'] ] );
            }
        }
        return [ 'saved' => true, 'owner' => $owner->id(), 'revision' => $document['revision'], 'fields' => $document['fields'], 'layout' => $document['layout'] ]
            + ( $owner->type === 'options' ? [ 'page' => $document['page'] ] : [] );
    }

    /** Merge the sent sections into the current document; a section left out stays as it is. */
    private static function apply( Owner $owner, ?array $current, array $input ): array {
        $has_fields = array_key_exists( 'fields', $input );
        $has_layout = array_key_exists( 'layout', $input );
        $has_page = array_key_exists( 'page', $input );
        if ( $has_page && $owner->type !== 'options' ) {
            throw new SchemaError( 'page', 'Only an options page has page settings.' );
        }
        if ( ! $has_fields && ! $has_layout && ! $has_page ) {
            throw new SchemaError( 'fields', $owner->type === 'options' ? 'Send page, fields, layout or any of them.' : 'Send fields, layout or both.' );
        }
        if ( $owner->type === 'options' && $current === null && ! $has_page ) {
            throw new SchemaError( 'page', 'A new options page needs page: {title}.' );
        }
        $fields = $current['fields'] ?? [];
        if ( $has_fields ) {
            $validator = new SchemaValidator( static fn( string $key ): ?array => self::shared( $owner, $key ) );
            $before = $fields;
            $fields = $validator->normalize( $input['fields'], $fields );
            self::assert_keys_mean_the_same( $owner, $fields );
            self::assert_translations_kept( $before, $fields, ! empty( $input['force'] ) );
        }
        $layout = $has_layout
            ? LayoutValidator::normalize( $input['layout'], $fields )
            : LayoutValidator::prune( $current['layout'] ?? [ 'tabs' => [] ], $fields );
        $out = [ 'fields' => $fields, 'layout' => $layout ];
        if ( $owner->type === 'options' ) {
            $out['page'] = $has_page ? OptionsPage::normalize( $input['page'], $current['page'] ?? null ) : $current['page'];
        }
        return $out;
    }

    /**
     * A field that becomes `copy` takes the original's value in every language version at the next
     * save of the original: what was written for a language is gone, and switching back does not
     * return it. So on a site with languages that change is made only with `force`, after the
     * caller was told which fields it is.
     *
     * @param list<array<string,mixed>> $before
     * @param list<array<string,mixed>> $after
     */
    private static function assert_translations_kept( array $before, array $after, bool $forced ): void {
        if ( $forced || ! Translation::is_multilingual() ) {
            return;
        }
        $policies = static function ( array $fields, string $prefix = '' ) use ( &$policies ): array {
            $out = [];
            foreach ( $fields as $field ) {
                $out[ (string) ( $field['key'] ?? '' ) ] = [ $prefix . (string) ( $field['name'] ?? '' ), (string) ( $field['translation'] ?? '' ) ];
                $out                                   += $policies( (array) ( $field['sub_fields'] ?? [] ), $prefix . (string) ( $field['name'] ?? '' ) . '.' );
            }
            return $out;
        };
        $was     = $policies( $before );
        $changed = [];
        foreach ( $policies( $after ) as $key => [ $name, $policy ] ) {
            $old = $was[ $key ][1] ?? '';
            if ( $policy === 'copy' && $old !== '' && $old !== 'copy' ) {
                $changed[] = sprintf( '"%s" (%s → copy)', $name, $old );
            }
        }
        if ( $changed !== [] ) {
            throw new SchemaError(
                'fields',
                sprintf(
                    'This makes %s a copy of the original in every language. At the next save of an original record, each of its language versions takes the original\'s value for %s, and what was written for that language is gone — setting the policy back does not return it. Nothing was saved. Tell the user which fields lose their translations; when they agree, send the same call with force: true.',
                    implode( ', ', $changed ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- validated field names and fixed policy words, read as JSON text; escaping turns the quotes into entities.
                    count( $changed ) === 1 ? 'it' : 'them'
                ),
                'fields_replace_translations'
            );
        }
    }

    /**
     * Every options page: its slug, title and what it holds.
     *
     * @return list<array{slug: string, title: string, menuTitle: string, position: int, revision: int, fields: int}>
     */
    public static function options_pages(): array {
        $pages = [];
        foreach ( SchemaRepository::all() as $document ) {
            if ( ( $document['type'] ?? '' ) !== 'options' ) {
                continue;
            }
            $pages[] = [
                'slug'     => $document['slug'],
                'revision' => $document['revision'],
                'fields'   => count( array_filter( $document['fields'], static fn( array $f ): bool => empty( $f['retired'] ) ) ),
            ] + $document['page'];
        }
        usort( $pages, static fn( array $a, array $b ): int => [ $a['position'], $a['slug'] ] <=> [ $b['position'], $b['slug'] ] );
        return $pages;
    }

    /**
     * Remove an options page for good: its file (fields, tabs, menu entry) and its values in every
     * language. Nothing is kept to come back.
     *
     * @return array|WP_Error
     */
    public static function delete_options_page( string $slug, int $expected ) {
        $owner = Owner::of( 'options', $slug );
        $document = $owner ? SchemaRepository::get( $owner ) : null;
        if ( ! $document ) {
            return new WP_Error( 'fields_owner', 'No options page "' . $slug . '". See list-options-pages.' );
        }
        if ( $document['revision'] !== $expected ) {
            return new WP_Error( 'fields_conflict', 'The page changed since you read it (now revision ' . $document['revision'] . '). Read it again with get-options-page.' );
        }
        try {
            SchemaRepository::delete( $owner );
        } catch ( \RuntimeException $e ) {
            return new WP_Error( 'fields_storage', $e->getMessage() );
        }
        $rows = OptionRows::all( $slug );
        foreach ( $rows as $row ) {
            delete_option( $row );
        }
        do_action( 'fabrement_fields_schema_deleted', $owner );
        return [ 'deleted' => true, 'options' => $slug, 'valueRowsDeleted' => count( $rows ) ];
    }

    /**
     * Post types share one meta table: a key must mean the same field in every one of them — a
     * group's `venue.city` here and a field `venue_city` there would both be `fab_venue_city`, and
     * one meta query, a search or an SEO variable would read the two as one. Same path, same type;
     * retired fields count, their values are kept. A repeater's children too: the same repeater on
     * two types writes the same row keys (fab_agenda_0_time). Options pages each have their own row.
     *
     * @throws SchemaError
     */
    private static function assert_keys_mean_the_same( Owner $owner, array $fields ): void {
        if ( $owner->type === 'options' ) {
            return;
        }
        $mine = StorageLayout::keys( $fields );
        foreach ( self::other_owners( $owner ) as $id => $document ) {
            $stored = $document['fields'] ?? [];
            foreach ( array_intersect_key( StorageLayout::keys( $stored ), $mine ) as $key => $theirs ) {
                if ( $theirs === $mine[ $key ] ) {
                    continue;
                }
                throw new SchemaError(
                    'fields',
                    esc_html( sprintf(
                        '"%1$s" (%2$s) would be stored under the key %3$s, which on %4$s "%5$s" already holds "%6$s" (%7$s). One key is one field for every %4$s: use the same path and type there, or another name.',
                        $mine[ $key ]['path'],
                        $mine[ $key ]['type'],
                        $key,
                        $owner->type,
                        (string) ( $document['slug'] ?? $id ),
                        $theirs['path'],
                        $theirs['type']
                    ) ),
                    'field_key_clash'
                );
            }
        }
    }

    /**
     * A top-level field with this key in another owner's file of the same kind: post types share
     * one meta table, so one key means one storage shape there. Options pages each have their own
     * row and share nothing.
     */
    private static function shared( Owner $owner, string $key ): ?array {
        if ( $owner->type === 'options' ) {
            return null;
        }
        foreach ( self::other_owners( $owner ) as $document ) {
            foreach ( $document['fields'] as $field ) {
                if ( $field['key'] === $key ) {
                    return $field;
                }
            }
        }
        return null;
    }

    /** Other schemas sharing this owner's storage namespace. */
    private static function other_owners( Owner $owner ): iterable {
        foreach ( SchemaRepository::all() as $id => $document ) {
            if ( $id !== $owner->id() && ( $document['type'] ?? '' ) === $owner->type ) {
                yield $id => $document;
            }
        }
    }

    /** @return Owner|WP_Error */
    private static function owner( string $type, string $slug, bool $must_exist ) {
        $owner = Owner::of( $type, $slug );
        if ( ! $owner ) {
            return new WP_Error( 'fields_owner', $type === 'options' ? 'slug: a-z, 0-9, _ and -, at most 32 characters, no "__".' : 'type is post (term, user and comment are not available yet); slug is the post type.' );
        }
        if ( ! $owner->enabled() ) {
            return new WP_Error( 'fields_owner', 'Fields for ' . $type . ' are not available yet. Post types and options pages are.' );
        }
        if ( $must_exist && ! $owner->exists() ) {
            return new WP_Error( 'fields_owner', 'No editable ' . $type . ' "' . $slug . '" on this site. List post types first.' );
        }
        return $owner;
    }

    /** The MCP tool that reads this owner's fields and revision. */
    private static function read_tool( Owner $owner ): string {
        return $owner->type === 'options' ? 'get-options-page' : 'get-fields';
    }

    private static function error( SchemaError $e ): WP_Error {
        // MCP clients often show only the message, so the path goes into it too.
        return new WP_Error( $e->error_code, $e->path . ': ' . $e->getMessage(), [ 'path' => $e->path ] );
    }
}
