<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateTranslator;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Start one language's version of a site template — the agent's door into the same record the
 * Templates screen's language control opens. The version is a template record of its own, a copy of the
 * original: this tool makes it and hands back its body; the text is then changed with
 * save-template {templateId: version id}, the ordinary edit.
 *
 * Offered only on a site with languages: elsewhere the tool is absent from tools/list, so a
 * single-language site keeps the surface it always had. Where the translation plugin's own
 * editor owns the words (ATE) the tool stays listed — the skill doc that describes it must
 * describe a tool that exists — and refuses in execute() with the screen to go to instead.
 */
final class TranslateTemplateAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/translate-template';
    }

    public function label(): string {
        return 'Translate template';
    }

    public function description(): string {
        return 'Start another language\'s version of a site template: copies the ORIGINAL (from list-templates) '
            . 'as that language\'s version and returns its frame — blocks, outline, hash — to translate (save-template {templateId: the version\'s id, mode:"edit"}). '
            . 'A {name:"core/block", attributes:{ref}} entry — the header, the footer — is a GLOBAL BLOCK, translated with its own tools and left as it is in the frame: '
            . 'get-synced-pattern {patternId: ref}; when its missingLanguages names this language, translate-synced-pattern {patternId: ref, language}, then save-synced-pattern on the PATTERN version it returns. '
            . 'An existing version is returned as it is (created:false). Refuses with screenUrl where the translation '
            . 'plugin\'s editor owns the template.';
    }

    public function contract(): ?string {
        return 'template-translation';
    }



    public function is_available(): bool {
        return Translation::is_multilingual();
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'templateId', 'language' ],
            'properties'           => [
                'templateId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The ORIGINAL template\'s id (list-templates).' ],
                'language'   => [ 'type' => 'string', 'minLength' => 2, 'description' => 'Target language code from list-languages.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'templateId', 'language', 'created', 'editUrl' ],
            'properties' => [
                'templateId'       => [ 'type' => 'integer', 'description' => 'The language version\'s own id — edit it with get-template / save-template; NOT assignable, assign the original.' ],
                'language'         => [ 'type' => 'string' ],
                'created'          => [ 'type' => 'boolean', 'description' => 'true when this call created the version, false when it already existed.' ],
                'editUrl'          => [ 'type' => 'string' ],
                'title'            => [ 'type' => 'string', 'description' => 'The version\'s title: the original\'s with the language code beside it. Only admins read it — leave it.' ],
                'blocks'           => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'The version\'s frame, as save-template takes it back.' ],
                'outline'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'hash'             => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( array $input ) {
        $template_id = (int) ( $input['templateId'] ?? 0 );
        $language    = trim( (string) ( $input['language'] ?? '' ) );

        $opened = SiteTemplateTranslator::open( $template_id, $language );
        if ( is_wp_error( $opened ) ) {
            return self::relay( $opened );
        }

        $version = SiteTemplateRegistry::find_any( $opened['id'] );

        return [
            'templateId' => $opened['id'],
            'language'   => $language,
            'created'    => $opened['created'],
            'editUrl'    => admin_url( 'post.php?post=' . $opened['id'] . '&action=edit' ),
            'title'      => $version !== null ? $version->title : '',
        ] + ( $version !== null ? SiteTemplateTranslator::fill( $version ) : [ 'blocks' => [], 'outline' => [], 'hash' => '' ] );
    }

    /**
     * The translator's refusals, worded for an agent: the plugin-owned case names the screen in
     * the message itself, because an agent reads the text and rarely the error data.
     */
    private static function relay( WP_Error $error ): WP_Error {
        $data = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
        if ( $error->get_error_code() === 'fabrement_st_translation_not_manual' ) {
            $screen = (string) ( $data['screenUrl'] ?? '' );
            return ToolResult::error(
                'translation_owned_by_plugin',
                'This template is translated by the site\'s translation plugin (get-template → translation.editor = "plugin"): it rebuilds every language version from the original, so a copy made here would not survive. Do not write; tell the user it is translated in that plugin\'s own translation editor'
                    . ( $screen !== '' ? ' — ' . $screen : '' )
                    . '. If they want YOU to translate it, they switch the template (or the post type) to the WordPress editor in the plugin\'s translation-editor setting, after which `editor` reads "manual".',
                $data
            );
        }

        if ( $error->get_error_code() === 'fabrement_st_not_original' ) {
            $original = (int) ( $data['originalId'] ?? 0 );
            return ToolResult::error(
                'not_an_original',
                sprintf( 'That template is itself a language version. Pass the original%s.', $original > 0 ? ' — id ' . $original : ' (get-template translation.originalId)' ),
                $data
            );
        }

        return ToolResult::error( (string) $error->get_error_code(), $error->get_error_message(), $data );
    }
}
