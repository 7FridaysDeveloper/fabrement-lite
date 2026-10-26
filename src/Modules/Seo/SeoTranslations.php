<?php

namespace Fabrement\Modules\Seo;

/**
 * Whether a language version's search texts are translated — one rule for get-seo and list-seo.
 *
 * A version's title and description are its own texts, written in its language (a translation
 * plugin creates a version with them empty, or — through its own translation editor — with what
 * the translator left there). Compared with the original's stored texts, field by field:
 *
 *   translated      the version has its own text, different from the original's
 *   sameAsOriginal  the version holds the original's text word for word — nobody translated it
 *   missing         the original has its own text, the version has none (it falls back to the
 *                   type's template, in its language)
 *   template        neither has its own text: both follow the template, and there is nothing to do
 *
 * Only the two fields a search result shows; social texts fall back to them.
 */
final class SeoTranslations {

    public const FIELDS = [ 'title', 'metaDesc' ];

    private const TODO = [ 'sameAsOriginal', 'missing' ];

    /**
     * @param array<string,mixed> $original The original's stored fields (SeoBridge::read_post / readTerm).
     * @param array<string,mixed> $version  The version's.
     * @return array<string,string> field → status
     */
    public static function status( array $original, array $version ): array {
        $out = [];
        foreach ( self::FIELDS as $field ) {
            $own   = trim( (string) ( $original[ $field ] ?? '' ) );
            $their = trim( (string) ( $version[ $field ] ?? '' ) );
            if ( $their === '' ) {
                $out[ $field ] = $own === '' ? 'template' : 'missing';
            } else {
                $out[ $field ] = $their === $own ? 'sameAsOriginal' : 'translated';
            }
        }
        return $out;
    }

    /** @param array<string,string> $status What {@see status()} returned. */
    public static function untranslated( array $status ): bool {
        return array_intersect( $status, self::TODO ) !== [];
    }
}
