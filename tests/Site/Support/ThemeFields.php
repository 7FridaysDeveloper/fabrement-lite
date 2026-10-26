<?php

namespace Fabrement\Tests\Site\Support;

/**
 * What a theme does with Fabrement fields, as a site's own theme code would (a must-use plugin
 * standing for functions.php): at the foot of every page it prints the fields of the record being
 * viewed and of the options pages it uses, read with the public PHP API in the language WPML took
 * from the address. A visitor's page then shows what a template would show — each language its own.
 *
 * The values are printed as JSON in one script tag, so a test reads them back exactly
 * (ThemeFields::read()), not by scraping markup.
 */
final class ThemeFields {

    private const FILE = '/var/www/html/wp-content/mu-plugins/qa-theme-fields.php';

    /** @param string[] $options_pages The options pages the theme reads on every page. */
    public static function install( Site $site, array $options_pages = [] ): void {
        $pages = var_export( array_values( $options_pages ), true );
        $site->write( self::FILE, <<<PHP
<?php
// The theme: each page prints what the templates read — the viewed record's fields and the options pages.
add_action( 'wp_footer', static function (): void {
    \$out = [ 'lang' => (string) apply_filters( 'wpml_current_language', null ) ];
    if ( is_singular() ) {
        \$id            = (int) get_queried_object_id();
        \$out['id']     = \$id;
        \$out['title']  = get_the_title( \$id );
        \$out['fields'] = fabrement_get_fields( \$id );
    }
    \$out['options'] = [];
    foreach ( $pages as \$page ) {
        \$out['options'][ \$page ] = fabrement_get_options( \$page );
    }
    echo '<script type="application/json" id="qa-theme-fields">' . wp_json_encode( \$out ) . '</script>';
} );

PHP );
    }

    /**
     * What the theme printed on a page: lang, id, title, fields (by name), options (by page).
     *
     * @return array<string,mixed>|null null when the page carries none (not a page of the site).
     */
    public static function read( string $html ): ?array {
        if ( ! preg_match( '#<script type="application/json" id="qa-theme-fields">(.*?)</script>#s', $html, $m ) ) {
            return null;
        }
        $data = json_decode( $m[1], true );
        return is_array( $data ) ? $data : null;
    }
}
