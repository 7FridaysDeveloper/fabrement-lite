<?php

namespace Fabrement\Modules\Fields\Translation;

use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\FieldValueError;
use Fabrement\Modules\Fields\Values\ChoiceValues;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueSelection;
use Fabrement\Modules\Fields\Types\FieldTypes;
use InvalidArgumentException;
use Fabrement\Modules\Fields\Write\WriteRequest;
use Fabrement\Modules\Fields\Write\WriteMode;
use Fabrement\Modules\Fields\Schema\StorageLayout;

/** Shared ownership split and WPML word locks for every public writer. */
final class TranslationSplit {
    public function original_part( FieldObject $object, FieldObject $original, WriteRequest $request ): WriteRequest {
        $all = $object->all_fields();
        $keys = array_intersect_key( $all, $request->values );
        $before = $this->editor( $object, $keys, true );
        $now = $this->editor( $original, $keys );
        $stored = $original->stored( $keys );
        $values = [];
        $selection = [];
        foreach ( $request->values as $key => $value ) {
            $field = $all[ $key ];
            $next = TranslationPolicy::to_original( $field, $value, $before[ $key ], $now[ $key ] );
            $selected = TranslationPolicy::original_selection( $field, $request->selection[ $key ] ?? true, $next, $now[ $key ] );
            // An unchanged stale submission may clear the shared choice only while the
            // original still holds that same value. Never clear a newer original value.
            $next = ChoiceValues::clear_removed( $field, $next, $value, $selected );
            if ( ! ValueCodec::same( $field['type'], $next, $now[ $key ] )
                || ( isset( $request->clears[ $key ] ) && self::clears_missing_original( $field, $next, $key, $stored['present'], $request->clears[ $key ] ) )
                || ValueSelection::missing( $field, $next, $selected, $key, $stored['present'] ) ) {
                $values[ $key ] = $next;
                $selection[ $key ] = $selected;
            }
        }
        return $request->with_values( $values, $selection );
    }

    public function own_part( FieldObject $object, FieldObject $original, WriteRequest $request, array $projected = [] ): WriteRequest {
        $all = $object->all_fields();
        $source = array_replace( $this->editor( $original, array_intersect_key( $all, $request->values ) ), $projected );
        $values = [];
        foreach ( $request->values as $key => $value ) {
            $field = $all[ $key ];
            if ( $field['translation'] === 'copy' && ! FieldTypes::of( $field )->is_container() ) {
                continue;
            }
            $values[ $key ] = TranslationPolicy::with_original( $field, $value, $source[ $key ] );
        }
        return $request->with_values( $values );
    }

    private function editor( FieldObject $object, array $fields, bool $shown = false ): array {
        $read = $shown ? $object->write_values( $fields ) : $object->stored( $fields );
        $values = [];
        foreach ( $fields as $key => $field ) {
            $values[ $key ] = ValueCodec::editor_value( $field, $read['values'][ $key ] );
        }
        return $values;
    }

    public function assert_editable( FieldObject $object, array $active, array &$changes, array $stored, WriteMode $mode, array $projected = [] ): void {
        if ( ! $object->is_translation() ) {
            return;
        }
        // Where WPML's translation editor owns this translation, its jobs rewrite the WORDS they
        // carry (TranslationPolicy::texts) — so those are translated there. Everything no job
        // carries (copy_once, ignore: a price or a date of this language) is edited here.
        $translation_editor_owns_words = $object->translation_editor_owns_words();
        $source = $object->original_stored( $active );
        // Judged against the original as a save in progress is about to leave it (FieldWrite).
        foreach ( $projected as $key => $value ) {
            if ( isset( $active[ $key ] ) ) {
                $source['values'][ $key ] = $value;
                $source['exists'][ $key ] = true;
            }
        }
        foreach ( $changes as $key => $value ) {
            $original = ValueCodec::editor_value( $active[ $key ], $source['values'][ $key ] );
            $current = $stored['exists'][ $key ] ? ValueCodec::editor_value( $active[ $key ], $stored['values'][ $key ] ) : null;
            if ( $translation_editor_owns_words ) {
                // Only the words a WPML job carries: the original's (by row id). A row the
                // translation holds of its own (a copy_once repeater) is in no job and is written here.
                $job  = TranslationPolicy::texts( $active[ $key ], $original );
                $held = array_intersect_key( TranslationPolicy::texts( $active[ $key ], $current ?? ValueCodec::empty_value( $active[ $key ] ) ), $job );
                $sent = array_intersect_key( TranslationPolicy::texts( $active[ $key ], $value ), $job );
                // Words of a row the translation did not hold yet — one the site's code adds here and
                // sends to the original (FieldWrite) — are the original's until WPML's job
                // translates them: the translation takes them as the original holds them.
                foreach ( array_intersect_key( array_diff_key( $job, $held ), $sent ) as $path => $text ) {
                    $held[ $path ] = $text;
                }
                ksort( $held );
                ksort( $sent );
                if ( array_map( static fn( $t ) => $t['text'], $held ) !== array_map( static fn( $t ) => $t['text'], $sent ) ) {
                    $error = ( new FieldValueError( FieldValueError::TRANSLATOR_WORDS, [ 'original' => self::original_call( $object ) ] ) )->at( $active[ $key ], $active[ $key ]['name'] );
                    throw $error;
                }
                $lost = TranslationPolicy::lost_on_next_job( $active[ $key ], $value, $current ?? ValueCodec::empty_value( $active[ $key ] ),
                    $source['exists'][ $key ] ? [ $original ] : null );
                if ( $lost ) {
                    $labels = TranslationPolicy::texts( $active[ $key ], $value );
                    $label = $labels[ $lost[0] ]['label'] ?? $active[ $key ]['label'];
                    $error = ( new FieldValueError( FieldValueError::TRANSLATOR_EMPTY_ORIGINAL, [ 'original' => self::original_call( $object ) ] ) )
                        ->at( [ 'label' => $label ] + $active[ $key ], $active[ $key ]['name'] );
                    throw $error;
                }
            }
            try {
                TranslationPolicy::assert_editable( $active[ $key ], $value, $original, $active[ $key ]['name'], $current );
            } catch ( InvalidArgumentException $e ) {
                // Name where it is changed for an agent: the original record, or the options page's original language.
                if ( $this->where( $object, $mode ) === '' ) {
                    throw $e;
                }
                throw new InvalidArgumentException( rtrim( $e->getMessage(), '.' ) . $this->where( $object, $mode ) . '.', 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message was escaped where it was made; where() is a tool call, not HTML.
            }
            // What the original owns is written as the original holds it: a stale copy heals here.
            $changes[ $key ] = TranslationPolicy::with_original( $active[ $key ], $value, $original );
        }
    }

    private function where( FieldObject $object, WriteMode $mode ): string {
        return $mode->is_agent() ? ' (' . self::original_call( $object ) . ')' : '';
    }

    private static function original_call( FieldObject $object ): string {
        return $object->type === 'options'
            ? 'set-field-values {options: "' . $object->owner->slug . '", language: ""}'
            : 'set-field-values {postId: ' . PostTranslations::original_of( $object ) . '}';
    }

    /** A copied empty value needs its own row even when the original currently reads empty. */
    private static function clears_missing_original( array $field, $value, string $path, array $present, array $clear_paths ): bool {
        foreach ( $clear_paths as $segments ) {
            if ( self::clears_original_path( $field, $value, $path, $present, $segments ) ) {
                return true;
            }
        }
        return false;
    }

    /** Follow only a path the caller cleared; never infer intent from a blank sibling. */
    private static function clears_original_path( array $field, $value, string $path, array $present, array $segments ): bool {
        $type = FieldTypes::of( $field );
        if ( $field['translation'] === 'ignore' || ( $type->is_container() && $field['translation'] === 'copy_once' ) ) {
            return false;
        }
        if ( ! $type->is_container() ) {
            return $segments === [] && $field['translation'] === 'copy' && ! isset( $present[ $path ] ) && $type->is_empty( $value );
        }
        if ( $field['type'] === 'repeater' ) {
            if ( $segments === [] && $value === [] && $type->follows_original( $field ) && ! isset( $present[ $path ] ) ) {
                return true;
            }
            $row_selector = $segments !== [] ? array_shift( $segments ) : null;
            $child_name = $segments !== [] ? array_shift( $segments ) : null;
            foreach ( (array) $value as $index => $row ) {
                if ( $row_selector !== null && $row_selector !== $index && $row_selector !== $row['id'] ) {
                    continue;
                }
                foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                    if ( ( $child_name === null || $child_name === $child['name'] ) && array_key_exists( $key, $row['values'] ) && self::clears_original_path( $child, $row['values'][ $key ], StorageLayout::row_child( $path, $index, $key ), $present, $segments ) ) {
                        return true;
                    }
                }
            }
            return false;
        }
        $child_name = $segments !== [] ? array_shift( $segments ) : null;
        foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
            if ( ( $child_name === null || $child_name === $child['name'] ) && array_key_exists( $key, (array) $value ) && self::clears_original_path( $child, $value[ $key ], StorageLayout::child( $path, $key ), $present, $segments ) ) {
                return true;
            }
        }
        return false;
    }

    /** The record or options page a translation follows; null for an original or a single-language site. */
    public static function original_of( FieldObject $object ): ?FieldObject {
        if ( $object->type === 'options' ) {
            return $object->original();
        }
        $id = $object->type === 'post' ? PostTranslations::original_of( $object ) : 0;
        return $id && $id !== $object->id ? FieldObject::post( $id ) : null;
    }

}
