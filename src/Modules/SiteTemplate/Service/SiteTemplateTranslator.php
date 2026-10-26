<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * One template's version in another language — started, read, and reworded.
 *
 * The model is the translation plugin's own for a post edited in WordPress's editor: a version
 * is a second post of the same type, linked to the original by the plugin, that STARTS as a
 * full copy — content and width — and is then a record of its own. It is edited like the
 * original, it may end up with different blocks, and it is rendered as it is. Which pages the template dresses stays on the original: a reader
 * is handed the version the way they are handed a translated page.
 *
 * Two doors start the same version: the Templates screen's language control
 * ({@see \Fabrement\Route\SiteTemplate\TranslateSiteTemplateRestRoute}) and the agent's
 * translate-template tool. Both call {@see open()} — one set of refusals, in one place, so a
 * language the button will not start is a language the tool will not start either.
 */
final class SiteTemplateTranslator {

    /**
     * Find, or create, the post holding `$language`'s version of template `$template_id`.
     *
     * @return array{id:int,created:bool}|WP_Error The version's post id, or a WP_Error whose
     *         code names the refusal (see the route's docblock) and whose data may carry
     *         `screenUrl` where the translation plugin owns the words.
     */
    public static function open( int $template_id, string $language ) {
        $bridge = Translation::bridge();

        // Only a template in its own right can be translated. A version's own id here would
        // ask for a translation of a translation — named back to the caller with the original's
        // id, so the retry needs no guessing (the same answer ContentTranslator gives).
        $record = SiteTemplateRegistry::find_any( $template_id );
        if ( $record === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }
        if ( ! $bridge->is_original( $template_id, SiteTemplatePostType::POST_TYPE ) ) {
            $source = self::original_of( $template_id );
            return new WP_Error(
                'fabrement_st_not_original',
                __( 'This template is itself a language version — pass its original.', 'fabrement-lite' ),
                [ 'status' => 409, 'originalId' => $source ? (int) $source->id : 0 ]
            );
        }
        $original = SiteTemplateRegistry::find_assignable( $template_id );
        if ( $original === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }

        // The language must be one this site actually has. Anything else would create a record
        // nothing will ever render — a template that silently does not exist.
        $known = array_column( $bridge->languages(), 'code' );
        if ( ! in_array( $language, $known, true ) ) {
            return new WP_Error(
                'fabrement_st_bad_language',
                __( 'That language is not set up on this site.', 'fabrement-lite' ),
                [ 'status' => 400 ]
            );
        }
        // The original's own language is not a version to open: handing the original back as
        // "created:false" would read as "the version exists" and invite a translated fill onto it.
        $own = Translation::live_language_of( $template_id, SiteTemplatePostType::POST_TYPE, $bridge->default_language() );
        if ( $own === $language ) {
            return new WP_Error(
                'fabrement_st_same_language',
                __( 'The original is already in that language.', 'fabrement-lite' ),
                [ 'status' => 400 ]
            );
        }

        // Already there — hand back the same record rather than making a second copy. Opening
        // is a "take me to this language", and asking twice must not fork the content.
        $existing      = $bridge->all_versions( $template_id, SiteTemplatePostType::POST_TYPE );
        $existing_post = isset( $existing[ $language ] ) ? get_post( (int) $existing[ $language ] ) : null;
        // A TRASHED copy is not "already there". The listing hides it, so the language is offered
        // as not started — and handing back a link into the trash would make that language
        // impossible to start again, forever. Treated as absent, so the branch below writes a
        // fresh one.
        if ( $existing_post && $existing_post->post_status !== 'trash' ) {
            return [ 'id' => (int) $existing_post->ID, 'created' => false ];
        }

        // BELOW the branch above on purpose. This refusal is about WRITING a copy; opening a
        // language that already exists writes nothing, and refusing it would answer 409 for
        // every language on a site using the plugin's editor — including words already written
        // and already being served to readers.
        //
        // Not where a translation plugin's own editor owns the text. Its editor rewrites the
        // translated post from the original whenever a job completes, so wording written into
        // the copy would survive until the next run and then vanish, with nothing to say why —
        // the same refusal save-content makes before writing a translated page.
        if ( ! $bridge->writes_translations_manually( $template_id ) ) {
            return new WP_Error(
                'fabrement_st_translation_not_manual',
                __( 'This template is translated by the translation plugin: it rewrites each language from the original, so wording typed here would be overwritten by the next job. Translate it in the plugin’s own screen instead.', 'fabrement-lite' ),
                [
                    'status'    => 409,
                    'screenUrl' => $bridge->translation_screen_url( $template_id ),
                ]
            );
        }


        // A copy of nothing is not a starting point: an empty version renders nothing for that
        // language. Better to say so than to hand back a blank record.
        $content = (string) $original->raw_content;
        if ( trim( $content ) === '' ) {
            return new WP_Error(
                'fabrement_st_empty_original',
                __( 'This template has no content to translate yet — add blocks to it in the editor first.', 'fabrement-lite' ),
                [ 'status' => 409 ]
            );
        }

        // Native drafts may not yet have a manifest. Seed it before linking a copy.
        if ( SiteTemplatePostType::manifest_key( $template_id ) === '' ) {
            $config = ContentSourceConfig::write( $template_id, ContentSourceConfig::read( $template_id ) );
            if ( is_wp_error( $config ) ) { return $config; }
        }
        $content = ContentSourceConfig::strip_block_rules( $content );
        $copy = wp_insert_post(
            [
                'post_type'    => SiteTemplatePostType::POST_TYPE,
                'post_status'  => 'publish',
                // Named after the original with the language beside it: only an admin reads a
                // template's name, and every list of templates tells the versions apart by it.
                'post_title'   => $original->title . ' (' . $language . ')',
                // wp_slash for the same reason SiteTemplateWriter does it: wp_insert_post unslashes
                // everything it is given, and block delimiters carry backslash-escaped & < > " in
                // their attribute JSON. Without it every attribute of every block comes out corrupt.
                'post_content' => wp_slash( $content ),
            ],
            true
        );

        if ( is_wp_error( $copy ) ) {
            return $copy;
        }

        // The whole record, not just the words: width and manifest key are copied too, so the
        // version is the original in every respect the moment it exists, and diverges only where
        // someone changes it.
        // The same global blocks as the original: the copied content places them, and the reader's
        // language picks each pattern's version when the page renders.
        update_post_meta( (int) $copy, Keys::META_TEMPLATE_CONTENT_WIDTH, $original->content_width );
        // The ORIGINAL's manifest key, not one of its own. The file holds the rules for the whole
        // group: a version with its own key would be a second copy of them, which is the drift
        // {@see TemplateManifestStore} exists to end — and the one this class used to cause, by
        // copying markup that carried the rules inside it.
        $manifest_key = SiteTemplatePostType::manifest_key( $template_id );
        if ( $manifest_key !== '' ) {
            update_post_meta( (int) $copy, Keys::META_TEMPLATE_KEY, $manifest_key );
        }

        $bridge->link_translation( (int) $copy, $template_id, $language, SiteTemplatePostType::POST_TYPE );
        SiteTemplateRegistry::flush();

        // Read the link back rather than trusting the write. A translation plugin digests its
        // configuration on a handful of admin screens, so on a site where it has not yet seen
        // that this post type is translatable, the link is a no-op that reports nothing — and
        // the copy stays its OWN original. It would then show up as a second template with the
        // same name, assignable to pages and registered in WordPress's own Template dropdown:
        // exactly the per-language duplicate this whole design forbids. Better to remove it and
        // say so than to leave one behind.
        // version_facts(), not visible_versions(): the latter is memoised per trid by the
        // translation plugin, and the branch above already filled that entry BEFORE the link was
        // written. Reading it again can hand back the map as it was, which would look like a
        // failed link and delete a translation that is in fact correctly linked.
        // The language is the test. `isOriginal` is NOT: it is read from a nullable column that
        // the plugin legitimately leaves empty when it cannot name the source language, and this
        // branch force-deletes — skipping the trash — so a wrong answer here destroys a copy that
        // was linked correctly. A record filed under the language asked for is linked; if it is
        // also its own original, the group is odd but the copy is not garbage.
        if ( Translation::live_language_of( (int) $copy, SiteTemplatePostType::POST_TYPE ) !== $language ) {
            wp_delete_post( (int) $copy, true );
            SiteTemplateRegistry::flush();

            return new WP_Error(
                'fabrement_st_link_failed',
                __( 'The translation could not be linked to this template — the translation plugin does not treat templates as translatable yet. Open WP Admin → Plugins once so it re-reads its configuration, then try again.', 'fabrement-lite' ),
                [ 'status' => 409 ]
            );
        }

        return [ 'id' => (int) $copy, 'created' => true ];
    }

    /**
     * A template's frame as entries save-template takes back, preserving all blocks
     * and attributes. Shared source rules are returned separately, never in blocks.
     *
     * @return list<array<string,mixed>>
     */
    public static function entries( int $template_id ): array {
        $entries = BlockEntry::of_post( $template_id );
        foreach ( $entries as &$entry ) {
            if ( ( $entry['name'] ?? '' ) === 'fabrement/content-source' ) { unset( $entry['attributes']['rules'] ); }
            if ( isset( $entry['markup'] ) ) { $entry['markup'] = ContentSourceConfig::strip_block_rules( $entry['markup'] ); }
        }
        return $entries;
    }

    /**
     * What a template's read adds to its summary: its frame (`blocks`, with the `outline` and
     * `hash` an edit is pinned by). A global block in it is a {name:"core/block", attributes:{ref}}
     * entry; its words are the synced-pattern tools'. The same read serves get-template and
     * translate-template.
     *
     * @return array{blocks:list<array<string,mixed>>,outline:list<string>,hash:string}
     */
    public static function fill( SiteTemplateRecord $record ): array {
        $blocks = self::entries( $record->id );
        return [
            'blocks'  => $blocks,
            'outline' => \Fabrement\Modules\Mcp\Support\ContentEdits::outline( $blocks ),
            'hash'    => \Fabrement\Modules\Storage\TextEdits::hash( $blocks ),
        ];
    }

    /**
     * The refusal every WRITE into a language version shares: null where the version is ours
     * to edit, a 409 where the translation plugin's own editor owns it.
     *
     * A version is edited by two doors — the template save (its blocks and width;
     * save-template for the agent) and the content-source save (the GROUP's rules,
     * which a version can reach but does not own: one manifest serves every language) — and
     * WPML's answer is the same at each: in its Translation Editor mode it does not open a
     * translation in the native editor at all, because the next job rewrites the post from
     * the original and anything typed in directly is lost. Originals are never refused: they
     * are edited here whatever the mode, and that is where the plugin's jobs read from.
     * Deleting a version is not a write of this kind — the plugin sees the deletion and the
     * language goes back to "not translated", exactly as trashing a translated page does.
     */
    public static function refusal_for_version( int $template_id ): ?WP_Error {
        $bridge = Translation::bridge();
        if ( $bridge->is_original( $template_id, SiteTemplatePostType::POST_TYPE ) || $bridge->writes_translations_manually( $template_id ) ) {
            return null;
        }
        return new WP_Error(
            'fabrement_st_translation_not_manual',
            __( 'This language version is written by the translation plugin: its editor rewrites each language from the original, so a change made here would be overwritten by the next job. Edit the ORIGINAL here, or this language in the plugin’s own screen.', 'fabrement-lite' ),
            [
                'status'    => 409,
                'screenUrl' => $bridge->translation_screen_url( $template_id ),
            ]
        );
    }

    /**
     * The template a language version belongs to (itself, for an original), or null when the id
     * is no template at all.
     */
    public static function original_of( int $template_id ): ?SiteTemplateRecord {
        // find_any: a version switched to draft in the editor is still a version of its
        // original, and an original in draft is still the original of its versions. Status is
        // the callers' question.
        $record = SiteTemplateRegistry::find_any( $template_id );
        if ( $record === null ) {
            return null;
        }
        $bridge = Translation::bridge();
        if ( $bridge->is_original( $template_id, SiteTemplatePostType::POST_TYPE ) ) {
            return $record;
        }
        $siblings = $bridge->all_versions( $template_id, SiteTemplatePostType::POST_TYPE );
        $facts    = $bridge->version_facts( array_values( $siblings ), SiteTemplatePostType::POST_TYPE );
        foreach ( $siblings as $id ) {
            if ( $facts[ (int) $id ]['isOriginal'] ?? false ) {
                return SiteTemplateRegistry::find_any( (int) $id );
            }
        }
        return null;
    }
}
