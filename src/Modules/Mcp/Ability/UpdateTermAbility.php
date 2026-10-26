<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Taxonomy\TermWriter;

/**
 * Edit an existing term in ANY registered taxonomy. The term id is stable, so every post
 * keeps its assignment — unlike delete + create, which strands them.
 */
final class UpdateTermAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/update-term';
    }

    public function label(): string {
        return 'Update term';
    }

    public function description(): string {
        return 'Rename a term, change its slug/description or move it under another parent (0 = top level); '
            . 'posts keep their assignment. A slug or parent change moves the archive URL — the response `note` '
            . 'says what moved.';
    }

    public function contract(): ?string {
        return 'content-model-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'taxonomy', 'term' ],
            'properties'           => [
                'taxonomy'    => [ 'type' => 'string', 'description' => 'Taxonomy slug (see list-taxonomies).' ],
                'term'        => [
                    'type'        => [ 'string', 'integer' ],
                    'description' => 'The term to edit — id, slug or name.',
                ],
                'name'        => [ 'type' => 'string' ],
                'slug'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'parent'      => [
                    'type'        => [ 'string', 'integer' ],
                    'description' => 'New parent (id, slug or name); 0 makes it top-level. Hierarchical taxonomies only.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'termId', 'taxonomy', 'name' ],
            'properties' => [
                'termId'   => [ 'type' => 'integer' ],
                'taxonomy' => [ 'type' => 'string' ],
                'name'     => [ 'type' => 'string' ],
                'slug'     => [ 'type' => 'string' ],
                'parent'   => [ 'type' => 'integer' ],
                'url'      => [ 'type' => 'string' ],
                'changed'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'note'     => [ 'type' => 'string', 'description' => 'Present whenever the archive URL moved — a slug change, a parent change, or both. Says the old URL now 404s and how many descendant terms moved with it.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        // Same normalisation the writer applies.
        $taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );

        // The writer snapshots the term before the write and hands back previousSlug —
        // one resolution order, one source of truth. That also catches the rename
        // nobody asked for: a parent move that collides with a sibling slug.
        $result = TermWriter::update_term( $taxonomy, $input['term'] ?? '', $input );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $old_slug = (string) ( $result['previousSlug'] ?? '' );
        unset( $result['previousSlug'] );

        // A MOVE, not a rename. The archive URL is the whole chain of ancestors plus the slug, so
        // re-parenting moves it exactly as renaming does — /topic/old-parent/child/ becomes
        // /topic/new-parent/child/, the old one 404s, and WordPress redirects neither. Keyed on
        // the slug alone, this said nothing at all for a parent move: `changed: ["parent"]` came
        // back looking like a tidy-up.
        $slug_moved   = $old_slug !== '' && $old_slug !== ( $result['slug'] ?? '' );
        $parent_moved = in_array( 'parent', (array) ( $result['changed'] ?? [] ), true );
        if ( ! $slug_moved && ! $parent_moved ) {
            return $result;
        }

        $note = self::moved_note( $taxonomy, (int) ( $result['termId'] ?? 0 ), $slug_moved, $parent_moved );
        if ( $note !== '' ) {
            $result['note'] = $note;
        }
        return $result;
    }

    /**
     * What moved, and what it took with it.
     *
     * Three things move an archive URL and only one of them used to be reported: the slug, the
     * parent, and — for every DESCENDANT — an ancestor's slug or parent. A term deeper in the tree
     * gets no answer of its own, so the one the caller does get has to name them.
     *
     * Template rules and bindings are keyed by term ID, which a move of either kind leaves alone.
     */
    private static function moved_note( string $taxonomy, int $term_id, bool $slug_moved, bool $parent_moved ): string {
        $what = $slug_moved && $parent_moved
            ? 'The slug and the parent both changed'
            : ( $slug_moved ? 'The slug changed' : 'The parent changed' );

        $note = $what . ': the term archive URL moved and the old one now 404s (WordPress does not redirect it) — update any menu/content links.';

        // Children ride along, and nothing else tells the caller so: they are not in this answer,
        // and asking for one gives no hint that its address was rewritten by an ancestor.
        $children = $term_id > 0 ? get_term_children( $term_id, $taxonomy ) : array();
        if ( is_array( $children ) && $children !== array() ) {
            $note .= sprintf(
                ' The %d term(s) under it moved with it — their URLs are rebuilt from this one, and their old addresses 404 too (list-terms {taxonomy:"%s"} for the new ones).',
                count( $children ),
                $taxonomy
            );
        }

        return $note;
    }
}
