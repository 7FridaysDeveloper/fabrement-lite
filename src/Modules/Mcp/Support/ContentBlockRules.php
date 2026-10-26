<?php

namespace Fabrement\Modules\Mcp\Support;

/**
 * Blocks an agent may not put into a record's content, and the sentence that says why.
 *
 * Some blocks are registered and dynamic, so every other check lets them through, yet they only
 * mean something in one place the platform puts them itself. The template's content slot is the
 * first: in a page's content it would sit outside any template, where no rule and no
 * manifest know about it.
 *
 * The list is a filter, so a module (or a site) adds a block with one line:
 *
 *     add_filter( ContentBlockRules::FILTER, static function ( array $blocks ): array {
 *         $blocks['fabrement/something'] = 'Why it cannot go into content, and what to do instead.';
 *         return $blocks;
 *     } );
 */
final class ContentBlockRules {

    /** Filters the refused blocks: array<block name, sentence for the agent>. */
    public const FILTER = 'fabrement_content_refused_blocks';

    private const DEFAULTS = [
        'fabrement/content-source' => 'it belongs in a template, where it shows the current page content. Use save-template.',
    ];

    /** Why $name may not be placed in content, or null when it may. */
    public static function refusal( string $name ): ?string {
        $refused = apply_filters( self::FILTER, self::DEFAULTS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is a fabrement_ hook name.
        if ( ! is_array( $refused ) || ! isset( $refused[ $name ] ) ) {
            return null;
        }
        $reason = trim( (string) $refused[ $name ] );
        return $reason !== '' ? $reason : 'this block cannot be placed in content.';
    }
}
