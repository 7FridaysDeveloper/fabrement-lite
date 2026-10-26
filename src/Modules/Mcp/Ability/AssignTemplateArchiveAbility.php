<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;

/**
 * Bind a site template to an ARCHIVE view (post-type archive / blog home), or
 * unbind it. Resolution happens at render time — re-binding re-dresses the
 * archive instantly, nothing is written into posts.
 */
final class AssignTemplateArchiveAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/assign-template-archive';
    }

    public function label(): string {
        return 'Assign template to archive';
    }

    public function description(): string {
        return 'Bind a site template to an archive view (context from list-archives: "archive:<postType>" | '
            . '"blog-home" | "tax" | "tax:<taxonomy>" | "tax:<taxonomy>:<termId>"); templateId 0 unbinds. The '
            . 'template\'s contentSource can use an existing theme PHP file for that context (list-theme-templates + save-template); '
            . 'without one the slot shows the posts page\'s own blocks on blog-home and is empty on other archives.';
    }

    public function contract(): ?string {
        return 'template-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'context', 'templateId' ],
            'properties'           => [
                'context'    => [ 'type' => 'string', 'description' => '"archive:<postType>", "blog-home", "tax", "tax:<taxonomy>" or "tax:<taxonomy>:<termId>" (see list-archives). A single term is named by its ID and nothing else — a slug is renamed, and a binding keyed on one stops matching with nothing to say so. Ids come from list-terms or list-archives.' ],
                'templateId' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Template id to bind; 0 unbinds.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'context', 'templateId', 'bound' ],
            'properties' => [
                'context'    => [ 'type' => 'string' ],
                'templateId' => [ 'type' => 'integer' ],
                'bound'      => [ 'type' => 'boolean' ],
                'url'        => [ 'type' => 'string' ],
                'note'        => [ 'type' => 'string', 'description' => 'Present when the call changed nothing — an unbind of a view that was not bound.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        $context     = sanitize_text_field( (string) ( $input['context'] ?? '' ) );
        $template_id = (int) ( $input['templateId'] ?? 0 );

        // A bound archive must point at a REAL template; unbinding (0) is always fine.
        if ( $template_id > 0 ) {
            $record = SiteTemplateRegistry::find_assignable( $template_id );
            if ( $record === null ) {
                return ToolResult::error( 'template_not_found', SiteTemplateRegistry::not_assignable_message( $template_id ) . ' See list-templates.' );
            }
        }

        // WHAT IS STORED CAN ALWAYS BE REMOVED.
        //
        // The checks below ask whether the view named still exists, which is right for BINDING —
        // there is no sense dressing a view nobody can reach. For unbinding they are upside down: a
        // row becomes rubbish exactly when the thing it points at is renamed or deleted, so the
        // deader the row, the more reliably they protected it. Measured on this site,
        // `tax:cuisine:italian` — a term since renamed to `italian-food` — was refused with
        // unknown_term and could be removed by no tool at all, while still able to come alive on
        // the wrong term if that slug were ever reused.
        //
        // Only for a key stored VERBATIM, so a typo in a context is still an error rather than a
        // silent removal of nothing. Nothing is normalised in this branch either: the row has to
        // address itself, or the unbind would rewrite the key and delete a different row.
        $unbinding_stored = $template_id === 0 && isset( ArchiveTemplateBinding::all()[ $context ] );

        // A term-archive context must name a real, PUBLIC taxonomy (private terms have
        // no archive URLs) — and a real term when it goes that deep. Bare "tax" names
        // the view kind itself, so there is nothing to look up.
        if ( ! $unbinding_stored && strncmp( $context, 'tax:', 4 ) === 0 ) {
            $parts   = explode( ':', $context );
            if ( $parts[1] === 'post_format' ) {
                return ToolResult::error( 'post_format', 'post_format is a theme feature, not a browsable classification — list-archives does not offer it and it cannot be bound.' );
            }
            $tax_obj = taxonomy_exists( $parts[1] ) ? get_taxonomy( $parts[1] ) : null;
            if ( $tax_obj === null || $tax_obj === false ) {
                return ToolResult::error( 'unknown_taxonomy', sprintf( 'Taxonomy "%s" does not exist — see list-taxonomies.', $parts[1] ) );
            }
            if ( ! is_taxonomy_viewable( $tax_obj ) ) {
                return ToolResult::error( 'taxonomy_private', sprintf( 'Taxonomy "%s" is not viewable on the front end — its terms have no archive pages to dress.', $parts[1] ) );
            }
            // AN ID, AND ONLY AN ID. A slug is a name somebody renames, and a binding keyed on
            // one stops matching without saying so.
            //
            // WP_Term::get_instance, not get_term(), because get_term() would adjust the id to
            // the reader's language and could accept one belonging to a different version.
            if ( isset( $parts[2] ) && ! ctype_digit( $parts[2] ) ) {
                return ToolResult::error(
                    'term_not_an_id',
                    sprintf( 'Name the term by its ID, not "%s". A slug is renamed and the binding would stop matching with nothing to say so — see list-terms for the id.', $parts[2] )
                );
            }

            $bound = isset( $parts[2] ) ? \WP_Term::get_instance( (int) $parts[2], $parts[1] ) : null;

            if ( isset( $parts[2] ) && ! $bound instanceof \WP_Term ) {
                return ToolResult::error( 'unknown_term', sprintf( 'Term %s does not exist in taxonomy "%s" — see list-terms.', $parts[2], $parts[1] ) );
            }

            // KEYED ON THE ORIGINAL OF THE GROUP, whichever version was named.
            //
            // current_context() maps the queried term to its original before it builds the key, so
            // that every language of one term resolves to the same binding. A key holding a
            // TRANSLATION's id is therefore one nobody ever asks for: measured on cuisine, where
            // 167 is the English original and 168 its Polish version, a reader on EITHER archive
            // emits `tax:cuisine:167`, and `tax:cuisine:168` matches no step of the chain
            // ["tax:cuisine:167", "tax:cuisine", "tax"]. The view falls
            // through to the taxonomy-wide template and nothing reports a thing. Usages::term()
            // looks for the original's key too, so delete-term cannot see such a binding either and
            // leaves it behind as an orphan.
            //
            //
            // EXCEPT when the context as given is bound RIGHT NOW: a key that is already stored has
            // to address itself, or a stale row could never be unbound — the unbind would rewrite
            // the key, delete a different row, and leave the old one in place for good.
            if ( $bound instanceof \WP_Term && ! isset( ArchiveTemplateBinding::all()[ $context ] ) ) {
                $original = \Fabrement\Modules\Translation\Translation::bridge()->original_term_id( (int) $bound->term_id, $parts[1] );
                $context  = strtolower( 'tax:' . $parts[1] . ':' . ( $original > 0 ? $original : (int) $bound->term_id ) );
            }
        }

        // For a post-type archive, the type must exist AND actually have an archive. Skipped for a
        // stored key being unbound, for the reason above: a type deleted since is precisely the
        // case where the row has to go.
        if ( ! $unbinding_stored && strncmp( $context, 'archive:', 8 ) === 0 ) {
            $type   = substr( $context, 8 );
            $object = get_post_type_object( $type );
            if ( $object === null ) {
                return ToolResult::error( 'unknown_post_type', sprintf( 'Post type "%s" does not exist — see list-archives for bindable views.', $type ) );
            }
            if ( empty( $object->has_archive ) ) {
                return ToolResult::error( 'no_archive', sprintf( 'Post type "%s" has no archive (has_archive is off) — see list-archives.', $type ) );
            }
        }

        // Read before the write, so the answer can say what HAPPENED rather than what now IS.
        // `bound:false` is true of a view somebody just unbound and of one nobody ever bound, and
        // the two answers were identical: an agent unbinding the wrong-but-valid context — the
        // taxonomy when a single term was bound, a neighbouring post type — got success and never
        // learned it had cleared nothing.
        $before = ArchiveTemplateBinding::all();

        if ( ! ArchiveTemplateBinding::set( $context, $template_id ) ) {
            return ToolResult::error( 'bad_context', sprintf( 'Invalid context "%s" — use "archive:<postType>", "blog-home", "tax", "tax:<taxonomy>" or "tax:<taxonomy>:<termId>".', $context ) );
        }

        $url = '';
        if ( $context === 'blog-home' ) {
            $url = (string) ( get_post_type_archive_link( 'post' ) ?: home_url( '/' ) );
        } elseif ( strncmp( $context, 'archive:', 8 ) === 0 ) {
            $url = (string) ( get_post_type_archive_link( substr( $context, 8 ) ) ?: '' );
        } elseif ( strncmp( $context, 'tax:', 4 ) === 0 ) {
            $parts = explode( ':', $context );
            if ( isset( $parts[2] ) ) {
                $term = ctype_digit( $parts[2] ) ? \WP_Term::get_instance( (int) $parts[2], $parts[1] ) : null;
            } else {
                // Taxonomy-level binding: a representative term's URL, so the
                // "verify with the URL" step has something to open.
                $terms = get_terms( [ 'taxonomy' => $parts[1], 'number' => 1, 'hide_empty' => false ] );
                $term  = is_array( $terms ) && isset( $terms[0] ) ? $terms[0] : null;
            }
            $link = $term instanceof \WP_Term ? get_term_link( $term ) : '';
            $url  = is_wp_error( $link ) ? '' : (string) $link;
        }

        $out = [
            'context'    => $context,
            'templateId' => $template_id,
            'bound'      => $template_id > 0,
            'url'        => $url,
        ];

        if ( $template_id === 0 && ArchiveTemplateBinding::all() === $before ) {
            $out['note'] = sprintf(
                'Nothing to remove: "%s" was not bound to anything. If you meant a different view, list-archives shows every bindable one and which template it currently wears — a term binding and its taxonomy-wide binding are different rows.',
                $context
            );
        }

        return $out;
    }
}
