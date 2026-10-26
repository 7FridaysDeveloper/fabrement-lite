<?php

namespace Fabrement\Modules\Fields\Schema;

use InvalidArgumentException;

/** A rejected schema or layout. `path` points at the offending input, e.g. `fields[2].sub_fields[0].name`. */
final class SchemaError extends InvalidArgumentException {

    public string $path;
    public string $error_code;

    public function __construct( string $path, string $message, string $code = 'invalid_fields' ) {
        parent::__construct( $message );
        $this->path = $path;
        $this->error_code = $code;
    }
}
