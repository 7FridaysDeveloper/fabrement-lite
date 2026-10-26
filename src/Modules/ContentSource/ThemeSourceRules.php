<?php
namespace Fabrement\Modules\ContentSource;

use Fabrement\Modules\Mcp\Support\McpAbilityException;

/** Validate choices, never PHP code. Every render rechecks the confined file path. */
final class ThemeSourceRules {
    public static function validate( $rules ): array {
        if ( ! is_array( $rules ) ) { throw new McpAbilityException( 'Content Source rules must be an array.' ); }
        $seen = []; $out = [];
        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) || ! is_string( $rule['context'] ?? null ) || ! is_string( $rule['file'] ?? null )
                || array_diff( array_keys( $rule ), [ 'context', 'file' ] ) !== [] ) { throw new McpAbilityException( 'Each source rule needs only context and file strings.' ); }
            $context = $rule['context']; $file = $rule['file'];
            if ( ! preg_match( '/^(?:singular:[a-z0-9_-]+|archive:[a-z0-9_-]+|blog-home|404|tax(?::[a-z0-9_-]+(?::[1-9][0-9]*)?)?)$/D', $context ) || isset( $seen[$context] ) ) {
                throw new McpAbilityException( 'Source contexts must be valid and unique: ' . esc_html( $context ) );
            }
            if ( ThemeTemplateFiles::resolve( $file ) === null ) { throw new McpAbilityException( 'Choose an existing PHP file from list-theme-templates: ' . esc_html( $file ) ); }
            if ( ! RenderKindHeader::fits( ThemeTemplateFiles::declares_of( $file ), $context ) ) { throw new McpAbilityException( 'The theme file declares a different view kind: ' . esc_html( $file ) ); }
            $seen[$context] = true; $out[] = [ 'context' => $context, 'file' => $file ];
        }
        return $out;
    }

    /** Nonempty theme-source choices require the built-in slot, including inside groups. */
    public static function assert_slot( string $content ): void {
        $blocks = parse_blocks( $content ); $found = 0;
        $walk = static function ( array $items ) use ( &$walk, &$found ): void {
            foreach ( $items as $block ) {
                if ( ( $block['blockName'] ?? '' ) === ContentSourceBlock::NAME ) { ++$found; }
                if ( ! empty( $block['innerBlocks'] ) ) { $walk( $block['innerBlocks'] ); }
            }
        };
        $walk( $blocks );
        if ( $found !== 1 ) { throw new McpAbilityException( 'Theme PHP sources require one fabrement/content-source block in the template.' ); }
    }
}
