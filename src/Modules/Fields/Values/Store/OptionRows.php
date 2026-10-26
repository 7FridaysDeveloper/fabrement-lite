<?php

namespace Fabrement\Modules\Fields\Values\Store;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;

/**
 * The wp_options rows of options pages: their names and their autoload flag.
 *
 *   fabrement_options_<slug>          the page in the site's default language (the original);
 *   fabrement_options_<slug>.<lang>   that language's stored values, seeded on creation.
 *
 * A dot, which no slug holds, so a page called `site_uk` never meets `site` in Ukrainian.
 * When the site's default language changes, OptionVersions::default_changed() swaps the rows so every
 * language keeps its own values.
 */
final class OptionRows {

    /** The page slug a row name belongs to; '' for a name that is not one of ours. */
    public static function slug_of( string $row ): string {
        if ( strpos( $row, Keys::OPTIONS_PAGE_PREFIX ) !== 0 ) {
            return '';
        }
        return explode( '.', substr( $row, strlen( Keys::OPTIONS_PAGE_PREFIX ) ), 2 )[0];
    }

    /** Whether a page's rows load with every request (its `page.autoload`, on unless turned off). */
    public static function autoloads( string $slug ): bool {
        $owner = Owner::of( 'options', $slug );
        $document = $owner ? SchemaRepository::get( $owner ) : null;
        return (bool) ( $document['page']['autoload'] ?? true );
    }

    /** @return string[] Every row of a page: the original's and each language's. */
    public static function all( string $slug ): array {
        global $wpdb;
        $base = Keys::options_page( $slug );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- listing a page's rows by name; get_option() cannot.
        return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s", $base, $wpdb->esc_like( $base . '.' ) . '%' ) );
    }

    /** Apply a page's autoload setting to the rows it already has. */
    public static function set_autoload( string $slug, bool $autoload ): void {
        $rows = self::all( $slug );
        if ( $rows ) {
            wp_set_option_autoload_values( array_fill_keys( $rows, $autoload ) );
        }
    }
}
