<?php

namespace Fabrement\Modules\Mcp\Support;

/**
 * Text a client sends for a block, a title, a menu label, a field value or a form template is
 * refused when it carries a script, an event handler, an iframe or style tag, or an executable
 * address. Never rewritten: the caller is told what and where, and nothing is saved.
 */
final class ContentSafety {

    /** Block lists are judged entry by entry (entry()), so untouched neighbours stay; a file is bytes. */
    private const JUDGED_ELSEWHERE = [ 'blocks', 'edits', 'base64' ];

    /** Tool => input keys holding a Contact Form 7 template, which allows form controls too. */
    private const FORM = [
        'fabrement/create-cf7-form'    => [ 'template' ],
        'fabrement/update-cf7-form'    => [ 'template' ],
        'fabrement/translate-cf7-form' => [ 'template' ],
    ];

    /**
     * One block entry the caller SENT ({name, attributes} or {markup}); stored entries carried
     * through an edit are not brought here, so an old embed does not block every later save.
     */
    public static function entry( array $entry, string $where ): void {
        if ( isset( $entry['markup'] ) && is_string( $entry['markup'] ) ) {
            self::html( $entry['markup'], $where );
            self::blocks( parse_blocks( $entry['markup'] ), $where );
        } else {
            self::attributes( is_array( $entry['attributes'] ?? null ) ? $entry['attributes'] : [], $where );
        }
    }

    private static function blocks( array $blocks, string $where ): void {
        foreach ( $blocks as $block ) {
            self::attributes( $block['attrs'] ?? [], $where );
            self::blocks( $block['innerBlocks'] ?? [], $where );
        }
    }

    /** Values set on a block, however deep: they become markup and addresses when it is drawn. */
    private static function attributes( array $values, string $where ): void {
        $allowed = wp_kses_allowed_html( 'post' );
        foreach ( $values as $key => $value ) {
            if ( is_array( $value ) ) {
                self::attributes( $value, $where );
            } elseif ( is_string( $value ) ) {
                // Core's dynamic wrappers turn tagName into a real element name.
                $problem = $key === 'tagName' && ! isset( $allowed[ strtolower( $value ) ] )
                    ? sprintf( 'the %s element', $value )
                    // A key that holds an address takes no script scheme at all; any other value is
                    // words first: a heading "JavaScript: the good parts" is not an address.
                    : ( ( in_array( $key, [ 'url', 'href', 'src' ], true ) ? self::scheme( $value ) : self::executable( $value ) ) ?? self::problem( $value, $allowed ) );
                if ( $problem !== null ) {
                    self::refuse( $where, $problem );
                }
            }
        }
    }

    /**
     * Every text a writing tool was sent: a title, a menu label, a form template, a field value.
     * The same rule as for blocks, for every caller — an administrator's client included.
     */
    public static function input( array $input, string $tool = '' ): void {
        $post = wp_kses_allowed_html( 'post' );
        foreach ( $input as $key => $value ) {
            if ( in_array( $key, self::JUDGED_ELSEWHERE, true ) ) {
                continue;
            }
            // Contact Form 7's own list for a form: post HTML plus input, select, button and the like.
            $form = in_array( $key, self::FORM[ $tool ] ?? [], true ) && function_exists( 'wpcf7_kses_allowed_html' );
            self::walk( $value, (string) $key, $form ? wpcf7_kses_allowed_html( 'form' ) : $post );
        }
    }

    /** @param mixed $value */
    private static function walk( $value, string $where, array $allowed ): void {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $inner ) {
                self::walk( $inner, is_int( $key ) ? $where . '[' . $key . ']' : $where . '.' . $key, $allowed );
            }
        } elseif ( is_string( $value ) ) {
            $problem = self::executable( $value ) ?? self::problem( $value, $allowed );
            if ( $problem !== null ) {
                self::refuse( $where, $problem );
            }
        }
    }

    /** Refuses markup a post may not hold. */
    public static function html( string $value, string $where ): void {
        $problem = self::problem( $value, wp_kses_allowed_html( 'post' ) );
        if ( $problem !== null ) {
            self::refuse( $where, $problem );
        }
    }

    /**
     * What in the text a post may not hold, or null. The text is read tag by tag the way a browser
     * reads it (WP_HTML_Tag_Processor) and each tag, attribute, address and style is put to
     * KSES's own rules — not compared as a string, so quoting, spacing, a trailing ";" in a style
     * or "/>" do not matter, and "a < b" is plain text.
     *
     * @param array<string, mixed> $allowed
     */
    private static function problem( string $value, array $allowed ): ?string {
        if ( strpos( $value, '<' ) === false ) {
            return null;
        }
        $css  = static fn( string $style ): string => (string) preg_replace( '/[\s;]+/', '', $style );
        $uris = wp_kses_uri_attributes();
        $ok   = wp_allowed_protocols();
        $html = new \WP_HTML_Tag_Processor( $value );
        // KSES drops a style rule holding any "(" it does not know; the editor writes rgba(),
        // shadows and filters. Allowed for this check only.
        add_filter( 'safecss_filter_attr_allow_css', [ self::class, 'allow_css_functions' ], 10, 2 );
        try {
            return self::tags( $html, $allowed, $css, $uris, $ok );
        } finally {
            remove_filter( 'safecss_filter_attr_allow_css', [ self::class, 'allow_css_functions' ], 10 );
        }
    }

    /** Harmless CSS functions with plain arguments: colours, filters, transforms, timing. */
    private const CSS_FUNCTIONS = 'rgba?|hsla?|blur|brightness|contrast|drop-shadow|grayscale|hue-rotate|invert|saturate|sepia|opacity|translate(?:[xyz]|3d)?|scale(?:[xyz]|3d)?|rotate(?:[xyz]|3d)?|skew[xy]?|matrix(?:3d)?|perspective|cubic-bezier|steps|minmax|repeat|fit-content';

    /**
     * Whether a style rule KSES refused holds nothing but those functions.
     *
     * @param bool   $allow What KSES decided.
     * @param string $css   The rule as KSES tests it.
     */
    public static function allow_css_functions( $allow, $css ): bool {
        if ( $allow ) {
            return true;
        }
        $rest = (string) $css;
        do {
            // Innermost first: drop-shadow( 0 0 2px rgba( 0, 0, 0, .5 ) ).
            $rest = (string) preg_replace( '/(?<![a-z0-9_-])(?:' . self::CSS_FUNCTIONS . ')\(\s*[0-9a-z%.,\s#\/+-]*\)/i', ' ', $rest, -1, $count );
        } while ( $count > 0 );
        // KSES's own test for what is left.
        return preg_match( '%[\\\\(&=}]|/\*%', $rest ) !== 1;
    }

    /**
     * @param array<string, mixed> $allowed
     * @param list<string>         $uris
     * @param list<string>         $ok
     */
    private static function tags( \WP_HTML_Tag_Processor $html, array $allowed, callable $css, array $uris, array $ok ): ?string {
        while ( $html->next_tag( [ 'tag_closers' => 'visit' ] ) ) {
            $tag = strtolower( (string) $html->get_tag() );
            if ( ! isset( $allowed[ $tag ] ) ) {
                return sprintf( 'the %s element', $tag );
            }
            if ( $html->is_tag_closer() ) {
                continue;
            }
            foreach ( (array) $html->get_attribute_names_with_prefix( '' ) as $name ) {
                $raw   = $html->get_attribute( $name );
                $text  = is_string( $raw ) ? $raw : '';
                $n     = $name;
                $v     = $text;
                $whole = $name . '="' . esc_attr( $text ) . '"';
                if ( ! wp_kses_attr_check( $n, $v, $whole, is_string( $raw ) ? 'n' : 'y', $tag, $allowed ) ) {
                    return sprintf( 'the %1$s attribute on the %2$s element', $name, $tag );
                }
                // KSES drops the rules of a style it does not allow and keeps the rest.
                if ( $name === 'style' && $css( $v ) !== $css( $text ) ) {
                    return sprintf( 'a style rule on the %s element', $tag );
                }
                if ( in_array( $name, $uris, true ) && wp_kses_bad_protocol( $text, $ok ) !== wp_kses_no_null( $text ) ) {
                    return sprintf( 'the address in %1$s on the %2$s element', $name, $tag );
                }
            }
        }
        // "a <b" with no end: a browser would take what follows into the tag.
        return $html->paused_at_incomplete_token() ? 'a less-than sign that starts a tag which never ends (put a space after the sign, or close the tag)' : null;
    }

    /** A block value that is an address by itself: a bare URL is not HTML, so no tag rule sees its scheme. */
    private static function scheme( string $value ): ?string {
        $address = (string) preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        return preg_match( '/^(javascript|vbscript|data):/i', $address, $m ) === 1 ? sprintf( 'a %s: address', strtolower( $m[1] ) ) : null;
    }

    /**
     * Plain text that would run if a theme printed it as an address: `javascript:` with code after
     * it, or a data: document. A title such as "JavaScript: the good parts" is words, not an address.
     */
    private static function executable( string $value ): ?string {
        $address = (string) preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( preg_match( '/^(javascript|vbscript):.*[(=;`<]/is', $address, $m ) === 1 ) {
            return sprintf( 'a %s: address', strtolower( $m[1] ) );
        }
        return preg_match( '~^data:[a-z0-9.+-]+/[a-z0-9.+-]+[;,]~i', $address ) === 1 ? 'a data: address' : null;
    }

    private static function refuse( string $where, string $problem ): void {
        throw new McpAbilityException(
            esc_html(
                sprintf(
                    '%1$s is refused because of %2$s. Nothing was saved. Allowed: plain text and post-safe HTML (p, br, strong, em, a, lists, headings, an inline style); no scripts, event handlers, iframe or style tags, or executable addresses. To show a tag as words write it with &lt; and &gt;.',
                    $where,
                    $problem
                )
            ),
            [],
            'unsafe_content'
        );
    }
}
