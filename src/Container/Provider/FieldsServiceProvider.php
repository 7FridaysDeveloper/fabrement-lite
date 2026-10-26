<?php

namespace Fabrement\Container\Provider;

use Fabrement\Modules\Fields\Translation\TranslationSplit;
use Fabrement\Modules\Fields\Write\FieldWrite;
use Fabrement\Modules\Fields\Write\ValueWriter;
use Fabrement\Modules\Fields\Write\WriteRules;
use Fabrement\Modules\Translation\Translation;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

/** Lazy, stateless dependencies shared by the external field-writing entries. */
final class FieldsServiceProvider implements ServiceProviderInterface {
    public function register( Container $pimple ): void {
        $pimple[ WriteRules::class ] = static fn() => new WriteRules();
        $pimple[ TranslationSplit::class ] = static fn() => new TranslationSplit();
        $pimple[ ValueWriter::class ] = static fn() => new ValueWriter();
        $pimple[ FieldWrite::class ] = static fn( Container $c ) => new FieldWrite(
            $c[ WriteRules::class ], $c[ TranslationSplit::class ], $c[ ValueWriter::class ], Translation::bridge()
        );
    }
}
