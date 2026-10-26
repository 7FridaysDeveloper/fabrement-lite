<?php

namespace Fabrement\Modules\Seo\Bridge\Yoast;

/**
 * A term's SEO as Yoast stores it: one row per term in the `wpseo_taxonomy_meta` option (not
 * termmeta), written through Yoast's own validator. Part of
 * {@see \Fabrement\Modules\Seo\Bridge\YoastBridge}; nothing else calls it.
 *
 * @internal
 */
final class TermMeta {

    /**
     * Agent field → key in Yoast's per-term store (`wpseo_taxonomy_meta[taxonomy][term_id]`).
     * Terms have no nofollow, advanced robots or schema types.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'title'              => 'wpseo_title',
        'metaDesc'           => 'wpseo_desc',
        'focusKeyword'       => 'wpseo_focuskw',
        'canonical'          => 'wpseo_canonical',
        'noindex'            => 'wpseo_noindex',
        'breadcrumbTitle'    => 'wpseo_bctitle',
        'cornerstone'        => 'wpseo_is_cornerstone',
        'ogTitle'            => 'wpseo_opengraph-title',
        'ogDescription'      => 'wpseo_opengraph-description',
        'ogImage'            => 'wpseo_opengraph-image',
        'ogImageId'          => 'wpseo_opengraph-image-id',
        'twitterTitle'       => 'wpseo_twitter-title',
        'twitterDescription' => 'wpseo_twitter-description',
        'twitterImage'       => 'wpseo_twitter-image',
        'twitterImageId'     => 'wpseo_twitter-image-id',
    ];

    /** Image ids Yoast keeps with the address it prints beside them. */
    private const IMAGE_URLS = [ 'ogImageId' => 'ogImage', 'twitterImageId' => 'twitterImage' ];

    /** @return list<string> The fields a term takes — not the addresses kept beside image ids. */
    public static function fields(): array {
        return array_values( array_diff( array_keys( self::FIELDS ), self::IMAGE_URLS ) );
    }

    /**
     * Yoast has no public API for term SEO; its own term screen goes through WPSEO_Taxonomy_Meta,
     * and so do we. False when a Yoast release no longer has the pieces used here: term SEO is
     * then refused instead of half-written.
     */
    public static function supported(): bool {
        return class_exists( '\WPSEO_Taxonomy_Meta' )
            && method_exists( '\WPSEO_Taxonomy_Meta', 'validate_term_meta_data' )
            && property_exists( '\WPSEO_Taxonomy_Meta', 'defaults_per_term' )
            && is_array( \WPSEO_Taxonomy_Meta::$defaults_per_term );
    }

    /**
     * A term's stored SEO, agent field names, only the fields that hold something.
     *
     * @return array<string, mixed>
     */
    public static function read( \WP_Term $term ): array {
        return self::decode( self::row( $term ) );
    }

    /**
     * read() for MANY terms: the option is read once for all of them.
     *
     * @param list<\WP_Term> $terms
     * @return array<int, array<string, mixed>> term id → fields that hold something
     */
    public static function read_many( array $terms ): array {
        $all = self::raw_option();
        $out = [];
        foreach ( $terms as $term ) {
            $row = $all[ (string) $term->taxonomy ][ (int) $term->term_id ] ?? [];
            $out[ (int) $term->term_id ] = self::decode( array_merge( \WPSEO_Taxonomy_Meta::$defaults_per_term, is_array( $row ) ? $row : [] ) );
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $stored a term's row, defaults merged in
     * @return array<string, mixed>
     */
    private static function decode( array $stored ): array {
        $out = [];
        foreach ( self::FIELDS as $field => $key ) {
            if ( in_array( $field, self::IMAGE_URLS, true ) ) {
                continue;
            }
            $value = $stored[ $key ] ?? '';
            if ( $field === 'noindex' ) {
                if ( $value === 'noindex' || $value === 'index' ) {
                    $out[ $field ] = $value === 'noindex';
                }
                continue;
            }
            if ( $field === 'cornerstone' ) {
                if ( (string) $value === '1' ) {
                    $out[ $field ] = true;
                }
                continue;
            }
            if ( (string) $value !== '' ) {
                $out[ $field ] = in_array( $field, [ 'ogImageId', 'twitterImageId' ], true ) ? (int) $value : (string) $value;
            }
        }
        return $out;
    }

    /**
     * Write agent-shaped fields onto a term. Yoast's single-key setter resets the term's OTHER
     * keys to their defaults, so the whole stored set is read, changed and written back — then
     * the term's indexable is marked outdated, since nothing in Yoast watches this store.
     *
     * Read and written RAW, past `get_option()`: on a WPML site Yoast SEO Multilingual swaps this
     * option for its translation into the request's language outside wp-admin — REST and MCP
     * included — and caches that for the rest of the request. Through `get_option()` a write would
     * start from another language's words (and Yoast's own setter would save them back for EVERY
     * term), and a read-back would show the state before the write.
     *
     * @param array<string, mixed> $fields `null` or "" clears a field.
     * @return list<string> written field names
     */
    public static function write( \WP_Term $term, array $fields ): array {
        $fields  = ImageUrls::fill( $fields, self::IMAGE_URLS );
        $values  = self::values( $term, $fields );
        $written = [];
        foreach ( array_keys( $fields ) as $field ) {
            if ( isset( self::FIELDS[ $field ] ) && ! in_array( $field, self::IMAGE_URLS, true ) ) {
                $written[] = $field;
            }
        }
        // Yoast's own validation (it also drops values equal to the defaults), then the raw option
        // with this one term replaced — the flag tells Yoast's option validator it is done.
        try {
            $clean = \WPSEO_Taxonomy_Meta::validate_term_meta_data( $values, self::row( $term ) );
        } catch ( \Throwable $e ) {
            return [];
        }
        if ( ! is_array( $clean ) ) {
            return [];
        }
        // Yoast's validator keeps only values that differ from the defaults, so a field set BACK to
        // its default leaves the row. On a WPML site that is not the same thing: WP SEO Multilingual
        // makes Yoast create a second copy of this option's object (Yoast's option classes share one
        // static instance), and the second copy's validator re-reads every field missing from a row
        // from the stored one — the old value comes back. Every field this call set stays in the row,
        // its default value included, so no validator has a gap to fill. Yoast's own term screen has
        // the same gap on such a site. Kept at Yoast's DEFAULT, never the value sent: a field is also
        // missing when Yoast's sanitising turned the value into the default (a canonical that is no
        // URL, a title of tags), and that value must not reach the row past it.
        foreach ( $fields as $field => $value ) {
            $key = self::FIELDS[ $field ] ?? '';
            if ( $key !== '' && ! array_key_exists( $key, $clean ) ) {
                $clean[ $key ] = \WPSEO_Taxonomy_Meta::$defaults_per_term[ $key ] ?? '';
            }
        }
        $all = self::raw_option();
        unset( $all['wpseo_already_validated'] );
        if ( $clean !== [] ) {
            $all[ (string) $term->taxonomy ][ (int) $term->term_id ] = $clean;
        } else {
            unset( $all[ (string) $term->taxonomy ][ (int) $term->term_id ] );
        }
        $all['wpseo_already_validated'] = true;
        update_option( 'wpseo_taxonomy_meta', $all );
        Indexables::outdate( (int) $term->term_id, 'term' );

        return $written;
    }

    /**
     * The term's row with `$fields` put in, encoded as Yoast keeps them.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function values( \WP_Term $term, array $fields ): array {
        $values = self::row( $term );
        foreach ( $fields as $field => $value ) {
            if ( ! isset( self::FIELDS[ $field ] ) ) {
                continue;
            }
            $key = self::FIELDS[ $field ];
            if ( $field === 'noindex' ) {
                $values[ $key ] = $value === null || $value === '' ? 'default' : ( $value ? 'noindex' : 'index' );
            } elseif ( $field === 'cornerstone' ) {
                $values[ $key ] = $value ? '1' : '0';
            } elseif ( in_array( $field, [ 'ogImageId', 'twitterImageId' ], true ) ) {
                $values[ $key ] = (int) $value > 0 ? (string) (int) $value : '';
            } else {
                $values[ $key ] = $value === null ? '' : trim( (string) $value );
            }
        }
        return $values;
    }

    /**
     * The fields Yoast would not keep as sent, each with what it would store instead: its term
     * validation run on the row without saving it.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    public static function not_kept( \WP_Term $term, array $fields ): array {
        $values = self::values( $term, $fields );
        try {
            $clean = \WPSEO_Taxonomy_Meta::validate_term_meta_data( $values, self::row( $term ) );
        } catch ( \Throwable $e ) {
            return [];
        }
        $out = [];
        foreach ( array_keys( $fields ) as $field ) {
            if ( ! isset( self::FIELDS[ $field ] ) || in_array( $field, self::IMAGE_URLS, true ) || in_array( $field, [ 'noindex', 'cornerstone', 'ogImageId', 'twitterImageId' ], true ) ) {
                continue;
            }
            $key  = self::FIELDS[ $field ];
            $sent = (string) ( $values[ $key ] ?? '' );
            if ( $sent === '' ) {
                continue;
            }
            $kept = $clean[ $key ] ?? ( \WPSEO_Taxonomy_Meta::$defaults_per_term[ $key ] ?? '' );
            $kept = is_scalar( $kept ) ? (string) $kept : '';
            if ( ! Kept::same( $sent, $kept, $field === 'canonical' ) ) {
                $out[ $field ] = $kept;
            }
        }
        return $out;
    }

    /**
     * One term's stored SEO as Yoast keeps it (defaults merged in), from the raw option.
     *
     * @return array<string, mixed>
     */
    private static function row( \WP_Term $term ): array {
        $all = self::raw_option();
        $row = $all[ (string) $term->taxonomy ][ (int) $term->term_id ] ?? [];

        return array_merge( \WPSEO_Taxonomy_Meta::$defaults_per_term, is_array( $row ) ? $row : [] );
    }

    /**
     * `wpseo_taxonomy_meta` as stored — not through get_option(), which a translation plugin may
     * answer with another language's copy (see write()).
     *
     * @return array<string, mixed>
     */
    private static function raw_option(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the stored value itself is the point: get_option() is filtered per language (see write()).
        $raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wpseo_taxonomy_meta' ) );
        $value = is_string( $raw ) ? maybe_unserialize( $raw ) : [];

        return is_array( $value ) ? $value : [];
    }
}
