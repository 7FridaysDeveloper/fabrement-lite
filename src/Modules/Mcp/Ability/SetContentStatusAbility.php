<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ContentGuard;

use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Every REVERSIBLE status change of a record: publish, back to draft, to the trash, out of it.
 * (Deleting for good is delete-content, the one irreversible step.)
 *
 * Its own tool, and a destructive one, because publishing is the one outward-facing step in the
 * whole surface: a crawled URL is not un-crawled by reverting the status. It used to be a
 * `status` field on save-content, so an agent that only meant to verify a page could publish
 * it in the same call that wrote it, and a client had no signal that anything public happened.
 * As a DestructiveAbility it carries `destructiveHint`, which tells the client to ask the user.
 */
final class SetContentStatusAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/set-content-status';
    }

    public function label(): string {
        return 'Set content status';
    }

    public function description(): string {
        return 'Change a page/post/custom record\'s status — everything reversible: "publish", "draft" (unpublish), '
            . '"trash" (recoverable), "restore" (out of the trash, with the status and address it had before; find '
            . 'trashed records with list-content {status:"trash"}). Records are created and edited as drafts with '
            . 'save-content; deleting for good is delete-content. ASK THE USER FIRST, IN THE SAME TURN, for "publish" '
            . 'and for "trash", and wait for the answer — naming the record and what will happen to it. PUBLISH puts '
            . 'the page in front of everyone and in front of search engines at once, and a crawled URL is not '
            . 'un-crawled by reverting the status; TRASH takes a live page off the site, so every link and every menu '
            . 'entry pointing at it breaks while it sits there. Neither is yours to decide. "Finish this page" is not '
            . 'consent to publish it; preview {id} shows a draft at its real address without making it public, '
            . 'which is what verifying looks like.';
    }

    public function contract(): ?string {
        return 'content-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'contentId' ],
            'properties'           => [
                'contentId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Content id (see list-content).' ],
                'status'    => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'trash', 'restore' ], 'description' => 'Default "publish". "draft" unpublishes; "trash" moves to the trash; "restore" takes it out.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'contentId', 'status', 'url' ],
            'properties' => [
                'contentId' => [ 'type' => 'integer' ],
                'title'     => [ 'type' => 'string' ],
                'postType'  => [ 'type' => 'string' ],
                'status'    => [ 'type' => 'string', 'description' => 'Status AFTER the call, read back from WordPress.' ],
                'url'       => [ 'type' => 'string' ],
                'changed'   => [ 'type' => 'boolean', 'description' => 'False when it already had that status.' ],
            ],
        ];
    }

    public function execute( array $input ) {
        $id     = (int) ( $input['contentId'] ?? 0 );
        $asked  = (string) ( $input['status'] ?? 'publish' );
        $target = $asked === 'draft' ? 'draft' : 'publish';

        $post = get_post( $id );
        // The same records save-content writes: public types, never media or plumbing.
        if ( ! $post instanceof \WP_Post || $post->post_type === 'attachment' || ! is_post_type_viewable( $post->post_type ) ) {
            return ContentGuard::not_found( $id );
        }

        if ( $asked === 'trash' ) {
            return $post->post_status === 'trash' ? $this->result( $post, false ) : $this->trash( $post );
        }

        if ( $asked === 'restore' || $post->post_status === 'trash' ) {
            return $this->restore( $post, $asked );
        }

        $capability = $target === 'publish' ? 'publish_post' : 'edit_post';
        if ( ! current_user_can( $capability, $id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You are not allowed to change the status of content %d.', $id ) );
        }

        if ( $target === 'draft' ) {
            // Unpublishing the front, posts or not-found page empties it with no error anywhere.
            $refusal = \Fabrement\Modules\Mcp\Support\SitePageGuard::refusal( $post, 'unpublishing' );
            if ( $refusal !== null ) {
                return $refusal;
            }
        }

        $changed = $post->post_status !== $target;
        if ( $changed ) {
            $result = wp_update_post( [ 'ID' => $id, 'post_status' => $target ], true );
            if ( is_wp_error( $result ) ) {
                return new WP_Error( 'status_failed', 'Could not change the status: ' . $result->get_error_message() );
            }
        }

        return $this->result( $post, $changed );
    }

    /**
     * To the trash through wp_trash_post(), which keeps the old status for restore.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function trash( \WP_Post $post ) {
        $id = (int) $post->ID;
        if ( ! current_user_can( 'delete_post', $id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You are not allowed to trash content %d.', $id ) );
        }
        $refusal = \Fabrement\Modules\Mcp\Support\SitePageGuard::refusal( $post, 'trashing' );
        if ( $refusal !== null ) {
            return $refusal;
        }
        if ( ! wp_trash_post( $id ) ) {
            return new WP_Error( 'trash_failed', sprintf( 'WordPress refused to move content %d to the trash (a site with EMPTY_TRASH_DAYS 0 has no trash — delete-content is then the only way).', $id ) );
        }

        return $this->result( get_post( $id ), true );
    }

    /**
     * Out of the trash through wp_untrash_post(), never a plain status write: trashing renames the
     * slug to "…__trashed" and keeps the old status in meta, and only untrash puts both back.
     * Publishing or drafting a trashed record directly would leave it at the mangled address.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function restore( \WP_Post $post, string $asked ) {
        $id = (int) $post->ID;
        if ( $post->post_status !== 'trash' ) {
            return new WP_Error( 'not_trashed', sprintf( 'Content %d is not in the trash (status "%s") — nothing to restore.', $id, $post->post_status ) );
        }
        if ( $asked !== 'restore' ) {
            return new WP_Error( 'in_trash', sprintf( '"%s" is in the trash — restore it first with status "restore", then change its status.', $post->post_title ) );
        }
        if ( ! current_user_can( 'delete_post', $id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You are not allowed to restore content %d.', $id ) );
        }

        // Since WordPress 5.6 an untrashed post comes back as a DRAFT; the status it had before is
        // what "restore" means here.
        add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
        $result = wp_untrash_post( $id );
        remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
        if ( ! $result ) {
            return new WP_Error( 'restore_failed', sprintf( 'Could not restore content %d from the trash.', $id ) );
        }

        return $this->result( get_post( $id ), true );
    }

    /** @return array<string,mixed> */
    private function result( \WP_Post $post, bool $changed ): array {
        $id       = (int) $post->ID;
        $language = Translation::live_language_of( $id, (string) $post->post_type );

        return [
            'contentId' => $id,
            'title'     => (string) $post->post_title,
            'postType'  => (string) $post->post_type,
            'status'    => (string) get_post_status( $id ),
            'url'       => Translation::bridge()->permalink( $id, $language ),
            'changed'   => $changed,
        ];
    }
}
