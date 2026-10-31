<?php

namespace Fabrement\Tests;

use Fabrement\Capabilities;
use Fabrement\Modules\Mcp\Ability;
use Fabrement\Modules\Mcp\McpCapabilityCeiling;
use Fabrement\Modules\Mcp\Roster;
use PHPUnit\Framework\TestCase;

/** The template tools an Editor's client gets, and the site-wide ones it does not. */
final class TemplateToolsTest extends TestCase {

    private const TEMPLATE_TOOLS = [
        Ability\ListTemplatesAbility::class,
        Ability\GetTemplateAbility::class,
        Ability\SaveTemplateAbility::class,
        Ability\ListTemplatePagesAbility::class,
        Ability\AssignTemplatePagesAbility::class,
        Ability\DeleteTemplateAbility::class,
        Ability\TranslateTemplateAbility::class,
    ];

    protected function setUp(): void {
        WordPressState::reset();
    }

    private static function tool( string $class ): Ability\AbstractAbility {
        return ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
    }

    public function test_the_roster_has_the_template_tools_and_no_site_wide_one(): void {
        $names = array_map( static fn( string $class ): string => self::tool( $class )->name(), Roster::CLASSES );
        self::assertCount( 47, $names );
        foreach ( self::TEMPLATE_TOOLS as $class ) {
            self::assertContains( $class, Roster::CLASSES );
        }
        foreach ( [ 'set-default-template', 'assign-template-archive', 'list-archives', 'list-theme-templates' ] as $name ) {
            self::assertNotContains( 'fabrement/' . $name, $names );
        }
    }

    public function test_templates_are_an_editors_and_survive_the_mcp_ceiling(): void {
        self::assertSame( 'edit_others_pages', Capabilities::TEMPLATES );
        self::assertNotContains( Capabilities::TEMPLATES, McpCapabilityCeiling::WITHHELD );
    }

    public function test_each_template_tool_asks_for_the_templates_capability(): void {
        foreach ( self::TEMPLATE_TOOLS as $class ) {
            WordPressState::select_user( 5, [ 'manage_options', 'edit_posts', 'edit_others_posts' ] );
            self::assertFalse( (bool) self::tool( $class )->check_permission(), $class . ' without ' . Capabilities::TEMPLATES );
            WordPressState::select_user( 5, [ 'manage_options', 'edit_posts', 'edit_others_posts', Capabilities::TEMPLATES ] );
            self::assertTrue( (bool) self::tool( $class )->check_permission(), $class . ' with ' . Capabilities::TEMPLATES );
        }
    }

    public function test_save_template_takes_no_theme_file_or_site_wide_input(): void {
        $keys = array_keys( self::tool( Ability\SaveTemplateAbility::class )->input_schema()['properties'] );
        foreach ( [ 'contentSource', 'defaultFor', 'archives', 'notFound' ] as $key ) {
            self::assertNotContains( $key, $keys );
        }
    }
}
