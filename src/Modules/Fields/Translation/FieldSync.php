<?php

namespace Fabrement\Modules\Fields\Translation;

use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueRepository;
use Fabrement\Modules\Fields\Schema\StorageLayout;

/** One translation projection and persistence path for post fields and options values. */
final class FieldSync {
    /** Pure projection: policies, presence, row identities and optional WPML translated words. */
    public static function project( array $active, array $from, array $to, array $texts = [], bool $linked = false, bool $mirror = false, ?array $job = null ): array {
        $changes = [];
        $deletes = [];
        foreach ( $active as $key => $field ) {
            if ( $field['translation'] === 'ignore' ) {
                continue;
            }
            // Resolved defaults are useful for display, but are not stored source/target values.
            $source_value = self::stored_value( $field, $from['values'][ $key ], $key, $from['present'] );
            $target_value = self::stored_value( $field, $to['values'][ $key ], $key, $to['present'] );
            if ( $mirror ) {
                $held = $target_value;
                if ( ! $from['exists'][ $key ] ) {
                    // Cleared on the original: a group keeps the duplicate's ignore sub-fields.
                    if ( $to['exists'][ $key ] && $field['type'] === 'group' ) {
                        $next = TranslationPolicy::mirror( $field, [], $held, true );
                        if ( wp_json_encode( $next ) !== wp_json_encode( $held ) ) {
                            $changes[ $key ] = $next;
                        }
                    } elseif ( $to['exists'][ $key ] ) {
                        $deletes[] = $key;
                    }
                    continue;
                }
                $next = TranslationPolicy::mirror( $field, $source_value, $held, $to['exists'][ $key ] );
                if ( ! $to['exists'][ $key ] || wp_json_encode( $next ) !== wp_json_encode( $held ) ) {
                    $changes[ $key ] = $next;
                }
                continue;
            }
            if ( ! $from['exists'][ $key ] ) {
                // Nothing to carry. What follows the original is cleared with it: a copied
                // value, and the rows of a translated repeater.
                $follows = TranslationPolicy::follows_original( $field );
                if ( $follows && $to['exists'][ $key ] && $field['type'] === 'group' ) {
                    // A cleared group is not the original's whole: its copied sub-fields are
                    // cleared with it, the translation's own (translate, copy_once, ignore) stay.
                    $next = TranslationPolicy::merge( $field, [], $target_value, true, $key, $to['present'] );
                    if ( $job !== null ) {
                        $next = TranslationPolicy::clear_texts( $field, $next, null, $job );
                    }
                    if ( wp_json_encode( $next ) !== wp_json_encode( $target_value ) ) {
                        $changes[ $key ] = $next;
                    }
                } elseif ( $follows && $to['exists'][ $key ] ) {
                    $deletes[] = $key;
                } elseif ( $job !== null && $to['exists'][ $key ] ) {
                    $next = TranslationPolicy::clear_texts( $field, $target_value, null, $job );
                    if ( wp_json_encode( $next ) === wp_json_encode( $target_value ) ) {
                        continue;
                    }
                    if ( $field['type'] === 'group' ) {
                        $changes[ $key ] = $next;
                    } else {
                        $deletes[] = $key;
                    }
                }
                continue;
            }
            $held = $target_value;
            if ( $linked && $to['exists'][ $key ] ) {
                $target_value = TranslationPolicy::adopt_rows( $field, $source_value, $target_value );
            }
            // `present` lets a sub-field added to the schema later (no row in the translation
            // yet) be seeded like a new top-level field, instead of reading as "cleared".
            $next = TranslationPolicy::merge( $field, $source_value, $target_value, $to['exists'][ $key ], $key, $to['present'] );
            if ( $texts ) {
                $next = TranslationPolicy::apply_texts( $field, $next, $texts );
            }
            if ( $job !== null ) {
                $next = TranslationPolicy::clear_texts( $field, $next, [ $source_value ], $job );
            }
            if ( ! $to['exists'][ $key ] || wp_json_encode( $next ) !== wp_json_encode( $held ) ) {
                $changes[ $key ] = $next;
            }
        }
        return [ 'changes' => $changes, 'deletes' => $deletes ];
    }

    /** Editor shape with absent children omitted; row identity and explicit null remain intact. */
    private static function stored_value( array $field, $value, string $path, array $present ) {
        if ( ! in_array( $field['type'], [ 'group', 'repeater' ], true ) ) {
            return $value;
        }
        $children = static function ( array $values, string $prefix ) use ( $field, $present ): array {
            $out = [];
            foreach ( ValueCodec::active( $field['sub_fields'] ) as $key => $child ) {
                $child_path = StorageLayout::child( $prefix, $key );
                if ( isset( $present[ $child_path ] ) ) {
                    $out[ $key ] = self::stored_value( $child, $values[ $key ], $child_path, $present );
                }
            }
            return $out;
        };
        if ( $field['type'] === 'group' ) {
            return $children( (array) $value, $path );
        }
        $rows = [];
        foreach ( (array) $value as $index => $row ) {
            $rows[] = [ 'id' => $row['id'], 'values' => $children( $row['values'], $path . '_' . $index ) ];
        }
        return $rows;
    }

    /** Initial storage of a new version; also used by the pending-post preview without writing. */
    public static function initial( FieldObject $target, array $fields ): array {
        $source = TranslationSplit::original_of( $target );
        if ( ! $source ) {
            return [];
        }
        $plan = self::project( $fields, $source->stored( $fields ), ValueRepository::decode( $fields, [] ) );
        $map = [];
        foreach ( $plan['changes'] as $key => $value ) {
            ValueCodec::flatten( $fields[ $key ], $value, $key, $map );
        }
        return $map;
    }

    /** No saved event: a synchronization must not recursively trigger another synchronization. */
    public static function sync( FieldObject $source, FieldObject $target, array $texts = [], bool $linked = false, bool $mirror = false, ?array $job = null ): void {
        $all = $target->all_fields();
        $active = ValueCodec::active( $all );
        $plan = self::project( $active, $source->stored( $active ), $target->stored( $active ), $texts, $linked, $mirror, $job );
        if ( $plan['changes'] || $plan['deletes'] ) {
            ValueRepository::write( $target->type, $target->id, $all, $plan['changes'], $plan['deletes'] );
        }
    }
}
