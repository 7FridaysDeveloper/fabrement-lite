<?php

namespace Fabrement\Modules\Seo\Bridge\Yoast;

/**
 * A record's SEO as Yoast stores it: post meta under the `_yoast_wpseo_` prefix — the fields, and
 * the main term per taxonomy. Part of {@see \Fabrement\Modules\Seo\Bridge\YoastBridge}; nothing
 * else calls it.
 *
 * @internal
 */
final class PostMeta {

    public const PREFIX = '_yoast_wpseo_';

    /**
     * Agent-facing field → Yoast meta key (without the prefix). The agent never sees the right side.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'title'           => 'title',
        'metaDesc'        => 'metadesc',
        'focusKeyword'    => 'focuskw',
        'canonical'       => 'canonical',
        'noindex'         => 'meta-robots-noindex',
        'nofollow'        => 'meta-robots-nofollow',
        'robotsAdvanced'  => 'meta-robots-adv',
        'breadcrumbTitle' => 'bctitle',
        'cornerstone'     => 'is_cornerstone',
        'schemaPageType'  => 'schema_page_type',
        'schemaArticleType' => 'schema_article_type',
        'ogTitle'         => 'opengraph-title',
        'ogDescription'   => 'opengraph-description',
        'ogImage'         => 'opengraph-image',
        'ogImageId'       => 'opengraph-image-id',
        'twitterTitle'    => 'twitter-title',
        'twitterDescription' => 'twitter-description',
        'twitterImage'    => 'twitter-image',
        'twitterImageId'  => 'twitter-image-id',
    ];

    /** Image ids Yoast keeps with the address it prints beside them. */
    private const IMAGE_URLS = [ 'ogImageId' => 'ogImage', 'twitterImageId' => 'twitterImage' ];

    /** Values Yoast accepts in `meta-robots-adv` (comma-separated). */
    public const ROBOTS_ADVANCED = [ 'noimageindex', 'noarchive', 'nosnippet' ];

    /** Where Yoast keeps a record's main term of a taxonomy: `_yoast_wpseo_primary_<taxonomy>`. */
    private const PRIMARY = self::PREFIX . 'primary_';

    /**
     * Raw stored values for one post, agent field names, only the fields that hold something.
     *
     * @return array<string, mixed>
     */
    public static function read( int $post_id ): array {
        $out = [];
        foreach ( self::FIELDS as $field => $key ) {
            if ( in_array( $field, self::IMAGE_URLS, true ) ) {
                continue;
            }
            $value = get_post_meta( $post_id, self::PREFIX . $key, true );
            if ( $value === '' || $value === null || $value === false ) {
                continue;
            }
            $out[ $field ] = self::decode( $field, (string) $value );
        }
        return $out;
    }

    /**
     * read() for MANY records, limited to `$fields`: one query for all of them.
     *
     * @param list<int>    $post_ids
     * @param list<string> $fields agent field names
     * @return array<int, array<string, mixed>> id → fields that hold something
     */
    public static function read_many( array $post_ids, array $fields ): array {
        global $wpdb;
        $keys = [];
        foreach ( $fields as $field ) {
            if ( isset( self::FIELDS[ $field ] ) && ! in_array( $field, self::IMAGE_URLS, true ) ) {
                $keys[ self::PREFIX . self::FIELDS[ $field ] ] = $field;
            }
        }
        $out = [];
        foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $post_ids ) ) ), 1000 ) as $chunk ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one read for a listing of many records; placeholders are built for each id and key.
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', ...array_merge( $chunk, array_keys( $keys ) ) ) );
            foreach ( (array) $rows as $row ) {
                if ( (string) $row->meta_value === '' || isset( $out[ (int) $row->post_id ][ $keys[ $row->meta_key ] ] ) ) {
                    continue;
                }
                $out[ (int) $row->post_id ][ $keys[ $row->meta_key ] ] = self::decode( $keys[ $row->meta_key ], (string) $row->meta_value );
            }
        }
        return $out;
    }

    /**
     * Write agent-shaped fields. `null` or "" clears the key. Returns the field names written.
     *
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    public static function write( int $post_id, array $fields ): array {
        $fields  = ImageUrls::fill( $fields, self::IMAGE_URLS );
        $written = [];
        foreach ( $fields as $field => $value ) {
            if ( ! isset( self::FIELDS[ $field ] ) ) {
                continue;
            }
            $key    = self::FIELDS[ $field ];
            $stored = self::encode( $field, $value );
            // update_post_meta() unslashes: the raw writes are slashed here, so "A \ B" stays (Yoast's
            // setter slashes on its own).
            $meta_key = self::PREFIX . $key;
            if ( $stored === '' ) {
                delete_post_meta( $post_id, $meta_key );
            } elseif ( class_exists( '\WPSEO_Meta' ) && method_exists( '\WPSEO_Meta', 'set_value' ) ) {
                // Yoast's setter is update_post_meta() with the value slashed; its false (unchanged
                // or cleaned to the stored value) is final, a raw retry would store the same.
                \WPSEO_Meta::set_value( $key, $stored, $post_id );
            } else {
                update_post_meta( $post_id, $meta_key, wp_slash( $stored ) );
            }
            if ( ! in_array( $field, self::IMAGE_URLS, true ) ) {
                $written[] = $field;
            }
        }
        return $written;
    }

    /**
     * The fields Yoast would not keep as sent, each with what it would store instead: its own
     * cleaning of the meta (and every filter on it) run without writing.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    public static function not_kept( string $post_type, array $fields ): array {
        $out = [];
        foreach ( $fields as $field => $value ) {
            if ( ! isset( self::FIELDS[ $field ] ) || in_array( $field, self::IMAGE_URLS, true ) ) {
                continue;
            }
            $sent = self::encode( $field, $value );
            if ( $sent === '' ) {
                continue;
            }
            $kept = sanitize_meta( self::PREFIX . self::FIELDS[ $field ], $sent, 'post', $post_type );
            $kept = is_scalar( $kept ) ? (string) $kept : '';
            if ( ! Kept::same( $sent, $kept, $field === 'canonical' ) ) {
                $out[ $field ] = $kept;
            }
        }
        return $out;
    }

    public static function primary( int $post_id, string $taxonomy ): int {
        return (int) get_post_meta( $post_id, self::PRIMARY . $taxonomy, true );
    }

    public static function set_primary( int $post_id, string $taxonomy, int $term_id ): void {
        if ( $term_id > 0 ) {
            update_post_meta( $post_id, self::PRIMARY . $taxonomy, (string) $term_id );
        } else {
            delete_post_meta( $post_id, self::PRIMARY . $taxonomy );
        }
    }

    /** Agent value → Yoast's stored string. */
    private static function encode( string $field, $value ): string {
        switch ( $field ) {
            case 'noindex':
                // Yoast: '' / '0' = site default, '1' = noindex, '2' = index (explicit).
                if ( $value === null || $value === '' ) {
                    return '';
                }
                return $value ? '1' : '2';
            case 'nofollow':
            case 'cornerstone':
                return $value ? '1' : '';
            case 'robotsAdvanced':
                if ( ! is_array( $value ) ) {
                    return '';
                }
                $keep = array_values( array_intersect( self::ROBOTS_ADVANCED, array_map( 'strval', $value ) ) );
                return implode( ',', $keep );
            case 'ogImageId':
            case 'twitterImageId':
                return (int) $value > 0 ? (string) (int) $value : '';
            default:
                return $value === null ? '' : trim( (string) $value );
        }
    }

    /** Yoast's stored string → agent value. */
    private static function decode( string $field, string $stored ) {
        switch ( $field ) {
            case 'noindex':
                return $stored === '1' ? true : ( $stored === '2' ? false : null );
            case 'nofollow':
            case 'cornerstone':
                return $stored === '1';
            case 'robotsAdvanced':
                return array_values( array_filter( array_map( 'trim', explode( ',', $stored ) ) ) );
            case 'ogImageId':
            case 'twitterImageId':
                return (int) $stored;
            default:
                return $stored;
        }
    }
}
