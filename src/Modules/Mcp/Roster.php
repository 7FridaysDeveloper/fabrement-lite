<?php

namespace Fabrement\Modules\Mcp;

use Fabrement\Modules\Mcp\Ability\AbilityInterface;
use Pimple\Container;

/**
 * The single source of truth for which abilities the Fabrement MCP server
 * exposes. Both the ability registration (Registrar) and the server tool
 * list (ServerDefinition) read THIS list — a tool cannot be registered but
 * forgotten on the server, or vice versa.
 *
 * Adding a tool = create an Ability class and append its FQCN here. A
 * container binding (McpServiceProvider) is needed ONLY when the ability
 * takes constructor dependencies — see {@see self::instantiate()}.
 */
final class Roster {

    /**
     * Ability class names in the order they appear in tools/list.
     *
     * @var list<class-string<Ability\AbilityInterface>>
     */
    public const CLASSES = [
        Ability\GetSkillDocAbility::class,
        Ability\ListRegisteredBlocksAbility::class,
        Ability\GetRegisteredBlockSchemaAbility::class,
        Ability\GetThemeStylesAbility::class,
        Ability\ListBlockUsageAbility::class,
        Ability\ListTemplatesAbility::class,
        Ability\ListThemeTemplatesAbility::class,
        Ability\GetTemplateAbility::class,
        Ability\SaveTemplateAbility::class,
        Ability\ListTemplatePagesAbility::class,
        Ability\AssignTemplatePagesAbility::class,
        Ability\SetDefaultTemplateAbility::class,
        Ability\DeleteTemplateAbility::class,
        Ability\TranslateTemplateAbility::class,
        Ability\AssignTemplateArchiveAbility::class,
        Ability\PreviewAbility::class,
        Ability\ListMenusAbility::class,
        Ability\CreateMenuAbility::class,
        Ability\UpdateMenuAbility::class,
        Ability\DeleteMenuAbility::class,
        Ability\ListFormsAbility::class,
        Ability\CreateCf7FormAbility::class,
        Ability\GetCf7FormAbility::class,
        Ability\UpdateCf7FormAbility::class,
        Ability\TranslateCf7FormAbility::class,
        Ability\DeleteCf7FormAbility::class,
        Ability\UploadMediaAbility::class,
        Ability\ListMediaAbility::class,
        Ability\ListPostTypesAbility::class,
        Ability\ListPostTypeFieldsAbility::class,
        Ability\GetFieldsAbility::class,
        Ability\SaveFieldsAbility::class,
        Ability\GetFieldValuesAbility::class,
        Ability\SetFieldValuesAbility::class,
        Ability\ListOptionsPagesAbility::class,
        Ability\GetOptionsPageAbility::class,
        Ability\SaveOptionsPageAbility::class,
        Ability\DeleteOptionsPageAbility::class,
        Ability\ListContentAbility::class,
        Ability\GetContentAbility::class,
        Ability\ListLanguagesAbility::class,
        Ability\SaveContentAbility::class,
        Ability\SetContentStatusAbility::class,
        Ability\ListSyncedPatternsAbility::class,
        Ability\GetSyncedPatternAbility::class,
        Ability\SaveSyncedPatternAbility::class,
        Ability\DeleteSyncedPatternAbility::class,
        Ability\TranslateSyncedPatternAbility::class,
        Ability\TranslateContentAbility::class,
        Ability\SetPostTermsAbility::class,
        Ability\DeleteContentAbility::class,
        Ability\DeleteMediaAbility::class,
        Ability\GetSiteSettingsAbility::class,
        Ability\UpdateSiteSettingsAbility::class,
        Ability\ListTaxonomiesAbility::class,
        Ability\ListTermsAbility::class,
        Ability\CreateTermAbility::class,
        Ability\UpdateTermAbility::class,
        Ability\DeleteTermAbility::class,
        Ability\ListArchivesAbility::class,
        Ability\TranslateTermAbility::class,
        Ability\GetSeoAbility::class,
        Ability\ListSeoAbility::class,
        Ability\SetSeoAbility::class,
        Ability\SetSeoDefaultsAbility::class,
        Ability\SetSchemaAbility::class,
        Ability\ListPluginsAbility::class,
    ];

    /**
     * Builds every rostered ability, in Roster order.
     *
     * A bound class comes from the container (that is how it gets its
     * dependencies); anything else is simply constructed — the dependency-free
     * majority needs no wiring at all.
     *
     * Fail-soft on purpose: this runs on `wp_abilities_api_init`, hooked only for
     * a request to the MCP route (or WP-CLI). A broken entry (missing class, or one
     * that grew a constructor dependency nobody bound) must cost that one tool,
     * never a fatal on the whole request.
     *
     * @return list<AbilityInterface>
     */
    public static function instantiate( Container $container ): array {
        $abilities = [];

        foreach ( self::CLASSES as $class ) {
            if ( isset( $container[ $class ] ) ) {
                $ability = $container[ $class ];
                if ( self::applies( $ability ) ) {
                    $abilities[] = $ability;
                }
                continue;
            }

            try {
                $ability = new $class();
                if ( self::applies( $ability ) ) {
                    $abilities[] = $ability;
                }
            } catch ( \Throwable $e ) {
                // ArgumentCountError (unbound dependency), missing class, …
                \Fabrement\Framework\Log::debug( '[fabrement][mcp] ability skipped: ' . $class . ' — ' . $e->getMessage() );
            }
        }

        return $abilities;
    }


    /**
     * A tool that says it does not apply here is left out of the roster entirely, rather than
     * offered and made to refuse. See {@see Ability\AbstractAbility::is_available()}.
     */
    private static function applies( Ability\AbstractAbility $ability ): bool {
        return $ability->is_available();
    }
}
