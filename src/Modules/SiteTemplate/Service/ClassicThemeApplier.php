<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;

/**
 * Classic-theme applier: lists every published site template in the classic
 * "Template" dropdown (`theme_page_templates`) and, when a page has one
 * selected, swaps the whole page template for our canvas via
 * `template_include`. The theme's own header.php/footer.php never run on such
 * a page — no double-header fighting.
 */
final class ClassicThemeApplier {

    /** The template being rendered by canvas.php on this request. */
    private static ?SiteTemplateRecord $current = null;

    public static function init(): void {
        // The classic "Template" dropdown filter is per-post-type
        // (`theme_{$post_type}_templates`), so register one per public type.
        // Late 'init' priority: custom post types are registered by then.
        add_action( 'init', [ self::class, 'register_template_filters' ], 99 );
        // Late priority: page builders / themes also filter template_include —
        // an explicit per-page pick of OUR template must win.
        add_filter( 'template_include', [ self::class, 'swap_template' ], 999 );
    }

    /**
     * Offer the templates in the "Template" dropdown of every type a template could dress.
     *
     * EVERY public type: choosing a frame for ONE record is a per-record decision, and the
     * dropdown is where a person makes it. Offering it only where a default already applies
     * meant the choice existed for types that needed it least and was missing exactly where a
     * one-off frame is wanted. A default is a separate, type-wide decision.
     */
    public static function register_template_filters(): void {
        if ( wp_is_block_theme() ) {
            return;
        }
        foreach ( array_keys( SiteTemplatePostType::available_post_types() ) as $post_type ) {
            add_filter( "theme_{$post_type}_templates", [ self::class, 'add_templates' ], 10, 4 );
        }
    }

    /**
     * @param array<string,string> $templates slug => label.
     * @return array<string,string>
     */
    public static function add_templates( $templates, $theme = null, $post = null, $post_type = 'page' ) {
        foreach ( SiteTemplateRegistry::originals() as $template ) {
            $templates[ $template->slug ] = sprintf( 'Fabrement: %s', $template->title );
        }
        return $templates;
    }

    /** @param string $template Resolved template path. */
    public static function swap_template( $template ) {
        if ( wp_is_block_theme() ) {
            return $template;
        }
        // Singulars resolve via the per-page pick / post-type default; archive views
        // (post-type archive, blog home) via the explicit archive binding — an archive is
        // only ever dressed when someone bound a template to it.
        //
        // EVERY singular is asked, whatever its post type. A list of "covered" types used to
        // gate this whole branch, which meant it also vetoed an explicit per-record pick: a
        // record whose type was not on the list wore the theme's frame even though somebody
        // had chosen ours for that one page, in the editor's own Template dropdown. That list
        // is gone; TemplateResolver::singular_template() decides on the record alone — its own pick first,
        // then its type's default.
        if ( ArchiveTemplateBinding::current_context() === '404' ) {
            // Not found: the "Default for: 404" template, else — when the not-found PAGE is being
            // shown — whatever frame that page wears, else the theme.
            $record = ArchiveTemplateBinding::current_record()
                ?? ( \Fabrement\Modules\NotFound\NotFoundPage::serving() ? TemplateResolver::singular_template() : null );
        } elseif ( is_singular() ) {
            $record = TemplateResolver::singular_template();
        } else {
            $record = ArchiveTemplateBinding::current_record();
        }
        if ( $record === null ) {
            return $template;
        }
        self::$current = $record;
        return dirname( __DIR__ ) . '/Templates/canvas.php';
    }

    /** Read by canvas.php while rendering. */
    public static function current_template(): ?SiteTemplateRecord {
        return self::$current;
    }
}
