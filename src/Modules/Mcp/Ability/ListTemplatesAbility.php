<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplatePages;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\Translation\Translation;

/**
 * Read-only: every site template on the site — its problem if it has one,
 * how many pages use it, and which post types default to it.
 */
final class ListTemplatesAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/list-templates';
    }

    public function label(): string {
        return 'List templates';
    }

    public function description(): string {
        return 'List the site templates (the frames around pages): id, title, '
            . 'contentWidth, problem (what is wrong with it, "" when nothing — no content slot, or a global block in it that was deleted or is not published), pageCount, defaultFor. What a template holds — its header and footer global blocks among it — is in get-template `blocks`. Call FIRST when the user may mean an EXISTING '
            . 'template — update it by id instead of creating a duplicate.'
            . self::multilingual_text( ' Originals only, each with language, translations {code: version id} and missingLanguages.' );
    }

    public function languages_contract(): ?string {
        return 'template-translation';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'templates' ],
            'properties' => [
                'templates' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'            => [ 'type' => 'integer' ],
                            'title'         => [ 'type' => 'string' ],
                            'slug'          => [ 'type' => 'string' ],
                            'contentWidth'  => [ 'type' => 'string' ],
                            'problem'       => [ 'type' => 'string', 'description' => 'What is wrong with the template, in a sentence; "" when nothing is.' ],
                            'pageCount'     => [ 'type' => 'integer' ],
                            'defaultFor'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        ] + self::multilingual( [
                            'language'     => [ 'type' => 'string', 'description' => 'Language code of the original.' ],
                            'translations' => [ 'type' => 'object', 'description' => 'code → id of that language version, the original included. A code missing here has no version yet.' ],
                            'missingLanguages' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Site languages with NO version of this template yet — readers there get the original. Make them with translate-template {templateId: this id, language}.' ],
                        ] ),
                    ],
                ],
            ],
        ];
    }

    public function contract(): ?string {
        return 'template-contract';
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $defaults = SiteTemplatePostType::type_defaults();
        $counts   = SiteTemplatePages::counts();
        // The same batch originals() already ran — one query for every template's languages.
        // Empty on a single-language site, and then nothing below adds a key.
        $langs = Translation::is_multilingual() ? SiteTemplateRegistry::languages_of_all() : [];

        $templates = array_map(
            static fn( $record ): array => $record->to_summary( $defaults )
                + [ 'pageCount' => $counts[ $record->slug ] ?? 0 ]
                + ( ! isset( $langs[ $record->id ] ) ? [] : [
                    'language'         => (string) $langs[ $record->id ]['language'],
                    'translations'     => (object) $langs[ $record->id ]['translations'],
                    'missingLanguages' => Translation::missing_languages( $langs[ $record->id ]['translations'] ),
                ] ),
            // originals(): a language version holds no role on the site — it is reached through
            // the original it belongs to — and an agent shown one would assign it as a second frame.
            SiteTemplateRegistry::originals()
        );

        return [ 'templates' => array_values( $templates ) ];
    }
}
