<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\NotKept;
use Fabrement\Modules\Mcp\Support\StrictBooleans;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Yoast DEFAULTS — for a post type, a taxonomy, or the site — instead of the same set-seo on
 * fifty records.
 *
 * A `book` type whose SEO title is "%%title%% by {{author}} | %%sitename%%" and whose schema
 * page type is ItemPage needs that said ONCE; so does "noindex every `testimonial`". And the
 * knowledge graph (company or person, name, logo) is what every page's schema hangs off — a
 * site without it has an Organization node called "" with no logo.
 */
final class SetSeoDefaultsAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/set-seo-defaults';
    }

    public function label(): string {
        return 'Set SEO defaults';
    }

    public function description(): string {
        return 'Set Yoast DEFAULTS for exactly one scope — a postType (title/metaDesc templates, noindex, schema'
            . ' types; its ARCHIVE is set-seo {archive}), a taxonomy, or site:true (separator, website name, the knowledge graph:'
            . ' companyOrPerson/companyName/companyLogoId, facebookUrl/twitterUsername/socialProfiles, defaultOgImageId, home templates). Templates use Yoast variables '
            . '(%%title%%, %%sitename%%, %%sep%%, %%fab_<field>%% — a Fabrement field as a reader sees it, %%cf_<key>%% — another plugin\'s raw field…); '
            . 'an unknown or empty variable is REMOVED from the output. Refused when Yoast is not active.'
            . self::multilingual_text( ' Text defaults are per LANGUAGE: without `language` this writes the original; with '
            . '`language` it stores that language version of the same text fields (the original must exist first).' );
    }

    public function contract(): ?string {
        return 'seo-contract';
    }

    public function input_schema(): array {
        $str  = static fn( int $max = 300 ) => [ 'type' => [ 'string', 'null' ], 'maxLength' => $max ];
        $bool = [ 'type' => 'boolean' ];
        return [
            'type'                 => 'object',
            'properties'           => [
                'postType'          => [ 'type' => 'string' ],
                'taxonomy'          => [ 'type' => 'string' ],
                'site'              => [ 'type' => 'boolean' ],
                'titleTemplate'     => $str(),
                'metaDescTemplate'  => $str( 500 ),
                'noindex'           => $bool,
                'schemaPageType'    => SetSeoAbility::one_of( Seo::bridge()->page_types(), true ),
                'schemaArticleType' => SetSeoAbility::one_of( Seo::bridge()->article_types(), true ),
                'socialTitle'       => $str(),
                'socialDescription' => $str( 500 ),
                'separator'         => SetSeoAbility::one_of( Seo::bridge()->separators(), true ),
                'websiteName'       => $str( 200 ),
                'alternateName'     => $str( 200 ),
                'companyOrPerson'   => [ 'type' => [ 'string', 'null' ], 'enum' => [ 'company', 'person', null ] ],
                'companyName'       => $str( 200 ),
                'companyLogoId'     => [ 'type' => [ 'integer', 'null' ], 'minimum' => 0 ],
                'personUserId'      => [ 'type' => [ 'integer', 'null' ], 'minimum' => 0 ],
                'homeTitleTemplate'    => $str(),
                'homeMetaDescTemplate' => $str( 500 ),
                'breadcrumbsEnabled'   => $bool,
                'facebookUrl'          => [ 'type' => [ 'string', 'null' ], 'maxLength' => 500, 'description' => 'site: the Facebook page of the organisation (Organization sameAs, article:publisher).' ],
                'twitterUsername'      => [ 'type' => [ 'string', 'null' ], 'maxLength' => 100, 'description' => 'site: the X/Twitter handle ("fabrement", "@fabrement" or its profile URL).' ],
                'socialProfiles'       => [ 'type' => [ 'array', 'null' ], 'maxItems' => 30, 'items' => [ 'type' => 'string', 'maxLength' => 500 ], 'description' => 'site: every OTHER profile URL of the organisation (Instagram, LinkedIn, YouTube…) — the sameAs of the Organization node. Replaces the list; null clears it.' ],
                'defaultOgImageId'     => [ 'type' => [ 'integer', 'null' ], 'minimum' => 0, 'description' => 'site: the share image for pages with no image of their own (upload-media id).' ],
            ] + self::multilingual( [
                'language' => [ 'type' => 'string', 'description' => 'Store the TEXT fields (templates, names) as this language version instead of the original. Codes: list-languages. Flags, ids, separator and schema types are one value for every language and are refused with it.' ],
            ] ),
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'scope', 'written' ],
            'properties' => [
                'scope'     => [ 'type' => 'string', 'enum' => [ 'postType', 'taxonomy', 'site' ] ],
                'slug'      => [ 'type' => 'string' ],
                'written'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'defaults'  => [ 'type' => 'object' ],
                'warnings'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ] + self::multilingual( [
                'language'  => [ 'type' => 'string', 'description' => 'The language written — present only when a translation was stored.' ],
            ] ),
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        $invalid = StrictBooleans::check( $this->input_schema(), $input );
        if ( $invalid !== null ) {
            return $invalid;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'Changing SEO defaults needs the manage_options capability.' );
        }
        $seo = Seo::bridge();
        if ( ! $seo->is_active() ) {
            return new WP_Error( 'yoast_inactive', 'Yoast SEO is not active — there is no settings store to write into. Ask the site admin to install and activate it.' );
        }

        $scopes = array_values( array_filter( [ 'postType', 'taxonomy', 'site' ], static fn( $k ) => ! empty( $input[ $k ] ) ) );
        if ( count( $scopes ) !== 1 ) {
            return new WP_Error( 'one_scope', 'Give exactly one of postType, taxonomy or site:true.' );
        }
        $scope = $scopes[0];
        $slug  = '';

        if ( $scope === 'postType' ) {
            $slug = (string) $input['postType'];
            if ( ! get_post_type_object( $slug ) ) {
                return new WP_Error( 'unknown_post_type', sprintf( 'Post type "%s" is not registered — see list-post-types.', $slug ) );
            }
        } elseif ( $scope === 'taxonomy' ) {
            $slug = (string) $input['taxonomy'];
            if ( ! taxonomy_exists( $slug ) ) {
                return new WP_Error( 'unknown_taxonomy', sprintf( 'Taxonomy "%s" is not registered — see list-taxonomies.', $slug ) );
            }
        }

        // What this tool takes of the scope's fields (the store may know some it does not offer).
        $allowed = array_values( array_intersect( $seo->default_fields( $scope ), array_keys( $this->input_schema()['properties'] ) ) );
        $fields  = [];
        $ignored = [];
        $language = (string) ( $input['language'] ?? '' );
        if ( $language !== '' && $language === Translation::bridge()->default_language() ) {
            $language = ''; // the default language IS the original
        }
        if ( $language !== '' ) {
            $known = array_column( Translation::bridge()->languages(), 'code' );
            if ( ! in_array( $language, $known, true ) ) {
                return new WP_Error( 'unknown_language', sprintf( 'Language "%s" is not one of the site languages — see list-languages.', $language ) );
            }
        }

        foreach ( $input as $k => $v ) {
            if ( in_array( $k, [ 'postType', 'taxonomy', 'site', 'language' ], true ) ) {
                continue;
            }
            if ( in_array( $k, $allowed, true ) ) {
                $fields[ $k ] = $v;
            } else {
                $ignored[] = $k;
            }
        }
        if ( $ignored !== [] ) {
            return new WP_Error( 'not_for_scope', sprintf( '%s %s not a %s-scope field; nothing was written. %s takes: %s.', implode( ', ', $ignored ), count( $ignored ) > 1 ? 'are' : 'is', $scope, $scope, implode( ', ', $allowed ) ) );
        }
        if ( $fields === [] ) {
            return new WP_Error( 'nothing_to_set', sprintf( 'No %s-scope field given. Allowed: %s.', $scope, implode( ', ', $allowed ) ) );
        }
        $warnings = [];

        // A language version holds only what a visitor reads. Everything else is one value for the
        // site, and storing it "in Polish" would silently change it for every language.
        if ( $language !== '' ) {
            $per_language = array_values( array_intersect( $seo->translatable_fields( $scope ), $allowed ) );
            $shared       = array_diff( array_keys( $fields ), $per_language );
            if ( $shared !== [] ) {
                return new WP_Error( 'shared_field', sprintf( '%s is one value for every language — set it without `language`. Per-language fields: %s.', implode( ', ', $shared ), implode( ', ', $per_language ) ) );
            }
            foreach ( $fields as $k => $v ) {
                if ( ! is_string( $v ) || trim( $v ) === '' ) {
                    return new WP_Error( 'empty_translation', sprintf( '%s: a language version must be text; omit the field instead of clearing it.', $k ) );
                }
            }
            $refused = NotKept::error( $seo->not_kept_defaults( $fields ) );
            if ( $refused !== null ) {
                return $refused;
            }
            $result = $seo->write_defaults_translation( $scope, $slug, $language, $fields );
            if ( $result['emptyOriginal'] !== [] ) {
                $warnings[] = sprintf( 'Not stored — the original is still empty, set it first (without `language`): %s.', implode( ', ', $result['emptyOriginal'] ) );
            }
            $refused_by_plugin = array_values( array_diff( array_keys( $fields ), $result['written'], $result['emptyOriginal'] ) );
            if ( $refused_by_plugin !== [] && $result['written'] !== [] ) {
                $warnings[] = sprintf( 'Not stored — the translation plugin refused: %s.', implode( ', ', $refused_by_plugin ) );
            }
            if ( $result['written'] === [] && $result['emptyOriginal'] === [] ) {
                return new WP_Error(
                    'not_translatable',
                    \Fabrement\Modules\Translation\Translation::bridge()->supports_strings()
                        ? 'The translation plugin could not store these.'
                        : \Fabrement\Modules\Translation\Translation::STRINGS_MISSING
                );
            }
            return [
                'scope'     => $scope,
                'slug'      => $slug,
                'language'  => $language,
                'written'   => $result['written'],
                'defaults'  => (object) ( $seo->read_defaults_translations( $scope, $slug )[ $language ] ?? [] ),
                'warnings'  => $warnings,
            ];
        }

        if ( array_key_exists( 'companyLogoId', $fields ) ) {
            $logo_id = (int) $fields['companyLogoId'];
            if ( $logo_id > 0 ) {
                $att = get_post( $logo_id );
                if ( ! $att instanceof \WP_Post || $att->post_type !== 'attachment' || strpos( (string) $att->post_mime_type, 'image/' ) !== 0 ) {
                    return new WP_Error( 'not_image', sprintf( 'companyLogoId %d is not an image attachment.', $logo_id ) );
                }
            }
        }
        if ( array_key_exists( 'personUserId', $fields ) && (int) $fields['personUserId'] > 0 && ! get_userdata( (int) $fields['personUserId'] ) ) {
            return new WP_Error( 'no_user', sprintf( 'personUserId %d is not a user.', (int) $fields['personUserId'] ) );
        }
        if ( ! empty( $fields['defaultOgImageId'] ) && ! wp_attachment_is_image( (int) $fields['defaultOgImageId'] ) ) {
            return new WP_Error( 'not_image', sprintf( 'defaultOgImageId %d is not an image attachment — upload-media first.', (int) $fields['defaultOgImageId'] ) );
        }
        // Yoast validates these as it saves and, on one bad address, silently keeps the OLD list —
        // so they are checked here, where the caller can be told which one.
        foreach ( [ 'facebookUrl' ] as $k ) {
            if ( isset( $fields[ $k ] ) && $fields[ $k ] !== '' && ! self::is_http_url( (string) $fields[ $k ] ) ) {
                return new WP_Error( 'bad_url', sprintf( '%s must be an absolute http(s) URL.', $k ) );
            }
        }
        if ( array_key_exists( 'socialProfiles', $fields ) ) {
            $profiles = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $fields['socialProfiles'] ) ) ) );
            foreach ( $profiles as $url ) {
                if ( ! self::is_http_url( $url ) ) {
                    return new WP_Error( 'bad_url', sprintf( 'socialProfiles: "%s" is not an absolute http(s) URL.', $url ) );
                }
            }
            $fields['socialProfiles'] = $profiles;
        }
        if ( isset( $fields['twitterUsername'] ) && $fields['twitterUsername'] !== '' ) {
            $handle = ltrim( trim( (string) $fields['twitterUsername'] ), '@' );
            if ( preg_match( '#^https?://(?:www\.)?(?:twitter|x)\.com/([A-Za-z0-9_]{1,25})/?$#i', $handle, $m ) === 1 ) {
                $handle = $m[1];
            }
            if ( preg_match( '/^[A-Za-z0-9_]{1,25}$/', $handle ) !== 1 ) {
                return new WP_Error( 'bad_twitter', 'twitterUsername is an X/Twitter handle ("fabrement" or "@fabrement") or its profile URL.' );
            }
            $fields['twitterUsername'] = $handle;
        }
        // Yoast outputs Article only for a type that supports `author`; stored for any other type,
        // an article type would be reported saved and never appear.
        if ( $scope === 'postType' && ! empty( $fields['schemaArticleType'] ) && $fields['schemaArticleType'] !== 'None' && ! $seo->article_supported( $slug ) ) {
            return new WP_Error(
                'article_needs_author',
                sprintf( 'Yoast outputs an Article node only for post types that support "author" — "%s" does not, so schemaArticleType would do nothing. Describe the records with set-schema instead.', $slug )
            );
        }

        $refused = NotKept::error( $seo->not_kept_defaults( $fields ) );
        if ( $refused !== null ) {
            return $refused;
        }
        $written   = $seo->write_defaults( $scope, $slug, $fields );

        if ( $scope === 'site' ) {
            $graph = $seo->knowledge_graph();
            if ( $graph !== null && $graph['node'] === 'Person' ) {
                $warnings[] = 'companyOrPerson is "person" but personUserId is not an existing user — Yoast outputs no Person node until it is.';
            } elseif ( $graph !== null && $graph['missing'] !== [] ) {
                $lacks      = [ 'companyName' => 'name (set companyName)', 'companyLogoId' => 'logo (upload-media the logo, then companyLogoId)' ];
                $warnings[] = 'companyOrPerson is "company" but the Organization has no ' . implode( ' and no ', array_map( static fn( string $field ): string => $lacks[ $field ], $graph['missing'] ) ) . ' — Yoast outputs NO Organization node until it has both.';
            }
            $from = [
                'websiteName' => 'the website name (websiteName)',
                'siteTitle'   => 'the site title (Settings → General)',
                'siteLogo'    => 'the site logo (Site Identity / the Site Logo block)',
                'filter'      => 'a value site code supplies through a filter',
            ];
            if ( $graph !== null && isset( $graph['borrowed']['companyName'] ) ) {
                $warnings[] = 'The Organization takes its name from ' . $from[ $graph['borrowed']['companyName'] ] . ' (no companyName) — set companyName if the company is called otherwise.';
            }
            if ( $graph !== null && isset( $graph['borrowed']['companyLogoId'] ) ) {
                $warnings[] = 'The Organization takes its logo from ' . $from[ $graph['borrowed']['companyLogoId'] ] . ' (no companyLogoId) — set companyLogoId only for a different logo.';
            }
        }
        // No re-index: Yoast reads a type's templates, noindex and schema types from its options
        // while it renders (title-<type> etc.), and its index holds only a record's OWN values —
        // a change here is live on the next page view. (Checked against Yoast 28.5.)

        return [
            'scope'     => $scope,
            'slug'      => $slug,
            'written'   => $written,
            'defaults'  => (object) $seo->read_defaults( $scope, $slug ),
            'warnings'  => $warnings,
        ];
    }
    /** An absolute http(s) URL WordPress would keep. */
    private static function is_http_url( string $url ): bool {
        return preg_match( '#^https?://[^\s/$.?\#].[^\s]*$#i', $url ) === 1 && esc_url_raw( $url ) !== '';
    }
}
