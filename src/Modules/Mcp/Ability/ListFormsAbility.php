<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Forms\FormCatalog;

/** Read-only: the site's Contact Form 7 forms. */
final class ListFormsAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/list-forms';
    }

    public function label(): string {
        return 'List forms';
    }

    public function description(): string {
        return 'The Contact Form 7 forms on this site with their ids. Never write a <form> by hand: '
            . 'place the Contact Form 7 block or shortcode with one of these ids.' . self::multilingual_text( ' `withoutLanguage` lists forms that exist but WPML hides — never create them again.' );
    }

    public function contract(): ?string {
        return 'forms-contract';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        $group = [
            'type'       => 'object',
            'required'   => [ 'active', 'forms' ],
            'properties' => [
                // Separate from `forms` because an empty list alone cannot tell "plugin off"
                // from "plugin on, no forms yet" — two different things to tell the user.
                'active' => [ 'type' => 'boolean', 'description' => 'False → `forms` is always empty.' ],
                'forms'  => [
                    'type'        => 'array',
                    'description' => 'Up to 24; forms with markup over 50 000 characters are skipped, so this list may be incomplete.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'id'          => [ 'type' => 'integer', 'description' => 'The id the Contact Form 7 block or shortcode takes.' ],
                            'title'       => [ 'type' => 'string' ],
                            'html'        => [ 'type' => 'string', 'description' => 'Rendered markup, for reference only — never paste it into content.' ],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'type'       => 'object',
            'required'   => [ 'cf7' ],
            'properties' => [
                'cf7' => $group,
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $out = [
            'cf7' => [
                'active' => FormCatalog::is_cf7_active(),
                'forms'  => FormCatalog::collect_contact_forms(),
            ],
        ];

        // Forms WPML cannot see: they exist, but made before forms were translatable they have no
        // language, and WPML drops them from every listing — this one and its own screens. Named
        // here so an agent does not conclude there are none and build a second set.
        if ( $out['cf7']['active'] ) {
            $lost = \Fabrement\Modules\Translation\Translation::bridge()->without_language( 'wpcf7_contact_form' );
            if ( $lost !== [] ) {
                $out['cf7']['withoutLanguage'] = $lost;
                $out['cf7']['note']            = sprintf(
                    '%d form(s) exist but WPML hides them: they have no language. Do not create them again. Ask the site admin to open WPML → Support → Troubleshooting and click "Fix post type assignment for translations", then list-forms again.',
                    count( $lost )
                );
            }
        }

        return $out;
    }
}
