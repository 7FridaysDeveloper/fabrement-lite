<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Remove a media item — the other half of upload-media, which could add files but never take
 * one back. An agent that uploaded a wrong logo left it in the library forever.
 */
final class DeleteMediaAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/delete-media';
    }

    public function label(): string {
        return 'Delete media';
    }

    public function description(): string {
        return 'Delete a media item (permanent by default — media has no useful trash). It is deleted even while '
            . 'something still shows it — a block attribute, a featured image, the site icon: `usedBy` says where, '
            . 'and those places lose the image, so tell the user.'
            . self::multilingual_text( ' The id list-media gives deletes the image with every language copy, listed in `alsoDeleted`. '
                . 'With `language`, only that language\'s copy goes (as does a copy\'s own id from list-media `languages`): the '
                . 'file stays, and pages in that language show the original with the original\'s alt text.' );
    }

    public function contract(): ?string {
        return 'site-assets-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'mediaId' ],
            'properties'           => [
                'mediaId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Attachment id (see list-media).' ],
                'force' => [ 'type' => 'boolean', 'description' => 'Default true. False sends it to the trash if the site keeps media trash.' ],
            ] + self::multilingual( [
                'language' => [ 'type' => 'string', 'description' => 'Language code (list-languages): delete only this language\'s copy of the image, never the original.' ],
            ] ),
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'deleted', 'id' ],
            'properties' => [
                'deleted' => [ 'type' => 'boolean' ],
                'id'      => [ 'type' => 'integer' ],
                'title'   => [ 'type' => 'string' ],
                'usedBy'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Where it was still shown; those places have lost the image.' ],
            ] + self::multilingual( [
                'alsoDeleted' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Language duplicates of the same file removed with it.' ],
            ] ),
        ];
    }

    public function execute( array $input ) {
        global $wpdb;

        $id    = (int) ( $input['mediaId'] ?? 0 );
        $force = ! array_key_exists( 'force', $input ) || ! empty( $input['force'] );

        $attachment = get_post( $id );
        if ( ! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment' ) {
            return new WP_Error( 'not_found', sprintf( 'No media item with id %d — see list-media.', $id ) );
        }
        $language = trim( (string) ( $input['language'] ?? '' ) );
        if ( $language !== '' ) {
            $copy = $this->copy_in( $id, $language );
            if ( $copy instanceof WP_Error ) {
                return $copy;
            }
            $id         = $copy;
            $attachment = get_post( $id );
        }
        if ( ! current_user_can( 'delete_post', $id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You are not allowed to delete media %d.', $id ) );
        }

        // ONE FILE, SEVERAL RECORDS. A translation plugin duplicates an attachment per language,
        // and every duplicate points at the SAME bytes on disk. Deleting one row left the others
        // behind: nothing pointed at them any more, and the file stayed with them --
        // a leak that grew with every upload/delete cycle. So the unit of work is the GROUP.
        //
        // House rule, the same one templates follow: deleting the ORIGINAL takes every language
        // with it, because these are copies of one file rather than editorial versions; deleting
        // a single language's duplicate leaves the original and its file alone.
        $group = self::group_for( $id );
        // One language's copy: the original keeps the file, and pages showing that file keep it.
        $keeps_file = Translation::bridge()->is_active() && ! Translation::bridge()->is_original( $id, 'attachment' );

        $used = [];

        foreach ( $group as $member ) {
            if ( (int) get_option( 'site_icon' ) === $member ) {
                $used[] = 'the site icon (favicon)';
                break;
            }
        }

        // Where it is still shown — reported, not refused: whoever deletes an image may mean to, and
        // the answer tells them which pages lost it. Featured images, and the {id,url} pairs blocks
        // store in their attributes: the id appearing in content or meta, one query each.
        // Asked of EVERY id in the group: a page may well use the Polish duplicate, and deleting
        // the English original takes that duplicate with it. Checking only the id we were handed
        // would report "nothing uses this" and then break that page.
        $placeholders = implode( ',', array_fill( 0, count( $group ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- usage check right before a delete: must see live rows of every post type and status, which get_posts() would filter.
        $thumb_of     = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$placeholders}) LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a run of %d built with array_fill() from count( $group ), one per id in $group.
                $group
            )
        );
        foreach ( (array) $thumb_of as $post_id ) {
            $used[] = sprintf( 'the featured image of "%s" (id %d)', (string) get_post_field( 'post_title', (int) $post_id, 'raw' ), (int) $post_id );
        }

        // Three shapes, because wp_json_encode writes the id wherever it falls in the object:
        // `"id":42,` mid-object, `"id":42}` last, and the plain URL for blocks that store one.
        // An EMPTY url (an attachment whose _wp_attached_file meta is gone) would otherwise
        // become LIKE '%%' and match every post on the site — refusing to delete precisely the
        // broken files a cleanup is for.
        $patterns = array();
        foreach ( $group as $member ) {
            $patterns[] = '%"id":' . $member . ',%';
            $patterns[] = '%"id":' . $member . '}%';
            $member_url = $keeps_file ? '' : (string) wp_get_attachment_url( $member );
            if ( $member_url !== '' ) {
                $patterns[] = '%' . $wpdb->esc_like( $member_url ) . '%';
            }
        }
        $clause   = implode( ' OR ', array_fill( 0, count( $patterns ), 'post_content LIKE %s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- usage check right before a delete: a LIKE over post content of every type, which WP_Query cannot express; must see live rows.
        $in_posts = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status != 'trash' AND ( {$clause} ) LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $clause is a run of "post_content LIKE %s" counted from $patterns; every value is in $patterns.
                $patterns
            )
        );
        foreach ( (array) $in_posts as $post_id ) {
            $used[] = sprintf( 'content of "%s" (id %d)', (string) get_post_field( 'post_title', (int) $post_id, 'raw' ), (int) $post_id );
        }

        $title = (string) $attachment->post_title;

        // Duplicates first, the id we were asked about last: the file is unlinked with the final
        // record that points at it, and a failure part-way leaves the asked-for record intact
        // rather than a live row whose bytes are already gone.
        $also = [];
        foreach ( $group as $member ) {
            if ( $member === $id ) {
                continue;
            }
            // Verified before it is destroyed. The plugin's table outlives the posts in it, so a
            // stale row there would otherwise delete an unrelated record with no trash to undo it.
            $post = get_post( $member );
            if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
                continue;
            }
            if ( wp_delete_attachment( $member, $force ) ) {
                $also[] = $member;
            }
        }

        $deleted = wp_delete_attachment( $id, $force );
        if ( ! $deleted ) {
            return new WP_Error( 'delete_failed', sprintf( 'WordPress refused to delete media %d.', $id ) );
        }

        return [
            'deleted'     => true,
            'id'          => $id,
            'title'       => $title,
            'usedBy'      => $used,
        ] + self::multilingual( [
            'alsoDeleted' => $also,
        ] );
    }

    /**
     * The id of an image's copy in one language, to delete that copy alone. Never the original:
     * without it the copies would have no source, and deleting the image is the call without
     * `language`.
     *
     * @return int|WP_Error
     */
    private function copy_in( int $id, string $language ) {
        $bridge = Translation::bridge();
        if ( ! in_array( $language, array_column( $bridge->languages(), 'code' ), true ) ) {
            return new WP_Error( 'invalid_language', 'language: Not a language of this site. See list-languages.' );
        }
        $copy = (int) ( $bridge->version_facts( [ $id ], 'attachment' )[ $id ]['translations'][ $language ] ?? 0 );
        if ( $copy <= 0 ) {
            return new WP_Error( 'no_copy', sprintf( 'Media %d has no %s copy: pages in %s already show the original.', $id, $language, $language ) );
        }
        if ( $bridge->is_original( $copy, 'attachment' ) ) {
            return new WP_Error( 'is_original', sprintf( 'The %s version of media %d is the original, which the other languages copy. To remove the image, delete it without `language`.', $language, $id ) );
        }
        return $copy;
    }

    /**
     * Every attachment row that shares this file: the id itself, plus its language duplicates.
     *
     * Only when the id is the ORIGINAL. Handed one language's duplicate, the group is that
     * duplicate alone -- the original still owns the file, so taking the copy away breaks
     * nothing, and taking the original with it would be a surprise nobody asked for.
     *
     * @return int[]
     */
    private static function group_for( int $id ): array {
        $bridge = Translation::bridge();
        if ( ! $bridge->is_active() || ! $bridge->is_original( $id, 'attachment' ) ) {
            return [ $id ];
        }

        $group = [ $id ];
        foreach ( $bridge->visible_versions( $id, 'attachment' ) as $translation_id ) {
            $translation_id = (int) $translation_id;
            if ( $translation_id > 0 && ! in_array( $translation_id, $group, true ) ) {
                $group[] = $translation_id;
            }
        }
        return $group;
    }
}
