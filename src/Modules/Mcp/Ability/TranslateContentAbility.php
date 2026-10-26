<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\Translation\ContentTranslator;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Start a page's version in another language: a full copy of the original, linked to it, as a
 * draft — then the agent translates the text with save-content {contentId: the copy}.
 *
 * Offered only on a site with languages: elsewhere the tool is absent from tools/list, so a
 * single-language site keeps the surface it always had. Where the translation plugin's own
 * editor owns the words the tool stays listed and refuses in execute() with the screen to go to.
 */
final class TranslateContentAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/translate-content';
    }

    public function label(): string {
        return 'Translate content';
    }

    public function description(): string {
        return 'Start a page/post in another language: a FULL draft copy of the ORIGINAL (blocks, excerpt, '
            . 'image, terms mapped, template, SEO settings such as noindex) linked as that language\'s version; '
            . 'returns its id and blocks. Then translate the TEXT only and save with save-content {contentId: the copy, '
            . 'mode:"replace", blocks, title}; publish it with set-content-status once the user agrees. Its SEO title and description start empty (the type\'s template '
            . 'speaks for it): get-seo {contentId} shows the original\'s, set-seo {contentId: the copy} writes them '
            . 'translated. Its Fabrement fields, if any, start as the original\'s: translate the words of its translate fields with '
            . 'set-field-values {postId: the copy}. An existing version is returned as it is '
            . '(created:false). Refuses with screenUrl where the translation plugin\'s editor owns the words, and for a post '
            . 'type the plugin does not translate (one set of records for every language: edit the original).';
    }

    public function contract(): ?string {
        return 'translation-contract';
    }

    public function is_available(): bool {
        return Translation::is_multilingual();
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'contentId', 'language' ],
            'properties'           => [
                'contentId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The ORIGINAL\'s id (list-content `originalId`).' ],
                'language'  => [ 'type' => 'string', 'minLength' => 2, 'description' => 'Target language code from list-languages.' ],
                'slug'      => [ 'type' => 'string', 'maxLength' => 200, 'description' => 'Optional URL slug for the new version, in its language. Omitted: derived from the translated title when it is published. Ignored when the version already exists — change it with save-content {slug}.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'contentId', 'language', 'created', 'blocks', 'editUrl' ],
            'properties' => [
                'contentId' => [ 'type' => 'integer', 'description' => 'The language version\'s own id — the one save-content {contentId} edits.' ],
                'language'  => [ 'type' => 'string' ],
                'created'   => [ 'type' => 'boolean', 'description' => 'true when this call made the copy, false when the version already existed.' ],
                'status'    => [ 'type' => 'string' ],
                'title'     => [ 'type' => 'string' ],
                'blocks'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'The copy\'s blocks, as save-content takes them: {name, attributes} or {markup}. Translate the text and send them back.' ],
                'editUrl'   => [ 'type' => 'string' ],
                'slug'      => [ 'type' => 'string', 'description' => 'The version\'s slug as stored; "" until it is published when none was given.' ],
                'parent'    => [ 'type' => 'integer', 'description' => 'The version\'s parent — the parent\'s version in this language; 0 = top level.' ],
                'note'      => [ 'type' => 'string', 'description' => 'Present when the copy could not go under the original\'s parent — it has no version in this language, or it is trashed or gone: the copy was put at the top level.' ],
            ],
        ];
    }

    public function execute( array $input ) {
        $content_id = (int) ( $input['contentId'] ?? 0 );
        $language   = trim( (string) ( $input['language'] ?? '' ) );

        $slug       = sanitize_title( (string) ( $input['slug'] ?? '' ) );

        $opened = ContentTranslator::open( $content_id, $language, $slug );
        if ( is_wp_error( $opened ) ) {
            return self::relay( $opened );
        }

        $out = [
            'contentId' => $opened['id'],
            'language'  => $language,
            'created'   => $opened['created'],
            'status'    => (string) get_post_status( $opened['id'] ),
            'title'     => (string) get_post_field( 'post_title', $opened['id'], 'raw' ),
            'blocks'    => BlockEntry::of_post( $opened['id'] ),
            'editUrl'   => (string) get_edit_post_link( $opened['id'], 'raw' ),
            'parent'    => (int) get_post_field( 'post_parent', $opened['id'] ),
            'slug'      => (string) get_post_field( 'post_name', $opened['id'] ),
        ];
        $dropped = $opened['parentDropped'] ?? null;
        if ( is_array( $dropped ) ) {
            $out['note'] = $dropped['reason'] === 'untranslated'
                ? sprintf(
                    'The original sits under record %1$d, which has no "%2$s" version yet, so this copy was put at the top level. Translate %1$d too, then move this one under it: save-content {contentId: %3$d, mode:"add", parent: <its version id>}.',
                    (int) $dropped['id'],
                    $language,
                    (int) $opened['id']
                )
                : sprintf(
                    'The original\'s parent, record %1$d, is in the trash or gone, so this copy was put at the top level. Give it a parent with save-content {contentId: %2$d, mode:"add", parent} if it needs one.',
                    (int) $dropped['id'],
                    (int) $opened['id']
                );
        }

        return $out;
    }

    /** The translator's refusals, worded for an agent — the plugin-owned case names its screen. */
    private static function relay( WP_Error $error ): WP_Error {
        $data = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
        if ( $error->get_error_code() === 'fabrement_ct_translation_not_manual' ) {
            $screen = (string) ( $data['screenUrl'] ?? '' );
            return ToolResult::error(
                'translation_owned_by_plugin',
                'This content is translated by the site\'s translation plugin: it rebuilds every language version from the original, so a copy made here would not survive. Do not write; tell the user it is translated in that plugin\'s own translation editor'
                    . ( $screen !== '' ? ' — ' . $screen : '' )
                    . '. If they want YOU to translate it, they switch the record (or the post type) to the WordPress editor in the plugin\'s translation-editor setting.',
                $data
            );
        }
        if ( $error->get_error_code() === 'fabrement_ct_type_not_translated' ) {
            return ToolResult::error(
                'type_not_translated',
                sprintf( 'Post type "%s" is the same in every language (the translation plugin does not translate it): there is no version to make. Edit the original — every language shows it. Versions per language need the type to be translatable in the translation plugin settings.', (string) ( $data['postType'] ?? '' ) ),
                $data
            );
        }
        if ( $error->get_error_code() === 'fabrement_ct_version_in_trash' ) {
            $version = (int) ( $data['versionId'] ?? 0 );
            return ToolResult::error(
                'version_in_trash',
                sprintf( 'This content already has a "%s" version (%d), in the trash. Restore it with set-content-status { contentId: %d, status: "restore" } and edit that one — a second copy would leave the trashed one a stray original once restored.', (string) ( $data['language'] ?? '' ), $version, $version ),
                $data
            );
        }
        if ( $error->get_error_code() === 'fabrement_ct_not_original' ) {
            $original = (int) ( $data['originalId'] ?? 0 );
            return ToolResult::error(
                'not_an_original',
                sprintf( 'That content is itself a language version. Pass the original%s.', $original > 0 ? ' — id ' . $original : ' (list-content `originalId`)' ),
                $data
            );
        }

        return ToolResult::error( (string) $error->get_error_code(), $error->get_error_message(), $data );
    }
}
