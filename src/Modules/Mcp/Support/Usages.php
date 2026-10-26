<?php

namespace Fabrement\Modules\Mcp\Support;

use Fabrement\Framework\Keys;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding;
use Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\Translation\Translation;

/**
 * Who still points at a thing, in words — the answer a delete tool owes before it deletes.
 *
 * Nothing on a Fabrement site stands alone. A template is pointed at by pages and archive views,
 * a term by the bindings and rules keyed on it. Delete the thing and
 * the pointer stays, aimed at nothing: an empty content slot, a page that lost its frame. None of
 * that announces itself — a missing binding looks exactly like one that was never made — so it is
 * found by opening the page, days later.
 *
 * delete-media already worked this way and was the only one that did. Two of its decisions are kept
 * here because they are the right ones:
 *
 *   - answers sized to what they are. Most of these lists are bounded by CONFIGURATION — a type
 *     has one default, a view has one binding, a template has a handful of rules — so they are
 *     given in full: a truncated refusal is worse than a long one, because the caller repoints
 *     what it was shown, forces past the rest, and finds out from a broken page. The one list
 *     that is not bounded — the pages that chose a template — is counted, not named.
 *   - err toward reporting. A false positive costs a re-read, a false negative breaks a live page,
 *     and those are not the same size of mistake.
 *
 * Every method answers with plain sentences, not ids, because the answer is read by whoever is
 * deciding whether to go ahead.
 *
 * @return list<string> is the shape of all of them.
 */
final class Usages {

    /** @return list<array{file:string,context:string,said:string}> */
    private static function rule_index(): array {
        $index = [];
        $seen = [];
        // One manifest per translation group. Re-read on every check after tool writes.
        foreach ( SiteTemplateRegistry::all() as $record ) {
            $key = $record->manifest_key;
            if ( $key !== '' && isset( $seen[$key] ) ) { continue; }
            if ( $key !== '' ) { $seen[$key] = true; }
            foreach ( ContentSourceConfig::read( $record->id )['rules'] as $rule ) {
                $index[] = [
                    'file' => $rule['file'], 'context' => $rule['context'],
                    'said' => sprintf( 'the "%s" view of template "%s" (id %d)', $rule['context'], $record->title, $record->id ),
                ];
            }
        }
        return $index;
    }

    /**
     * Content-source rules whose CONTEXT matches — the views a template draws for this subject.
     *
     * A rule is not a binding: it names its subject by SLUG ("singular:book", "tax:cuisine"), so it
     * survives the subject going away and works again if the slug comes back. That makes it
     * something to REPORT and never to clean up — the opposite of a binding, which holds a template
     * id and cannot heal itself.
     *
     * @param list<string> $contexts exact context keys to look for.
     * @return list<string>
     */
    private static function rules( array $contexts ): array {
        $out = [];

        foreach ( self::rule_index() as $rule ) {
            if ( ! in_array( $rule['context'], $contexts, true ) ) {
                continue;
            }

            $out[] = $rule['said'] . ' draws it with ' . $rule['file'];
        }

        return $out;
    }

    /** Pages, post-type defaults and archive views a template still dresses. */
    public static function template( int $template_id ): array {
        $out = [];

        $record = SiteTemplateRegistry::find_any( $template_id );
        if ( $record === null ) {
            return $out;
        }

        // A LANGUAGE VERSION is pointed at by nothing, by design: pages and archives resolve on the
        // ORIGINAL's id and the reader is handed the version afterwards. So every check below finds
        // nothing for one — and [] is the right answer, because [] means "nothing holds this" and
        // that is true.
        //
        // It used to return a SENTENCE here, saying the version can go and its readers fall back.
        // True, and the wrong shape: every method of this class means non-empty as "something still
        // points at it, refuse", and four delete tools read it that way. A sentence that means the
        // opposite made the one value in the class that has to be interpreted before it is used —
        // and the next caller to follow the contract would refuse to delete any language version on
        // the site. What a version costs to delete is {@see version_of()}, asked separately.
        if ( ! Translation::bridge()->is_original( $template_id, SiteTemplatePostType::POST_TYPE ) ) {
            return [];
        }

        foreach ( (array) get_option( Keys::OPTION_TEMPLATE_TYPE_DEFAULTS, [] ) as $type => $id ) {
            if ( (int) $id === $template_id ) {
                $out[] = sprintf( 'the default for post type "%s"', (string) $type );
            }
        }

        foreach ( ArchiveTemplateBinding::all() as $context => $id ) {
            if ( (int) $id === $template_id ) {
                $out[] = sprintf( 'the archive view "%s"', (string) $context );
            }
        }

        // Pages pick a template the way WordPress picks any page template — by its slug in
        // _wp_page_template — so this counts them rather than naming all of them.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- usage check before a delete: counts pages of every type and status but the trash that picked this template, read live.
        $pages = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} m
                 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status != 'trash'
                 WHERE m.meta_key = '_wp_page_template' AND m.meta_value = %s",
                $record->slug
            )
        );
        if ( $pages > 0 ) {
            $out[] = sprintf( '%d page(s) that chose it explicitly', $pages );
        }

        return $out;
    }

    /**
     * What a LANGUAGE VERSION of a template costs to delete, or '' when it is not one.
     *
     * Separate from {@see template()} on purpose. That method answers one question — "does anything
     * still hold this" — and its answer is read as a yes/no by every caller. This is advice, and
     * advice that reads like a refusal is how a tool ends up refusing something it meant to allow.
     */
    public static function version_of( int $template_id ): string {
        $bridge = Translation::bridge();
        if ( $bridge->is_original( $template_id, SiteTemplatePostType::POST_TYPE ) ) {
            return '';
        }

        $language = Translation::live_language_of( $template_id, SiteTemplatePostType::POST_TYPE );
        // The original is a ROLE, not a language: {@see SiteTemplateRegistry::original_id()}. Asked
        // here as "the default language's version", this named the wrong record for any group
        // authored in another language — and for the English version of a Polish original it named
        // the very id being deleted.
        $original = SiteTemplateRegistry::original_id( $template_id );

        return sprintf(
            'readers in "%s" — this is that language\'s version of template id %d, and they fall back to its words without it',
            $language !== '' ? $language : '?',
            $original > 0 ? $original : 0
        );
    }



    public static function term( int $term_id, string $taxonomy ): array {
        $out = [];
        foreach ( self::term_contexts( $term_id, $taxonomy ) as $context ) {
            $out[] = sprintf( 'the template bound to "%s"', $context );
        }

        return $out;
    }

    /**
     * The binding contexts keyed on one term — the same lookup {@see term()} reports, as the raw
     * keys rather than sentences.
     *
     * Separate because the caller does not only NAME what holds the term: it hands back the call
     * that releases it, and that call needs the context verbatim. Reading it out of the sentence
     * would be parsing our own prose.
     *
     * Not counted, and it does not need to be: a term has one key and a binding map has unique ones,
     * so this cannot be longer than one.
     *
     * @return list<string>
     */
    public static function term_contexts( int $term_id, string $taxonomy ): array {
        $keys = self::term_keys( $term_id, $taxonomy );

        $out = [];
        foreach ( ArchiveTemplateBinding::all() as $context => $id ) {
            if ( in_array( (string) $context, $keys, true ) ) {
                $out[] = (string) $context;
            }
        }

        return $out;
    }

    /**
     * The context keys one term answers to — its id, the only shape bindings and rules are written in.
     *
     * @return list<string>
     */
    private static function term_keys( int $term_id, string $taxonomy ): array {
        return [ 'tax:' . $taxonomy . ':' . $term_id ];
    }

    /**
     * Templates whose content slot draws a body for THIS term's archive.
     *
     * Separate from {@see term()}, which reports the frame BINDINGS. The two are different kinds
     * of loss and the tool answers them differently: a binding is removed with one call and its
     * absence leaves a live view in the wrong frame, so deleting the term is refused outright; a
     * rule points at a body for a view that stops existing with the term, so it is refused with a
     * way past — see DeleteTermAbility.
     *
     * @return list<string>
     */
    public static function term_rules( int $term_id, string $taxonomy ): array {
        return self::rules( self::term_keys( $term_id, $taxonomy ) );
    }

}
