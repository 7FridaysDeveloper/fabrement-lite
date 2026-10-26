<?php
namespace Fabrement\Modules\Mcp\Ability;
use Fabrement\Modules\ContentPreview\ContentPreview;
use Fabrement\Modules\Mcp\Support\ContentGuard;
use WP_Error;

/** Tokenized previews of content rendered by the installed theme and blocks. */
final class PreviewAbility extends AbstractAbility {
    public function name(): string { return 'fabrement/preview'; }
    public function label(): string { return 'Preview content'; }
    public function contract(): ?string { return 'preview-contract'; }
    public function description(): string { return 'Get a temporary frontend preview URL for a page/post/CPT record, including drafts. Private, password-protected and trashed records are refused.'; }
    public function input_schema(): array {
        return [ 'type' => 'object', 'required' => [ 'id' ], 'additionalProperties' => false,
            'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ] ];
    }
    public function output_schema(): array {
        return [ 'type' => 'object', 'required' => [ 'url', 'expiresIn', 'status' ], 'properties' => [
            'url' => [ 'type' => 'string' ], 'expiresIn' => [ 'type' => 'integer' ], 'status' => [ 'type' => 'string' ],
        ] ];
    }
    /** Not read-only: it mints a temporary link that opens the draft to whoever holds it. */
    public function annotations(): array { return [ 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true ]; }
    public function execute( array $input ) {
        if ( array_diff( array_keys( $input ), [ 'id' ] ) ) {
            return new WP_Error( 'preview_input', 'preview takes a record id and nothing else.' );
        }
        return $this->content( (int) ( $input['id'] ?? 0 ) );
    }
    private static function private_record( string $field, int $id ): WP_Error {
        return new WP_Error( 'preview_private', sprintf( '%s: Record %d cannot be shared through a preview link.', $field, $id ), [ 'status' => 400 ] );
    }

    /** A record on its real front-end address, through its template. */
    private function content( int $id ) {
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post || $post->post_type === 'attachment' || ! is_post_type_viewable( $post->post_type ) ) {
            return ContentGuard::not_found( $id );
        }
        if ( $post->post_status === 'trash' ) {
            return new WP_Error( 'trashed', sprintf( 'Content %d is in the trash — restore it with set-content-status {status:"restore"} to preview it.', $id ) );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You cannot preview content %d on this site.', $id ) );
        }
        if ( ! ContentPreview::shareable( $id ) ) {
            return self::private_record( 'id', $id );
        }
        return [ 'url' => ContentPreview::url( $id, null ), 'expiresIn' => ContentPreview::TTL, 'status' => (string) $post->post_status ];
    }

}
