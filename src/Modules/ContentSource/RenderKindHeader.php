<?php

namespace Fabrement\Modules\ContentSource;

/**
 * The `Fabrement:` file header — how a render file says which view it fills.
 *
 *     <?php
 *     /**
 *      * Fabrement: archive
 *      *\/
 *
 * The value is the SAME dialect the content-source rules use, so there is one
 * vocabulary rather than two:
 *
 *   "singular"          — any single post/page/CPT view (a KIND: broad)
 *   "archive"           — any archive view, posts index included (a KIND: broad)
 *   "singular:page"     — that one view, and no other (a CONTEXT: exact)
 *   "archive:product"   — likewise
 *   "blog-home"         — likewise (its kind is archive: it runs the main loop)
 *   "tax:genre"         — any term archive of that taxonomy (kind: archive)
 *   "tax:genre:12"      — that one term's archive (the term id)
 *   "tax"               — any term archive of any taxonomy
 *
 * Declaring the KIND is enough to be safe — it is the distinction that decides
 * whether the body works at all (a singular body assumes the queried post is set
 * up; an archive body runs the main loop itself). Declaring the exact CONTEXT is a
 * further promise: "this was written for product archives specifically", which lets
 * the picker stop offering it for the blog index.
 *
 * Anything else — an unknown value, or no header — is UNCLASSIFIED: null, meaning
 * "cannot tell", never "singular". An undeclared file stays offered everywhere,
 * because refusing it would break bodies that work today.
 *
 * The syntax is WordPress's own file-header format, read with get_file_data() — the
 * same call core uses for `Template Name:`. Deliberate: a theme developer already
 * knows this shape, and core's reader only touches the first 8KB.
 *
 * A theme file under {theme}/templates/ has nowhere else to say it, so the header
 * is its only source of truth.
 */
final class RenderKindHeader {

    /** The header key, as it appears before the colon. */
    public const HEADER = 'Fabrement';

    /** A bare kind, or a kind + post type, or the posts index. */
    private const DECLARATION_RE = '/^(singular|archive)(:[a-z0-9_-]+)?$|^blog-home$|^404$|^tax(:[a-z0-9_-]+(:[a-z0-9%_-]+)?)?$/D';

    /** What a file on disk declares ("archive", "archive:product", …), or null. */
    public static function from_file( string $full ): ?string {
        $data = get_file_data( $full, [ 'declares' => self::HEADER ] );
        return self::normalise( (string) ( $data['declares'] ?? '' ) );
    }



    /**
     * The view SHAPE a declaration implies — the coarse question, and the one that
     * decides whether a body can run at all. "blog-home" is an archive: it renders a
     * list and runs the main loop itself.
     */
    public static function kind_of( string $declaration ): ?string {
        $declaration = self::normalise( $declaration );
        if ( $declaration === null ) {
            return null;
        }
        // "404" runs no loop — it is a singular body.
        return ( strncmp( $declaration, 'singular', 8 ) === 0 || $declaration === '404' ) ? 'singular' : 'archive';
    }

    /**
     * May a file declaring $declaration fill $context?
     *
     * A bare kind fits every context of that shape. An exact context fits only
     * itself — that is the whole point of naming the post type: "archive:product"
     * says this body knows about products, so offering it for the blog index would
     * be offering something its author never promised.
     */
    public static function fits( ?string $declaration, string $context ): bool {
        $declaration = $declaration === null ? null : self::normalise( $declaration );
        if ( $declaration === null || $context === '' ) {
            return true; // Unclassified: cannot tell, so do not refuse.
        }
        if ( $declaration === 'singular' || $declaration === 'archive' ) {
            return self::kind_of( $declaration ) === self::context_kind( $context );
        }
        // A term context accepts any level of its own chain: a body declared for the
        // taxonomy ("tax:genre") — or for term archives at large ("tax") — fits the
        // term's view, exactly as its rule would have matched it.
        return in_array(
            $declaration,
            \Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding::context_chain( $context ),
            true
        );
    }

    /** The shape a view context needs: singular views want a singular body, everything else an archive one. */
    public static function context_kind( string $context ): string {
        // "404" runs no loop — a singular body, like a page's.
        return ( strncmp( $context, 'singular:', 9 ) === 0 || $context === '404' )
            ? 'singular'
            : 'archive';
    }


    /** Whitelist a raw header value; anything unrecognised is "cannot tell". */
    private static function normalise( string $raw ): ?string {
        $value = strtolower( trim( $raw ) );
        return preg_match( self::DECLARATION_RE, $value ) === 1 ? $value : null;
    }
}
