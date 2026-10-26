<?php

namespace Fabrement\Modules\Fields\Write;

use Fabrement\Modules\Fields\Values\FieldObject;

/** Replaceable routing choice. Validation, permissions and translation locks stay in the core. */
class WriteRules {
    /** @param list<string> $paths Fields whose changes belong to the original. */
    public function original( FieldObject $target, FieldObject $original, array $paths, WriteMode $mode ): WriteDecision {
        return WriteDecision::route();
    }
}
