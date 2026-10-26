<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Translation\Translation;

/**
 * Read-only: the languages of a multilingual site.
 *
 * Registered only where the site has several languages (see is_available()), so its presence
 * already answers "is this site multilingual". It is the first call on such a site, because a
 * page may exist in a language you were not looking in, and "no page called X" is not the same
 * as "no such page".
 */
final class ListLanguagesAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/list-languages';
    }

    public function label(): string {
        return 'List languages';
    }

    public function description(): string {
        return 'The languages of this multilingual site: active, default, languages [{code, label, '
            . 'isDefault}]. Call it first when a task mentions a language — codes come from here, never '
            . 'guessed. Another language of an EXISTING page or template is never a new record: '
            . 'translate-content / translate-template make the version, then it is edited by its own id. '
            . '`capabilities` says what CAN be translated here — check it before planning a translation.';
    }

    public function contract(): ?string {
        return 'translation-contract';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'active', 'default', 'languages' ],
            'properties' => [
                'active'    => [
                    'type'        => 'boolean',
                    'description' => 'Always true: this tool exists only on a multilingual site.',
                ],
                'default'   => [
                    'type'        => 'string',
                    'description' => 'The site\'s own language; content created without a language lands here.',
                ],
                'capabilities' => [
                    'type'       => 'object',
                    'properties' => [
                        'contentTranslation' => [ 'type' => 'boolean', 'description' => 'Pages, posts, templates, patterns, terms.' ],
                        'stringTranslation'  => [ 'type' => 'boolean', 'description' => 'SEO title/description templates per language (set-seo-defaults {language}).' ],
                        'formTranslation'    => [ 'type' => 'boolean', 'description' => 'Contact Form 7 forms (translate-cf7-form). Absent without Contact Form 7.' ],
                    ],
                ],
                'note'      => [ 'type' => 'string', 'description' => 'Present when something cannot be translated here, and why.' ],
                'languages' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'code'      => [ 'type' => 'string', 'description' => 'What every other tool expects — pass this, never a guess.' ],
                            'label'     => [ 'type' => 'string', 'description' => 'Human name, for talking to the user.' ],
                            'isDefault' => [ 'type' => 'boolean' ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Absent on a single-language site — which is most of them. There is nothing to answer, and
     * a tool asking "which languages?" where there is one is a question an agent must read and
     * dismiss on every task.
     */
    public function is_available(): bool {
        return Translation::is_multilingual();
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $bridge    = Translation::bridge();
        $languages = $bridge->languages();

        // The SITE's language, not the request's: this answers "where does content with no
        // language of its own land", which is a property of the site.
        $default = $bridge->default_language();
        $out     = [];
        foreach ( $languages as $language ) {
            $out[] = [
                'code'      => (string) $language['code'],
                'label'     => (string) $language['label'],
                'isDefault' => (string) $language['code'] === $default,
            ];
        }

        $strings      = $bridge->supports_strings();
        $capabilities = [ 'contentTranslation' => true, 'stringTranslation' => $strings ];
        $notes        = $strings ? [] : [ Translation::STRINGS_MISSING ];
        // Only where there ARE forms to translate: without Contact Form 7 the question is moot.
        if ( class_exists( '\WPCF7_ContactForm' ) ) {
            $forms                           = $bridge->is_post_type_translatable( 'wpcf7_contact_form' ) === true;
            $capabilities['formTranslation'] = $forms;
            if ( ! $forms ) {
                $notes[] = Translation::FORMS_MISSING;
            }
        }
        $result = [
            'active'       => true,
            'default'      => $default,
            'capabilities' => $capabilities,
            'languages'    => $out,
        ];
        if ( $notes !== [] ) {
            $result['note'] = implode( ' ', $notes );
        }
        return $result;
    }
}
