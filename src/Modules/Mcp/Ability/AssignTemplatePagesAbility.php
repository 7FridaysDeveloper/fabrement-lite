<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplatePages;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Set which records explicitly use a template. `mode` decides how pageIds is read:
 * replace makes it the exact set and every record it omits falls back to its type's
 * default; add and remove touch only the ids passed. `mode` is REQUIRED: a replace the
 * caller did not ask for silently strips the template from every other page. Thin adapter over
 * {@see SiteTemplatePages::reconcile()}.
 */
final class AssignTemplatePagesAbility extends AbstractAbility {

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
            . 'of any public type, not just the ones that take a template by default. For a whole-type frame '
            . 'use set-default-template instead.'
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
                        . self::multilingual_text( ' A page is written in every language whose version is LIVE, so one page reads 1 until it is translated, 2 once it is, and 1 again if that translation goes to the bin — the number tracks what was written, not the size of the group.' ),
                ],
                'mode'      => [ 'type' => 'string', 'description' => 'The mode this call ran in.' ],
                'cleared'   => [
                    'type'        => 'integer',
                    'description' => 'Records released from THIS template. One that changed hands to another template is in `tookFrom`, not here. It counts records INCLUDING any in the trash, which `pageCount` never counts — so clearing a binned pick moves this and leaves `pageCount` where it was.'
                        . self::multilingual_text( ' Counted per record, like `assigned`, not per translation group.' ),
                ],
                'pageCount' => [
                    'type'        => 'integer',
                    'description' => 'How many records carry this template AFTER the call — the whole set, not just what this call wrote, so it is normally the larger of the two.'
                        . self::multilingual_text( ' Counted once per translation GROUP, while `assigned` counts records, so a single translated page can make `assigned` the larger instead.' ),
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

        // Does this call GIVE the template to anything? `remove`, and a `replace` with no ids, only
        // take it away - and taking it away is exactly what somebody does about a template that has
        // stopped being ready. Refusing that left every record it held stuck on a broken frame with
        // no way out but repairing the block, which is not what the caller was asking for.
        $assigns = $mode !== SiteTemplatePages::MODE_REMOVE && $page_ids !== [];


        // Only a call that WRITES needs its ids to be writable. `remove` is how a pick is taken off
        // a record that has since been binned - carriers_of() reaches into the trash for exactly
        // that - so refusing a trashed id here made the one stale pick this tool exists to clear
        // reachable only by a caller who already knew the whole remaining set.
        $refusal = self::refuse_uncoverable( $page_ids, $assigns );
        if ( $refusal instanceof WP_Error ) {
            return $refusal;
        }

        $result = SiteTemplatePages::reconcile( $record->slug, $page_ids, $mode );
        $moved  = (array) ( $result['moved'] ?? [] );
        unset( $result['moved'] );

        $out = $result + [
            'mode'      => $mode,
            'pageCount' => SiteTemplatePages::counts()[ $record->slug ] ?? 0,
        ];

        // TOOK IT FROM SOMEWHERE. A record wears one template, so giving it this one removes
        // whatever it had. Correct, and invisible in the counters: `cleared` means released from
        // THIS template and stays 0, so a caller adding a page it had not read first would
        // re-frame a live page and see nothing about it.
        if ( $moved !== [] ) {
            $ids   = [];
            $parts = [];
            // WHAT IT LEFT IS NOT ALWAYS SOMETHING THIS TOOL CAN GIVE BACK. The slug on a record
            // is whatever WordPress's own template picker wrote: one of ours, a file the THEME
            // provides, or a slug of ours whose template has since been unpublished or deleted.
            // Each answers "how do I undo this?" differently, so each carries its OWN sentence.
            // One verdict for the whole set advised assign-template-pages for a theme file the
            // moment a single recoverable template shared the answer.
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

                // find() is publish-only, so it answers null for a template that still exists as a
                // draft or in the trash. Calling one of those "no longer exists" sends somebody
                // hunting for a record that is one click from being back.
                $givable = SiteTemplateRegistry::find_assignable( $from_id );
                $any     = SiteTemplateRegistry::find_any( $from_id );

                if ( $givable !== null ) {
                    /* translators: 1: comma-separated ids, 2: template title, 3: template id. */
                    $parts[] = sprintf( __( '%1$s left "%2$s" — put one back with assign-template-pages {templateId: %3$d, mode: "add"}', 'fabrement-lite' ), $who, $givable->title, $from_id );
                } elseif ( $any !== null ) {
                    // WHY it cannot be given decides what to advise. A draft is one click from
                    // usable; a language VERSION never becomes assignable at all, and telling
                    // somebody to wait for it to become one is advice that can never be followed.
                    //
                    // ASK THE ROLE, NOT THE LANGUAGE. original_id() exists because "the default
                    // language's version" is a different question with a different answer: a
                    // template authored in Polish has a Polish ORIGINAL and an English version,
                    // and reading the group by the site's default language returns the version —
                    // for one group, the very id that had just been refused. Its docblock names
                    // two callers that got this wrong; this is not the third.
                    $is_version = Translation::bridge()->is_active()
                        && ! Translation::bridge()->is_original( $from_id, SiteTemplatePostType::POST_TYPE );
                    $original   = $is_version ? SiteTemplateRegistry::original_id( $from_id ) : 0;

                    if ( $is_version && $original > 0 && $original !== $from_id ) {
                        /* translators: 1: comma-separated ids, 2: template title, 3: the original template's id. */
                        $parts[] = sprintf( __( '%1$s left "%2$s", which is one language\'s version — a template is given out through its ORIGINAL, id %3$d', 'fabrement-lite' ), $who, $any->title, $original );
                    } else {
                        // Every template is published, so the only way to exist and still not be
                        // assignable is to be one language's version.
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
     * Refuse ids the assignment could never reach — which now means ids that are not records.
     *
     * It used to refuse a record whose post type was not on a separate covered-types list, because
     * reconcile() walked only those types and the call would otherwise answer `{assigned:0}` — the
     * shape of a success for a write that did nothing. That is no longer the rule: a per-record
     * pick outranks the covered-types list everywhere else (the editor offers the dropdown on every
     * type, the renderer honours the meta on every type), so refusing it here would leave this tool
     * weaker than the screen it mirrors.
     *
     * What remains worth refusing is an id with no record behind it, or one pointing at something a
     * template cannot dress at all — an attachment, a revision, the template post type itself.
     * Those cannot be the caller's intent, and answering "assigned: 0" for them says nothing.
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
            // STATUS IS THE OTHER HALF of "could never reach" — but only for a call that WRITES.
            // reconcile() assigns to the statuses an editor shows, so a trashed id would come back
            // {assigned:0}, the shape of a success, for an id the caller named on purpose. Taking a
            // template OFF a trashed record is the opposite: it is the one way to clear a pick made
            // before the record was binned, and carriers_of() reaches there deliberately.
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
