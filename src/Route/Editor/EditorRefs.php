<?php

namespace Fabrement\Route\Editor;

use Fabrement\Modules\Translation\Version;
use Fabrement\Modules\Translation\Translation;

/**
 * What the editor shows for the records and media it holds — titles, addresses, languages,
 * statuses, thumbnails. ONE place, called by the /post-refs and /media-refs routes and by the
 * Fabrement fields box (which sends these rows with its values, so it opens without a request).
 * The editor keeps them in one repository (assets/editor-plugin/src/refs-repository.ts) and asks
 * here only for what it lacks.
 *
 * Everything is read in batches: the posts in one query, the language groups in one query per
 * post type — never one lookup per id.
 */
final class EditorRefs {

    /**
     * The picker rows for these ids. Records that are gone or unreadable are left out; trashed
     * ones stay, and say so by their status.
     *
     * @param int[] $ids
     * @return list<array<string,mixed>>
     */
    public static function posts( array $ids, string $lang ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) {
            return [];
        }
        $bridge = Translation::bridge();

        // Batched, not per id: the posts in one query, the language groups in one query per post
        // type. Asked one record at a time this cost a post lookup and two translation lookups
        // per id — hundreds of queries for a relationship or a repeater of records.
        _prime_post_caches( $ids, false, false );
        $posts  = [];
        $by_type = [];
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            // Trashed records stay in: a field keeps the id it was given (the original of a
            // translation group, even when that original is in the trash), and the picker says so
            // by `status` instead of calling the pick "unavailable".
            if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', $id ) ) {
                continue;
            }
            $posts[ $id ]                       = $post;
            $by_type[ (string) $post->post_type ][] = $id;
        }
        $groups = [];
        foreach ( $by_type as $type => $type_ids ) {
            $groups += $bridge->version_facts( $type_ids, (string) $type );
        }

        $shown = [];
        if ( $lang !== '' ) {
            $visible = array_flip( Version::posts( array_keys( $posts ), $lang, [ 'publish' ] ) );
            foreach ( $posts as $id => $post ) {
                $shown[ $id ] = self::on_page( $id, $lang, $groups[ $id ] ?? null, $visible );
            }
        }

        $out = [];
        foreach ( $posts as $id => $post ) {
            $group = $groups[ $id ] ?? null;
            $row   = [
                'id'        => $id,
                'title'     => (string) get_the_title( $post ),
                'url'       => (string) get_permalink( $post ),
                'subtype'   => (string) $post->post_type,
                'status'    => (string) $post->post_status,
                'language'  => (string) ( $group['language'] ?? '' ),
                'languages' => $group ? array_keys( array_filter( $group['statuses'], static fn( string $status ): bool => $status === 'publish' ) ) : [],
            ];
            if ( $lang !== '' ) {
                $row['onPage'] = [
                    'state' => $shown[ $id ]['state'],
                    'id'    => (int) $shown[ $id ]['id'],
                    'title' => $shown[ $id ]['id'] > 0 ? (string) get_the_title( (int) $shown[ $id ]['id'] ) : '',
                ];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * What a visitor of a page in $lang is shown for one record: the version fabrement()->post()
     * reads there (published, as WP_Query and WPML give it), and how it relates to the record.
     *
     * @param array{language:string,translations:array<string,int>,statuses:array<string,string>,isOriginal:bool}|null $group
     * @param array<int,int> $visible The versions shown on that page, as keys.
     * @return array{id:int,state:string}
     */
    private static function on_page( int $id, string $lang, ?array $group, array $visible ): array {
        $copy = (int) ( $group['translations'][ $lang ] ?? 0 );
        if ( $copy > 0 && isset( $visible[ $copy ] ) ) {
            return [ 'id' => $copy, 'state' => $copy === $id ? 'same' : 'translated' ];
        }
        if ( isset( $visible[ $id ] ) && ( $group === null || $group['language'] === $lang ) ) {
            return [ 'id' => $id, 'state' => 'same' ];
        }
        foreach ( array_merge( [ $id ], array_map( 'intval', array_values( $group['translations'] ?? [] ) ) ) as $version ) {
            if ( isset( $visible[ $version ] ) ) {
                return [ 'id' => $version, 'state' => 'original' ];
            }
        }
        return [ 'id' => 0, 'state' => 'hidden' ];
    }

    /**
     * Media as the fields box shows them: address, thumbnail, alt, title, file name, type, size.
     * Read by id, exactly — a list query would be scoped by WPML to the admin language and hand
     * back other ids. One query primes the posts and their meta; the size is read from the
     * attachment metadata WordPress wrote at upload, not from the disk.
     *
     * @param int[] $ids
     * @return list<array{id:int,url:string,thumb:string,alt:string,title:string,filename:string,mimeType:string,fileSize:int}>
     */
    public static function media( array $ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) {
            return [];
        }
        _prime_post_caches( $ids, false, true );
        $out = [];
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' || ! current_user_can( 'read_post', $id ) ) {
                continue;
            }
            $url   = (string) wp_get_attachment_url( $id );
            $thumb = wp_attachment_is_image( $id ) ? wp_get_attachment_image_src( $id, 'medium' ) : false;
            $meta  = wp_get_attachment_metadata( $id );
            $out[] = [
                'id'       => $id,
                'url'      => $url,
                'thumb'    => $thumb ? (string) $thumb[0] : '',
                'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
                'title'    => (string) get_the_title( $post ),
                'filename' => $url !== '' ? wp_basename( $url ) : '',
                'mimeType' => (string) $post->post_mime_type,
                'fileSize' => is_array( $meta ) ? (int) ( $meta['filesize'] ?? 0 ) : 0,
            ];
        }
        return $out;
    }
}
