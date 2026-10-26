<?php

namespace Fabrement\Modules\Seo\Bridge\Yoast;

use Fabrement\Modules\Translation\Translation;

/**
 * Yoast's settings: the `wpseo_titles` / `wpseo_social` / `wpseo` options — templates and flags
 * per post type and taxonomy, the site and its organisation, the post-type archives — and their
 * versions per language. Part of
 * {@see \Fabrement\Modules\Seo\Bridge\YoastBridge}; nothing else calls it.
 *
 * @internal
 */
final class Options {

    /**
     * Agent field → option key pattern. `%s` is the post type / taxonomy slug.
     *
     * @var array<string, array<string, string>>
     */
    private const TYPE_KEYS = [
        'postType' => [
            'titleTemplate'      => 'title-%s',
            'metaDescTemplate'   => 'metadesc-%s',
            'noindex'            => 'noindex-%s',
            'schemaPageType'     => 'schema-page-type-%s',
            'schemaArticleType'  => 'schema-article-type-%s',
            'socialTitle'        => 'social-title-%s',
            'socialDescription'  => 'social-description-%s',
            'socialImageUrl'     => 'social-image-url-%s',
        ],
        'taxonomy' => [
            'titleTemplate'    => 'title-tax-%s',
            'metaDescTemplate' => 'metadesc-tax-%s',
            'noindex'          => 'noindex-tax-%s',
        ],
    ];

    /**
     * Site-wide keys (no slug). Organisation = the knowledge-graph node every page's schema hangs off.
     *
     * @var array<string, string>
     */
    private const SITE_KEYS = [
        'separator'        => 'separator',
        'websiteName'      => 'website_name',
        'alternateName'    => 'alternate_website_name',
        'companyOrPerson'  => 'company_or_person',
        'companyName'      => 'company_name',
        'companyLogoId'    => 'company_logo_id',
        'companyLogo'      => 'company_logo',
        'personUserId'     => 'company_or_person_user_id',
        'homeTitleTemplate'    => 'title-home-wpseo',
        'homeMetaDescTemplate' => 'metadesc-home-wpseo',
        'breadcrumbsEnabled'   => 'breadcrumbs-enable',
        'breadcrumbsHome'      => 'breadcrumbs-home',
        // `wpseo_social` — the Organization's sameAs profiles and the fallback share image. Keys
        // are unique across Yoast's options, so WPSEO_Options finds the right one by name.
        'facebookUrl'          => 'facebook_site',
        'twitterUsername'      => 'twitter_site',
        'socialProfiles'       => 'other_social_urls',
        'defaultOgImageId'     => 'og_default_image_id',
        'defaultOgImage'       => 'og_default_image',
    ];

    /**
     * The fields a translation plugin can hold per language: text a visitor reads. Everything
     * else in the option — flags, ids, the separator, schema types — is one value for the site.
     */
    private const TRANSLATABLE = [
        'titleTemplate', 'metaDescTemplate', 'socialTitle', 'socialDescription',
        'websiteName', 'alternateName', 'companyName',
        'homeTitleTemplate', 'homeMetaDescTemplate', 'breadcrumbsHome',
    ];

    /** The `wpseo_*` option every key lives in — the string context the translation plugin files it under. */
    private const TITLES_OPTION = 'wpseo_titles';

    /**
     * Yoast's `*-ptarchive-*` options: what it prints on a post-type archive. One value for the
     * whole site.
     */
    private const ARCHIVE_KEYS = [
        'title'    => 'title-ptarchive-%s',
        'metaDesc' => 'metadesc-ptarchive-%s',
        'noindex'  => 'noindex-ptarchive-%s',
    ];

    /** Image ids Yoast keeps with the address it prints beside them. */
    private const IMAGE_URLS = [ 'companyLogoId' => 'companyLogo', 'defaultOgImageId' => 'defaultOgImage' ];

    /**
     * Yoast's copy of the company logo's sizes, filled the first time it prints the Organization and
     * printed from then on; dropped with every logo change, as Yoast's own setup does, so it is rebuilt.
     */
    private const LOGO_CACHE = 'company_logo_meta';

    /** Fields Yoast keeps as plain text: tags, a lone "<" and "%" with two hex digits do not survive. */
    private const TEXTS = [
        'titleTemplate', 'metaDescTemplate', 'socialTitle', 'socialDescription', 'websiteName', 'alternateName',
        'companyName', 'homeTitleTemplate', 'homeMetaDescTemplate', 'title', 'metaDesc',
    ];

    /** Ids cleared with 0: on '' Yoast keeps the old company_logo_id (its company_logo check drops the default id) and resets the user id to false. */
    private const ZERO_IDS = [ 'companyLogoId', 'personUserId' ];

    /** Yoast's option arrays our keys live in; key names are unique across them. */
    private const OPTION_NAMES = [ 'wpseo_titles', 'wpseo_social', 'wpseo' ];

    /** @return list<string> Which agent fields exist for a scope. */
    public static function fields( string $scope ): array {
        return array_values( array_diff( array_keys( $scope === 'site' ? self::SITE_KEYS : ( self::TYPE_KEYS[ $scope ] ?? [] ) ), self::IMAGE_URLS ) );
    }

    /** @return list<string> */
    public static function separators(): array {
        if ( ! class_exists( '\WPSEO_Option_Titles' ) ) {
            return [];
        }
        return array_map( 'strval', array_keys( \WPSEO_Option_Titles::get_instance()->get_separator_options() ) );
    }

    /** @return list<string> Of those, the text that is kept per language. */
    public static function translatable( string $scope ): array {
        return array_values( array_intersect( self::fields( $scope ), self::TRANSLATABLE ) );
    }

    /** Yoast's "taxonomy to show in breadcrumbs" of a post type (`post_types-<type>-maintax`); '' for none. */
    public static function breadcrumb_taxonomy( string $post_type ): string {
        $taxonomy = self::get( 'post_types-' . $post_type . '-maintax' );
        return is_string( $taxonomy ) && $taxonomy !== '0' ? $taxonomy : '';
    }

    /** @return array<string, mixed> the stored defaults for a scope, agent field names. */
    public static function read( string $scope, string $slug ): array {
        $out = [];
        foreach ( self::map( $scope, $slug ) as $field => $key ) {
            if ( in_array( $field, self::IMAGE_URLS, true ) ) {
                continue;
            }
            $value = self::get( $key );
            if ( $value === null || $value === '' ) {
                continue;
            }
            // Unset ids come back as false/0 from Yoast — not a value worth listing.
            if ( ( $value === false || $value === 0 ) && in_array( $field, self::ZERO_IDS, true ) ) {
                continue;
            }
            $out[ $field ] = $value;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<string> written field names
     */
    public static function write( string $scope, string $slug, array $fields ): array {
        $map     = self::map( $scope, $slug );
        $written = [];
        foreach ( self::stored( $fields ) as $field => $value ) {
            if ( ! isset( $map[ $field ] ) ) {
                continue;
            }
            self::set( $map[ $field ], $value );
            if ( ! in_array( $field, self::IMAGE_URLS, true ) ) {
                $written[] = $field;
            }
        }
        if ( in_array( 'companyLogoId', $written, true ) ) {
            self::set( self::LOGO_CACHE, false );
        }
        return $written;
    }

    /**
     * The fields Yoast would not keep as sent, each with what it would store instead ('' for an
     * address it would refuse — and then keep the old one): its own text and address checks, run
     * without writing. A list reports its first such item as `field[i]`.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    public static function not_kept( array $fields ): array {
        $out = [];
        foreach ( $fields as $field => $value ) {
            if ( in_array( $field, self::TEXTS, true ) && is_string( $value ) && trim( $value ) !== '' && class_exists( '\WPSEO_Utils' ) ) {
                $kept = (string) \WPSEO_Utils::sanitize_text_field( $value );
                if ( ! Kept::same( $value, $kept ) ) {
                    $out[ $field ] = $kept;
                }
            }
            if ( $field === 'facebookUrl' && is_string( $value ) && $value !== '' ) {
                $kept = self::social_url( $value );
                if ( $kept === null || ! Kept::same( $value, $kept, true ) ) {
                    $out[ $field ] = (string) $kept;
                }
            }
            if ( $field === 'socialProfiles' && is_array( $value ) ) {
                foreach ( array_values( $value ) as $i => $url ) {
                    $kept = self::social_url( (string) $url );
                    if ( $kept === null || ! Kept::same( (string) $url, $kept, true ) ) {
                        $out[ $field . '[' . $i . ']' ] = (string) $kept;
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /** A profile address as Yoast keeps it; null when Yoast would refuse it. */
    private static function social_url( string $url ): ?string {
        if ( ! class_exists( '\WPSEO_Option_Social' ) ) {
            return $url;
        }
        $kept = \WPSEO_Option_Social::get_instance()->validate_social_url( $url );
        return is_string( $kept ) ? $kept : null;
    }

    /**
     * Agent values as Yoast keeps them: null clears ("" is Yoast's built-in default, [] the profile
     * list, 0 an id of the titles option), and an image id brings its address. Switches arrive as
     * true or false only.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function stored( array $fields ): array {
        foreach ( $fields as $field => $value ) {
            if ( $value === null ) {
                $fields[ $field ] = $field === 'socialProfiles' ? [] : ( in_array( $field, self::ZERO_IDS, true ) ? 0 : '' );
            }
        }
        return ImageUrls::fill( $fields, self::IMAGE_URLS, [ 'companyLogoId' => 0, 'defaultOgImageId' => '' ] );
    }

    /**
     * The site's Organization or Person node as Yoast decides it (Meta_Tags_Context): a company
     * without a name takes the site's (website name, else the site title), without a logo the
     * site logo, else the theme's; it is printed only with both. A person needs an existing user.
     *
     * `borrowed` says where each value Yoast takes instead comes from: the name from its own
     * "Website name" (websiteName) or the site title (siteTitle), the logo from the site logo
     * (siteLogo — WordPress keeps the theme's custom logo in step with it), either from a site
     * filter (filter).
     *
     * @return array{node:string, missing:list<string>, borrowed:array<string,string>}|null
     */
    public static function knowledge_graph(): ?array {
        $site = self::read( 'site', '' );
        switch ( $site['companyOrPerson'] ?? '' ) {
            case 'company':
                $missing  = [];
                $borrowed = [];
                $own_name = (string) ( $site['companyName'] ?? '' );
                $name     = apply_filters( 'wpseo_schema_company_name', $own_name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast's own filter, read so the answer is what Yoast prints.
                if ( empty( $name ) ) {
                    if ( ! empty( $site['websiteName'] ) ) {
                        $borrowed['companyName'] = 'websiteName';
                    } elseif ( ! empty( get_bloginfo( 'name' ) ) ) {
                        $borrowed['companyName'] = 'siteTitle';
                    } else {
                        $missing[] = 'companyName';
                    }
                } elseif ( $own_name === '' ) {
                    $borrowed['companyName'] = 'filter';
                }
                $logo     = (int) ( $site['companyLogoId'] ?? 0 );
                $fallback = $logo > 0 ? 0 : (int) ( get_option( 'site_logo' ) ?: get_theme_mod( 'custom_logo', 0 ) );
                $shown    = apply_filters( 'wpseo_schema_company_logo_id', $logo > 0 ? $logo : $fallback ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast's own filter, read so the answer is what Yoast prints.
                if ( ! is_numeric( $shown ) || (int) $shown < 1 ) {
                    $missing[] = 'companyLogoId';
                } elseif ( $logo < 1 ) {
                    $borrowed['companyLogoId'] = (int) $shown === $fallback ? 'siteLogo' : 'filter';
                }
                return $missing === [] && $borrowed === [] ? null : [ 'node' => 'Organization', 'missing' => $missing, 'borrowed' => $borrowed ];
            case 'person':
                $user = (int) ( $site['personUserId'] ?? 0 );
                return $user > 0 && get_userdata( $user ) ? null : [ 'node' => 'Person', 'missing' => [ 'personUserId' ], 'borrowed' => [] ];
        }
        return null;
    }

    /**
     * Store a language's version of text defaults. The plugin translates the value the option
     * holds NOW, so an original that is still empty has nothing to translate — the caller says so.
     *
     * @param array<string,string> $fields agent field → translated text
     * @return array{written:list<string>, emptyOriginal:list<string>}
     */
    public static function write_translation( string $scope, string $slug, string $lang, array $fields ): array {
        $map     = self::map( $scope, $slug );
        $bridge  = Translation::bridge();
        $written = [];
        $empty   = [];
        foreach ( $fields as $field => $value ) {
            if ( ! isset( $map[ $field ] ) || ! in_array( $field, self::TRANSLATABLE, true ) ) {
                continue;
            }
            $stored   = self::stored_titles();
            $in_row   = array_key_exists( $map[ $field ], $stored );
            $original = $in_row ? $stored[ $map[ $field ] ] : self::get( $map[ $field ] );
            $original = is_scalar( $original ) ? (string) $original : '';
            if ( $original === '' ) {
                $empty[] = $field;
                continue;
            }
            if ( ! $in_row ) {
                self::set( $map[ $field ], $original );
            }
            if ( $bridge->translate_option( self::TITLES_OPTION, $map[ $field ], $original, $lang, (string) $value ) ) {
                $written[] = $field;
            }
        }
        return [ 'written' => $written, 'emptyOriginal' => $empty ];
    }

    /**
     * The titles option as the database holds it — not through WPML, which hands a translation to a
     * request in another language, nor through Yoast, which adds its defaults for keys never saved.
     * WPML translates only the keys of this row, so a translation needs its key here.
     *
     * @return array<string, mixed>
     */
    private static function stored_titles(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the unfiltered row; get_option() is filtered by WPML and Yoast.
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::TITLES_OPTION ) );
        $row = is_string( $raw ) ? maybe_unserialize( $raw ) : [];
        return is_array( $row ) ? $row : [];
    }

    /**
     * @return array<string,array<string,string>> language → { agent field → translated text }, filled ones only.
     */
    public static function translations( string $scope, string $slug ): array {
        $bridge = Translation::bridge();
        $out    = [];
        foreach ( self::map( $scope, $slug ) as $field => $key ) {
            if ( ! in_array( $field, self::TRANSLATABLE, true ) ) {
                continue;
            }
            $original = self::get( $key );
            $original = is_scalar( $original ) ? (string) $original : '';
            if ( $original === '' ) {
                continue;
            }
            foreach ( $bridge->option_translations( self::TITLES_OPTION, $key, $original ) as $code => $text ) {
                $out[ $code ][ $field ] = $text;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> title / metaDesc / noindex of a type's archive. */
    public static function read_archive( string $post_type ): array {
        $out = [];
        foreach ( self::ARCHIVE_KEYS as $field => $pattern ) {
            $value = self::get( sprintf( $pattern, $post_type ) );
            if ( $value !== null && $value !== '' ) {
                $out[ $field ] = $field === 'noindex' ? (bool) $value : $value;
            }
        }
        return $out;
    }

    /** @return list<string> written field names */
    public static function write_archive( string $post_type, array $fields ): array {
        $written = [];
        foreach ( $fields as $field => $value ) {
            if ( ! isset( self::ARCHIVE_KEYS[ $field ] ) ) {
                continue;
            }
            // Null clears: "" is Yoast's "use my built-in template", false its default for the flag.
            $stored = $field === 'noindex' ? (bool) $value : ( $value === null ? '' : trim( (string) $value ) );
            self::set( sprintf( self::ARCHIVE_KEYS[ $field ], $post_type ), $stored );
            $written[] = $field;
        }
        return $written;
    }

    /**
     * A record's or a term's stored fields against its type's templates, as Yoast falls back: its
     * own value, else the template, else nothing; its own robots setting, else the type's.
     *
     * @param array<string,mixed> $stored
     * @param string              $scope  'postType' or 'taxonomy'
     * @return array{title:string, metaDesc:string, noindex:bool, canonical:string}
     */
    public static function summary( array $stored, string $scope, string $slug ): array {
        $map    = self::map( $scope, $slug );
        $source = static function ( string $field, string $template ) use ( $stored, $map ): string {
            if ( trim( (string) ( $stored[ $field ] ?? '' ) ) !== '' ) {
                return 'own';
            }
            return trim( (string) self::get( $map[ $template ] ) ) !== '' ? 'template' : 'none';
        };
        $noindex = $stored['noindex'] ?? null;

        return [
            'title'     => $source( 'title', 'titleTemplate' ),
            'metaDesc'  => $source( 'metaDesc', 'metaDescTemplate' ),
            'noindex'   => $noindex === null ? (bool) self::get( $map['noindex'] ) : (bool) $noindex,
            'canonical' => (string) ( $stored['canonical'] ?? '' ),
        ];
    }

    /** @return array<string, string> agent field → option key for a scope. */
    private static function map( string $scope, string $slug ): array {
        if ( $scope === 'site' ) {
            return self::SITE_KEYS;
        }
        $out = [];
        foreach ( self::TYPE_KEYS[ $scope ] ?? [] as $field => $pattern ) {
            $out[ $field ] = sprintf( $pattern, $slug );
        }
        return $out;
    }

    /** @return mixed */
    private static function get( string $key ) {
        if ( class_exists( '\WPSEO_Options' ) && method_exists( '\WPSEO_Options', 'get' ) ) {
            try {
                return \WPSEO_Options::get( $key, null );
            } catch ( \Throwable $e ) {
                // Fall back to the stored arrays below.
            }
        }
        foreach ( self::OPTION_NAMES as $name ) {
            $all = get_option( $name, [] );
            if ( is_array( $all ) && array_key_exists( $key, $all ) ) {
                return $all[ $key ];
            }
        }
        return null;
    }

    /** @param mixed $value */
    private static function set( string $key, $value ): void {
        if ( class_exists( '\WPSEO_Options' ) && method_exists( '\WPSEO_Options', 'set' ) ) {
            try {
                \WPSEO_Options::set( $key, $value );
                return;
            } catch ( \Throwable $e ) {
                // Fall back to a raw write below.
            }
        }
        $target = self::TITLES_OPTION;
        foreach ( self::OPTION_NAMES as $name ) {
            $all = get_option( $name, [] );
            if ( is_array( $all ) && array_key_exists( $key, $all ) ) {
                $target = $name;
                break;
            }
        }
        $all = get_option( $target, [] );
        if ( ! is_array( $all ) ) {
            $all = [];
        }
        $all[ $key ] = $value;
        update_option( $target, $all );
    }
}
