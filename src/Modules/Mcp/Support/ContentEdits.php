<?php

namespace Fabrement\Modules\Mcp\Support;

use Fabrement\Modules\Storage\TextEdits;

/**
 * save-content {mode:"edit"}: change a record's body by OPERATIONS on its top-level blocks
 * instead of re-sending every block.
 *
 * The body is the list get-content returns — {name, attributes} for a server-rendered block,
 * {markup} for a static one — and a block is addressed by its position in that list. Blocks have
 * no instance id in post_content (Gutenberg's clientId lives only in the editor), so every
 * positional op also names the block it expects there: a list that shifted since the read is
 * refused with what actually sits at that index, rather than editing the wrong block.
 *
 * Ops apply in order to one copy; each sees the result of the previous one; one failure writes
 * nothing.
 */
final class ContentEdits {

    public const OPS = [ 'update', 'text', 'insert', 'replace', 'remove', 'move' ];

    /**
     * JSON schema for the `edits` list. `$reader` is the tool that returns the body the edits are
     * written against: get-content, get-synced-pattern, get-template.
     */
    public static function schema( string $reader = 'get-content' ): array {
        $block = [
            'type'                 => 'object',
            'description'          => 'A new block, same shape as blocks[]: {name, attributes} or {markup}.',
            'properties'           => [
                'name'       => [ 'type' => 'string' ],
                'attributes' => [ 'type' => 'object' ],
                'markup'     => [ 'type' => 'string' ],
            ],
            'additionalProperties' => false,
        ];

        return [
            'type'        => [ 'array', 'string' ],
            'description' => 'mode "edit" only. Applied in order to the body ' . $reader . ' returned; all or none.',
            'items'       => [
                'type'                 => 'object',
                'required'             => [ 'op' ],
                'properties'           => [
                    'op'         => [
                        'type'        => 'string',
                        'enum'        => self::OPS,
                        'description' => 'update {index,name,attributes} merges attributes of a {name} block · text {index,name,attribute?,mode?,old?,new?,replaceAll?} edits a fragment of a {markup} block or of one string attribute · insert {at,block} · replace {index,name,block} · remove {index,name} · move {index,name,to}.',
                    ],
                    'index'      => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Position in ' . $reader . ' `blocks`, 0-based.' ],
                    'name'       => [ 'type' => 'string', 'description' => 'The block expected at `index` (' . $reader . ' `outline`) — refused if another block is there.' ],
                    'at'         => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'insert: position the new block takes; the block there moves down. = count appends.' ],
                    'to'         => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'move: position after the move.' ],
                    'attributes' => [ 'type' => 'object', 'description' => 'update: merged over stored attributes; null removes a top-level key. For ACF only, data is patched by field name, omitted fields stay, repeater count reductions remove excess rows. Replace the block to replace data wholesale.' ],
                    'block'      => $block,
                    'attribute'  => [ 'type' => 'string', 'description' => 'text: edit this string attribute of a {name} block instead of markup.' ],
                    'mode'       => [ 'type' => 'string', 'enum' => TextEdits::MODES, 'description' => 'text: how the fragment is applied; default replace.' ],
                    'old'        => [ 'type' => 'string' ],
                    'new'        => [ 'type' => 'string' ],
                    'replaceAll' => [ 'type' => 'boolean' ],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * The block names in body order — what an op's `name` guard is checked against, and what
     * get-content reports as `outline`. A {markup} entry is named by the block its markup holds.
     *
     * @param list<array<string,mixed>> $entries
     * @return list<string>
     */
    public static function outline( array $entries ): array {
        return array_map( [ self::class, 'name_of' ], $entries );
    }

    /**
     * The record's body as entries.
     *
     * This used to refuse a record holding classic content — text outside any block — because
     * BlockEntry::of_post dropped it, so an edit that rewrote the body from these entries deleted
     * writing nobody had seen. It is carried now, wrapped as core/freeform, and an edit keeps it
     * the way it keeps any other block it does not touch.
     *
     * @return list<array<string,mixed>>
     */
    public static function current( int $post_id ): array {
        return BlockEntry::of_post( $post_id );
    }

    /**
     * @param list<array<string,mixed>> $entries The body as `$reader` returns it.
     * @param mixed                     $edits
     * @param string                    $reader  The tool a refusal sends the agent back to.
     * @return list<array<string,mixed>> The body after every op.
     */
    public static function apply( array $entries, $edits, string $reader = 'get-content' ): array {
        // A client holding a stale schema sends the list JSON-encoded.
        $edits = \Fabrement\Modules\Storage\TextEdits::decode_object( $edits );
        if ( ! is_array( $edits ) || $edits === [] ) {
            throw new McpAbilityException( 'mode "edit" needs a non-empty `edits` list.' );
        }

        foreach ( array_values( $edits ) as $i => $edit ) {
            $n  = $i + 1;
            $op = is_array( $edit ) ? (string) ( $edit['op'] ?? '' ) : '';

            switch ( $op ) {
                case 'update':
                    $at             = self::guarded( $entries, $edit, $n, $reader );
                    $changed = self::merged( $entries[ $at ], $edit, $n );
                    ContentSafety::entry( $changed, sprintf( 'Edit #%d', $n ) );
                    self::assert_new_refs( $entries[ $at ], $changed, $n, $reader === 'get-template' );
                    $entries[ $at ] = $changed;
                    break;

                case 'text':
                    $at             = self::guarded( $entries, $edit, $n, $reader );
                    $changed = self::texted( $entries[ $at ], $edit, $n );
                    ContentSafety::entry( $changed, sprintf( 'Edit #%d', $n ) );
                    self::assert_new_refs( $entries[ $at ], $changed, $n, $reader === 'get-template' );
                    $entries[ $at ] = $changed;
                    break;

                case 'insert':
                    $at = self::position( $edit['at'] ?? null, count( $entries ), 'at', $n );
                    array_splice( $entries, $at, 0, [ self::new_block( $edit['block'] ?? null, $n, $reader === 'get-template' ) ] );
                    break;

                case 'replace':
                    $at             = self::guarded( $entries, $edit, $n, $reader );
                    $entries[ $at ] = self::new_block( $edit['block'] ?? null, $n, $reader === 'get-template' );
                    break;

                case 'remove':
                    $at = self::guarded( $entries, $edit, $n, $reader );
                    array_splice( $entries, $at, 1 );
                    break;

                case 'move':
                    $from  = self::guarded( $entries, $edit, $n, $reader );
                    $to    = self::position( $edit['to'] ?? null, count( $entries ) - 1, 'to', $n );
                    $moved = array_splice( $entries, $from, 1 );
                    array_splice( $entries, $to, 0, $moved );
                    break;

                default:
                    throw new McpAbilityException( sprintf( 'Edit #%d: unknown op "%s". Use %s.', (int) $n, esc_html( $op ), esc_html( implode( ', ', self::OPS ) ) ) );
            }
        }

        return array_values( $entries );
    }

    /** The index an op targets, after checking it exists and holds the block the caller expects. */
    private static function guarded( array $entries, array $edit, int $n, string $reader ): int {
        if ( ! isset( $edit['index'] ) ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (%s) needs `index` — the block\'s position in %s `blocks`.', (int) $n, esc_html( $edit['op'] ), esc_html( $reader ) ) );
        }
        if ( ! is_int( $edit['index'] ) ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (%s): `index` must be an integer (got %s).', (int) $n, esc_html( $edit['op'] ), esc_html( (string) wp_json_encode( $edit['index'] ) ) ) );
        }
        $index = $edit['index'];
        if ( $index < 0 || $index >= count( $entries ) ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (%s): index %d does not exist — the body has %d blocks (0–%d) at this point.', (int) $n, esc_html( $edit['op'] ), (int) $index, count( $entries ), (int) max( 0, count( $entries ) - 1 ) ) );
        }

        $expected = isset( $edit['name'] ) ? trim( (string) $edit['name'] ) : '';
        if ( $expected === '' ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (%s) needs `name` — the block you expect at index %d (%s `outline`), so a shifted body is caught instead of edited.', (int) $n, esc_html( $edit['op'] ), (int) $index, esc_html( $reader ) ) );
        }
        $actual = self::name_of( $entries[ $index ] );
        if ( $actual !== $expected ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (%s): index %d holds %s, not %s. Nothing was saved — read the record again (%s) or account for the edits before this one.', (int) $n, esc_html( $edit['op'] ), (int) $index, esc_html( $actual ), esc_html( $expected ), esc_html( $reader ) ) );
        }

        return $index;
    }

    private static function position( $raw, int $max, string $field, int $n ): int {
        if ( ! is_int( $raw ) || $raw < 0 || $raw > $max ) {
            throw new McpAbilityException( sprintf( 'Edit #%d: `%s` must be an integer from 0 to %d at this point.', (int) $n, esc_html( $field ), (int) max( 0, $max ) ) );
        }
        return $raw;
    }

    /** update: attributes of a server-rendered block, merged key by key. */
    private static function merged( array $entry, array $edit, int $n ): array {
        if ( isset( $entry['markup'] ) ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (update): %s is a static block — its attributes are written into its HTML as well as its comment, and changing one without the other makes the editor report invalid content. Use op "text" on its markup, or "replace".', (int) $n, esc_html( self::name_of( $entry ) ) ) );
        }
        if ( ! isset( $edit['attributes'] ) || ! is_array( $edit['attributes'] ) || $edit['attributes'] === [] ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (update) needs a non-empty `attributes` object.', (int) $n ) );
        }

        // Only names being SET are checked: removing a stray key (null) is exactly how a page
        // saved with a wrong name gets cleaned up.
        $type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $entry['name'] );
        if ( $type !== null ) {
            $setting = array_keys( array_filter( $edit['attributes'], static fn( $v ) => $v !== null ) );
            if ( AcfBlockData::is_block( $type->name ) ) { $setting = array_diff( $setting, [ 'id' ] ); }
            BlockEntry::assert_known_attributes( $type, $setting, sprintf( 'Edit #%d (update)', $n ) );
        }

        $attrs = isset( $entry['attributes'] ) && is_array( $entry['attributes'] ) ? $entry['attributes'] : [];
        foreach ( $edit['attributes'] as $key => $value ) {
            if ( $value === null ) {
                unset( $attrs[ $key ] );
            } else {
                if ( $key === 'data' && AcfBlockData::is_block( (string) $entry['name'] ) && is_array( $value ) ) {
                    $attrs[ $key ] = AcfBlockData::merge( (string) $entry['name'], (array) ( $attrs[ $key ] ?? [] ), $value );
                } else { $attrs[ $key ] = $value; }
            }
        }
        if ( $type !== null ) { $attrs = RegisteredBlocks::validate( $type, $attrs, sprintf( 'Edit #%d', $n ) ); }

        $out = [ 'name' => $entry['name'] ];
        if ( $attrs !== [] ) {
            $out['attributes'] = $attrs;
        }
        return $out;
    }

    /** text: a fragment edit inside a static block's markup, or inside one string attribute. */
    private static function texted( array $entry, array $edit, int $n ): array {
        $one = array_intersect_key( $edit, array_flip( [ 'mode', 'old', 'new', 'replaceAll' ] ) );

        if ( isset( $edit['attribute'] ) ) {
            if ( isset( $entry['markup'] ) ) {
                throw new McpAbilityException( sprintf( 'Edit #%d (text): %s is a static block with no separate attributes — drop `attribute` to edit its markup.', (int) $n, esc_html( self::name_of( $entry ) ) ) );
            }
            $key   = (string) $edit['attribute'];
            $attrs = isset( $entry['attributes'] ) && is_array( $entry['attributes'] ) ? $entry['attributes'] : [];
            if ( ! array_key_exists( $key, $attrs ) || ! is_string( $attrs[ $key ] ) ) {
                throw new McpAbilityException( sprintf( 'Edit #%d (text): %s has no string attribute "%s" stored on this page — use op "update" to set it.', (int) $n, esc_html( $entry['name'] ), esc_html( $key ) ) );
            }
            $text = TextEdits::apply( $attrs[ $key ], [ $one ] );
            if ( is_wp_error( $text ) ) {
                throw new McpAbilityException( sprintf( 'Edit #%d (text, %s): %s', (int) $n, esc_html( $key ), self::bare( $text->get_error_message() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- TextEdits quotes the attribute's own text so the agent can copy it; returned as MCP JSON, never echoed as HTML.
            }
            $attrs[ $key ] = $text;
            $type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $entry['name'] );
            if ( $type !== null ) { $attrs = RegisteredBlocks::validate( $type, $attrs, sprintf( 'Edit #%d', $n ) ); }
            return [ 'name' => $entry['name'], 'attributes' => $attrs ];
        }

        if ( ! isset( $entry['markup'] ) ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (text): %s is a server-rendered block — its text lives in attributes. Pass `attribute`, or use op "update".', (int) $n, esc_html( $entry['name'] ) ) );
        }

        // append/prepend act on the whole string, and a block's markup starts and ends with its
        // delimiters — so the text would always land outside the block. Say that, instead of the
        // generic "content outside any block" the parser check below would give.
        if ( in_array( $edit['mode'] ?? 'replace', [ 'append', 'prepend' ], true ) ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (text): %s on markup would put the text outside the block\'s <!-- wp:… --> delimiters. Use insertBefore/insertAfter with an anchor inside the HTML (e.g. old: "</p>").', (int) $n, esc_html( $edit['mode'] ) ) );
        }

        $markup = TextEdits::apply( (string) $entry['markup'], [ $one ] );
        if ( is_wp_error( $markup ) ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (text): %s', (int) $n, self::bare( $markup->get_error_message() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- TextEdits quotes the block's own markup so the agent can copy it; returned as MCP JSON, never echoed as HTML.
        }

        // The edit must leave ONE block of the same kind, in canonical form. Stored markup already
        // is what serialize_blocks() writes, and an edit that stays inside the HTML keeps it that
        // way. Touching a delimiter does not: a removed closer is silently re-added on save (a
        // "success" that changed nothing), and a nested block's closer moved elsewhere re-parses
        // into a differently nested tree. Round-tripping catches both, plus splits and renames.
        $parsed = parse_blocks( $markup );
        $named  = array_values( array_filter( $parsed, static fn( $b ) => ( $b['blockName'] ?? null ) !== null ) );
        if ( count( $named ) !== 1 || $named[0]['blockName'] !== self::name_of( $entry ) || serialize_blocks( $parsed ) !== $markup ) {
            throw new McpAbilityException( sprintf( 'Edit #%d (text): the edit touches the <!-- wp:… --> delimiters of %s or of a block nested in it — the result is not valid block markup. Nothing was saved; keep `old`/`new` inside the HTML text, or use "replace" with the whole block.', (int) $n, esc_html( self::name_of( $entry ) ) ) );
        }
        $serialized = BlockEntry::serialize( [ 'markup' => $markup ], $n, 'edits', true );

        return [ 'markup' => $serialized['markup'] ];
    }

    /** A TextEdits message without its own "Edit #1 (mode): " lead — the op already names the edit. */
    private static function bare( string $message ): string {
        return (string) preg_replace( '/^Edit #\d+ \([a-zA-Z]+\): /', '', $message );
    }

    /**
     * A global block an update or a text edit makes an entry place is checked as a new placement
     * is: it exists, it is the original, and a header goes only into a template. What the entry
     * placed already is carried.
     */
    private static function assert_new_refs( array $before, array $after, int $n, bool $in_template ): void {
        $refs = static function ( array $entry ): array {
            if ( isset( $entry['markup'] ) ) {
                return \Fabrement\Modules\SiteTemplate\Service\TemplateParts::refs( (string) $entry['markup'] );
            }
            $ref = ( $entry['name'] ?? '' ) === 'core/block' ? ( $entry['attributes']['ref'] ?? null ) : null;
            return $ref === null ? [] : [ is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ? (int) $ref : $ref ];
        };
        foreach ( $refs( $after ) as $ref ) {
            if ( ! in_array( $ref, $refs( $before ), true ) ) {
                BlockEntry::serialize( [ 'name' => 'core/block', 'attributes' => [ 'ref' => $ref ] ], $n, 'edits', false, true, $in_template );
            }
        }
    }

    /** @param bool $in_template A template's frame takes what content does not: a header global block. */
    private static function new_block( $raw, int $n, bool $in_template = false ): array {
        if ( ! is_array( $raw ) ) {
            throw new McpAbilityException( sprintf( 'Edit #%d needs `block`: {name, attributes} or {markup}.', (int) $n ) );
        }
        // Both keys is two answers to one question; the serializer would silently take markup.
        if ( isset( $raw['markup'] ) && ( isset( $raw['name'] ) || isset( $raw['attributes'] ) ) ) {
            throw new McpAbilityException( sprintf( 'Edit #%d: `block` has both markup and name/attributes — send {name, attributes} for a block written by its attributes OR {markup} for a block saved as HTML.', (int) $n ) );
        }
        // Validated now, with the same rules as blocks[], so a bad block fails the whole call
        // before anything is written.
        $serialized = BlockEntry::serialize( $raw, $n, 'edits', false, true, $in_template );
        if ( isset( $raw['markup'] ) ) { return [ 'markup' => $serialized['markup'] ]; }
        return BlockEntry::describe( parse_blocks( $serialized['markup'] )[0] );
    }

    private static function name_of( array $entry ): string {
        if ( isset( $entry['name'] ) ) {
            return (string) $entry['name'];
        }
        foreach ( parse_blocks( (string) ( $entry['markup'] ?? '' ) ) as $block ) {
            if ( ( $block['blockName'] ?? null ) !== null ) {
                return (string) $block['blockName'];
            }
        }
        return '';
    }
}
