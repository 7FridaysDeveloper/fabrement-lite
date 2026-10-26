<?php

namespace Fabrement\Modules\Fields\Write;

use Fabrement\Modules\Fields\Translation\TranslationSplit;
use Fabrement\Modules\Fields\Translation\TranslationPolicy;
use Fabrement\Modules\Fields\Translation\PostTranslations;
use Fabrement\Modules\Fields\Translation\OptionVersions;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Modules\Translation\Contract\TranslationBridge;
use WP_Error;

/** One public save pipeline: authorize, project both targets, write original, sync, write own. */
final class FieldWrite {
    private ValueWriter $writer;
    private TranslationSplit $split;
    private WriteRules $rules;
    private TranslationBridge $bridge;

    public function __construct( WriteRules $rules, TranslationSplit $split, ValueWriter $writer, TranslationBridge $bridge ) {
        $this->rules = $rules;
        $this->split = $split;
        $this->writer = $writer;
        $this->bridge = $bridge;
    }

    /** Site PHP uses standard rules independently of the external entries' container bindings. */
    public static function standard(): self {
        return new self( new WriteRules(), new TranslationSplit(), new ValueWriter(), Translation::bridge() );
    }

    /** @return array|WP_Error No cross-object transaction is implied by a successful first write. */
    public function save( FieldObject $object, WriteRequest $request, WriteMode $mode ) {
        $committed = [];
        $changed_targets = [];
        $routed = [];
        $destination = $object;
        try {
            return $mode->run( function () use ( $object, $request, $mode, &$committed, &$changed_targets, &$routed, &$destination ) {
                if ( $error = $mode->authorize( $object ) ) {
                    return $error;
                }
                if ( ! $object->all_fields() ) {
                    return new WP_Error( 'fields_missing', 'This record has no Fabrement fields.', [ 'status' => 404 ] );
                }
                $request = $this->writer->normalize( $object, $request );
                $original = TranslationSplit::original_of( $object );
                $source = $original ? $this->split->original_part( $object, $original, $request ) : null;
                $own = $original ? $this->split->own_part( $object, $original, $request, $source->values ) : $request;
                $own = $this->writer->clear_removed_choices( $object, $own );
                if ( $source && $source->values ) {
                    if ( $error = $mode->authorize( $original ) ) {
                        return $error;
                    }
                    $decision = $this->rules->original( $object, $original, array_values( $source->paths ), $mode );
                    if ( $error = $decision->error() ) {
                        return $error;
                    }
                    $source_plan = $this->plan( $original, $source, $mode );
                    if ( is_wp_error( $source_plan ) ) {
                        return $source_plan;
                    }
                    $routed[] = self::target( $original ) + [ 'fields' => array_values( $source->paths ) ];
                }
                // Both destinations are checked before any seed, write or synchronization.
                $own_plan = $this->plan( $object, $own, $mode, $source ? $source->values : [] );
                if ( is_wp_error( $own_plan ) ) {
                    return $own_plan;
                }
                if ( $source && $source->values ) {
                    $destination = $original;
                    $changed = $this->writer->commit( $original, $source, $source_plan );
                    $committed[] = self::target( $original );
                    if ( $changed ) {
                        $changed_targets[] = [ $original, $source_plan ];
                    }
                    // Saved hooks may have synchronized or changed the original: read it again.
                    $own = $this->split->own_part( $object, $original, $request );
                    $own = $this->writer->clear_removed_choices( $object, $own );
                }
                $destination = $object;
                if ( $object->type === 'post' && $object->is_translation() && ! $object->holds_values() ) {
                    PostTranslations::seed( (int) $object->id );
                    $committed[] = self::target( $object );
                }
                $own_plan = $this->plan( $object, $own, $mode );
                if ( is_wp_error( $own_plan ) ) {
                    return $this->failure( $own_plan, $committed, $object );
                }
                $changed = $this->writer->commit( $object, $own, $own_plan, $request->values !== [] );
                $committed[] = self::target( $object );
                if ( $changed ) {
                    $changed_targets[] = [ $object, $own_plan ];
                }
                return [ 'changed' => $changed_targets !== [], 'committed' => $committed, 'routed' => $routed ];
            } );
        } catch ( \Exception $e ) {
            return $this->failure( ValueWriter::failure( $e, $mode ), $committed, $destination );
        } finally {
            // Even a later failure does not undo an already committed destination.
            foreach ( $changed_targets as [ $target, $plan ] ) {
                if ( $mode->is_agent() || ( $mode->is_editor() && $target->id !== $object->id ) ) {
                    $this->save_post( $target );
                } elseif ( $mode->is_code() && $target->type === 'post' && $this->changed_words( $plan ) ) {
                    try {
                        $this->bridge->original_changed( (int) $target->id );
                    } catch ( \Throwable $e ) {
                        // Values are already committed; a notification cannot undo that save.
                        \Fabrement\Framework\Log::debug( '[fabrement][fields] Translation status notification failed for ' . $target->id . ': ' . $e->getMessage() );
                    }
                }
            }
        }
    }

    /** Use the same translated texts as the WPML job/hash, excluding labels and row order. */
    private function changed_words( array $plan ): bool {
        foreach ( $plan['changes'] as $key => $value ) {
            $field = $plan['all'][ $key ];
            $before = $plan['stored']['exists'][ $key ]
                ? TranslationPolicy::texts( $field, $plan['stored']['values'][ $key ] ) : [];
            $after = TranslationPolicy::texts( $field, $value );
            $before = array_map( static fn( array $text ): string => $text['text'], $before );
            $after = array_map( static fn( array $text ): string => $text['text'], $after );
            ksort( $before );
            ksort( $after );
            if ( $before !== $after ) {
                return true;
            }
        }
        return false;
    }

    private function plan( FieldObject $object, WriteRequest $request, WriteMode $mode, array $projected = [] ) {
        $as_seeded = $object->type === 'options'
            ? ! OptionVersions::exists( $object )
            : $object->is_translation() && ! $object->holds_values();
        $plan = $this->writer->plan( $object, $request, $mode, $as_seeded );
        if ( ! is_wp_error( $plan ) ) {
            $this->split->assert_editable( $object, ValueCodec::active( $plan['all'] ), $plan['changes'], $plan['stored'], $mode, $projected );
            $values = array_replace( $object->write_values( ValueCodec::active( $plan['all'] ) )['values'], $request->values );
            if ( $object->is_translation() ) {
                $source = array_replace( $object->original_stored( ValueCodec::active( $plan['all'] ) )['values'], $projected );
                foreach ( ValueCodec::active( $plan['all'] ) as $key => $field ) {
                    $values[ $key ] = TranslationPolicy::with_original( $field, $values[ $key ], $source[ $key ] );
                }
            }
            $this->writer->validate_required( $plan['all'], $values );
        }
        return $plan;
    }

    private function failure( WP_Error $error, array $committed, FieldObject $failed ): WP_Error {
        if ( ! $committed ) {
            return $error;
        }
        return new WP_Error( 'fields_partial_write', $error->get_error_message() . ' Some destinations were already saved. Read the values again before retrying.',
            [ 'status' => 500, 'committed' => $committed, 'failed' => self::target( $failed ), 'cause' => $error->get_error_code() ] );
    }

    private static function target( FieldObject $object ): array {
        return $object->type === 'options' ? [ 'options' => $object->owner->slug, 'language' => $object->lang ] : [ 'postId' => (int) $object->id ];
    }

    private function save_post( FieldObject $object ): void {
        if ( $object->type !== 'post' || ( $object->is_translation() && $object->translation_editor_owns_words() ) ) {
            return;
        }
        // Saved as its Update button would, for WPML, SEO and caches — with its stored content as it
        // is: only the id goes in, so no new words come with it, and running them through kses again
        // for a user without unfiltered_html would strip an embed someone allowed to write it wrote.
        $filtered = has_filter( 'content_save_pre', 'wp_filter_post_kses' ) !== false;
        if ( $filtered ) {
            kses_remove_filters();
        }
        try {
            $saved = wp_update_post( [ 'ID' => (int) $object->id ], true );
        } finally {
            if ( $filtered ) {
                kses_init_filters();
            }
        }
        if ( is_wp_error( $saved ) ) {
            \Fabrement\Framework\Log::debug( '[fabrement][fields] Record ' . $object->id . ' not re-saved after its fields changed: ' . $saved->get_error_message() );
        }
    }
}
