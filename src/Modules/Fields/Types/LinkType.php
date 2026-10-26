<?php

namespace Fabrement\Modules\Fields\Types;

use Fabrement\Modules\Fields\Translation\TranslationPolicy;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\FieldValueError;

/**
 * An address or a record of this site, with a caption: {url, label, postId, lang, newTab,
 * useRecordTitle}. A picked record keeps no url of its own: the address comes from the record.
 */
final class LinkType extends FieldType {

    private const EMPTY = [ 'url' => '', 'label' => '', 'postId' => 0, 'lang' => '', 'newTab' => false, 'useRecordTitle' => false ];

    public function name(): string {
        return 'link';
    }

    /** Its caption (and a typed address) is words. */
    public function is_words(): bool {
        return true;
    }

    public function empty_value( array $field ) {
        return self::EMPTY;
    }

    public function is_empty( $value ): bool {
        return parent::is_empty( $value ) || ( is_array( $value ) && ( $value['url'] ?? '' ) === '' && ! ( $value['postId'] ?? 0 ) );
    }

    /** null — how a link nobody set reads — written back is the empty link. */
    public function from_public( array $field, $value, $previous, string $at ) {
        return $value === null ? $this->empty_value( $field ) : $value;
    }

    public function api_value( array $field, $value ) {
        return $this->is_empty( $value ) ? null : $value;
    }

    /** A link nobody set reads null in a template ("if ( $link )"). */
    public function reader_value( array $field, $value ) {
        return $this->is_empty( $value ) ? null : $value;
    }

    public function normalize( array $field, $value, $previous, bool $kept, string $at ) {
        $kept_post_id = is_array( $previous ) ? (int) ( $previous['postId'] ?? 0 ) : 0;
        if ( ! is_array( $value ) || array_diff( array_keys( $value ), array_keys( self::EMPTY ) ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::LINK_SHAPE );
        }
        $value = array_merge( self::EMPTY, $value );
        foreach ( [ 'url', 'label', 'lang' ] as $key ) {
            if ( ! is_string( $value[ $key ] ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
                throw new FieldValueError( FieldValueError::LINK_TEXT );
            }
        }
        if ( ! is_bool( $value['newTab'] ) || ! is_bool( $value['useRecordTitle'] ) || preg_match( '/^[a-zA-Z0-9_-]{0,20}$/D', $value['lang'] ) !== 1 ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::LINK_OPTIONS );
        }
        // A language the site does not have pins the link to a version that never exists, and the
        // link then prints nothing anywhere. What is already held is not judged again: a language
        // switched off since is the site's to decide about.
        $kept_lang = is_array( $previous ) ? (string) ( $previous['lang'] ?? '' ) : '';
        if ( $value['lang'] !== '' && $value['lang'] !== $kept_lang ) {
            $codes = array_column( \Fabrement\Modules\Translation\Translation::bridge()->languages(), 'code' );
            if ( ! in_array( $value['lang'], $codes, true ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
                throw new FieldValueError( FieldValueError::LINK_LANG, [ 'lang' => $value['lang'], 'codes' => $codes ] );
            }
        }
        Records::check( $value['postId'], 'post_object', $kept_post_id > 0 && $value['postId'] === $kept_post_id );
        $value['postId'] = Records::canonical( $value['postId'] );
        $url = $value['url'];
        if ( strlen( $url ) > 4096 || preg_match( '/[\x00-\x20]/', $url ) === 1 ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
            throw new FieldValueError( FieldValueError::LINK_ADDRESS );
        }
        if ( $value['postId'] ) {
            $value['url'] = '';
        } elseif ( $url !== '' && ( $url[0] === '#' || $url[0] === '/' || $url[0] === '\\' ) ) {
            // Site-relative addresses are kept as typed; esc_url_raw would prefix http:// to a fragment.
            // Letters outside ASCII ("/uk/новини/") are percent-encoded as a browser sends them, not
            // dropped by the cleaning below.
            $url = (string) preg_replace_callback( '/[\x80-\xff]+/', static fn( array $m ): string => rawurlencode( $m[0] ), $url );
            $clean = (string) preg_replace( '/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/', '', $url );
            // Judged as typed AND as it would be stored: "/\/evil.com" loses its backslash to the
            // cleaning above and becomes "//evil.com", which a browser opens as another site.
            if ( ! self::site_path( $url ) || ! self::site_path( $clean ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
                throw new FieldValueError( FieldValueError::LINK_SITE_PATH );
            }
            $value['url'] = $clean;
        } elseif ( $url !== '' ) {
            if ( preg_match( '/^(https?:|mailto:|tel:)/i', $url ) !== 1 ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data for the caller, not output.
                throw new FieldValueError( FieldValueError::LINK_SCHEME );
            }
            $value['url'] = esc_url_raw( $url, [ 'http', 'https', 'mailto', 'tel' ] );
        }
        $value['label'] = $kept ? $value['label'] : WordsType::words( $value['label'] );
        return $value;
    }

    /**
     * A fragment ("#top") or a path on this site ("/contacts"). Never "//host" or "/\host": a
     * browser reads both as an address on ANOTHER site (it treats "\" as "/").
     */
    private static function site_path( string $url ): bool {
        if ( $url === '' || $url[0] === '#' ) {
            return true;
        }
        return $url[0] === '/' && ( strlen( $url ) === 1 || ( $url[1] !== '/' && $url[1] !== '\\' ) );
    }

    public function flatten( array $field, $value, string $path, array &$meta ): void {
        $meta[ $path ] = $value;
    }

    public function inflate( array $field, $raw, string $path, array $meta, array &$present ) {
        $empty = ValueCodec::empty_value( $field );
        return is_array( $raw ) ? array_merge( $empty, array_intersect_key( $raw, $empty ) ) : $empty;
    }

    public function references( array $field, $value, array &$posts, array &$media ): void {
        if ( is_array( $value ) && (int) ( $value['postId'] ?? 0 ) > 0 ) {
            $posts[ (int) $value['postId'] ] = true;
        }
    }

    /** The caption, and a typed address (a picked record's address is the record's). */
    public function texts( array $field, $value, array $path, string $label ): array {
        $out = [];
        if ( ! is_array( $value ) ) {
            return $out;
        }
        foreach ( $value['postId'] ? [ 'label' ] : [ 'label', 'url' ] as $part ) {
            if ( ( $value[ $part ] ?? '' ) !== '' ) {
                $out[ TranslationPolicy::token( array_merge( $path, [ $part ] ) ) ] = [ 'text' => $value[ $part ], 'label' => $label . ' / ' . $part, 'html' => false ];
            }
        }
        return $out;
    }

    public function clear_texts( array $field, $value, ?array $source, array $job, array $path, array &$cleared ) {
        if ( ! is_array( $value ) || $this->is_empty( $value ) ) {
            return $value;
        }
        $held = $this->texts( $field, $value, $path, '' );
        $original = $source === null ? null : $source[0];
        if ( ( $original === null || $this->is_empty( $original ) ) && ! array_intersect_key( $held, $job ) ) {
            $cleared[ TranslationPolicy::token( $path ) ] = wp_json_encode( $value );
            return self::EMPTY;
        }
        $label = TranslationPolicy::token( array_merge( $path, [ 'label' ] ) );
        if ( isset( $held[ $label ] ) && ! isset( $job[ $label ] ) && is_array( $original ) && ( $original['label'] ?? '' ) === '' ) {
            $cleared[ $label ] = $value['label'];
            $value['label'] = '';
        }
        return $value;
    }

    public function apply_texts( array $field, $value, array $texts, array $path ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        $before = $value;
        foreach ( [ 'label', 'url' ] as $part ) {
            if ( array_key_exists( TranslationPolicy::token( array_merge( $path, [ $part ] ) ), $texts ) ) {
                $value[ $part ] = (string) $texts[ TranslationPolicy::token( array_merge( $path, [ $part ] ) ) ];
            }
        }
        // The picked record is the one already held: a trashed target must not fail the sync.
        try {
            return ValueCodec::normalize( [ 'required' => false ] + $field, $value, '', [ $before ] );
        } catch ( \InvalidArgumentException $e ) {
            // A translated address that is no address (no scheme, a space, a translated word) must
            // not cost the job every other word: the link keeps the address it had, its caption
            // is translated.
            if ( ( $value['url'] ?? '' ) === ( $before['url'] ?? '' ) ) {
                throw $e;
            }
            \Fabrement\Framework\Log::debug( '[fabrement][fields] Translated address of "' . $field['name'] . '" refused, the link keeps its address: ' . $e->getMessage() );
            $value['url'] = $before['url'] ?? '';
            return ValueCodec::normalize( [ 'required' => false ] + $field, $value, '', [ $before ] );
        }
    }
}
