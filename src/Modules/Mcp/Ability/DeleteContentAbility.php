<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ContentGuard;

use WP_Error;

/**
 * Delete a page/post/CPT record FOR GOOD. Everything reversible — publish, draft, trash, restore —
 * is set-content-status; this tool only does the one thing that cannot be undone, and says so with
 * a required `force: true`, so a call written for the old "trash by default" behaviour is refused
 * rather than silently deleting.
 */
final class DeleteContentAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/delete-content';
    }

    public function label(): string {
        return 'Delete content';
    }

    public function description(): string {
        return 'Delete a page/post/custom record PERMANENTLY — requires force:true, and only when the user said so. '
            . 'To move it to the trash (recoverable) use set-content-status {status:"trash"} instead. '
            . self::multilingual_text( 'Language versions are not deleted with it. ' )
            . 'Blocks used on the page are not deleted.';
    }

    public function contract(): ?string {
        return 'content-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            // `force` is checked in execute(), not required here: a schema refusal would hide the
            // pointer to set-content-status {status:"trash"} behind a generic validation error.
            'required'             => [ 'contentId' ],
            'properties'           => [
                'contentId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Content id (see list-content).' ],
                'force'     => [ 'type' => 'boolean', 'description' => 'Must be true: this deletes for good. For the trash use set-content-status.' ],
            ],
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
            ],
        ];
    }

    public function execute( array $input ) {
        $id = (int) ( $input['contentId'] ?? 0 );
        if ( ( $input['force'] ?? false ) !== true ) {
            return new WP_Error(
                'force_required',
                sprintf( 'delete-content deletes PERMANENTLY and needs force:true (only when the user said so). To move content %d to the trash, use set-content-status {contentId:%d, status:"trash"}.', $id, $id )
            );
        }

        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
            return ContentGuard::not_found( $id );
        }
        // A media file is not content. `attachment` is viewable, so it passed the check above and went
        // to wp_delete_post(), which hands it to wp_delete_attachment(): permanent unless MEDIA_TRASH,
        // whatever `force` said, while the answer claimed `trashed: true` — and past every "still
        // used on a page" check delete-media makes.
        if ( $post->post_type === 'attachment' ) {
            return new WP_Error(
                'is_media',
                sprintf( '%d is a media file, not content — delete it with delete-media, which checks where it is still used.', $id )
            );
        }
        if ( ! current_user_can( 'delete_post', $id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You are not allowed to delete content %d.', $id ) );
        }

        $title = (string) $post->post_title;

        // The front, posts and not-found pages: the site loses them with no error anywhere.
        $refusal = \Fabrement\Modules\Mcp\Support\SitePageGuard::refusal( $post, 'deleting' );
        if ( $refusal !== null ) {
            return $refusal;
        }

        $result = wp_delete_post( $id, true );
        if ( ! $result ) {
            return new WP_Error( 'delete_failed', sprintf( 'WordPress refused to delete content %d.', $id ) );
        }

        return [
            'deleted' => true,
            'id'      => $id,
            'title'   => $title,
        ];
    }
}
