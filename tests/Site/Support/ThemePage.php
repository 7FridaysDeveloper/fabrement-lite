<?php

namespace Fabrement\Tests\Site\Support;

/**
 * Which record and language a page shows, as a site's own theme code would print it (a must-use
 * plugin standing for functions.php): at the foot of every page, as JSON in one script tag, so a
 * test reads it back exactly (ThemePage::read()), not by scraping markup.
 */
final class ThemePage {

    private const FILE = '/var/www/html/wp-content/mu-plugins/qa-theme-page.php';

    public static function install( Site $site ): void {
        $site->write( self::FILE, <<<PHP
<?php
// The theme: each page prints its language and the viewed record.
add_action( 'wp_footer', static function (): void {
    \$out = [ 'lang' => (string) apply_filters( 'wpml_current_language', null ) ];
    if ( is_singular() ) {
        \$id           = (int) get_queried_object_id();
        \$out['id']    = \$id;
        \$out['title'] = get_the_title( \$id );
    }
    echo '<script type="application/json" id="qa-theme-page">' . wp_json_encode( \$out ) . '</script>';
} );

PHP );
    }

    /**
     * What the theme printed on a page: lang, id, title.
     *
     * @return array<string,mixed>|null null when the page carries none (not a page of the site).
     */
    public static function read( string $html ): ?array {
        if ( ! preg_match( '#<script type="application/json" id="qa-theme-page">(.*?)</script>#s', $html, $m ) ) {
            return null;
        }
        $data = json_decode( $m[1], true );
        return is_array( $data ) ? $data : null;
    }
}
