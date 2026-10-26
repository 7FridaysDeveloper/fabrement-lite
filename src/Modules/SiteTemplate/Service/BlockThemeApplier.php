<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;

/**
 * Block-theme (FSE) applier: registers every published site template as a plugin
 * block template (`register_block_template()`, WP 6.7+), so it appears in the
 * page editor's native Template switcher. Selecting one stores our slug in
 * `_wp_page_template` — the same value the classic applier understands.
 *
 * Registration carries NO content: assembling one parses the stored markup, and
 * `init` runs on every request while at most ONE template is ever rendered. The
 * content is filled in
 * when WordPress hands the template out — see {@see fill_content()}.
 */
final class BlockThemeApplier {

    /** @var array<string,SiteTemplateRecord> Our registered templates by slug, for the lazy fill. */
    private static array $records = [];

    public static function init(): void {
        // Priority 20: after the template post type is registered (10).
        add_action( 'init', [ self::class, 'register_templates' ], 20 );
        // The three doors a registered plugin template comes back through: the front-end
        // resolution path, a single read by id, and the editor's listing.
        add_filter( 'get_block_file_template', [ self::class, 'filter_template' ], 10, 1 );
        add_filter( 'get_block_template', [ self::class, 'filter_template' ], 10, 1 );
        add_filter( 'get_block_templates', [ self::class, 'filter_templates' ], 10, 1 );
        // Site-default resolution for singulars that picked nothing (see below).
        // The template-hierarchy filter is per QUERY type, not per post type:
        // pages resolve through `page_template_hierarchy`, while posts and every
        // other single CPT resolve through `single_template_hierarchy`. We hook
        // both and gate on the queried object's post type inside the callback,
        // so the site default reaches whichever post types have one.
        add_filter( 'page_template_hierarchy', [ self::class, 'prepend_site_default' ] );
        add_filter( 'single_template_hierarchy', [ self::class, 'prepend_site_default' ] );
        // Archive views (post-type archive / blog home) resolve via the explicit
        // archive binding — a bound template's slug is prepended to the hierarchy
        // exactly like the singular site default above.
        add_filter( 'archive_template_hierarchy', [ self::class, 'prepend_archive_binding' ] );
        add_filter( 'home_template_hierarchy', [ self::class, 'prepend_archive_binding' ] );
        // The not-found view when no page stands in for it ("Default for: 404").
        add_filter( '404_template_hierarchy', [ self::class, 'prepend_archive_binding' ] );
        // Core bug workaround (WP 7.0): when a slug__in query matches ONLY
        // registry-registered templates (like ours), get_block_templates()
        // returns an array keyed by template NAME — array_merge() keeps string
        // keys — and block-editor.php:469 reads [0] straight away, spraying
        // "Undefined array key 0" warnings in the editor of any page that has
        // our template explicitly selected. Re-index to a plain list.
        add_filter( 'get_block_templates', static fn( $templates ) => array_values( (array) $templates ) );
    }

    public static function register_templates(): void {
        // `register_block_template` ships with WP 6.7; classic themes get the
        // `theme_page_templates` path instead (see ClassicThemeApplier).
        if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
            return;
        }

        foreach ( SiteTemplateRegistry::originals() as $template ) {
            self::$records[ $template->slug ] = $template;
            register_block_template(
                'fabrement//' . $template->slug,
                [
                    'title'       => sprintf( 'Fabrement: %s', $template->title ),
                    'description' => __( 'Block template managed by Fabrement.', 'fabrement-lite' ),
                    // Filled by fill_content() when WordPress asks for this template.
                    'content'     => '',
                    // EVERY type a template can dress. This list is what WordPress checks before
                    // honouring a record's own `_wp_page_template`, so narrowing it to some smaller
                    // set made an explicit pick inert: the meta was stored, the editor had offered
                    // it, and the page still rendered in the theme's frame. A pick is the record's
                    // own choice and outranks the type's default (prepend_site_default below).
                    'post_types'  => array_keys( SiteTemplatePostType::available_post_types() ),
                ]
            );
        }
    }

    /**
     * Site-default resolution: a singular (page/post/CPT) whose post type has a
     * default and which has NO explicit pick renders that default template.
     * Resolved at render time — nothing is ever
     * written into pages, so switching or clearing the default re-dresses
     * the whole site instantly, and explicit picks (ours, Elementor's, the
     * theme's) always win. The front page needs no special-casing here: when
     * the theme (or the user, via the Site Editor) provides a `front-page`
     * template, WordPress resolves it BEFORE the page hierarchy and this
     * filter never comes into play.
     *
     * @param string[] $templates Template hierarchy candidates.
     * @return string[]
     */
    public static function prepend_site_default( $templates ) {
        if ( ! wp_is_block_theme() ) {
            return $templates;
        }
        // The not-found PAGE standing in for a missing address resolves as a page, but its frame is
        // the "Default for: 404" template when one is set — ahead of the page's own pick.
        if ( ArchiveTemplateBinding::current_context() === '404' ) {
            $record = ArchiveTemplateBinding::current_record();
            if ( $record !== null ) {
                array_unshift( $templates, $record->slug );
                return $templates;
            }
        }
        // A type is DEFAULT-dressed when somebody gave it a default and not otherwise — there is
        // no second list to consult. This callback runs for both the page and the single (post/CPT)
        // hierarchies. An explicit pick never arrives here: WordPress resolves a record's own
        // template before this filter's result is used, which is why the registration above offers
        // every type.
        $queried   = get_queried_object();
        $post_type = $queried instanceof \WP_Post ? $queried->post_type : '';
        if ( $post_type === '' ) {
            return $templates;
        }
        if ( TemplateResolver::picked_template()[0] ) {
            return $templates; // Explicit pick — WordPress resolves it itself.
        }
        $record = TemplateResolver::default_template_for( $post_type );
        if ( $record === null ) {
            return $templates; // no default for this post type.
        }
        array_unshift( $templates, $record->slug );
        return $templates;
    }

    /**
     * Archive resolution: when the current view is a bound archive context
     * (post-type archive / blog home), its template's slug leads the hierarchy —
     * WP then resolves it to our registered plugin template.
     *
     * @param string[] $templates Template hierarchy candidates.
     * @return string[]
     */
    public static function prepend_archive_binding( $templates ) {
        if ( ! wp_is_block_theme() ) {
            return $templates;
        }
        $record = ArchiveTemplateBinding::current_record();
        if ( $record === null ) {
            return $templates;
        }
        array_unshift( $templates, $record->slug );
        return $templates;
    }

    /**
     * Fill one template's content on the way out. Registration leaves it empty, so the
     * assembly happens for the template WordPress actually asked for — one on a page
     * view, the listed set in the editor — instead of all of them on every request.
     *
     * @param mixed $template A WP_Block_Template, or whatever another filter returned.
     * @return mixed The same value.
     */
    public static function filter_template( $template ) {
        if ( ! $template instanceof \WP_Block_Template || ( $template->plugin ?? '' ) !== 'fabrement' ) {
            return $template;
        }
        if ( (string) $template->content !== '' ) {
            return $template; // Already assembled earlier in this request.
        }
        $record = self::$records[ $template->slug ] ?? null;
        if ( $record === null ) {
            return $template;
        }

        $content = TemplateContent::for_reader( $record );

        // What core does to a registered template's content right before this filter — it ran
        // on the empty string, so hooked blocks (another plugin's header/footer insertions)
        // would be missing from what we put in now.
        if ( $content !== '' && function_exists( 'apply_block_hooks_to_content' ) ) {
            $content = apply_block_hooks_to_content(
                $content,
                $template,
                'insert_hooked_blocks_and_set_ignored_hooked_blocks_metadata'
            );
        }

        $template->content = $content;

        return $template;
    }

    /**
     * The editor's listing: the same fill, for every template of ours in the result.
     *
     * @param mixed $templates
     * @return mixed
     */
    public static function filter_templates( $templates ) {
        if ( ! is_array( $templates ) ) {
            return $templates;
        }
        foreach ( $templates as $template ) {
            self::filter_template( $template );
        }
        return $templates;
    }
}
