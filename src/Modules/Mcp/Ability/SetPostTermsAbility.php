<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ContentGuard;
use Fabrement\Modules\Seo\PrimaryTerm;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Taxonomy\TermWriter;

use WP_Error;

/**
 * Classify a piece of content — put a post in a category, a book in a genre.
 *
 * Without this, taxonomies and terms were half a feature: an agent could register `genre` and
 * fill it with "Fiction", create a Book, and then have no way to say the book IS fiction. The
 * archive it had just built for that genre would list nothing, and nothing in the tool list
 * explained why.
 */
final class SetPostTermsAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/set-post-terms';
    }

    public function label(): string {
        return 'Set post terms';
    }

    public function description(): string {
        return 'Classify one record: attach EXISTING terms (id|slug|name; create-term first) of ONE taxonomy. '
            . '`mode` is REQUIRED and has no default: replace (the terms become exactly `terms`, anything else is '
            . 'dropped; [] clears) · add (these are added, the rest stay) · remove (these come off, the rest stay). '
            . 'The taxonomy must be attached to that post type. `primary` (Yoast SEO, hierarchical taxonomies) picks '
            . 'the main one among the terms of the record — the one its breadcrumbs and category URL use; 0 clears it.';
    }

    public function contract(): ?string {
        return 'content-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            // `mode` has no default: "replace" drops every term of that taxonomy the record
            // already carries, and a caller that only wanted to ADD one has no sign that the
            // others are gone — the response lists what the record holds now, which looks right.
            'required'             => [ 'contentId', 'taxonomy', 'terms', 'mode' ],
            'properties'           => [
                'contentId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The content id (list-content / save-content).' ],
                'taxonomy' => [ 'type' => 'string', 'description' => 'Taxonomy slug (see list-taxonomies).' ],
                'terms'    => [
                    'type'        => 'array',
                    'items'       => [ 'type' => [ 'string', 'integer' ] ],
                    'description' => 'Term ids, slugs or exact names — each must already exist.',
                ],
                'primary'  => [
                    'type'        => [ 'string', 'integer' ],
                    'description' => 'Optional, needs Yoast SEO: the MAIN term (id|slug|name) of a hierarchical taxonomy — one the record has after this call. Yoast uses it for the breadcrumb trail and the category in URLs; without it Yoast takes the deepest term. 0 clears it (back to the deepest term).',
                ],
                'mode'     => [
                    'type'        => 'string',
                    'enum'        => [ 'replace', 'add', 'remove' ],
                    'description' => 'Required — there is no default. replace: the terms of this taxonomy on this '
                        . 'record become exactly `terms`, so anything not listed is DROPPED and [] clears them. '
                        . 'add: these terms are added, the rest stay. remove: these are taken off, the rest stay.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'contentId', 'taxonomy', 'terms' ],
            'properties' => [
                'contentId' => [ 'type' => 'integer' ],
                'taxonomy' => [ 'type' => 'string' ],
                'mode'     => [ 'type' => 'string' ],
                'primary'  => [ 'type' => 'integer', 'description' => 'The main term id when one is set (Yoast); 0 = none.' ],
                'terms'    => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'termId' => [ 'type' => 'integer' ],
                            'name'   => [ 'type' => 'string' ],
                            'slug'   => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        $post_id  = (int) ( $input['contentId'] ?? 0 );
        $taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
        // THE SAME THREE WORDS the other set-taking tools use (assign-template-pages), so an agent
        // does not have to remember a different spelling here. `remove` takes ONE term off without
        // sending every other term back.
        // No default (the input schema requires it): silently replacing was how a call meant to
        // add one term took every other term off the record.
        $mode = (string) $input['mode'];

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return ContentGuard::not_found( $post_id );
        }
        // The per-POST check the other writing tools already make. Classifying someone else's
        // post — or, with replace + [], stripping its terms — is an edit like any other.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error( 'forbidden', sprintf( 'You are not allowed to edit content %d.', $post_id ) );
        }
        $refused = TermWriter::refuse_term_taxonomy( $taxonomy );
        if ( $refused !== null ) {
            return $refused;
        }
        $object = get_taxonomy( $taxonomy );
        if ( $object !== false && ! current_user_can( $object->cap->assign_terms ) ) {
            return new WP_Error( 'forbidden_taxonomy', sprintf( 'You are not allowed to assign "%s" terms.', $taxonomy ) );
        }
        // Attached or not decides whether this has any visible effect. WordPress would happily
        // write the relationship either way, and the term would then exist in the database and
        // nowhere else — no metabox, no archive, no explanation.
        if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
            return new WP_Error(
                'taxonomy_not_attached',
                sprintf(
                    'Taxonomy "%s" does not classify "%s", so the assignment would be invisible everywhere. Use a taxonomy that classifies it (list-taxonomies).',
                    $taxonomy,
                    $post->post_type
                )
            );
        }

        $wants_primary = array_key_exists( 'primary', $input ) && $input['primary'] !== '' && $input['primary'] !== null;
        if ( $wants_primary ) {
            // Yoast's notion, stored where Yoast reads it — pointless without Yoast, and refused
            // before anything is written rather than half-done.
            if ( ! Seo::bridge()->is_active() ) {
                return new WP_Error( 'yoast_inactive', 'A primary term is a Yoast SEO setting and Yoast is not active — ask the site admin to activate it, or leave `primary` out.' );
            }
            if ( ! in_array( $taxonomy, PrimaryTerm::taxonomies( $post->post_type ), true ) ) {
                return new WP_Error( 'no_primary_here', sprintf( 'Yoast keeps a primary term only for hierarchical taxonomies (categories) — "%s" is not one for "%s".', $taxonomy, $post->post_type ) );
            }
        }

        $language = '';
        if ( \Fabrement\Modules\Translation\Translation::is_multilingual() ) {
            $language = \Fabrement\Modules\Translation\Translation::live_language_of( $post_id, $post->post_type );
        }

        $ids = [];
        foreach ( (array) ( $input['terms'] ?? [] ) as $term ) {
            $id = self::term_id( $taxonomy, $term, $language );
            if ( $id === 0 ) {
                return new WP_Error(
                    'term_not_found',
                    sprintf( 'No term "%s" in "%s" — create it with create-term first.', is_scalar( $term ) ? (string) $term : '', $taxonomy )
                );
            }

            // NEGATIVE = found, but not in this post's language. Refused rather than attached,
            // because attaching it is a state WordPress itself never produces: WPML's own handler
            // either creates the missing version (flat taxonomies) or drops the term from the post
            // (hierarchical ones) — it never files a post under another language's term. Refused
            // rather than silently dropped, too: the caller asked for a label and deserves to know
            // it did not land, and the fix is one call away.
            if ( $id < 0 ) {
                return new WP_Error(
                    'term_not_in_language',
                    sprintf(
                        'Term "%s" exists, but has no version in "%s" — the language of content %d. Translate it first: translate-term {termId:%d, language:"%s", name:"…"}. Attaching the original instead would file this post under a term of another language, which no screen would show it in.',
                        is_scalar( $term ) ? (string) $term : '',
                        $language,
                        $post_id,
                        -$id,
                        $language
                    )
                );
            }

            $ids[] = $id;
        }

        // The main term is checked against the terms the record WILL have, before anything is
        // written: a call that cannot be done whole is refused whole.
        $primary = $wants_primary ? self::primary_to_set( $post_id, $taxonomy, $input['primary'], $ids, $mode, $language ) : 0;
        if ( is_wp_error( $primary ) ) {
            return $primary;
        }

        // IN THE POST'S OWN LANGUAGE, the way update_term() and delete_term() already run. The ids
        // above are mapped correctly — term_id() put each one through version_in() — and then
        // wp_set_object_terms() hands them back: the translation plugin adjusts a term id to the
        // language of the REQUEST, so a Polish post written from an English call was filed under
        // the English term. Measured: ids resolved to the pl term, the row came back holding the
        // en one; the identical call inside this wrapper stores the pl id.
        //
        // The interface says exactly this about itself, in the same words, and this is the one
        // write that was not using it.
        $result = \Fabrement\Modules\Translation\Translation::bridge()->in_language(
            $language,
            static function () use ( $post_id, $ids, $taxonomy, $mode ) {
                if ( 'remove' !== $mode ) {
                    return wp_set_object_terms( $post_id, $ids, $taxonomy, 'add' === $mode );
                }

                // WordPress has no "set minus these": wp_remove_object_terms() is the one call that
                // takes terms off without touching the others, and it answers true/false rather
                // than the resulting list, so the read-back below reports what is left.
                $removed = wp_remove_object_terms( $post_id, $ids, $taxonomy );

                return is_wp_error( $removed ) ? $removed : $ids;
            }
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        // Fabrement changed the record's terms: the SEO plugin's integration drops a main term the
        // record no longer holds (before a new one is chosen below).
        \Fabrement\Modules\Seo\TermChanges::announce( $post_id, $taxonomy );

        // READ BACK IN THE SAME LANGUAGE. wp_get_object_terms() is adjusted like everything else,
        // so an English request reported the English sibling of what it had just filed: the answer
        // named term 388 "Alpha" while the row held 389 "Alfa". The caller checks its work against
        // this list, and a list describing another language's terms cannot be checked against.
        $current = \Fabrement\Modules\Translation\Translation::bridge()->in_language(
            $language,
            static function () use ( $post_id, $taxonomy ) {
                return wp_get_object_terms( $post_id, $taxonomy );
            }
        );

        // Choosing one runs after the terms are on the record and the old choice was checked.
        if ( $wants_primary ) {
            PrimaryTerm::set( $post_id, $taxonomy, $primary );
        }
        $primary = Seo::bridge()->is_active() ? PrimaryTerm::get( $post_id, $taxonomy ) : 0;

        return [
            'contentId' => $post_id,
            'taxonomy' => $taxonomy,
            'mode'     => $mode,
            'primary'  => $primary,
            'terms'    => is_array( $current )
                ? array_map(
                    static fn( $term ) => [
                        'termId' => (int) $term->term_id,
                        'name'   => (string) $term->name,
                        'slug'   => (string) $term->slug,
                    ],
                    $current
                )
                : [],
        ];
    }

    /**
     * The main term `primary` names, checked against the record's terms after this call; 0 clears
     * it.
     *
     * @param mixed $primary id, slug or name; 0 / "0" clears
     * @param int[] $ids     the terms this call sends, already resolved in `$language`
     * @return int|WP_Error
     */
    private static function primary_to_set( int $post_id, string $taxonomy, $primary, array $ids, string $mode, string $language ) {
        if ( $primary === 0 || $primary === '0' ) {
            return 0;
        }
        $id    = self::term_id( $taxonomy, $primary, $language );
        $label = is_scalar( $primary ) ? (string) $primary : '';
        if ( $id === 0 ) {
            return new WP_Error( 'term_not_found', sprintf( 'No term "%s" in "%s" for `primary` — nothing was written.', $label, $taxonomy ) );
        }
        if ( $id < 0 ) {
            return new WP_Error( 'term_not_in_language', sprintf( 'Term "%s" has no version in "%s", the language of content %d — nothing was written. Translate it first: translate-term {termId:%d, language:"%s", name:"…"}.', $label, $language, $post_id, -$id, $language ) );
        }
        $held  = array_map(
            'intval',
            (array) \Fabrement\Modules\Translation\Translation::bridge()->in_language(
                $language,
                static fn() => wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] )
            )
        );
        $after = $mode === 'replace' ? $ids : ( $mode === 'add' ? array_merge( $held, $ids ) : array_diff( $held, $ids ) );
        if ( in_array( $id, array_map( 'intval', $after ), true ) ) {
            return $id;
        }
        return new WP_Error(
            'primary_not_assigned',
            sprintf(
                'The primary term "%s" is not one of the "%s" terms the record would have after this call — nothing was written. Send `primary` with one of them (or add it to `terms`).',
                $label,
                $taxonomy
            )
        );
    }

    /**
     * A term id from an id, slug or name. 0 when nothing matches.
     *
     * @param string $language the language of the POST being classified, '' on a single-language site
     */
    private static function term_id( string $taxonomy, $term, string $language = '' ): int {
        $resolve = static function () use ( $taxonomy, $term ) {
            if ( is_numeric( $term ) ) {
                $found = get_term( (int) $term, $taxonomy );

                return $found instanceof \WP_Term ? $found->term_id : 0;
            }
            foreach ( [ 'slug', 'name' ] as $field ) {
                $found = get_term_by( $field, (string) $term, $taxonomy );
                if ( $found instanceof \WP_Term ) {
                    return $found->term_id;
                }
            }

            return 0;
        };

        if ( $language === '' || ! \Fabrement\Modules\Translation\Translation::is_multilingual() ) {
            return (int) $resolve();
        }

        // RESOLVED IN THE POST'S LANGUAGE. A term belongs to a language, and attaching the wrong
        // language's version puts the post in an archive nobody browsing that language will see —
        // and leaves its own language's archive without it. Names and slugs are looked up among
        // that language's terms; an id given for another language's version is mapped to it.
        $bridge = \Fabrement\Modules\Translation\Translation::bridge();
        $found  = (int) $bridge->in_language( $language, $resolve );
        if ( $found <= 0 ) {
            return 0;
        }

        // version_in() with a NAMED language answers 0 rather than falling back — the caller asked
        // for that version and the bridge refuses to substitute another (see its contract). So 0
        // here means "this term exists, just not in the post's language", which is a different
        // thing from "no such term" and must not be reported as one.
        //
        // A term of a taxonomy that is NOT part of the language split does not reach that 0 — it
        // has one version serving every language, and that version is the answer. Measured on
        // WPML, since the difference decides whether this refuses a taxonomy nobody can translate:
        //
        //   category        36  translated tax, no pl version -> versionIn(pl) = 0    -> refused
        //   category         1  translated tax, pl version 38 -> versionIn(pl) = 38
        //   plugin_category 26  taxonomy not translated       -> versionIn(pl) = 26   -> attached
        //   format          62  term carries no language      -> versionIn(pl) = 62   -> attached
        //
        // A bridge that answered 0 for the last two would leave those taxonomies unusable here:
        // this would refuse with term_not_in_language and send the caller to translate-term, which
        // refuses the same taxonomy right back. "No language split" must mean "the same id".
        $mapped = $bridge->version_in( $found, $taxonomy, $language );

        return $mapped > 0 ? $mapped : -$found;
    }
}
