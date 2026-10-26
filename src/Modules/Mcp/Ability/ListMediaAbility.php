<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Translation\Translation;

/**
 * Read-only: search/browse the site's media library — check before uploading duplicates,
 * find an existing photo/logo the user mentions.
 */
final class ListMediaAbility extends AbstractAbility {

    private const PER_PAGE = 24;

    public function name(): string {
        return 'fabrement/list-media';
    }

    public function label(): string {
        return 'List media';
    }

    public function description(): string {
        return 'Search the media library (title, filename, alt text; kind image/video/audio/document), newest '
            . 'first: id, url, mimeType, altText, width, height. Use it before upload-media and when the user '
            . 'refers to an existing image.'
            . self::multilingual_text( ' One row per image, whatever language it was uploaded in; `id` and `altText` are the original\'s, '
                . 'and `id` works on a page of any language — the page shows its own language\'s copy. `languages` lists the copies the '
                . 'site keeps per language, each with ITS id and alt text; a page in a language not listed shows the original\'s alt.' );
    }

    public function contract(): ?string {
        return 'site-assets-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'search' => [
                    'type'        => 'string',
                    'description' => 'Substring over title, filename and alt text (e.g. "logo", "hero").',
                ],
                'kind'   => [
                    'type'        => 'string',
                    'enum'        => [ 'image', 'video', 'audio', 'document', 'all' ],
                    'description' => 'Media kind filter; default "image".',
                ],
                'page'   => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Page number (24 per page), newest first. Default 1.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'items', 'total' ],
            'properties' => [
                'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'total' => [ 'type' => 'integer' ],
                'page'  => [ 'type' => 'integer' ],
                'pages' => [ 'type' => 'integer' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $kind = strtolower( trim( (string) ( $input['kind'] ?? 'image' ) ) );
        $page = max( 1, (int) ( $input['page'] ?? 1 ) );

        $mime_map = [
            'image'    => 'image',
            'video'    => 'video',
            'audio'    => 'audio',
            'document' => [ 'application', 'text' ],
            'all'      => '',
        ];
        $mime     = $mime_map[ $kind ] ?? 'image';

        $search = trim( (string) ( $input['search'] ?? '' ) );
        // get_posts() suppresses filters: every attachment in every language, as stored. Ids only,
        // so the whole library costs one column; the page is read in full below.
        $base = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            // The id breaks a tie of uploads in the same second, so pages never overlap.
            'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
            'fields'         => 'ids',
            'post_mime_type' => $mime ?: null,
        ];
        $ids = $this->find( $base, $search );

        // A translation plugin copies an image per language: one file, several rows. Each copy
        // stands for its original, so the list has one row per image and a search that matched a
        // French alt text finds the image too. An image the plugin has no language for stands alone.
        $bridge = Translation::bridge();
        if ( $bridge->is_active() && $ids !== [] ) {
            $originals = $bridge->originals_of_many( $ids, 'attachment' );
            $groups    = array_values( array_unique( array_map( static fn( int $id ): int => $originals[ $id ] ?? $id, $ids ) ) );
            // Newest first by the ORIGINAL's date: a copy made later must not lift its image up.
            $ids = $groups === [] ? [] : array_map(
                'intval',
                get_posts(
                    [
                        'post__in'       => $groups,
                        'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
                        'post_status'    => 'inherit',
                        'post_type'      => 'attachment',
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                    ]
                )
            );
        }

        $total = count( $ids );
        $slice = array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

        return [
            'items' => $this->items( $slice ),
            'total' => $total,
            'page'  => $page,
            'pages' => (int) ceil( $total / self::PER_PAGE ),
        ];
    }

    /** @return int[] Attachment ids matching the search, newest first. */
    private function find( array $base, string $search ): array {
        if ( '' === $search ) {
            return array_map( 'intval', get_posts( $base ) );
        }
        // `s` covers title/content only — alt text and the stored filename are meta,
        // so a real search is the union of both queries (date order preserved below).
        $by_text = get_posts( $base + [ 's' => $search ] );
        $by_meta = get_posts(
            $base + [
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- alt text and filename live only in meta; run only for a search.
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key'     => '_wp_attachment_image_alt',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ],
                    [
                        'key'     => '_wp_attached_file',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ],
                ],
            ]
        );
        $ids = array_values( array_unique( array_map( 'intval', array_merge( $by_text, $by_meta ) ) ) );
        rsort( $ids ); // Ids are chronological enough for a stable newest-first page.
        return $ids;
    }

    /** @param int[] $slice */
    private function items( array $slice ): array {
        $bridge    = Translation::bridge();
        $multi     = $bridge->is_active();
        $versions  = $multi ? $bridge->version_facts( $slice, 'attachment' ) : [];
        $languages = array_column( $bridge->languages(), 'code' );
        // Every row's meta and every copy's alt text in one query, not one per id.
        $all = $slice;
        foreach ( $versions as $version ) {
            $all = array_merge( $all, array_map( 'intval', array_values( (array) $version['translations'] ) ) );
        }
        update_meta_cache( 'post', array_unique( $all ) );

        $items = [];
        foreach ( $slice as $id ) {
            $meta = wp_get_attachment_metadata( $id );
            $item = [
                'id'       => $id,
                'url'      => (string) wp_get_attachment_url( $id ),
                'mimeType' => (string) get_post_mime_type( $id ),
                'title'    => (string) get_post_field( 'post_title', $id, 'raw' ),
                'altText'  => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
                'filename' => wp_basename( (string) get_attached_file( $id ) ),
                'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : null,
                'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : null,
            ];
            if ( $multi ) {
                // The copies of the site's languages, the original among them. None: an image the
                // translation plugin has no language for — every page shows it as it is.
                $copies = [];
                foreach ( (array) ( $versions[ $id ]['translations'] ?? [] ) as $code => $copy ) {
                    if ( in_array( (string) $code, $languages, true ) ) {
                        $copies[ (string) $code ] = [
                            'id'      => (int) $copy,
                            'altText' => (string) get_post_meta( (int) $copy, '_wp_attachment_image_alt', true ),
                        ];
                    }
                }
                $item['languages'] = (object) $copies;
            }
            $items[] = $item;
        }
        return $items;
    }
}
