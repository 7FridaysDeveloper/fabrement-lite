<?php

namespace Fabrement\Modules\Seo\Bridge\Yoast;

/**
 * Yoast's settings, read only: the templates and flags per post type and taxonomy a record or
 * term falls back to, and the breadcrumb taxonomy. Part of
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
            'titleTemplate'    => 'title-%s',
            'metaDescTemplate' => 'metadesc-%s',
            'noindex'          => 'noindex-%s',
        ],
        'taxonomy' => [
            'titleTemplate'    => 'title-tax-%s',
            'metaDescTemplate' => 'metadesc-tax-%s',
            'noindex'          => 'noindex-tax-%s',
        ],
    ];

    /** Yoast's option array our keys live in. */
    private const OPTION_NAMES = [ 'wpseo_titles' ];

    /** Yoast's "taxonomy to show in breadcrumbs" of a post type (`post_types-<type>-maintax`); '' for none. */
    public static function breadcrumb_taxonomy( string $post_type ): string {
        $taxonomy = self::get( 'post_types-' . $post_type . '-maintax' );
        return is_string( $taxonomy ) && $taxonomy !== '0' ? $taxonomy : '';
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
}
