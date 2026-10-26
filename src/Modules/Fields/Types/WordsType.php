<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Translation\TranslationPolicy;
use Fabrement\Modules\Fields\Values\ValueCodec;

/**
 * Words: text, textarea, wysiwyg. Translated per language by default, and what a translator sees.
 */
final class WordsType extends TextualType {

    private string $name;

    /** Set while the site's own code writes (the PHP API): words are kept as written. */
    private static bool $site_code = false;

    public function __construct( string $name ) {
        $this->name = $name;
    }

    public function name(): string {
        return $this->name;
    }

    public function is_words(): bool {
        return true;
    }

    protected function clean( array $field, string $value, bool $kept ): string {
        // What the record already holds, sent back unchanged, is kept byte for byte: a group or
        // row sent whole must not re-clean the sub-fields nobody touched.
        if ( $kept ) {
            return $value;
        }
        return self::words( $value );
    }

    /**
     * Words as ACF saves them (acf-form-functions.php): as typed for someone who may post
     * unfiltered HTML; through wp_kses_post for anyone else, which keeps a "<" that opens no tag as
     * "&lt;" ("Kids &lt;12") and turns any other markup into harmless text. Printed with esc_html()
     * that reads "Kids <12" again; the fields form shows it decoded, as ACF's esc_attr() does.
     * Written by the site's own code (fabrement()->fields()->set(), see as_site_code()), as typed: that
     * code is trusted like ACF's update_field(), whoever the request happens to run as.
     */
    public static function words( string $value ): string {
        $value = wp_check_invalid_utf8( $value );
        return self::$site_code || current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
    }

    /**
     * Run a write of the site's own code (a theme, a plugin, a cron job, an import): words in it are
     * kept as written. The fields form and the MCP tools still clean them by the user's rights.
     *
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public static function as_site_code( callable $write ) {
        return self::with_trust( true, $write );
    }

    /** Nested entry points restore their own policy, including an untrusted save inside PHP. */
    public static function with_trust( bool $trusted, callable $write ) {
        $before         = self::$site_code;
        self::$site_code = $trusted;
        try {
            return $write();
        } finally {
            self::$site_code = $before;
        }
    }

    /**
     * The text of words for SEO and schema, where no markup belongs: script and style with what
     * they hold, tags (a "<" followed by a letter or "/") and comments go; a "<" that opens no tag
     * ("<12", "a <= b") stays, and nothing is trimmed. Not a sanitiser: its output is escaped
     * where it is printed.
     */
    public static function strip_tags( string $value ): string {
        $value = (string) preg_replace( '@<(script|style)\b[^>]*>.*?</\1\s*>@si', '', $value );
        return (string) preg_replace( '@<!--.*?-->|</?[a-zA-Z][^<>]*>@s', '', $value );
    }


    public function texts( array $field, $value, array $path, string $label ): array {
        if ( ! is_string( $value ) || $value === '' ) {
            return [];
        }
        return [ TranslationPolicy::token( $path ) => [ 'text' => $value, 'label' => $label, 'html' => $this->name === 'wysiwyg' ] ];
    }

    public function apply_texts( array $field, $value, array $texts, array $path ) {
        $token = TranslationPolicy::token( $path );
        return array_key_exists( $token, $texts ) ? ValueCodec::normalize( [ 'required' => false ] + $field, (string) $texts[ $token ] ) : $value;
    }

    public function clear_texts( array $field, $value, ?array $source, array $job, array $path, array &$cleared ) {
        if ( isset( $job[ TranslationPolicy::token( $path ) ] ) || $this->texts( $field, $value, $path, '' ) === []
            || ( $source !== null && $this->texts( $field, $source[0], $path, '' ) !== [] ) ) {
            return $value;
        }
        $cleared[ TranslationPolicy::token( $path ) ] = $value;
        return $source === null ? ValueCodec::empty_value( $field ) : $this->blank_value( $field );
    }
}
