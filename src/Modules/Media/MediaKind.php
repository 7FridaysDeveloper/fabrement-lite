<?php

namespace Fabrement\Modules\Media;

defined( 'ABSPATH' ) || exit;

/** The media kinds an attachment falls into by its mime: image, video, audio, or document for the rest. */
final class MediaKind {

    public const KINDS = [ 'image', 'video', 'audio', 'document' ];

    public static function of( string $mime ): string {
        foreach ( [ 'image', 'video', 'audio' ] as $kind ) {
            if ( str_starts_with( $mime, $kind . '/' ) ) {
                return $kind;
            }
        }
        return 'document';
    }

    /**
     * @param mixed $kinds
     * @return list<string>|null the kinds, or null when none or not valid
     */
    public static function try_parse( $kinds ): ?array {
        try {
            return $kinds === null ? null : self::parse( $kinds );
        } catch ( \InvalidArgumentException $e ) {
            return null;
        }
    }

    /**
     * @param mixed $kinds
     * @return list<string>
     */
    public static function parse( $kinds ): array {
        $list = is_string( $kinds ) ? [ $kinds ] : $kinds;
        if ( ! is_array( $list ) || $list === [] || array_values( $list ) !== $list ) {
            throw new \InvalidArgumentException( 'give a media kind (' . esc_html( implode( ', ', self::KINDS ) ) . ') or a list of them.' );
        }
        foreach ( $list as $kind ) {
            if ( ! is_string( $kind ) || ! in_array( $kind, self::KINDS, true ) ) {
                throw new \InvalidArgumentException( '"' . ( is_scalar( $kind ) ? esc_html( (string) $kind ) : esc_html( get_debug_type( $kind ) ) ) . '" is not a media kind — use ' . esc_html( implode( ', ', self::KINDS ) ) . '.' );
            }
        }
        if ( count( array_unique( $list ) ) !== count( $list ) ) {
            throw new \InvalidArgumentException( 'a media kind is listed twice.' );
        }
        return $list;
    }
}
