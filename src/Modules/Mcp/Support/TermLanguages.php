<?php

namespace Fabrement\Modules\Mcp\Support;

use Fabrement\Modules\Translation\Translation;

/**
 * A term's place among its language versions — what list-terms reports per row, for the SEO tools.
 */
final class TermLanguages {

    /**
     * The term's language, the original of its group and every version, as code → term id. Null on
     * a one-language site and for a term of an untranslated taxonomy (one set for every language).
     *
     * @return array{language:string, originalId:int, translations:array<string,int>}|null
     */
    public static function of( \WP_Term $term ): ?array {
        if ( ! Translation::is_multilingual() ) {
            return null;
        }
        $bridge   = Translation::bridge();
        $taxonomy = (string) $term->taxonomy;
        $language = $bridge->term_language( (int) $term->term_id, $taxonomy );
        if ( $language === '' ) {
            return null;
        }
        $original = $bridge->original_term_id( (int) $term->term_id, $taxonomy );

        return [ 'language' => $language, 'originalId' => $original, 'translations' => Translation::term_translations( $original, $taxonomy ) ];
    }
}
