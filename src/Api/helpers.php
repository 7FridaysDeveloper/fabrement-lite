<?php

/** The plugin's global functions for theme PHP: field values and the posts page's own content. */

defined( 'ABSPATH' ) || exit;

function fabrement(): \Fabrement\Api\Fabrement {
    return \Fabrement\Api\Fabrement::instance();
}

/**
 * Safe output for a `textarea` value: plain multi-line text, its line breaks kept.
 *
 * A textarea holds what the editor typed, and Enter there stores a newline — which HTML collapses
 * into a space, so a two-line address used to print as one line. Each break becomes a <br>.
 *
 * The inline tags stay allowed (strong/em/b/i, <br>, safe <a href|target|rel>) for the values
 * written before textarea became plain text, when a line break had to be typed as a literal
 * <br>. A break already written that way is not doubled. Formatted prose is a `wysiwyg`
 * field, read with get_wysiwyg() — not this.
 *
 * Decodes any pre-encoded entities first so values stored via WP's auto-escaping round-trip
 * cleanly.
 *
 * Usage in a theme template:
 *   <?= fabrement_kses_textarea( fabrement()->fields()->get( 'address' ) ) ?>
 */
function fabrement_kses_textarea( $value ): string {
    $text = str_replace( [ "\r\n", "\r" ], "\n", htmlspecialchars_decode( (string) $value ) );
    $text = (string) preg_replace( '#(?<!<br>)(?<!<br/>)(?<!<br />)\n#i', "<br>\n", $text );

    return wp_kses(
        $text,
        [
            'strong' => [],
            'br'     => [],
            'b'      => [],
            'i'      => [],
            'em'     => [],
            'a'      => [
                'href'   => [],
                'target' => [],
                'rel'    => [],
            ],
        ]
    );
}

/** The posts page's own blocks on the blog index ("" on other archives), from a theme PHP template, preserving loop state. */
function fabrement_archive_content(): string {
    return \Fabrement\Modules\ContentSource\ArchiveOwnContent::render();
}
