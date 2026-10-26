<?php

namespace Fabrement\Modules\Translation\Bridge\Wpml;

use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Fields\Schema\StorageLayout;
use Fabrement\Modules\Fields\Translation\OptionVersions;
use Fabrement\Modules\Fields\Translation\PostTranslations;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Translation\Translation;

/** Internal WPML field integration, registered only by WpmlBridge. Policies stay in Fields. */
final class FieldHooks {
    /** @var string[] Our keys on both sides of a duplicate, excluded from WPML's own copying. */
    private static array $duplicate_keys = [];

    public static function init(): void {
        // WPML fires this when a translation row is inserted/moved and on translation saves.
        add_action( 'wpml_translation_update', static function ( $args ): void {
            $id = (int) ( $args['element_id'] ?? 0 );
            if ( $id > 0 && in_array( $args['type'] ?? '', [ 'insert', 'update' ], true )
                && strpos( (string) ( $args['element_type'] ?? '' ), 'post_' ) === 0 ) {
                PostTranslations::linked( $id );
            }
        }, 100 );
        add_action( 'wpml_before_make_duplicate', [ self::class, 'before_duplicate' ], 10, 2 );
        add_filter( 'wpml_duplicate_custom_fields_exceptions', static fn( $keys ) => array_values( array_unique( array_merge( (array) $keys, self::$duplicate_keys ) ) ) );
        add_action( 'icl_make_duplicate', static function ( $source, $lang, $post, $target ): void {
            self::$duplicate_keys = [];
            PostTranslations::seed( (int) $target, true );
        }, 100, 4 );
        add_filter( 'wpml_tm_translation_job_data', [ self::class, 'package' ], 100, 2 );
        add_filter( 'wpml_tm_post_md5_content', [ self::class, 'md5_content' ], 100, 2 );
        add_filter( 'wpml_tm_adjust_translation_fields', [ self::class, 'job_labels' ], 100, 3 );
        add_action( 'wpml_pro_translation_completed', [ self::class, 'completed' ], 100, 3 );
        add_filter( 'wpml_setting', static function ( $settings, $name ) {
            return $name === 'translation-management' && is_array( $settings ) ? self::protect( $settings ) : $settings;
        }, 100, 2 );
        add_action( 'init', [ self::class, 'protect_loaded' ], 100 );
        add_filter( 'wpml_custom_field_setting_is_html_disabled', [ self::class, 'lock_setting' ], 10, 2 );
        add_filter( 'wpml_custom_field_settings_override_lock_render', [ self::class, 'setting_label' ], 10, 2 );
        add_action( 'icl_after_set_default_language', static function ( $code, $previous ): void {
            OptionVersions::default_changed( (string) $code, (string) $previous );
        }, 10, 2 );
    }

    /** Keep our keys locked on WPML's custom-field settings screen. */
    public static function lock_setting( $disabled, $setting ) {
        $key = is_object( $setting ) && method_exists( $setting, 'get_index' ) ? (string) $setting->get_index() : '';
        return $key !== '' && StorageLayout::owns( $key ) ? true : $disabled;
    }

    public static function setting_label( $override, $setting ) {
        $key = is_object( $setting ) && method_exists( $setting, 'get_index' ) ? (string) $setting->get_index() : '';
        if ( $key === '' || ! StorageLayout::owns( $key ) ) {
            return $override;
        }
        printf(
            '<span class="fabrement-wpml-cf" title="%s">%s</span> ',
            esc_attr__( 'Set in the Fabrement fields, not here: Fabrement translates this field itself.', 'fabrement-lite' ),
            esc_html( self::describe_key( $key ) )
        );
        return true;
    }

    /** "Fabrement · Agenda / Topic: translate" for one of our meta keys. */
    public static function describe_key( string $key ): string {
        foreach ( SchemaRepository::all() as $document ) {
            if ( ( $document['type'] ?? '' ) === 'options' ) {
                continue;
            }
            foreach ( $document['fields'] as $field ) {
                if ( preg_match( StorageLayout::owned_pattern( $field ), $key ) === 1 ) {
                    $labels = [];
                    $policy = self::policy_at( $field, $key, (string) $field['key'], $labels );
                    return 'Fabrement · ' . implode( ' / ', $labels ) . ': ' . $policy;
                }
            }
        }
        return 'Fabrement';
    }

    private static function policy_at( array $field, string $key, string $path, array &$labels ): string {
        $labels[] = (string) $field['label'];
        $policy = (string) $field['translation'];
        // An own container's children do not have independent translation policies.
        if ( $key === $path || empty( $field['sub_fields'] ) || in_array( $policy, [ 'copy_once', 'ignore' ], true ) ) {
            return $policy;
        }
        $rest = substr( $key, strlen( $path ) + 1 );
        if ( $field['type'] === 'repeater' ) {
            if ( preg_match( '/^(\d+)_(.+)$/', $rest, $m ) !== 1 || $m[2] === StorageLayout::ROW_ID ) {
                return $policy;
            }
            $path .= '_' . $m[1];
        }
        foreach ( $field['sub_fields'] as $child ) {
            $child_path = $path . '_' . $child['key'];
            if ( $key === $child_path || strpos( $key, $child_path . '_' ) === 0 ) {
                return self::policy_at( $child, $key, $child_path, $labels );
            }
        }
        return $policy;
    }

    /** Encode common field texts in WPML's job format. */
    public static function package( $package, $post ) {
        $object = $post instanceof \WP_Post ? FieldObject::post( (int) $post->ID ) : null;
        if ( ! $object || ! is_array( $package ) ) {
            return $package;
        }
        foreach ( PostTranslations::texts( $object ) as $token => $entry ) {
            $package['contents'][ $token ] = [ 'translate' => 1, 'data' => base64_encode( $entry['text'] ), 'format' => 'base64' ];
        }
        return $package;
    }

    /** Extend WPML's checksum with exactly the same texts as its field job. */
    public static function md5_content( $content, $post ) {
        $object = $post instanceof \WP_Post ? FieldObject::post( (int) $post->ID ) : null;
        $texts = $object ? PostTranslations::texts( $object ) : [];
        if ( ! $texts ) {
            return $content;
        }
        ksort( $texts );
        return $content . ';fabrement-fields;' . implode( ';', array_map( static fn( $t ) => $t['text'], $texts ) );
    }

    public static function job_labels( $fields, $job, $post ) {
        $object = $post instanceof \WP_Post ? FieldObject::post( (int) $post->ID ) : null;
        if ( ! $object || ! is_array( $fields ) ) {
            return $fields;
        }
        $texts = PostTranslations::texts( $object );
        foreach ( $fields as $i => $field ) {
            $entry = $texts[ $field['field_type'] ?? '' ] ?? null;
            if ( $entry ) {
                $fields[ $i ]['title'] = $entry['label'];
                $fields[ $i ]['field_style'] = $entry['html'] ? '2' : '1';
            }
        }
        return $fields;
    }

    /** Decode WPML's completed job; common field synchronization applies its words. */
    public static function completed( $target_id, $fields, $job ): void {
        $target_id = (int) $target_id;
        $source_id = (int) ( $job->original_doc_id ?? 0 );
        if ( ! $source_id || $source_id === $target_id ) {
            return;
        }
        $texts = [];
        $tokens = [];
        foreach ( (array) ( $job->elements ?? [] ) as $element ) {
            $token = (string) ( $element->field_type ?? '' );
            if ( strpos( $token, 'fabrement-field-' ) === 0 ) {
                $tokens[ $token ] = true;
            }
            if ( strpos( $token, 'fabrement-field-' ) !== 0 || empty( $element->field_finished ) || ( $element->field_format ?? '' ) !== 'base64' ) {
                continue;
            }
            $translated = base64_decode( (string) $element->field_data_translated, true );
            if ( $translated !== false ) {
                $texts[ $token ] = $translated;
            }
        }
        PostTranslations::sync( $source_id, $target_id, $texts, false, true, $tokens );
    }

    public static function before_duplicate( $source_id, $lang ): void {
        self::$duplicate_keys = [];
        // WPML deletes every unprotected key: retain ours on the original AND existing duplicate.
        $duplicate = Translation::bridge()->version_in( (int) $source_id, (string) get_post_type( (int) $source_id ), (string) $lang );
        foreach ( array_unique( [ (int) $source_id, $duplicate ] ) as $post ) {
            if ( $post <= 0 ) {
                continue;
            }
            foreach ( array_keys( (array) get_post_meta( $post ) ) as $key ) {
                if ( StorageLayout::owns( (string) $key ) ) {
                    self::$duplicate_keys[] = (string) $key;
                }
            }
        }
        self::$duplicate_keys = array_values( array_unique( self::$duplicate_keys ) );
    }

    /** WPML never copies our meta individually; shared FieldSync preserves row identities. */
    private static function protect( array $settings ): array {
        foreach ( (array) ( $settings['custom_fields_translation'] ?? [] ) as $key => $mode ) {
            if ( StorageLayout::owns( (string) $key ) ) {
                $settings['custom_fields_translation'][ $key ] = 0;
            }
        }
        return $settings;
    }

    public static function protect_loaded(): void {
        global $iclTranslationManagement;
        if ( is_object( $iclTranslationManagement ) && isset( $iclTranslationManagement->settings ) && is_array( $iclTranslationManagement->settings ) ) {
            $iclTranslationManagement->settings = self::protect( $iclTranslationManagement->settings );
        }
    }
}
