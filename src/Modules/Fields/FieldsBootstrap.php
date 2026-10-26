<?php

namespace Fabrement\Modules\Fields;

use Fabrement\Modules\Fields\Admin\FieldsAdmin;
use Fabrement\Modules\Fields\Translation\OptionVersions;
use Fabrement\Modules\Fields\Translation\PostTranslations;
use Fabrement\Modules\Fields\Values\FieldMetaQuery;

/** Field module hooks, in their established order. REST entries use RestRoutesBootstrap. */
final class FieldsBootstrap {
    public static function init(): void {
        FieldsAdmin::init();
        PostTranslations::init();
        OptionVersions::init();
        FieldMetaQuery::init();
        require_once __DIR__ . '/helpers.php';
    }
}
