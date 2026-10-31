<?php

/** The plugin's global functions for theme PHP: fabrement() and the posts page's own content. */

defined( 'ABSPATH' ) || exit;

function fabrement(): \Fabrement\Api\Fabrement {
    return \Fabrement\Api\Fabrement::instance();
}

/** The posts page's own blocks on the blog index ("" on other archives), from a theme PHP template, preserving loop state. */
function fabrement_archive_content(): string {
    return \Fabrement\Modules\ContentSource\ArchiveOwnContent::render();
}
