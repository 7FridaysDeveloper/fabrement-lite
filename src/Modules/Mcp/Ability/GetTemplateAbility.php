<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\ArchiveTemplateBinding;
use Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplatePages;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateTranslator;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Read-only: one template's FULL state — its frame (blocks, outline, hash) and, read-only, its
 * theme-file rules (contentSource) and site-wide assignments. Read it before editing the frame.
 */
final class GetTemplateAbility extends AbstractAbility {

    public function check_permission() {
        return parent::check_permission() && current_user_can( Capabilities::TEMPLATES );
    }

    public function name(): string {
        return 'fabrement/get-template';
    }

    public function label(): string {
        return 'Get template';
    }

    public function description(): string {
        return 'One block template in full, answered as {template:{…}}: contentWidth, '
            . 'blocks (its frame as save-template takes it back), outline, hash, problem. '
            . 'Read-only here: contentSource (theme files the slot draws), defaultFor and archiveContexts — set in wp-admin. '
            . 'A {name:"core/block", attributes:{ref}} entry is a GLOBAL BLOCK — the header and the footer usually are: its words are read and '
            . 'changed with get-synced-pattern / save-synced-pattern {patternId: ref} (every template placing it changes). '
            . 'Read it before you change the frame: save-template mode "edit" needs its hash, '
            . 'and mode "replace" with `blocks` rewrites the whole frame.'
            . self::multilingual_text( ' Also translation {language, isOriginal, originalId, editor, screenUrl, translations, '
            . 'missingLanguages}; a language version is read and edited by ITS own id.' );
    }

    public function languages_contract(): ?string {
        return 'template-translation';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'templateId' ],
            'properties'           => [
                'templateId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Template id from list-templates' . self::multilingual_text( ', or a language version\'s id' ) . '.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'template' ],
            'properties' => [ 'template' => [ 'type' => 'object' ] ],
        ];
    }

    public function contract(): ?string {
        return 'template-contract';
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $template_id = (int) ( $input['templateId'] ?? 0 );
        $record      = SiteTemplateRegistry::find( $template_id );
        if ( $record === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }

        $summary = $record->to_summary( SiteTemplatePostType::type_defaults() )
            + [ 'contentSource' => ContentSourceConfig::read( $template_id ) ]
            + [ 'pageCount' => SiteTemplatePages::counts()[ $record->slug ] ?? 0 ]
            // The frame as save-template takes it back.
            + SiteTemplateTranslator::fill( $record )
            + [ 'archiveContexts' => ArchiveTemplateBinding::contexts_for_template( $template_id ) ];

        // Only where the site has languages.
        if ( Translation::is_multilingual() ) {
            $summary['translation'] = self::translation( $record );
        }

        return [ 'template' => $summary ];
    }

    /**
     * Where this record stands among the template's languages, and who owns its translation.
     *
     * `editor` is asked of the ORIGINAL: its setting governs its versions. `screenUrl` rides
     * along only where that editor is the translation plugin's.
     *
     * @return array<string,mixed>
     */
    private static function translation( \Fabrement\Modules\SiteTemplate\Dto\SiteTemplateRecord $record ): array {
        $bridge   = Translation::bridge();
        $type     = SiteTemplatePostType::POST_TYPE;
        $original = SiteTemplateTranslator::original_of( $record->id ) ?? $record;
        $facts    = $bridge->version_facts( [ $record->id ], $type )[ $record->id ] ?? [];
        $manual   = $bridge->writes_translations_manually( $original->id );

        $versions = [];
        foreach ( ( $facts['translations'] ?? [] ) as $code => $id ) {
            if ( (int) $id === $record->id ) {
                continue;
            }
            $versions[ (string) $code ] = [ 'id' => (int) $id ];
        }

        $out = [
            'language'         => (string) ( $facts['language'] ?? $bridge->default_language() ),
            'isOriginal'       => $original->id === $record->id,
            'originalId'       => $original->id,
            // Named for the role, not the product: whichever translation plugin the site runs.
            'editor'           => $manual ? 'manual' : 'plugin',
            'translations'     => (object) $versions,
            'missingLanguages' => Translation::missing_languages( $facts['translations'] ?? [] ),
        ];
        if ( ! $manual ) {
            $out['screenUrl'] = $bridge->translation_screen_url( $original->id );
        }

        return $out;
    }
}
