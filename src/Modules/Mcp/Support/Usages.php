<?php

namespace Fabrement\Modules\Mcp\Support;

defined( 'ABSPATH' ) || exit;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding;
use Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;

/**
 * Who still points at a thing, in words — the answer a delete tool owes before it deletes.
 *
 * A template is pointed at by records, post-type defaults and archive views; a term by the
 * template bindings and rules keyed on it. Delete the thing and the pointer stays, aimed at
 * nothing, and a re-created one gets a new id.
 *
 * Lists are given in full (they are bounded by configuration), and they err toward reporting:
 * a false positive costs a re-read, a false negative breaks a live page.
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
     * Separate because the caller also returns the contexts verbatim; reading them out of the
     * sentence would be parsing our own prose.
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
     * of loss and the tool answers them differently: a binding left behind leaves a live view in
     * the wrong frame, so deleting the term is refused outright; a
     * rule points at a body for a view that stops existing with the term, so it is refused with a
     * way past — see DeleteTermAbility.
     *
     * @return list<string>
     */
    public static function term_rules( int $term_id, string $taxonomy ): array {
        return self::rules( self::term_keys( $term_id, $taxonomy ) );
    }

    /**
     * The site-wide places a template dresses: post-type defaults (the 404 view among them) and
     * archive views. Set in wp-admin only.
     *
     * @return list<string>
     */
    public static function template_site_wide( int $template_id ): array {
        $out = [];
        foreach ( SiteTemplatePostType::type_defaults() as $type => $id ) {
            if ( (int) $id === $template_id ) {
                $out[] = $type === SiteTemplatePostType::NOT_FOUND
                    ? 'the not-found (404) view'
                    : sprintf( 'the default for post type "%s"', (string) $type );
            }
        }
        foreach ( ArchiveTemplateBinding::contexts_for_template( $template_id ) as $context ) {
            $out[] = sprintf( 'the archive view "%s"', (string) $context );
        }
        return $out;
    }

    /**
     * The records that picked this template themselves (`_wp_page_template`), counted, not named.
     *
     * @return list<string>
     */
    public static function template_pages( int $template_id ): array {
        $record = SiteTemplateRegistry::find_any( $template_id );
        if ( $record === null ) {
            return [];
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- usage check before a delete: records of every type and status but the trash that picked this template, read live.
        $pages = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} m
                 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status != 'trash'
                 WHERE m.meta_key = '_wp_page_template' AND m.meta_value = %s",
                $record->slug
            )
        );
        return $pages > 0 ? [ sprintf( '%d record(s) that chose it', $pages ) ] : [];
    }
}
