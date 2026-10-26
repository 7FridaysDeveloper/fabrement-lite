<?php

namespace Fabrement\Api\Fields;

use Fabrement\Api\Support\Quietly;

defined( 'ABSPATH' ) || exit;

/**
 * One row of a repeater, as get_rows() gives it: the fields methods with paths that start in the
 * row, its `_row` (id()) and its place when it was read (index()). Its writes join the record's
 * changes; save() on the record stores them.
 */
final class Row extends Place {

    private int $index;

    /** @param list<string> $prefix */
    protected function __construct( Fields $fields, array $prefix, int $index ) {
        parent::__construct( $fields, $prefix );
        $this->index = $index;
    }

    public function id( ...$unknown ): string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return (string) end( $this->prefix );
    }

    public function index( ...$unknown ): int {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->index;
    }
}
