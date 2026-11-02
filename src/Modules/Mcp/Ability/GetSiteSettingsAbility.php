<?php

namespace Fabrement\Modules\Mcp\Ability;

defined( 'ABSPATH' ) || exit;

use Fabrement\Modules\Translation\Translation;

/**
 * Read-only: the site settings a build needs. An administrator changes them in wp-admin.
 */
final class GetSiteSettingsAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/get-site-settings';
    }

    public function label(): string {
        return 'Get site settings';
    }

    public function description(): string {
        return 'The site settings a build needs, as they are now — siteTitle, tagline, showOnFront, frontPage '
            . '(the HOME page id), postsPage, notFoundPage, permalinkStructure, homeUrl, siteIcon. Read-only: an '
            . 'administrator changes them in wp-admin.';
    }

    public function contract(): ?string {
        return 'site-settings-contract';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'settings' ],
            'properties' => [
                'settings'     => [ 'type' => 'object' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        return [ 'settings' => self::current() ];
    }

    /**
     * The site settings as stored now.
     *
     * @return array<string,int|string>
     */
    public static function current(): array {
        $front     = self::stored_page_option( 'page_on_front' );
        $posts     = self::stored_page_option( 'page_for_posts' );
        $not_found = \Fabrement\Modules\NotFound\NotFoundPage::id();

        return [
            'siteTitle'          => (string) get_option( 'blogname' ),
            'tagline'            => (string) get_option( 'blogdescription' ),
            'showOnFront'        => (string) get_option( 'show_on_front' ),
            'frontPage'          => $front,
            'postsPage'          => $posts,
            'notFoundPage'       => $not_found,
            'permalinkStructure' => (string) get_option( 'permalink_structure' ),
            'homeUrl'            => (string) home_url( '/' ),
            'siteIcon'           => (int) get_option( 'site_icon' ),
            'siteIconUrl'        => (string) get_site_icon_url( 512 ),
        ] + self::page_translations( [ 'frontPage' => $front, 'postsPage' => $posts, 'notFoundPage' => $not_found ] );
    }

    /**
     * `page_on_front` / `page_for_posts` as STORED, past WPML's per-language rewrite of them.
     *
     * WPML filters both (`pre_option_page_on_front`, `pre_option_page_for_posts`) to the current
     * language's version, and in wp-admin "current language" is the switcher's, not the language
     * of anything being worked on. Read that way, a setting answers with a different number
     * depending on a cookie — while WordPress stores the original. One field cannot mean two
     * things, so it is reported as stored and the per-language view is
     * reported separately by {@see page_translations}.
     *
     * Read from the row rather than by unhooking the filter: WPML also caches its answer for the
     * whole request.
     */
    private static function stored_page_option( string $option ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the option as stored: get_option() answers with WPML's per-language rewrite and its request cache (see above).
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
        );
    }

    /**
     * `<field>Translations`: which page serves each of these settings in each language.
     *
     * ONLY ON A MULTILINGUAL SITE, and absent everywhere else — on a site with one language the
     * map only ever repeats the id beside it. Where there are languages it is the thing the bare
     * id cannot say: WordPress names one page per setting, WPML serves a different version of it
     * per language, and the Reading screen shows that as a separate picker per language. Reported
     * rather than folded into the id, because a number that changes with the reader is a number
     * an agent cannot act on.
     *
     * One query for all three: version_facts() answers translations AND statuses for a set of
     * ids at once. Its listing drops trashed versions, which is right here — a version in the
     * trash is not one this language can be served from.
     *
     * @param array<string,int> $pages field name => stored page id
     * @return array<string,array<string,int>>
     */
    private static function page_translations( array $pages ): array {
        $bridge = Translation::bridge();
        if ( count( $bridge->languages() ) < 2 ) {
            return [];
        }

        $ids = array_values( array_filter( $pages, static fn( int $id ): bool => $id > 0 ) );
        if ( $ids === [] ) {
            return [];
        }

        $facts = $bridge->version_facts( $ids, 'page' );
        $out   = [];
        foreach ( $pages as $field => $id ) {
            $versions = $facts[ $id ]['translations'] ?? [];
            if ( $id > 0 && $versions !== [] ) {
                $out[ $field . 'Translations' ] = array_map( 'intval', $versions );
            }
        }

        return $out;
    }
}
