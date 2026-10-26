<?php

namespace Fabrement\Api\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A group field, as get_group() gives it: the fields methods with paths that start in the group.
 * Its writes join the record's changes; save() on the record stores them.
 */
final class Group extends Place {
}
