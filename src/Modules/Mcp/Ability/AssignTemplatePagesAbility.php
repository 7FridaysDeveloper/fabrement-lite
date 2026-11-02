<?php

namespace Fabrement\Modules\Mcp\Ability;

defined( 'ABSPATH' ) || exit;

use Fabrement\Capabilities;
use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplatePages;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateTranslator;
use Fabrement\Modules\SiteTemplate\Service\SyncedPatterns;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Set which records explicitly use a template. `mode` decides how pageIds is read:
 * replace makes it the exact set and every record it omits falls back to its type's
 * default; add and remove touch only the ids passed. `mode` is REQUIRED: a replace the
 * caller did not ask for silently strips the template from every other page. Every record
 * the call would change — translations and records a replace releases included — must be
 * the user's to edit (edit_post). Thin adapter over {@see SiteTemplatePages::reconcile()}.
 */
final class AssignTemplatePagesAbility extends AbstractAbility {

    public function check_permission() {
        return parent::check_permission() && current_user_can( Capabilities::TEMPLATES );
    }

    public function name(): string {
        return 'fabrement/assign-template-pages';
    }

    public function label(): string {
        return 'Assign template pages';
    }

    public function description(): string {
        return 'Give records this template, or take it away. `mode` is required. add / remove touch only the ids you pass; '
            . 'replace makes pageIds the exact set and releases every other record that '
            . 'had it — use it when you know the whole set, and list-template-pages first. Works on a record '
            . 'of any public type. A post type\'s default template is set in wp-admin, not here.'
            . self::multilingual_text( ' A page id covers the page in every language — pass the ORIGINAL\'s id, its translations follow.' );
    }

    public function contract(): ?string {
        return 'template-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'templateId', 'pageIds', 'mode' ],
            'properties'           => [
                'templateId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Template id from list-templates.' ],
                'pageIds'    => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'integer', 'minimum' => 1 ],
                    'description' => 'The ids this call is about. With mode replace this is the FULL set and anything missing from it loses the template; with add or remove it is only the records to change.',
                ],
                'mode'       => [
                    'type'        => 'string',
                    'enum'        => [ 'replace', 'add', 'remove' ],
                    'description' => 'Required — there is no default. replace: the set becomes exactly pageIds, and every other record picking this template is released — send it only when you know the whole set. add: these records take the template, everything else is left alone. remove: these records release it, everything else is left alone.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'assigned', 'cleared', 'pageCount', 'mode' ],
            'properties' => [
                'assigned'  => [
                    'type'        => 'integer',
                    'description' => 'Records this call WROTE the template to. One that already carried it is not counted again.'
                        . self::multilingual_text( ' A page is written in every language whose version is live, so one page reads 1 until it is translated and 2 once it is.' ),
                ],
                'mode'      => [ 'type' => 'string', 'description' => 'The mode this call ran in.' ],
                'cleared'   => [
                    'type'        => 'integer',
                    'description' => 'Records released from THIS template, those in the trash included. One that changed hands to another template is in `tookFrom`, not here.',
                ],
                'pageCount' => [
                    'type'        => 'integer',
                    'description' => 'How many records carry this template AFTER the call — the whole set, not just what this call wrote.'
                        . self::multilingual_text( ' Counted once per translation group, while `assigned` counts records.' ),
                ],
                'tookFrom'  => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'integer' ],
                    'description' => 'Records that were carrying a DIFFERENT template and now carry this one. Absent when none did.',
                ],
                'note'      => [ 'type' => 'string', 'description' => 'Present with tookFrom: what each record left, and how to put it back when that is possible.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        $template_id = (int) ( $input['templateId'] ?? 0 );
        $record      = SiteTemplateRegistry::find_assignable( $template_id );
        if ( $record === null ) {
            return new WP_Error( 'fabrement_st_not_found', SiteTemplateRegistry::not_assignable_message( $template_id ), [ 'status' => 404 ] );
        }
        $page_ids = array_values( array_unique( array_map( 'intval', (array) ( $input['pageIds'] ?? [] ) ) ) );
        $mode     = (string) $input['mode'];

        // `remove`, and a `replace` with no ids, only take the template away.
        $assigns = $mode !== SiteTemplatePages::MODE_REMOVE && $page_ids !== [];

        // Only a call that WRITES needs its ids to be writable: `remove` may clear a binned record.
        $refusal = self::refuse_uncoverable( $page_ids, $assigns );
        if ( $refusal instanceof WP_Error ) {
            return $refusal;
        }

        // A pick is the record's own setting: the edit_post the editor's Template dropdown needs,
        // on every record this call would change.
        $locked = SiteTemplatePages::not_editable( $record->slug, $page_ids, $mode );
        if ( $locked !== [] ) {
            $listed = SyncedPatterns::refusal_list(
                array_map( static fn( int $id ): array => [ 'id' => $id, 'title' => get_the_title( $id ) ], $locked ),
                static fn( array $p ): string => sprintf( '%d "%s"', $p['id'], $p['title'] )
            );
            return ToolResult::error(
                'forbidden',
                sprintf( 'Nothing was changed: this call would change records you may not edit (edit_post): %s.', $listed['text'] ),
                [ 'notEditable' => array_column( $listed['readable'], 'id' ), 'hidden' => $listed['hidden'] ]
            );
        }

        $result = SiteTemplatePages::reconcile( $record->slug, $page_ids, $mode );
        $moved  = (array) ( $result['moved'] ?? [] );
        unset( $result['moved'] );

        $out = $result + [
            'mode'      => $mode,
            'pageCount' => SiteTemplatePages::counts()[ $record->slug ] ?? 0,
        ];

        // A record wears one template: giving it this one takes it off whatever it had, which
        // `cleared` (released from THIS template) does not show. Said here.
        if ( $moved !== [] ) {
            $ids   = [];
            $parts = [];
            // What a record left decides how it is undone, so each carries its own sentence.
            foreach ( $moved as $from_slug => $taken ) {
                $ids     = array_merge( $ids, $taken );
                $from_id = SiteTemplatePostType::id_from_slug( (string) $from_slug );
                $who     = implode( ', ', $taken );

                if ( $from_id === null ) {
                    $prefix = SiteTemplatePostType::SLUG_PREFIX;
                    $ours   = strncmp( (string) $from_slug, $prefix, strlen( $prefix ) ) === 0;
                    $parts[] = $ours
                        /* translators: 1: comma-separated ids, 2: an unparseable Fabrement slug. */
                        ? sprintf( __( '%1$s left "%2$s", a Fabrement slug that names no template — nothing to give back', 'fabrement-lite' ), $who, (string) $from_slug )
                        /* translators: 1: comma-separated ids, 2: a template file name. */
                        : sprintf( __( '%1$s left "%2$s", which this tool did not write and cannot give back — set it again in the editor if it was wanted', 'fabrement-lite' ), $who, (string) $from_slug );
                    continue;
                }

                $givable = SiteTemplateRegistry::find_assignable( $from_id );
                $any     = SiteTemplateRegistry::find_any( $from_id );

                if ( $givable !== null ) {
                    /* translators: 1: comma-separated ids, 2: template title, 3: template id. */
                    $parts[] = sprintf( __( '%1$s left "%2$s" — put one back with assign-template-pages {templateId: %3$d, mode: "add"}', 'fabrement-lite' ), $who, $givable->title, $from_id );
                } elseif ( $any !== null ) {
                    // Every template is published, so existing and not assignable means a language
                    // version: it is given out through its original.
                    $original = Translation::bridge()->is_active() ? SiteTemplateTranslator::original_of( $from_id ) : null;
                    if ( $original !== null && $original->id !== $from_id ) {
                        /* translators: 1: comma-separated ids, 2: template title, 3: the original template's id. */
                        $parts[] = sprintf( __( '%1$s left "%2$s", which is one language\'s version — a template is given out through its ORIGINAL, id %3$d', 'fabrement-lite' ), $who, $any->title, $original->id );
                    } else {
                        /* translators: 1: comma-separated ids, 2: template title, 3: the version's id. */
                        $parts[] = sprintf( __( '%1$s left "%2$s" (id %3$d), one language\'s version — records are handed to the ORIGINAL; read translation.originalId from get-template', 'fabrement-lite' ), $who, $any->title, $from_id );
                    }
                } else {
                    /* translators: 1: comma-separated ids, 2: the slug of a template that is gone. */
                    $parts[] = sprintf( __( '%1$s left a Fabrement template that no longer exists (%2$s)', 'fabrement-lite' ), $who, (string) $from_slug );
                }
            }
            $out['tookFrom'] = array_values( array_unique( array_map( 'intval', $ids ) ) );
            $out['note'] = sprintf(
                /* translators: %s: per-template descriptions of what moved, each with its own remedy. */
                __( 'A record wears one template, so these changed hands: %s.', 'fabrement-lite' ),
                implode( '; ', $parts )
            );
        }

        return $out;
    }

    /**
     * Refuse ids the assignment could never reach: no record, a type a template cannot dress
     * (an attachment, a revision, the template type itself), or — for a call that writes — a
     * status a template does not dress. Otherwise the answer would be `{assigned:0}`, the shape
     * of a success.
     *
     * @param int[] $page_ids
     * @param bool  $writes Whether this call assigns (true) or only releases (false).
     * @return WP_Error|null
     */
    private static function refuse_uncoverable( array $page_ids, bool $writes = true ): ?WP_Error {
        if ( $page_ids === [] ) {
            return null;  // With replace this clears every pick; with add or remove it does nothing.
        }

        $dressable   = array_keys( SiteTemplatePostType::available_post_types() );
        $unknown     = [];
        $undressable = [];
        $not_live    = [];

        foreach ( $page_ids as $id ) {
            $post = get_post( $id );
            if ( ! $post instanceof \WP_Post ) {
                $unknown[] = $id;
                continue;
            }
            if ( ! in_array( $post->post_type, $dressable, true ) ) {
                $undressable[ $post->post_type ][] = $id;
                continue;
            }
            if ( $writes && ! in_array( $post->post_status, SiteTemplatePages::STATUSES, true ) ) {
                $not_live[ $post->post_status ][] = $id;
            }
        }

        if ( $unknown === [] && $undressable === [] && $not_live === [] ) {
            return null;
        }

        $parts = [];
        if ( $unknown !== [] ) {
            $parts[] = sprintf(
                /* translators: %s: comma-separated list of ids. */
                __( 'no record exists for %s', 'fabrement-lite' ),
                implode( ', ', $unknown )
            );
        }
        foreach ( $undressable as $type => $ids ) {
            $parts[] = sprintf(
                /* translators: 1: post type slug, 2: comma-separated ids. */
                __( '"%1$s" is not a type a template can dress (%2$s)', 'fabrement-lite' ),
                $type,
                implode( ', ', $ids )
            );
        }
        foreach ( $not_live as $status => $ids ) {
            $parts[] = sprintf(
                /* translators: 1: post status, 2: comma-separated ids. */
                __( '%2$s is in the %1$s, which a template does not dress', 'fabrement-lite' ),
                $status === 'trash' ? __( 'trash', 'fabrement-lite' ) : $status,
                implode( ', ', $ids )
            );
        }

        return new WP_Error(
            'fabrement_st_pages_not_coverable',
            sprintf(
                /* translators: %s: the reasons. */
                __( 'Nothing was changed: %s. A template dresses public, visitable records — pass ids from list-template-pages.', 'fabrement-lite' ),
                implode( '; ', $parts )
            ),
            [
                'status'             => 409,
                'uncoverablePageIds' => array_values( array_merge( $unknown, ...array_values( $undressable ), ...array_values( $not_live ) ) ),
            ]
        );
    }
}
