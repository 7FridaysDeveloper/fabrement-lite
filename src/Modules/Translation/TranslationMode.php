<?php

namespace Fabrement\Modules\Translation;

/**
 * How the translation plugin treats a post type's records or a taxonomy's terms, as list answers
 * report it on a site with languages:
 *
 *   "none"      no language on them: one set shared by every language;
 *   "translate" each has a language; a language shows only what exists in it;
 *   "fallback"  each has a language; a language with no version shows the original.
 *
 * Nothing is stored on our side: the plugin holds the mode, and every read asks it.
 */
final class TranslationMode {

    /** What list and summary answers report: the plugin's mode today, or null (one language / no answer). */
    public static function of( string $kind, string $name ): ?string {
        return Translation::is_multilingual() ? Translation::bridge()->translation_mode( $kind, $name ) : null;
    }
}
