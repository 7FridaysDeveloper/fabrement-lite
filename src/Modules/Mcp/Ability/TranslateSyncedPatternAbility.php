<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\SiteTemplate\Service\SyncedPatterns;
use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\Translation\ContentTranslator;
use Fabrement\Modules\Translation\Translation;

/**
 * A global block's version in another language.
 *
 * Pages keep placing the ORIGINAL's id; the translation plugin swaps the ref for the reader's
 * language when it renders. So a translated pattern must be PUBLISHED at once — WordPress renders
 * a `wp_block` only when it is published, and the plugin swaps to the version whatever its status,
 * so a draft version would empty that section on every page in that language.
 */
final class TranslateSyncedPatternAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/translate-synced-pattern';
    }

    public function label(): string {
        return 'Translate synced pattern';
    }

    public function description(): string {
        return 'Make a global block\'s (synced pattern\'s) version in another language: a published copy of the ORIGINAL '
            . 'linked as that language\'s version; returns its id and blocks. Translate the TEXT and save it with '
            . 'save-synced-pattern {patternId: the version, mode:"replace", blocks}. Pages keep placing the original — the version shows '
            . 'on that language\'s pages automatically. An existing version is returned as it is (created:false).';
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
            'required'             => [ 'patternId', 'language' ],
            'properties'           => [
                'patternId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The ORIGINAL pattern (list-synced-patterns).' ],
                'language'  => [ 'type' => 'string', 'minLength' => 2, 'description' => 'Code from list-languages.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'patternId', 'language', 'created', 'blocks' ],
            'properties' => [
                'patternId' => [ 'type' => 'integer', 'description' => 'The version\'s own id — what save-synced-pattern edits.' ],
                'language'  => [ 'type' => 'string' ],
                'created'   => [ 'type' => 'boolean' ],
                'title'     => [ 'type' => 'string' ],
                'blocks'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'editUrl'   => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( array $input ) {
        $id       = (int) ( $input['patternId'] ?? 0 );
        $language = trim( (string) ( $input['language'] ?? '' ) );

        if ( SyncedPatterns::placeable( $id ) === null ) {
            return ToolResult::error( 'pattern_not_found', sprintf( 'No synced pattern with id %d — see list-synced-patterns.', $id ) );
        }

        // Patterns are translatable without asking: the translation plugin declares wp_block so in its
        // own configuration. Where someone unlocked and switched that off, ContentTranslator::open()
        // refuses (the copy would not link) rather than leave a second original behind.
        $opened = ContentTranslator::open( $id, $language );
        if ( is_wp_error( $opened ) ) {
            $data = is_array( $opened->get_error_data() ) ? $opened->get_error_data() : [];
            if ( $opened->get_error_code() === 'fabrement_ct_not_original' ) {
                return ToolResult::error( 'not_an_original', sprintf( 'That pattern is itself a language version — pass the original (id %d).', (int) ( $data['originalId'] ?? 0 ) ), $data );
            }
            if ( $opened->get_error_code() === 'fabrement_ct_version_in_trash' ) {
                $version = (int) ( $data['versionId'] ?? 0 );
                return ToolResult::error(
                    'version_in_trash',
                    sprintf( 'This global block already has a "%s" version (pattern %d), in the trash. Either the user restores it in WordPress (Patterns → Trash) and you edit it with save-synced-pattern, or you delete it for good with delete-synced-pattern { patternId: %d } and translate again — ask the user which.', (string) ( $data['language'] ?? '' ), $version, $version ),
                    $data
                );
            }
            if ( $opened->get_error_code() === 'fabrement_ct_translation_not_manual' ) {
                $screen = (string) ( $data['screenUrl'] ?? '' );
                return ToolResult::error(
                    'translation_owned_by_plugin',
                    'This global block is translated by the site\'s translation plugin: it rebuilds every language version from the original, so a version made here would not survive. Do not write; tell the user it is translated in that plugin\'s own translation editor'
                        . ( $screen !== '' ? ' — ' . $screen : '' ) . '.',
                    $data
                );
            }
            return $opened;
        }

        $version = (int) $opened['id'];
        if ( get_post_status( $version ) !== 'publish' ) {
            wp_update_post( [ 'ID' => $version, 'post_status' => 'publish' ] );
        }

        return [
            'patternId' => $version,
            'language'  => $language,
            'created'   => (bool) $opened['created'],
            'title'     => (string) get_post_field( 'post_title', $version, 'raw' ),
            'blocks'    => BlockEntry::of_post( $version ),
            'editUrl'   => (string) get_edit_post_link( $version, 'raw' ),
        ];
    }
}
