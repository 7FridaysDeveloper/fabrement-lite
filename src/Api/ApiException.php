<?php

namespace Fabrement\Api;

defined( 'ABSPATH' ) || exit;

/** A call of fabrement() that cannot be done as asked; a read logs it ({@see Support\Quietly}). */
final class ApiException extends \InvalidArgumentException {}
