<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Modules\Fields\Values\FieldValueError;
use Fabrement\Modules\Translation\Version;

/**
 * What every type that points at a record or a media item shares: whether a picked id may be
 * stored, and which id is stored for it.
 */
final class Records {

    /**
     * @param string        $what      image | file | post_object — what kind of item the id must be.
     * @param bool          $kept      The record already holds this id here: only its shape is checked.
     * @param string[]|null $post_types The types a record picker offers; null = any.
     */
    public static function check( $id, string $what, bool $kept = false, ?array $post_types = null ): void {
        if ( ! is_int( $id ) || $id < 0 ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::RECORD_ID );
        }
        if ( ! $id || $kept ) {
            return;
        }
        // The record's STATE decides, never who is saving: the same value is valid from the editor,
        // an agent, WP-CLI, cron or an import. Who may write the record holding the field is checked
        // elsewhere (FieldObject::can_edit). What a stored id shows is the reader's business: records as
        // WP_Query shows them; a single image or file as wp_get_attachment_image() draws it.
        $post = get_post( $id );
        if ( ! $post || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::RECORD_MISSING, [ 'id' => $id, 'what' => $what ] );
        }
        if ( $post_types && ! in_array( $post->post_type, $post_types, true ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::RECORD_TYPE, [ 'types' => $post_types ] );
        }
        // No postTypes: a record of any PUBLIC type — never a revision, a menu item, a synced pattern
        // or an internal type nobody can open.
        if ( ! $post_types && $what === 'post_object' && ! self::pickable( $post->post_type ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::RECORD_NOT_PUBLIC, [ 'type' => $post->post_type ] );
        }
        if ( in_array( $what, [ 'image', 'file' ], true ) && $post->post_type !== 'attachment' ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::NOT_MEDIA );
        }
        if ( $what === 'image' && ! wp_attachment_is_image( $id ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::NOT_IMAGE );
        }
    }

    /** A post type a record field may point at when it names none: public, and not media. */
    private static function pickable( string $type ): bool {
        $object = get_post_type_object( $type );
        return $object !== null && ! empty( $object->public ) && $type !== 'attachment';
    }

    /**
     * A record or media id as stored: the ORIGINAL of its translation group, whatever language
     * version was picked. One id per record in every language keeps meta queries and sorting the
     * same for all languages; the reader's version is chosen when it is shown (fabrement()->post(),
     * posts(), attachment(), link()). Without a translation plugin: the id itself.
     */
    public static function canonical( int $id ): int {
        return $id > 0 ? ( self::canonical_many( [ $id ] )[ $id ] ?? $id ) : $id;
    }

    /**
     * {@see canonical()} for many ids at once, as `id => stored id`: one query for all of them,
     * none for an id the request already asked about (the bridge keeps the answers). Only a type
     * translated TODAY has originals — the bridge reads the type's translation setting as it is now.
     *
     * @param list<int> $ids
     * @return array<int,int>
     */
    public static function canonical_many( array $ids ): array {
        return Translation::bridge()->originals_of_any( $ids );
    }

    /**
     * Values a query is given for a record or media field → the ids they are stored as: any
     * language's id becomes its original, all of them in one question to the translation plugin.
     * What is no id stays as it is (a string stays a string).
     *
     * @param list<mixed> $values
     * @return list<mixed>
     */
    public static function search_values( array $values ): array {
        $is_id  = static fn( $v ): bool => is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) );
        $stored = self::canonical_many( array_map( 'intval', array_values( array_filter( $values, $is_id ) ) ) );
        return array_map(
            static function ( $v ) use ( $is_id, $stored ) {
                if ( ! $is_id( $v ) || ! isset( $stored[ (int) $v ] ) ) {
                    return $v;
                }
                return is_string( $v ) ? (string) $stored[ (int) $v ] : $stored[ (int) $v ];
            },
            $values
        );
    }

    /** A media id as fabrement()->attachment() shows it on this page: its translation, else itself. */
    public static function reader_media( int $id ): int {
        return $id > 0 ? Version::media( $id, '' ) : $id;
    }

    /** A record as fabrement()->post() shows it on this page, or 0 where it shows none. */
    public static function reader_post( int $id ): int {
        return $id > 0 ? Version::post( $id, '' ) : $id;
    }

    /**
     * Which post types a record picker offers: slugs of public, REST-visible types (the ones the
     * editor's search can list). Left out: every such type.
     *
     * @throws SchemaError
     */
    public static function check_post_types( array $raw, array &$field, string $at ): void {
        if ( ! array_key_exists( 'postTypes', $raw ) ) {
            return;
        }
        $types = $raw['postTypes'];
        if ( ! is_array( $types ) || ! $types || array_values( $types ) !== $types || count( $types ) > 20 || count( array_unique( $types, SORT_REGULAR ) ) !== count( $types ) ) {
            throw new SchemaError( esc_html( $at . '.postTypes' ), 'Expected a list of 1–20 different post type slugs.' );
        }
        foreach ( $types as $i => $slug ) {
            $object = is_string( $slug ) && $slug !== 'attachment' ? get_post_type_object( $slug ) : null;
            if ( ! $object || empty( $object->public ) || empty( $object->show_in_rest ) ) {
                throw new SchemaError( esc_html( $at . '.postTypes[' . (int) $i . ']' ), 'Not a public post type of this site: ' . ( is_string( $slug ) ? '"' . esc_html( $slug ) . '"' : 'expected a slug' ) . '. See list-post-types.' );
            }
        }
        $field['postTypes'] = $types;
    }
}
