<?php

namespace Fabrement\Modules\Fields\Values;

use Fabrement\Modules\Fields\Schema\LayoutValidator;
use Fabrement\Modules\Fields\Types\FieldTypes;
use Fabrement\Modules\Fields\Translation\OptionVersions;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Read-only projections for the editor and MCP. Defaults and translated displays are resolved
 * by FieldObject; reading never seeds a translation or writes storage.
 */
final class FieldRead {

    /**
     * What the edit form needs: active fields, layout and editor values.
     *
     * @param string $lang The language the editor's pickers use; '' = the record's own.
     */
    public static function for_editor( FieldObject $object, string $lang = '' ) {
        $document = $object->document();
        if ( ! $document ) {
            return new WP_Error( 'fields_missing', 'This record has no Fabrement fields.', [ 'status' => 404 ] );
        }
        $all = $object->all_fields();
        $active = ValueCodec::active( $all );
        $stored = $object->type === 'options' ? $object->write_values( $active ) : $object->editor_values( $active );
        $values = [];
        foreach ( $active as $key => $field ) {
            $values[ $key ] = ChoiceValues::visible( $field, ValueCodec::editor_value( $field, $stored['values'][ $key ] ) );
        }
        return [
            'owner'    => $object->owner->id(),
            'fields'   => array_values( array_map( [ ValueCodec::class, 'without_retired' ], $active ) ),
            'layout'   => LayoutValidator::prune( $document['layout'], $document['fields'] ),
            'values'   => $values,
            'exists'      => $stored['exists'],
            'translation' => $object->translation_context(),
            'refs'        => self::refs( $object, $active, $values, $lang ),
        ] + ( $object->type === 'options' ? [ 'versionExists' => OptionVersions::exists( $object ) ] : [] );
    }

    /**
     * What the form shows for the records and media its values point at — titles, addresses,
     * thumbnails — so it opens in one request instead of one per picker field and one per image.
     * Read by id, exactly: a list query would be scoped by the translation plugin to the admin's
     * language and hand back other ids.
     *
     * @return array{posts: list<array>, media: list<array>}
     */
    private static function refs( FieldObject $object, array $active, array $values, string $lang = '' ): array {
        $posts = [];
        $media = [];
        self::collect_refs( $active, $values, $posts, $media );
        if ( $lang === '' && $object->type === 'post' ) {
            $lang = Translation::live_language_of( $object->id, $object->owner->slug );
        } elseif ( $lang === '' ) {
            $lang = $object->lang;
        }
        return [
            'lang'  => $lang,
            'posts' => $posts ? \Fabrement\Route\Editor\EditorRefs::posts( array_keys( $posts ), $lang ) : [],
            'media' => \Fabrement\Route\Editor\EditorRefs::media( array_keys( $media ) ),
            // Every record id looked up: one missing from `posts` is gone or unreadable, not unknown.
            'postIds' => array_keys( $posts ),
            'mediaIds' => array_keys( $media ),
        ];
    }

    /** @param array<int, true> $posts @param array<int, true> $media */
    private static function collect_refs( array $fields, array $values, array &$posts, array &$media ): void {
        foreach ( $fields as $key => $field ) {
            FieldTypes::of( $field )->references( $field, $values[ $key ] ?? null, $posts, $media );
        }
        unset( $posts[0], $media[0] );
    }

    /**
     * Values of a record in public (name-keyed) shape.
     *
     * @return array|WP_Error
     */
    public static function public_values( FieldObject $object ) {
        $active = ValueCodec::active( $object->all_fields() );
        if ( ! $active ) {
            return new WP_Error( 'fields_missing', self::no_fields( $object ) );
        }
        $shown = $object->editor_values( $active );
        $stored = $object->stored( $active );
        $values = [];
        $exists = [];
        foreach ( $active as $key => $field ) {
            $values[ $field['name'] ] = ValueCodec::api_value( $field, ValueCodec::public_value( $field, $shown['values'][ $key ], true ) );
            $exists[ $field['name'] ] = $stored['exists'][ $key ];
        }
        $target = $object->type === 'options'
            ? [ 'options' => $object->owner->slug, 'language' => $object->lang, 'versionExists' => OptionVersions::exists( $object ) ]
            : [ 'postId' => $object->id, 'postType' => $object->owner->slug ];
        return $target + [
            'values' => $values,
            'stored' => $exists,
        ];
    }

    private static function no_fields( FieldObject $object ): string {
        return $object->type === 'options'
            ? 'The options page ' . $object->owner->slug . ' has no Fabrement fields. Define them with save-options-page first.'
            : 'The ' . $object->owner->slug . ' post type has no Fabrement fields. Define them with save-fields first.';
    }

}
