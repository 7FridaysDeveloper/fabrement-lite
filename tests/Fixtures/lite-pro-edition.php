<?php
/**
 * Plugin Name: Fabrement Pro lifecycle test fixture
 * Text Domain: fabrement
 * Version: 0.0.1
 */
if (defined('FABREMENT_BLOCKS_FILE')) { throw new RuntimeException('Lite booted before the other edition; collision'); }
define('FABREMENT_BLOCKS_FILE', __FILE__);
define('FABREMENT_TEST_OTHER_EDITION', true);
