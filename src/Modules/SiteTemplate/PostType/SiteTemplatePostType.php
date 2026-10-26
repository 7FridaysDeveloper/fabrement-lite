<?php

namespace Fabrement\Modules\SiteTemplate\PostType;

use Fabrement\Framework\Keys;

/**
 * CPT `fabrement_template`: a frame composed from installed Gutenberg blocks.
 * The title names the template; post_content stores its complete serialized frame.
 * Administrators edit it in Gutenberg or through MCP. Both theme appliers use
 * that content, including its Content Source slot and reusable header/footer patterns.
 */
final class SiteTemplatePostType {

    public const POST_TYPE = 'fabrement_template';

    /** Every template's page-template slug starts with this — also how a pick is recognised. */
    public const SLUG_PREFIX = 'fabrement-template-';

    /**
     * The manifest key this template answers to, or '' when it has none yet.
     *
     * '' when the template was never saved with rules: its slot then renders the page's own
     * content ({@see \Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig::read()}).
     */
    public static function manifest_key( int $template_id ): string {
        return trim( (string) get_post_meta( $template_id, Keys::META_TEMPLATE_KEY, true ) );
    }

    /** `_wp_page_template` value for a template post. */
    public static function slug_for( int $template_id ): string {
        return self::SLUG_PREFIX . $template_id;
    }

    /** Reverse of {@see slug_for()}; null when the value is not ours. */
    public static function id_from_slug( string $slug ): ?int {
        if ( ! str_starts_with( $slug, self::SLUG_PREFIX ) ) {
            return null;
        }
        $id = substr( $slug, strlen( self::SLUG_PREFIX ) );
        return ctype_digit( $id ) ? (int) $id : null;
    }

    /**
     * Per-post-type default templates: post_type => template id, every stored
     * entry with a non-zero id. A type that has since gone private or been
     * de-registered keeps its entry here, so the screen and the tools can SHOW
     * it and clear it; only handing a default OUT checks the live registry.
     *
     * @return array<string,int>
     */
    public static function type_defaults(): array {
        $out = [];
        foreach ( self::stored_type_defaults() as $type => $id ) {
            if ( $id > 0 ) {
                $out[ (string) $type ] = $id;
            }
        }
        return $out;
    }

    /**
     * The RAW persisted per-type default map (post_type => int id): whatever the
     * option holds, cast to int, with no filtering at all. Unlike
     * {@see type_defaults()} it keeps a stored zero — it is the base every
     * mutation reads, so a zero somebody wrote stays visible to the next write
     * instead of being silently re-created.
     *
     * @return array<string,int>
     */
    private static function stored_type_defaults(): array {
        $stored = get_option( Keys::OPTION_TEMPLATE_TYPE_DEFAULTS );

        return is_array( $stored ) ? array_map( 'intval', $stored ) : [];
    }

    /**
     * The not-found VIEW, kept in the same default map as the post types because it is the same
     * kind of decision: one frame, chosen once, for every request of that kind. Not a post type —
     * nothing is registered under it — so it skips the registry check below.
     */
    public const NOT_FOUND = '404';

    /** Default template id for one post type — or for the not-found view (NOT_FOUND) — 0 = none. */
    public static function default_template_id_for( string $post_type ): int {
        if ( $post_type === self::NOT_FOUND ) {
            return self::type_defaults()[ self::NOT_FOUND ] ?? 0;
        }
        // A STORED default for a type a template can no longer dress does NOT render. The type may
        // have gone private or been de-registered since; setting one for it is refused, so applying
        // one would leave the write path and the render path disagreeing about the same type.
        // type_defaults() still SHOWS it, which is what lets the screen and the tools clear it.
        if ( ! isset( self::available_post_types()[ $post_type ] ) ) {
            return 0;
        }
        return self::type_defaults()[ $post_type ] ?? 0;
    }

    /**
     * Set (id > 0) or clear (id === 0) the default template for one post type.
     * A type has at most one default, so setting it here implicitly replaces any
     * previous template for that type. Returns the full sanitised map.
     *
     * @return array<string,int>
     */
    public static function set_default_for( string $post_type, int $template_id ): array {
        $map = self::stored_type_defaults();
        if ( $template_id > 0 ) {
            $map[ $post_type ] = $template_id;
        } else {
            unset( $map[ $post_type ] );
        }
        update_option( Keys::OPTION_TEMPLATE_TYPE_DEFAULTS, $map );
        return self::type_defaults();
    }

    /** Drop a template id from every post type's default (on template delete). */
    public static function clear_template_from_defaults( int $template_id ): void {
        $map = array_filter( self::stored_type_defaults(), static fn( int $id ) => $id !== $template_id );
        update_option( Keys::OPTION_TEMPLATE_TYPE_DEFAULTS, $map );
    }

    /**
     * The one page a post-type default cannot reach, and why — or nothing at all.
     *
     * On a CLASSIC theme a theme's own `front-page.php` outranks the post-type default
     * ({@see \Fabrement\Modules\SiteTemplate\Service\TemplateResolver::singular_template()}), and
     * that is deliberate: a homepage somebody designed in the theme should not be overwritten by a
     * site-wide default. A block theme needs none of this — `front-page` wins inside WordPress's own
     * hierarchy there, with no rule of ours involved.
     *
     * The rule was never the problem; the silence was. set-default-template answered "done", every
     * page of the type changed, and the front page kept the theme's frame with nothing anywhere
     * connecting the two. Worse in the listing: the front page comes back with `template: ""`, which
     * reads as "no pick yet, so it will take the default" — the one row where that inference is
     * wrong. So both callers say it out loud instead.
     *
     * Computed per ANSWER, never per row: the condition is a fact about the site (a classic theme
     * with a front-page.php), not about a page, and locate_template() touches the filesystem.
     *
     * @param string $post_type Limit to a front page of this type ('' = any).
     * @return array<string,string> A `note` key, or an empty array when the rule does not apply.
     */
    public static function front_page_override_note( string $post_type = '' ): array {
        if ( wp_is_block_theme() ) {
            return array();
        }

        $front_id = get_option( 'show_on_front' ) === 'page' ? (int) get_option( 'page_on_front' ) : 0;
        if ( $front_id <= 0 ) {
            return array();
        }
        if ( $post_type !== '' && get_post_type( $front_id ) !== $post_type ) {
            return array();
        }
        if ( locate_template( 'front-page.php' ) === '' ) {
            return array();
        }

        return array(
            'note' => sprintf(
                /* translators: %d: the front page record id. */
                __( 'The front page (id %d) does NOT take a post-type default: this classic theme has a front-page.php of its own, and it outranks the default. Only an explicit pick covers that page — assign-template-pages.', 'fabrement-lite' ),
                $front_id
            ),
        );
    }

    /**
     * Public post types offered for template coverage: slug => human label.
     * Excludes attachments and our own template CPT. This is the pool the admin
     * picks from in the SPA.
     *
     * @return array<string,string>
     */
    public static function available_post_types(): array {
        $out = [];
        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
            if ( in_array( $pt->name, [ 'attachment', self::POST_TYPE ], true ) ) {
                continue;
            }
            $label       = $pt->labels->name ?? ( $pt->label ?: $pt->name );
            $out[ $pt->name ] = (string) $label;
        }
        return $out;
    }

    /** Normalise any stored/submitted content-width value to 'full' | 'constrained'. */
    public static function sanitize_content_width( $raw ): string {
        return $raw === 'constrained' ? 'constrained' : 'full';
    }

    /** This template's content width: 'full' (default) or 'constrained'. */
    public static function content_width( int $template_id ): string {
        return self::sanitize_content_width( get_post_meta( $template_id, Keys::META_TEMPLATE_CONTENT_WIDTH, true ) );
    }
}
