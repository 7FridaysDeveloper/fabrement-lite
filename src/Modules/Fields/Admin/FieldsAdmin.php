<?php

namespace Fabrement\Modules\Fields\Admin;

use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Fields\Schema\StorageLayout;
use Fabrement\Modules\Fields\Translation\PostTranslations;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\FieldRead;

/**
 * The "Fabrement fields" box and its assets. HTTP requests live in Route/Fields.
 */
final class FieldsAdmin {

    public static function init(): void {
        add_action( 'add_meta_boxes', [ self::class, 'box' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
        // Hide our keys from the core Custom Fields box: edited there, a repeater breaks.
        add_filter( 'is_protected_meta', [ self::class, 'protect' ], 10, 2 );
    }

    public static function box( string $post_type, $post ): void {
        if ( ! $post || ! self::has_fields( $post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }
        // Classic Editor: the fields form is built for the block editor — there it saves with the
        // post; here it would take input and drop it on Update. Say where they are edited instead.
        if ( ! use_block_editor_for_post( $post ) ) {
            add_meta_box( 'fabrement-post-fields', __( 'Fabrement fields', 'fabrement-lite' ), static function (): void {
                echo '<p>' . esc_html__( 'Fabrement fields are edited in the block editor. Open this item in the block editor to fill them in; what is saved there shows on the site either way.', 'fabrement-lite' ) . '</p>';
            }, $post_type, 'normal', 'high' );
            return;
        }
        add_meta_box( 'fabrement-post-fields', __( 'Fabrement fields', 'fabrement-lite' ), static function ( $post ): void {
            printf( '<div data-fabrement-post-fields="%d"></div>', (int) $post->ID );
            // The form's first state rides with the page — fields, values and the titles and
            // thumbnails they point at — so the box opens without a request, like ACF's boxes.
            PostTranslations::remember_pending( $post );
            $object = FieldObject::post( (int) $post->ID );
            $state = $object ? FieldRead::for_editor( $object ) : null;
            if ( is_array( $state ) ) {
                self::print_state( (string) (int) $post->ID, $state );
            }
        }, $post_type, 'normal', 'high', [ '__block_editor_compatible_meta_box' => true ] );
    }

    /**
     * A form's first state, handed to the editor script (window.fabrementFieldsState[key]). The
     * script prints in the footer, after the box and the options page are drawn.
     *
     * @param array<string,mixed> $state
     */
    public static function print_state( string $key, array $state ): void {
        wp_add_inline_script(
            'fabrement-editor-plugin',
            sprintf(
                "window.fabrementFieldsState = window.fabrementFieldsState || {};\nwindow.fabrementFieldsState[%s] = %s;",
                wp_json_encode( $key ),
                wp_json_encode( $state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE )
            ),
            'before'
        );
    }

    public static function assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! self::has_fields( (string) $screen->post_type ) || ! $screen->is_block_editor() ) {
            return;
        }
        EditorAssets::register();
        wp_enqueue_script( 'fabrement-editor-plugin' );
        wp_enqueue_style( 'fabrement-editor-plugin' );
        wp_enqueue_style( 'wp-components' );
        wp_enqueue_media();
    }

    private static function has_fields( string $post_type ): bool {
        $owner = Owner::of( 'post', $post_type );
        if ( ! $owner || ! $owner->exists() ) {
            return false;
        }
        foreach ( SchemaRepository::get( $owner )['fields'] ?? [] as $field ) {
            if ( empty( $field['retired'] ) ) {
                return true;
            }
        }
        return false;
    }

    /** @param bool $protected */
    public static function protect( $protected, $meta_key ) {
        if ( $protected || ! is_string( $meta_key ) ) {
            return $protected;
        }
        return StorageLayout::owns( $meta_key );
    }

}
