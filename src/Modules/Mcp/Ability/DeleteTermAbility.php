<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\Mcp\Support\Usages;
use Fabrement\Modules\Taxonomy\TermWriter;
use Fabrement\Modules\Translation\Translation;

/**
 * Remove one term. The posts it classified stay; only the classification goes.
 *
 * Which is why this does NOT refuse over posts: losing a label is visible, reversible by
 * re-tagging, and exactly what the caller asked for. It refuses over one thing only — a template
 * bound to the term's archive. Measured: deleting the term leaves `tax:cuisine:217 => 9328` in the
 * options table, pointing at a term that no longer exists, and nothing ever reads that row again
 * or reports it. The term can be re-created; the binding cannot find it, because a new term gets a
 * new id. So the binding is removed FIRST, deliberately, or not at all.
 */
final class DeleteTermAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/delete-term';
    }

    public function label(): string {
        return 'Delete term';
    }

    public function description(): string {
        return 'Delete a term; posts just lose that label, children move up to the parent. Check `count` in '
            . 'list-terms first. Refused while a template is bound to its archive — unbind it first '
            . '(assign-template-archive), because that binding would otherwise stay pointing at nothing. '
            . 'Refused too while a template draws a BODY for this term (a content-source rule): decide whether '
            . 'that body is still wanted, then remove the rule or pass force:true.'
            . self::multilingual_text( ' Deleting an ORIGINAL leaves its other-language'
                . ' versions in place, as WordPress does with a translated post; the answer says which.' );
    }

    public function contract(): ?string {
        return 'content-model-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'taxonomy', 'term' ],
            'properties'           => [
                'taxonomy' => [ 'type' => 'string', 'description' => 'Taxonomy slug (see list-taxonomies).' ],
                'term'     => [
                    'type'        => [ 'string', 'integer' ],
                    'description' => 'Term id, slug or exact name.',
                ],
                'force'    => [
                    'type'        => 'boolean',
                    'description' => 'Delete even though a template draws a body for this term (a content-source rule), leaving that rule pointing at nothing. Not for the frame binding — that one is released with assign-template-archive and has no force.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'deleted', 'termId', 'taxonomy' ],
            'properties' => [
                'deleted'       => [ 'type' => 'boolean' ],
                'termId'        => [ 'type' => 'integer' ],
                'taxonomy'      => [ 'type' => 'string' ],
                // Declared because they are ANSWERS, not decoration: a caller that got past the
                // refusal with force:true learns here what that cost, and one deleting a
                // translated term learns what WPML did with the other languages. Undeclared
                // fields are ones an agent has no reason to look for.
                'orphanedRules' => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'string' ],
                    'description' => 'Only with force:true — the content-source rules now naming a term id that no longer exists. Nothing renders them; drop them unless the body is wanted for another term.',
                ],
                'note'          => [
                    'type'        => 'string',
                    'description' => 'What survived the deletion and needs a decision: the orphaned rules above' . self::multilingual_text( ', and what happened to the other languages of this term' ) . '.',
                ],
            ],
        ];
    }

    public function execute( array $input ) {
        // Normalised like the writer does, or "Category" skips the guards below and is still deleted.
        $taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
        // Before the guards: their "use force:true" must not be the answer for a menu.
        $refused = TermWriter::refuse_term_taxonomy( $taxonomy );
        if ( $refused !== null ) {
            return $refused;
        }

        // Resolve through the writer's OWN resolver, so the term this checks is the term it deletes.
        $term_id = TermWriter::term_id( $taxonomy, $input['term'] ?? '' );

        if ( $term_id > 0 ) {
            $bridge = Translation::bridge();

            // Bindings are keyed on the ORIGINAL term (ArchiveTemplateBinding::current_context()
            // resolves through originalTermId), so deleting a LANGUAGE VERSION takes nothing with
            // it: the binding still names a term that still exists, and that language's archive
            // falls back to the original's frame. Only the original's deletion orphans it.
            $original = $bridge->original_term_id( $term_id, $taxonomy );
            if ( $original === $term_id ) {
                $contexts = Usages::term_contexts( $term_id, $taxonomy );
                $used     = Usages::term( $term_id, $taxonomy );

                if ( $used !== [] ) {
                    // The call itself, filled in, not just the tool's name. This refusal is read by
                    // an agent, and the difference decides what happens next: a NAME makes it look
                    // up the context, guess its shape and often reach for the slug the tool does
                    // not take, while a ready call is one step. The refusal stays a refusal — the
                    // point is that a term's styling is given up out loud, not that it is hard.
                    $unbind = [];
                    foreach ( $contexts as $context ) {
                        $unbind[] = sprintf( 'assign-template-archive {context:"%s", templateId:0}', $context );
                    }

                    return ToolResult::error(
                        'term_in_use',
                        sprintf(
                            'Still bound: %s. Release it first — %s — then delete the term. The template itself is NOT deleted by that call and stays available to everything else, and the archive of this term falls back to the taxonomy-wide frame. A binding left behind names a term id that no longer exists, and re-creating the term does not restore it, because the new one gets a new id.',
                            implode( '; ', $used ),
                            implode( ', ', $unbind )
                        ),
                        [
                            'usedBy'   => $used,
                            'contexts' => $contexts,
                            'unbind'   => $unbind,
                        ]
                    );
                }

                // The BODY, after the frame. A rule names a theme file for this term's archive,
                // and unlike a binding it breaks nothing when it is left: the term is gone, so the
                // view is gone, and the rule simply never matches again. What is lost is the
                // DECISION — "this term gets that body" — and it is unrecoverable for the same
                // reason the binding is: the term comes back with a new id.
                //
                // So it is refused, but with a way past. `force` belongs here and not on the binding above: releasing a binding
                // is one call the refusal hands over ready to send, while a rule may be one of
                // several in a template somebody has to look at first.
                $orphaned = Usages::term_rules( $term_id, $taxonomy );
                if ( $orphaned !== [] && empty( $input['force'] ) ) {
                    return ToolResult::error(
                        'term_body_in_use',
                        sprintf(
                            'A template draws a body for this term: %s. Check whether that body is still wanted — ask whoever owns the site if it is not obvious — then either drop the rule from the template (save-template {templateId, contentSource} with the rule removed) or send this again with force:true. Left in place, the rule stays in the template naming a term id that no longer exists: harmless to render, because the view goes with the term, but the choice it records cannot be recovered — a re-created term gets a new id.',
                            implode( '; ', $orphaned )
                        ),
                        [ 'drawnBy' => $orphaned ]
                    );
                }
            }
        }

        $orphaned = $orphaned ?? [];

        // BEFORE the delete. Measured: deleting a term removes its icl_translations row, so asking
        // afterwards which languages it had answers "none" — the versions are still there, and the
        // note that should have named them would silently never appear.
        $others = $term_id > 0 ? self::other_versions( $term_id, $taxonomy ) : [];

        $result = TermWriter::delete_term( $taxonomy, $input['term'] ?? '' );

        // What happens to the OTHER LANGUAGES is WPML's decision, not ours, and it is a setting:
        // WPML_Term_Actions::delete_term_actions() either deletes every translation with the
        // original (`sync_delete_tax` on) or PROMOTES the next language to be the new original
        // (off — measured on this site: the pl row's source_language_code became NULL and
        // original_term_id() then answered with itself). Both are correct behaviours of a choice the
        // site owner already made, so this reports what is left instead of overriding it.
        // Forced past the refusal: name what it cost. The rule is still in the template, pointing
        // at an id nothing answers to, and nobody will go looking for it — the view it drew stopped
        // existing with the term, so nothing renders wrong to give it away.
        if ( ! is_wp_error( $result ) && $orphaned !== [] ) {
            $result['orphanedRules'] = $orphaned;
            $result['note']          = 'Left pointing at the deleted term: ' . implode( '; ', $orphaned )
                . '. Nothing renders it now, and a re-created term gets a new id, so drop the rule'
                . ' (save-template {templateId, contentSource}) unless the body is wanted for another term.';
        }

        if ( ! is_wp_error( $result ) && $others !== [] ) {
            // WP_Term::get_instance, NOT get_term(). The question here is "does this ROW still
            // exist", and get_term() does not answer it: it answers "which term of this group
            // should this language see", so it can hand back a WP_Term for an id WPML has just
            // deleted — and the note then tells the caller to go and delete a term that is gone.
            // The inverse is as bad: a version that IS there can resolve to nothing in the
            // request's language, and the note claims it went with the original.
            $survivors = array_values( array_filter( $others, static function ( $entry ) use ( $taxonomy ) {
                $id = (int) substr( (string) $entry, strpos( (string) $entry, ':' ) + 1 );

                return \WP_Term::get_instance( $id, $taxonomy ) instanceof \WP_Term;
            } ) );

            $prefix         = isset( $result['note'] ) ? $result['note'] . ' ' : '';
            $result['note'] = $prefix . ( $survivors === []
                ? 'Its other-language versions went with it — WPML is set to delete translations along with the original (sync_delete_tax).'
                : 'Still there in other languages: ' . implode( ', ', $survivors ) . '. WPML kept them and promoted one to be'
                    . ' the new original of the group; delete them by their own ids if they should go too.' );
        }

        return $result;
    }

    /**
     * The term's versions in other languages, as `code:id`.
     *
     * version_in() per language, the way translate-term reports a group: the bridge has no "every
     * version of this term" call, and a term id resolves per language through whichever exists.
     *
     * @return list<string>
     */
    private static function other_versions( int $term_id, string $taxonomy ): array {
        if ( ! Translation::is_multilingual() ) {
            return [];
        }

        $bridge = Translation::bridge();
        $out    = [];

        foreach ( $bridge->languages() as $language ) {
            $code = (string) ( $language['code'] ?? '' );
            $id   = $code !== '' ? $bridge->version_in( $term_id, $taxonomy, $code ) : 0;
            if ( $id > 0 && $id !== $term_id ) {
                $out[] = $code . ':' . $id;
            }
        }

        return $out;
    }
}
