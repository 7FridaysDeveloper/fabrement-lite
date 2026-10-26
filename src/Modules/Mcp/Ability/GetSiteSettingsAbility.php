<?php

namespace Fabrement\Modules\Mcp\Ability;

/**
 * Read-only: the site settings, as update-site-settings reports them. Reading used to be an empty call to
 * the update tool, which is what it looked like in every log — a write.
 */
final class GetSiteSettingsAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/get-site-settings';
    }

    public function label(): string {
        return 'Get site settings';
    }

    public function description(): string {
        return 'The site settings a build needs, as they are now — siteTitle, tagline, showOnFront, frontPage '
            . '(the HOME page id), postsPage, notFoundPage, permalinkStructure, homeUrl, siteIcon. update-site-settings changes '
            . 'siteTitle, tagline, notFoundPage and siteIcon.';
    }

    public function contract(): ?string {
        return 'site-settings-contract';
    }

    public function input_schema(): array {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'settings' ],
            'properties' => [
                'settings'     => [ 'type' => 'object' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        return [ 'settings' => UpdateSiteSettingsAbility::current() ];
    }
}
