<?php
namespace Fabrement\Container\Provider;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Fabrement\Modules\Fields\Write\FieldWrite;
use Fabrement\Modules\Mcp\Ability\SetFieldValuesAbility;
use Fabrement\Modules\Mcp\Registrar;
use Fabrement\Modules\Mcp\Roster;
use Fabrement\Modules\Mcp\ServerDefinition;
final class McpServiceProvider implements ServiceProviderInterface {
    public function register( Container $pimple ): void {
        $pimple[ SetFieldValuesAbility::class ] = static fn( Container $c ) => new SetFieldValuesAbility( $c[ FieldWrite::class ] );
        $pimple[ Registrar::class ] = static fn( Container $c ) => new Registrar( Roster::instantiate( $c ) );
        $pimple[ ServerDefinition::class ] = static fn( Container $c ) => new ServerDefinition( $c[ Registrar::class ] );
    }
}
