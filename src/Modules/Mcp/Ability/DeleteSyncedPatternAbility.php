<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\Service\SyncedPatterns;
use WP_Error;

/**
 * Delete a global block for good. Refused while anything places it: every page placing a deleted
 * pattern shows the editor's "Block has been deleted or is unavailable" and renders nothing.
 */
final class DeleteSyncedPatternAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/delete-synced-pattern';
    }

    public function label(): string {
        return 'Delete synced pattern';
    }

    public function description(): string {
        return 'Delete a global block (synced pattern) PERMANENTLY. A template\'s header/footer is never deleted while a '
            . 'template uses it — take it out of each, or put another in its place, with save-template {mode:"edit"} first. Otherwise refused while any record places it — '
            . 'the answer lists them; remove the placements first, or send force:true (those places then render nothing). '
            . 'The blocks inside are not deleted.'
            . self::multilingual_text( ' `note` says what changes for other languages.' );
    }

    public function contract(): ?string {
        return 'synced-pattern-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'patternId' ],
            'properties'           => [
                'patternId' => [ 'type' => 'integer', 'minimum' => 1 ],
                'force'     => [ 'type' => 'boolean', 'description' => 'Delete even while records place it.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'deleted', 'patternId' ],
            'properties' => [
                'deleted'   => [ 'type' => 'boolean' ],
                'patternId' => [ 'type' => 'integer' ],
                'title'     => [ 'type' => 'string' ],
                'orphaned'  => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Records still placing it — they now render nothing there.' ],
            ] + self::multilingual( [
                'note'      => [ 'type' => 'string', 'description' => 'What the deletion changes for the other languages — tell the user.' ],
            ] ),
        ];
    }

    public function execute( array $input ) {
        $id   = (int) ( $input['patternId'] ?? 0 );
        // Any synced pattern, the bin included: one moved to the bin from WordPress's screens is
        // deleted for good here too.
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post || $post->post_type !== SyncedPatterns::POST_TYPE || get_post_meta( $id, 'wp_pattern_sync_status', true ) === 'unsynced' ) {
            return new WP_Error( 'pattern_not_found', sprintf( 'No synced pattern with id %d — see list-synced-patterns.', $id ) );
        }
        if ( ! current_user_can( 'delete_post', $id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You cannot delete pattern %d on this site.', $id ) );
        }

        // Placed in a template: deleting it leaves a hole on every page wearing that template, so
        // force does not apply — the template gets another one first.
        $templates = \Fabrement\Modules\SiteTemplate\Service\TemplateParts::templates_using( $id );
        if ( $templates !== [] ) {
            return new WP_Error(
                'pattern_is_template_part',
                sprintf(
                    'Pattern %d is placed in %d template(s): %s. It cannot be deleted while they place it — take it out of each, or put another in its place, with save-template {templateId, mode: "edit"}, then delete it.',
                    $id,
                    count( $templates ),
                    implode( '; ', array_map( static fn( $t ) => sprintf( '%d "%s"', $t['id'], $t['title'] ) . ( isset( $t['originalId'] ) ? sprintf( ' (the %s version of %d)', $t['language'], $t['originalId'] ) : '' ), array_slice( $templates, 0, 5 ) ) )
                ),
                [ 'templates' => $templates ]
            );
        }

        $used = SyncedPatterns::placements( $id, 100 );
        if ( $used !== [] && empty( $input['force'] ) ) {
            return new WP_Error(
                'pattern_in_use',
                sprintf(
                    'Pattern %d is placed on %d record(s): %s. Remove those placements first (save-content mode "edit" for a page; save-template mode "edit" for a template), or send force:true — they would then render nothing there.',
                    $id,
                    count( $used ),
                    implode( '; ', array_map( static fn( $r ) => sprintf( '%s %d "%s"', $r['postType'], $r['id'], $r['title'] ), array_slice( $used, 0, 5 ) ) )
                ),
                [ 'usedBy' => $used ]
            );
        }

        // What the other languages see afterwards, read BEFORE the row goes.
        $note = '';
        if ( \Fabrement\Modules\Translation\Translation::is_multilingual() ) {
            $bridge   = \Fabrement\Modules\Translation\Translation::bridge();
            $group    = $bridge->visible_versions( $id, SyncedPatterns::POST_TYPE );
            $language = \Fabrement\Modules\Translation\Translation::live_language_of( $id, SyncedPatterns::POST_TYPE );
            $others   = array_diff_key( $group, [ $language => true ] );
            if ( ! $bridge->is_original( $id, SyncedPatterns::POST_TYPE ) ) {
                $note = sprintf( 'This was the %s version: pages in %s now show the original\'s words in this place.', $language, $language );
            } elseif ( $others !== [] ) {
                // The translation plugin promotes one of the versions to an original of its own.
                $note = sprintf(
                    'This was the original. Its versions (%s) are not deleted: the translation plugin makes one of them an original of its own, which list-synced-patterns now shows. Pages placing this id — in every language — no longer show it.',
                    implode( ', ', array_map( static fn( $code, $vid ) => $code . ' ' . (int) $vid, array_keys( $others ), $others ) )
                );
            }
        }

        $title = (string) $post->post_title;
        if ( ! wp_delete_post( $id, true ) ) {
            return new WP_Error( 'delete_failed', sprintf( 'WordPress refused to delete pattern %d.', $id ) );
        }

        return [
            'deleted'   => true,
            'patternId' => $id,
            'title'     => $title,
            'orphaned'  => $used,
        ] + ( $note === '' ? [] : [ 'note' => $note ] );
    }
}
