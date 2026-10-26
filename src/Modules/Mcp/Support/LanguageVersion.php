<?php

namespace Fabrement\Modules\Mcp\Support;

use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * `language` beside an original's id, as translate-content and translate-term take it: the call is
 * about that original's version in the language. A version keeps being addressed by its own id
 * without `language`; a version's id WITH `language` is refused rather than guessed from.
 */
final class LanguageVersion {

    /** Refuses a code that is not one of the site languages. */
    public static function check( string $lang ): ?WP_Error {
        $codes = array_column( Translation::bridge()->languages(), 'code' );
        if ( in_array( $lang, $codes, true ) ) {
            return null;
        }
        return new WP_Error( 'unknown_language', sprintf( 'Language "%s" is not one of the site languages — see list-languages.', $lang ) );
    }

    /**
     * The input with `contentId` / `termId` turned into the version in `language`, `language`
     * removed, and `from` holding the original's id. Input without `language`, or for an archive
     * (which has one SEO value for every language), comes back as it was.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    public static function target( array $input ) {
        if ( ! isset( $input['language'] ) || ! empty( $input['archive'] ) ) {
            return $input;
        }
        $lang = (string) $input['language'];
        $bad  = self::check( $lang );
        if ( $bad !== null ) {
            return $bad;
        }
        if ( ! empty( $input['termId'] ) && ! empty( $input['contentId'] ) ) {
            return new WP_Error( 'language_needs_target', '`language` goes with ONE original: contentId or termId.' );
        }
        $key = ! empty( $input['termId'] ) ? 'termId' : ( ! empty( $input['contentId'] ) ? 'contentId' : '' );
        if ( $key === '' ) {
            return new WP_Error( 'language_needs_target', '`language` goes with an original\'s contentId or termId.' );
        }
        $id     = (int) $input[ $key ];
        $bridge = Translation::bridge();
        if ( $key === 'termId' ) {
            $term = \WP_Term::get_instance( $id );
            if ( ! $term instanceof \WP_Term ) {
                return $input;
            }
            $type     = (string) $term->taxonomy;
            $original = $bridge->original_term_id( $id, $type );
            $tool     = 'translate-term';
        } else {
            $type = (string) get_post_type( $id );
            if ( $type === '' ) {
                return $input;
            }
            $original = Translation::original_of( $id, $type );
            $tool     = 'translate-content';
        }
        if ( $original !== $id ) {
            return new WP_Error(
                'not_the_original',
                sprintf( '%s %d is a language version: `language` goes with the original (%s %d). This version itself is %s %d without `language`.', $key, $id, $key, $original, $key, $id )
            );
        }
        $version = $bridge->version_in( $id, $type, $lang );
        if ( $version <= 0 ) {
            return new WP_Error(
                'fabrement_language_missing',
                sprintf( '%s %d has no "%s" version yet. Make it with %s {%s: %d, language: "%s"}, then call again.', $key, $id, $lang, $tool, $key, $id, $lang )
            );
        }
        unset( $input['language'] );
        $input[ $key ] = $version;
        $input['from'] = $id;
        return $input;
    }
}
