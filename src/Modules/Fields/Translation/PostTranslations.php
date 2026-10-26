<?php

namespace Fabrement\Modules\Fields\Translation;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Types\WordsType;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueRepository;
use Fabrement\Modules\Translation\Translation;

/** Post field lifecycle and editor context; provider details are behind TranslationBridge. */
final class PostTranslations {
    public static function init(): void {
        add_action( 'fabrement_fields_saved', [ self::class, 'saved' ], 10, 2 );
        Translation::bridge()->register_field_hooks();
    }

    /** Original of the post's translation group when its type is translated; 0 otherwise. */
    public static function original_of( FieldObject $object ): int {
        if ( $object->type !== 'post' ) {
            return 0;
        }
        $bridge = Translation::bridge();
        if ( ! $bridge->is_active() || $bridge->is_post_type_translatable( $object->owner->slug ) !== true ) {
            return 0;
        }
        $original = $bridge->original_of( $object->id, $object->owner->slug );
        return $original === $object->id ? ( self::pending_original( $object ) ?: $original ) : $original;
    }

    /** Remember a provider-validated original on its not-yet-linked editor draft. */
    public static function remember_pending( \WP_Post $post ): void {
        $original = Translation::bridge()->pending_original( $post->ID );
        if ( $original > 0 ) {
            update_post_meta( $post->ID, Keys::META_FIELDS_TRANSLATION_OF, $original );
        }
    }

    /** Original remembered on an auto-draft; a trashed one stays the original, as WPML keeps it. */
    private static function pending_original( FieldObject $object ): int {
        if ( get_post_status( (int) $object->id ) !== 'auto-draft' ) {
            return 0;
        }
        $original = (int) get_post_meta( (int) $object->id, Keys::META_FIELDS_TRANSLATION_OF, true );
        if ( ! $original || get_post_type( $original ) !== $object->owner->slug
            || Translation::bridge()->original_of( $original, $object->owner->slug ) !== $original ) {
            return 0;
        }
        return $original;
    }

    /** The provider linked or saved the post as a translation. */
    public static function linked( int $post_id ): void {
        delete_post_meta( $post_id, Keys::META_FIELDS_TRANSLATION_OF );
        self::seed( $post_id );
    }

    /** @param int|string $id Options versions have their own lifecycle in OptionVersions. */
    public static function saved( string $type, $id ): void {
        $object = $type === 'post' ? FieldObject::post( (int) $id ) : null;
        if ( ! $object || self::original_of( $object ) !== $id ) {
            return;
        }
        foreach ( Translation::bridge()->all_versions( $id, $object->owner->slug ) as $target_id ) {
            $target_id = (int) $target_id;
            if ( $target_id !== $id && get_post_status( $target_id ) !== 'trash' ) {
                self::sync( $id, $target_id );
            }
        }
    }

    /**
     * Seed a linked translation. Mirror only when its provider has just duplicated it again;
     * saving the duplicate itself keeps what was saved there until the original changes.
     */
    public static function seed( int $post_id, bool $mirror = false ): void {
        $object = FieldObject::post( $post_id );
        if ( ! $object || ! $object->all_fields() ) {
            return;
        }
        $original = self::original_of( $object );
        if ( $original && $original !== $post_id ) {
            self::sync( $original, $post_id, [], true, $mirror );
        }
    }

    /**
     * Apply shared field policies and optional translated words to one target.
     * Linked rows without matching IDs adopt source IDs by position; an ordinary source save
     * never adopts them. A provider duplicate mirrors all but ignore while it follows its source.
     *
     * @param array<string,string> $texts Stable token => translated text.
     */
    public static function sync( int $source_id, int $target_id, array $texts = [], bool $linked = false, bool $mirror = true, ?array $job = null ): bool {
        $source = FieldObject::post( $source_id );
        $target = FieldObject::post( $target_id );
        if ( ! $source || ! $target || $source->owner->id() !== $target->owner->id() ) {
            return false;
        }
        $all = $target->all_fields();
        $active = ValueCodec::active( $all );
        if ( ! $active ) {
            return false;
        }
        $mirror = $mirror && Translation::bridge()->duplicate_of( $target_id ) === $source_id;
        if ( $job !== null && self::original_of( $target ) !== $source_id ) {
            $job = null;
        }
        try {
            $sync = static fn() => FieldSync::sync( $source, $target, $texts, $linked, $mirror, $job );
            $texts ? WordsType::as_site_code( $sync ) : $sync();
            delete_post_meta( $target_id, Keys::META_FIELDS_SYNC_ERROR );
            return true;
        } catch ( \Throwable $error ) {
            update_post_meta( $target_id, Keys::META_FIELDS_SYNC_ERROR, $error->getMessage() );
            \Fabrement\Framework\Log::debug( '[fabrement][fields] Translation ' . $target_id . ' of ' . $source_id . ' not synced: ' . $error->getMessage() );
            return false;
        }
    }

    /** What the edit form of a translation needs to know; retain its existing response keys. */
    public static function editor_context( FieldObject $object ): array {
        $original = self::original_of( $object );
        if ( ! $original || $original === $object->id ) {
            return [ 'isTranslation' => false ];
        }
        $locks = TranslationPolicy::locks( $object->all_fields() );
        // A pending draft has no provider row yet, so use the original's editor choice.
        $managed = ! Translation::bridge()->writes_translations_manually( $original );
        return [
            'isTranslation'   => true,
            'originalId'      => $original,
            'managedByWpml'   => $managed,
            'lockedFields'    => $locks['fields'],
            'wordFields'      => $managed ? TranslationPolicy::word_locks( $object->all_fields() ) : [],
            'lockedRows'      => $locks['rows'],
            'error'           => (string) get_post_meta( $object->id, Keys::META_FIELDS_SYNC_ERROR, true ),
        ];
    }

    /** @return array<string,array{text:string,label:string,html:bool}> Stored original texts only. */
    public static function texts( FieldObject $object ): array {
        $active = ValueCodec::active( $object->all_fields() );
        if ( ! $active || self::original_of( $object ) !== $object->id ) {
            return [];
        }
        $stored = ValueRepository::read( 'post', $object->id, $active );
        $texts = [];
        foreach ( $active as $key => $field ) {
            if ( $stored['exists'][ $key ] ) {
                $texts += TranslationPolicy::texts( $field, ValueCodec::editor_value( $field, $stored['values'][ $key ] ) );
            }
        }
        return $texts;
    }
}
