<?php

namespace Fabrement\Modules\Mcp\Support;

/** The instruction documents in skills/, read as text by a validated id. */
final class LocalSkillDocs {

    private const ROOT = __DIR__ . '/../../../../skills';

    public static function read( string $id, bool $multilingual ): array {
        if ( ! preg_match( '/\A[a-z0-9][a-z0-9-]{0,99}\z/', $id ) ) {
            throw new McpAbilityException( 'Expected a lowercase document id, not a file path.', [ 'status' => 400 ], 'invalid_skill_id' );
        }

        $index = json_decode( self::text( 'index.json' ), true );
        if ( ! is_array( $index ) || ( $index['version'] ?? null ) !== 1 || ! is_array( $index['documents'] ?? null ) ) {
            throw new McpAbilityException( 'The bundled skill index is invalid. Reinstall the plugin files.', [], 'skill_docs_unavailable' );
        }
        $entry = $index['documents'][ $id ] ?? null;
        if ( ! is_array( $entry ) || ( ! $multilingual && empty( $entry['base'] ) ) ) {
            throw new McpAbilityException( sprintf( 'No local doc named "%s" for this site. Use the id in the tool\'s Rules line.', esc_html( $id ) ), [ 'status' => 404 ], 'not_found' );
        }

        $variant = $multilingual && ! empty( $entry['translation'] ) ? 'translation' : 'base';
        return [ 'id' => $id, 'content' => self::text( $variant . '/' . $id . '.xml' ) ];
    }

    /** Paths are constructed here from a validated id and fixed variant names. */
    private static function text( string $relative ): string {
        $root = realpath( self::ROOT );
        $file = realpath( self::ROOT . '/' . $relative );
        if ( $root === false || $file === false
            || strpos( $file, $root . DIRECTORY_SEPARATOR ) !== 0
            || ! is_file( $file ) || ! is_readable( $file ) ) {
            throw new McpAbilityException( 'A bundled skill file is missing or unreadable. Reinstall the plugin files.', [], 'skill_docs_unavailable' );
        }
        $content = file_get_contents( $file );
        if ( ! is_string( $content ) || trim( $content ) === '' ) {
            throw new McpAbilityException( 'A bundled skill file is empty or unreadable. Reinstall the plugin files.', [], 'skill_docs_unavailable' );
        }
        return $content;
    }
}
