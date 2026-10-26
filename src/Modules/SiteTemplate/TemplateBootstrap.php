<?php

namespace Fabrement\Modules\SiteTemplate;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\BlockThemeApplier;
use Fabrement\Modules\SiteTemplate\Service\ClassicThemeApplier;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateDeleter;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateWriter;
use Fabrement\Modules\SiteTemplate\Service\TemplateParts;
use Fabrement\Modules\SiteTemplate\Service\TemplateWidth;

/** Templates contain installed blocks, never generated executable files. */
final class TemplateBootstrap {
    public static function init(): void {
        add_action( 'init', [ self::class, 'register' ] );
        add_action( 'rest_api_init', [ SiteTemplateWriter::class, 'register_rest_fields' ] );
        add_action( 'save_post_' . SiteTemplatePostType::POST_TYPE, [ SiteTemplateRegistry::class, 'flush' ] );
        add_action( 'before_delete_post', [ SiteTemplateDeleter::class, 'forget' ], 10, 2 );
        add_filter( 'rest_pre_insert_' . SiteTemplatePostType::POST_TYPE, [ SiteTemplateWriter::class, 'validate_rest' ], 10, 2 );
        add_filter( 'pre_trash_post', [ TemplateParts::class, 'guard_delete' ], 10, 2 );
        add_filter( 'pre_delete_post', [ TemplateParts::class, 'guard_delete' ], 10, 2 );
        add_filter( 'rest_request_before_callbacks', [ TemplateParts::class, 'guard_rest_delete' ], 10, 3 );
        add_action( 'save_post_wp_block', [ TemplateParts::class, 'forget' ] );
        BlockThemeApplier::init();
        ClassicThemeApplier::init();
        TemplateWidth::init();
    }

    public static function register(): void {
        \Fabrement\Modules\Fields\Admin\EditorAssets::register();
        \Fabrement\Modules\ContentSource\ContentSourceBlock::register();
        $caps = [];
        foreach ( [ 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ] as $cap ) {
            $caps[ $cap ] = 'manage_options';
        }
        register_post_type( SiteTemplatePostType::POST_TYPE, [
            'label' => __( 'Templates', 'fabrement-lite' ),
            'labels' => [ 'add_new_item' => __( 'Add block template', 'fabrement-lite' ), 'edit_item' => __( 'Edit block template', 'fabrement-lite' ) ],
            'public' => false, 'publicly_queryable' => false,
            'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => true,
            'show_in_nav_menus' => false, 'show_in_admin_bar' => false,
            'rest_controller_class' => \Fabrement\Route\InternalPostsRestController::class,
            'supports' => [ 'title', 'editor', 'revisions' ],
            'template' => [ [ 'fabrement/content-source' ] ], 'template_lock' => false,
            'capabilities' => $caps, 'map_meta_cap' => false,
            'delete_with_user' => false, 'rewrite' => false, 'query_var' => false,
        ] );
    }
}
