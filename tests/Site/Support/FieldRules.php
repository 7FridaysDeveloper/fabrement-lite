<?php

namespace Fabrement\Tests\Site\Support;

/**
 * Small readers shared by the field-rule tests (keys across types, words, save dates): what a
 * visitor's page says in its <title>, and whether a field file is on the site's disk.
 */
final class FieldRules {

    /** The <title> of a page as a reader sees it: entities decoded, trimmed ('' when there is none). */
    public static function title( string $html ): string {
        preg_match( '#<title[^>]*>(.*?)</title>#s', $html, $m );
        return trim( html_entity_decode( $m[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }

    /** The <title> exactly as the HTML carries it, entities and all. */
    public static function raw_title( string $html ): string {
        preg_match( '#<title[^>]*>(.*?)</title>#s', $html, $m );
        return $m[1] ?? '';
    }

    /** Whether uploads/fabrement-lite/fields/<type>.<slug>.json exists on the site. */
    public static function field_file_exists( Site $site, string $type, string $slug ): bool {
        return $site->php( 'echo (int) is_file( wp_upload_dir()["basedir"] . "/fabrement-lite/fields/' . $type . '.' . $slug . '.json" );' ) === '1';
    }
}
