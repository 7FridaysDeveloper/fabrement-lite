<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * The few non-sensitive site settings an agent may change: title, tagline, site icon and the
 * not-found page. The home page, the posts page and the permalink structure decide the site's
 * addresses and stay with the administrator in Settings.
 *
 * A closed allow-list, not a generic option writer. `update_option` with an arbitrary key is a
 * remote-configuration tool: it can rewrite `siteurl` and lock everyone out, change
 * `users_can_register`, or disable updates — none of which any caller here needs.
 */
final class UpdateSiteSettingsAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/update-site-settings';
    }

    public function label(): string {
        return 'Update site settings';
    }

    public function description(): string {
        return 'Site settings: siteTitle, tagline, notFoundPage (the page shown for missing addresses), siteIcon '
            . '(favicon attachment id). Send only what changes; to read them, use get-site-settings. The home page, '
            . 'the posts page and permalinks are not settable here: the administrator sets them in Settings.'
            . self::multilingual_text(
                ' WITH LANGUAGES: notFoundPage names ONE page and its id — sent and reported — is its original; '
                . 'a translated id you send is resolved to that. Which page serves each language is reported as '
                . 'notFoundPageTranslations, and a language with no published version is listed in warnings.'
            );
    }

    public function contract(): ?string {
        return 'site-settings-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'siteTitle'          => [ 'type' => 'string', 'maxLength' => 200 ],
                'tagline'            => [ 'type' => 'string', 'maxLength' => 300, 'description' => 'The "blogdescription" line under the title.' ],
                'notFoundPage'       => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Published page id shown for every missing address (404 status kept); 0 = the theme\'s own 404.' ],
                'siteIcon'           => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Attachment id of the favicon (upload-media first). Square PNG, 512×512 or larger. 0 removes it.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'settings' ],
            'properties' => [
                'settings' => [ 'type' => 'object' ],
                'changed'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Accepted, but worth telling the user about.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'Changing site settings needs the manage_options capability.' );
        }

        $changed  = [];
        $warnings = [];

        if ( array_key_exists( 'siteTitle', $input ) ) {
            $value = sanitize_text_field( (string) $input['siteTitle'] );
            if ( trim( $value ) === '' ) {
                return new WP_Error( 'empty_title', 'The site title cannot be empty.' );
            }
            update_option( 'blogname', $value );
            $changed[] = 'siteTitle';
        }

        if ( array_key_exists( 'tagline', $input ) ) {
            update_option( 'blogdescription', sanitize_text_field( (string) $input['tagline'] ) );
            $changed[] = 'tagline';
        }

        // The not-found page must not be the home or posts page, compared as stored originals
        // (stored_page_option(): WPML answers get_option() with the admin's current language).
        $effective_not_found = array_key_exists( 'notFoundPage', $input )
            ? \Fabrement\Modules\Translation\Translation::original_of( (int) $input['notFoundPage'] )
            : 0;
        if ( $effective_not_found > 0 && in_array( $effective_not_found, [ self::stored_page_option( 'page_on_front' ), self::stored_page_option( 'page_for_posts' ) ], true ) ) {
            return new WP_Error( 'same_page', 'The not-found (404) page cannot also be the front page or the posts page.' );
        }

        if ( array_key_exists( 'notFoundPage', $input ) ) {
            $not_found = (int) $input['notFoundPage'];
            if ( $not_found === 0 ) {
                delete_option( Keys::OPTION_NOT_FOUND_PAGE );
            } else {
                $error = \Fabrement\Modules\NotFound\NotFoundPage::invalid_choice( $not_found );
                if ( $error !== null ) {
                    return new WP_Error( 'bad_not_found_page', $error . ' See list-content {postType:"page"}.' );
                }
                // A language version's id is stored as its original's, so the setting names one page.
                update_option( Keys::OPTION_NOT_FOUND_PAGE, \Fabrement\Modules\Translation\Translation::original_of( $not_found ) );
            }
            $changed[] = 'notFoundPage';
        }

        if ( array_key_exists( 'siteIcon', $input ) ) {
            $icon = (int) $input['siteIcon'];
            if ( $icon === 0 ) {
                delete_option( 'site_icon' );
            } else {
                $attachment = get_post( $icon );
                if ( ! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment' ) {
                    return new WP_Error( 'not_media', sprintf( 'siteIcon %d is not a media item — upload it with upload-media first and pass the id it returns.', $icon ) );
                }
                if ( strpos( (string) $attachment->post_mime_type, 'image/' ) !== 0 ) {
                    return new WP_Error( 'not_image', sprintf( 'siteIcon %d is a %s, not an image.', $icon, (string) $attachment->post_mime_type ) );
                }

                // WordPress generates every tile and touch-icon size by CROPPING this file, so a
                // small one is upscaled into a blurry favicon on exactly the devices with the
                // sharpest screens. Under 64px there is nothing to crop at all.
                $meta   = wp_get_attachment_metadata( $icon );
                $width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
                $height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
                if ( $width > 0 && $height > 0 ) {
                    if ( min( $width, $height ) < 64 ) {
                        return new WP_Error(
                            'icon_too_small',
                            sprintf( 'That image is %d×%d — a site icon needs at least 512×512 (64 is the absolute floor). Upload a larger one.', $width, $height )
                        );
                    }
                    if ( min( $width, $height ) < 512 ) {
                        $warnings[] = sprintf( 'The site icon is %d×%d; WordPress crops tiles up to 512×512, so it will look soft on high-density screens.', $width, $height );
                    }
                    if ( $width !== $height ) {
                        $warnings[] = sprintf( 'The site icon is %d×%d, not square — WordPress crops it to a square from the centre.', $width, $height );
                    }
                }

                update_option( 'site_icon', $icon );
            }
            $changed[] = 'siteIcon';
        }

        // current() re-reads from the row, so what comes back is what is STORED — including when a
        // write did not land. Nothing is overlaid from the request: a report built out of what the
        // caller asked for cannot tell it that the asking failed.
        $settings = self::current();

        return [
            'settings' => $settings,
            'changed'  => $changed,
            'warnings' => array_merge( $warnings, self::unpublished_version_warnings( $settings ) ),
        ];
    }

    /**
     * One line per language that cannot actually serve one of these settings.
     *
     * A site page is named once and served per language, so a language whose version is missing
     * or unpublished quietly shows nothing where the home, the blog or the 404 should be — the
     * failure we chased for an afternoon: a draft Polish posts page, and a blog that printed none
     * of its own words in Polish with no error anywhere. WPML says as much on its own Reading
     * screen; there is no reason for this tool to stay quiet about it.
     *
     * @param array<string,int|string|array<string,int>> $settings
     * @return list<string>
     */
    private static function unpublished_version_warnings( array $settings ): array {
        $bridge = Translation::bridge();
        $codes  = array_column( $bridge->languages(), 'code' );
        if ( count( $codes ) < 2 ) {
            return [];
        }

        $out = [];
        foreach ( [ 'frontPage' => 'home page', 'postsPage' => 'posts page', 'notFoundPage' => 'not-found page' ] as $field => $label ) {
            $id = (int) ( $settings[ $field ] ?? 0 );
            if ( $id <= 0 ) {
                continue;
            }
            // No map at all means WPML does not track this page (an untranslatable type, or a
            // record from before the type became translatable) — not "no language has it".
            $versions = $settings[ $field . 'Translations' ] ?? [];
            if ( ! is_array( $versions ) || $versions === [] ) {
                continue;
            }
            foreach ( $codes as $code ) {
                $version = (int) ( $versions[ $code ] ?? 0 );
                if ( $version > 0 && get_post_status( $version ) === 'publish' ) {
                    continue;
                }
                $out[] = sprintf(
                    'The %1$s has no published %2$s version — that language shows none of its content. %3$s',
                    $label,
                    $code,
                    $version > 0
                        ? sprintf( 'Page %d is a %s; publish it.', $version, (string) get_post_status( $version ) )
                        : 'Create it with translate-content.'
                );
            }
        }

        return $out;
    }

    /**
     * The site settings as stored now (more than this tool changes). Shared with get-site-settings
     * so the two can never describe the site differently.
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
     * whole request, so a re-read after update_option() returns the value that was just replaced.
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
