<?php

namespace Fabrement\Modules\Mcp\Support;

use Fabrement\Modules\SiteTemplate\Service\SyncedPatterns;

/**
 * One block, the way an agent hands it over and the way it is handed back.
 *
 * There are exactly two shapes, and they are not interchangeable — the split is WordPress's,
 * not ours:
 *   { name, attributes? }  — a known attribute-only save contract (Fabrement, ACF without saved
 *                            inner content, core/block). Stored as a self-closing comment.
 *   { markup }             — static blocks, blocks with saved inner content, and unverified
 *                            third-party save contracts. A PHP render callback alone does not
 *                            establish whether JavaScript save() also needs to produce HTML.
 *
 * This is the one place that knows the rule, so save-content, save-synced-pattern and
 * save-template agree to the byte instead of drifting.
 */
final class BlockEntry {

    /**
     * One entry → the block-comment markup to store. Throws (McpAbilityException) with the index
     * so the caller can point at the offending element.
     *
     * @param array<string,mixed> $entry
     * @param string              $label What to call the array in errors, e.g. "blocks" or "edits".
     * @return array{markup:string,blocks:list<string>} `blocks` is the registered names it turned
     *                                                   out to contain, for the tool's answer.
     */
    public static function serialize( array $entry, int $index, string $label = 'blocks', bool $allow_fabrement_markup = false, bool $check_attributes = true, bool $in_template = false ): array {
        if ( $check_attributes ) {
            ContentSafety::entry( $entry, sprintf( '%s[%d]', $label, $index ) );
        }
        // A CORE block arrives as literal markup: static blocks keep their content in the saved
        // markup and have no id/attribute form. Checked before `name` — the two are alternatives.
        if ( isset( $entry['markup'] ) ) {
            return self::from_markup( $entry['markup'], $index, $label, $allow_fabrement_markup, $in_template, $check_attributes );
        }

        $name = isset( $entry['name'] ) ? trim( (string) $entry['name'] ) : '';
        if ( $name === '' ) {
            throw new McpAbilityException(
                sprintf( '%s[%d] needs a `name` (from list-blocks) or a `markup` string.', esc_html( $label ), (int) $index )
            );
        }

        $type =\WP_Block_Type_Registry::get_instance()->get_registered( $name );
        if ( $type === null ) {
            throw new McpAbilityException(
                sprintf(
                    '%s[%d]: no block named "%s" is registered on this site. Use a name from list-blocks, or activate the theme/plugin that registers this block.',
                    esc_html( $label ),
                    (int) $index,
                    esc_html( $name )
                )
            );
        }

        // Registered and dynamic, yet not for content ({@see ContentBlockRules}). Not asked of a
        // stored body carried back unchanged — the flag that lets its fabrement markup through.
        $refused = $allow_fabrement_markup || ( $in_template && $name === 'fabrement/content-source' ) ? null : ContentBlockRules::refusal( $name );
        if ( $refused !== null ) {
            throw new McpAbilityException(
                sprintf( '%s[%d]: "%s" cannot be placed in content — %s', esc_html( $label ), (int) $index, esc_html( $name ), esc_html( $refused ) )
            );
        }

        if ( ! $type->is_dynamic() ) {
            throw new McpAbilityException(
                sprintf(
                    '%s[%d]: "%s" is a static block — its content lives in the saved markup, so it cannot be written from a name and attributes. Pass it as {markup: "<!-- wp:%s -->…<!-- /wp:%s -->"} instead.',
                    esc_html( $label ),
                    (int) $index,
                    esc_html( $name ),
                    esc_html( $name ),
                    esc_html( $name )
                )
            );
        }
        if ( $check_attributes && RegisteredBlocks::write_mode( $type ) !== 'attributes' ) {
            throw new McpAbilityException(
                sprintf( '%s[%d]: "%s" requires complete saved markup. Its PHP render callback does not establish an attribute-only save() contract. Use {markup} from the editor or get-content.', esc_html( $label ), (int) $index, esc_html( $name ) ),
                [], 'block_markup_required'
            );
        }

        $attributes = isset( $entry['attributes'] ) && is_array( $entry['attributes'] ) ? $entry['attributes'] : [];
        if ( $check_attributes ) {
            $attributes = RegisteredBlocks::validate( $type, $attributes, sprintf( '%s[%d]', $label, $index ) );
        }
        // A GLOBAL BLOCK placement. A ref to nothing renders an empty space with no error anywhere
        // but the editor's "Block has been deleted or is unavailable". Checked only for what the
        // caller SENT ($check_attributes): a stored placement whose pattern was deleted since must
        // not make every later edit of that page fail.
        if ( $name === 'core/block' ) {
            $ref = $attributes['ref'] ?? null;
            if ( is_string( $ref ) && ctype_digit( $ref ) ) {
                $ref = (int) $ref;
            }
            if ( $check_attributes && ! is_int( $ref ) ) {
                throw new McpAbilityException(
                    sprintf( '%s[%d]: core/block needs `ref` — the id of an existing synced pattern (list-synced-patterns).', esc_html( $label ), (int) $index )
                );
            }
            if ( $check_attributes && SyncedPatterns::placeable( $ref ) === null ) {
                throw new McpAbilityException( esc_html( self::missing_pattern( $ref, sprintf( '%s[%d]', $label, $index ) ) ) );
            }
            if ( $check_attributes && is_int( $ref ) ) {
                self::assert_original( $ref, sprintf( '%s[%d]', $label, $index ) );
            }
            if ( $check_attributes && is_int( $ref ) && ! $in_template ) {
                self::assert_not_frame( $ref, sprintf( '%s[%d]', $label, $index ) );
            }
            // Stored as a number, the way the editor writes it — a quoted ref is invisible to the
            // placement search and to the self-reference guard.
            if ( is_int( $ref ) ) {
                $attributes['ref'] = $ref;
            }
        }
        $title      = isset( $type->title ) && $type->title !== '' ? (string) $type->title : $name;

        return [ 'markup' => self::comment( $name, $attributes ), 'blocks' => [ $name ], 'title' => $title ];
    }

    /**
     * A parsed block → the entry shape a read hands back. A known attribute-only save contract
     * with no inner content becomes { name, attributes }; everything else becomes { markup }
     * so saved HTML and blocks with unknown client-side save contracts round-trip intact.
     *
     * @param array<string,mixed> $block A single parse_blocks() node.
     * @return array<string,mixed>
     */
    public static function describe( array $block ): array {
        $name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
        $type = $name !== '' ? \WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;

        $has_markup = trim( (string) ( $block['innerHTML'] ?? '' ) ) !== ''
            || ! empty( $block['innerBlocks'] );

        // Only a known save contract with no saved content can be edited by attributes.
        if ( $type !== null && RegisteredBlocks::write_mode( $type ) === 'attributes' && ! $has_markup ) {
            $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
            $entry = [ 'name' => $name ];
            if ( $attrs !== [] ) {
                $entry['attributes'] = $attrs;
            }
            return $entry;
        }

        // Everything else is described by exactly what WordPress stored.
        return [ 'markup' => serialize_block( $block ) ];
    }

    /**
     * A record's whole body as entries save-content takes back — {name, attributes} for a
     * server-rendered block, {markup} for a static one — so the agent edits in place and sends
     * the list to save-content {contentId, mode:"replace", blocks}.
     *
     * @return list<array<string,mixed>>
     */
    public static function of_post( int $post_id ): array {
        $content = (string) get_post_field( 'post_content', $post_id );
        if ( trim( $content ) === '' ) {
            return [];
        }
        $out = [];
        foreach ( parse_blocks( $content ) as $block ) {
            if ( ! isset( $block['blockName'] ) || $block['blockName'] === null ) {
                // CLASSIC CONTENT IS CONTENT. parse_blocks() answers a nameless node for text
                // written before the block editor — or by anyone who used the classic one — and
                // skipping it told the reader the page was EMPTY while the paragraph sat in the
                // database and rendered on the site. An agent that believes an empty answer and
                // sends the body back has just deleted writing it never saw. Measured on this
                // site: a page reported hasContent:true by one tool and blocks:[] by
                // this one, over `<p>Everything we have ever published.</p>`.
                //
                // Handed over as { markup }, which is the shape save-content already accepts for
                // anything it cannot name, so it round-trips untouched without the agent having to
                // understand it. Whitespace between blocks is not content and is still dropped.
                $raw = trim( (string) ( $block['innerHTML'] ?? '' ) );
                if ( $raw !== '' ) {
                    // Wrapped as core/freeform — WordPress's own name for classic content — and not
                    // handed over bare, because the write side refuses markup with no block
                    // delimiter (see from_markup). Bare, the entry could be read and never sent
                    // back; wrapped, it round-trips through save-content untouched and renders the
                    // same, which is what the classic block is for.
                    $out[] = [ 'markup' => '<!-- wp:freeform -->' . $raw . '<!-- /wp:freeform -->' ];
                }
                continue;
            }
            $out[] = self::describe( $block );
        }
        return $out;
    }

    /** Why a numeric ref is refused: the pattern is gone, in the bin, or not synced. */
    private static function missing_pattern( int $ref, string $where ): string {
        return sprintf( '%s: synced pattern %d does not exist, is in the trash or is not synced — see list-synced-patterns.', $where, $ref );
    }

    /**
     * core/block refs written as quoted digits become numbers, in place and at every depth — the
     * editor writes a number, and a quoted ref is invisible to the placement search.
     *
     * @param array<int,array<string,mixed>> $blocks
     */
    private static function normalise_refs( array &$blocks ): void {
        foreach ( $blocks as &$block ) {
            if ( ( $block['blockName'] ?? '' ) === 'core/block' && is_string( $block['attrs']['ref'] ?? null ) && ctype_digit( $block['attrs']['ref'] ) ) {
                $block['attrs']['ref'] = (int) $block['attrs']['ref'];
            }
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                self::normalise_refs( $block['innerBlocks'] );
            }
        }
        unset( $block );
    }

    /**
     * A page or a template places the ORIGINAL of a global block: each language is shown its own
     * version, and "where is it placed" is asked of the original.
     */
    private static function assert_original( int $ref, string $where ): void {
        $original = \Fabrement\Modules\SiteTemplate\Service\TemplateParts::original_of( $ref );
        if ( $original !== $ref && $original > 0 ) {
            throw new McpAbilityException(
                sprintf( '%1$s: global block %2$d is a language version — place the original, {"ref": %3$d}; each language is shown its own version automatically.', esc_html( $where ), (int) $ref, (int) $original )
            );
        }
    }

    private static function assert_not_frame( int $ref, string $where ): void {
        $post = SyncedPatterns::placeable( $ref );
        $kind = $post !== null ? SyncedPatterns::kind( $ref ) : 'section';
        if ( $kind !== 'section' ) {
            throw new McpAbilityException(
                sprintf( '%s: global block %d is a %s — it is placed in a template (save-template), not in content.', esc_html( $where ), (int) $ref, esc_html( $kind ) )
            );
        }
    }

    /**
     * Validate a markup entry and normalise it to what WordPress round-trips.
     *
     * @return array{markup:string,blocks:list<string>}
     */
    private static function from_markup( $raw, int $index, string $label, bool $allow_fabrement = false, bool $in_template = false, bool $check_attributes = true ): array {
        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            throw new McpAbilityException( sprintf( '%s[%d].markup must be a non-empty string.', esc_html( $label ), (int) $index ) );
        }
        $raw = trim( $raw );

        if ( ! has_blocks( $raw ) ) {
            throw new McpAbilityException(
                sprintf( '%s[%d].markup has no block delimiter — wrap the content, e.g. <!-- wp:paragraph --><p>text</p><!-- /wp:paragraph -->.', esc_html( $label ), (int) $index )
            );
        }

        $registry = \WP_Block_Type_Registry::get_instance();
        $names    = [];

        $walk = static function ( array &$list ) use ( &$walk, &$names, $registry, $index, $label, $allow_fabrement, $in_template, $check_attributes ): void {
            foreach ( $list as &$block ) {
                $name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

                if ( '' === $name ) {
                    if ( trim( (string) ( $block['innerHTML'] ?? '' ) ) !== '' ) {
                        throw new McpAbilityException(
                            sprintf( '%s[%d].markup has content outside any block — every piece of text must sit inside its own block.', esc_html( $label ), (int) $index )
                        );
                    }
                    continue;
                }

                // The plugin's own blocks (the content slot) are not written as markup into a page;
                // a template reads its own body back as markup, where its slot belongs.
                if ( ! $allow_fabrement && str_starts_with( $name, 'fabrement/' ) && ! ( $in_template && $name === 'fabrement/content-source' ) ) {
                    throw new McpAbilityException(
                        sprintf( '%s[%d].markup contains %s — it belongs to a template (save-template), not to page content.', esc_html( $label ), (int) $index, esc_html( $name ) )
                    );
                }

                if ( ! $registry->is_registered( $name ) ) {
                    throw new McpAbilityException(
                        sprintf( '%s[%d].markup uses %s, which is not a registered block on this site — check the name.', esc_html( $label ), (int) $index, esc_html( $name ) )
                    );
                }

                // The same placement check as a {name} entry — for markup the caller wrote, not for
                // a stored body carried back unchanged ($allow_fabrement marks that path).
                if ( ! $allow_fabrement && $name === 'core/block' ) {
                    $ref = $block['attrs']['ref'] ?? null;
                    if ( ! is_int( $ref ) ) {
                        throw new McpAbilityException(
                            sprintf( '%s[%d].markup places core/block without a numeric `ref` to an existing synced pattern (list-synced-patterns).', esc_html( $label ), (int) $index )
                        );
                    }
                    if ( SyncedPatterns::placeable( $ref ) === null ) {
                        throw new McpAbilityException( esc_html( self::missing_pattern( $ref, sprintf( '%s[%d].markup', $label, $index ) ) ) );
                    }
                    self::assert_original( $ref, sprintf( '%s[%d].markup', $label, $index ) );
                    if ( ! $in_template ) {
                        self::assert_not_frame( $ref, sprintf( '%s[%d].markup', $label, $index ) );
                    }
                }

                if ( $check_attributes ) {
                    $block['attrs'] = RegisteredBlocks::validate( $registry->get_registered( $name ), $block['attrs'] ?? [], sprintf( '%s[%d].%s', $label, $index, $name ) );
                }
                $names[] = $name;

                if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                    $walk( $block['innerBlocks'] );
                }
            }
        };

        $parsed = parse_blocks( $raw );
        self::normalise_refs( $parsed );
        $walk( $parsed );

        if ( $names === [] ) {
            throw new McpAbilityException( sprintf( '%s[%d].markup contains no blocks.', esc_html( $label ), (int) $index ) );
        }

        // Store what WordPress round-trips, not the caller's string: serialize_blocks() is what
        // the editor would have written, so an admin re-save does not "correct" it into a diff.
        return [ 'markup' => serialize_blocks( $parsed ), 'blocks' => array_values( array_unique( $names ) ) ];
    }

    /**
     * Refuse attribute names the block does not declare.
     *
     * WordPress keeps an unknown attribute in the saved comment and never passes it to the
     * block, so the write "succeeds", the page shows the block's default instead, and nothing
     * anywhere says why — a page built with `sectionTitle` for a block whose attribute is
     * `heading` showed the default title for weeks. The registry already knows every name the
     * block accepts, including the ones WordPress adds itself (className, style, anchor, lock,
     * metadata), so the check needs no list of its own.
     *
     * @param list<int|string> $keys
     */
    public static function assert_known_attributes( \WP_Block_Type $type, array $keys, string $where ): void {
        $declared = is_array( $type->attributes ) ? array_keys( $type->attributes ) : [];
        $unknown  = array_values( array_diff( array_map( 'strval', $keys ), $declared ) );
        if ( $unknown === [] ) {
            return;
        }

        // The block's own fields first; the attributes WordPress adds to every block are noise here.
        $own = array_values( array_diff( $declared, [ 'lock', 'metadata', 'className', 'style', 'anchor', 'align', 'backgroundColor', 'textColor', 'gradient', 'fontSize', 'fontFamily', 'borderColor' ] ) );

        throw new McpAbilityException(
            sprintf(
                '%s: %s %s not %s of %s, so it would be saved and ignored — the block would show its default instead. Its own attributes: %s; the design ones every block shares (align, style, textColor, fontSize…) are in get-block-source.',
                esc_html( $where ),
                esc_html( implode( ', ', array_map( static fn( $k ) => '`' . $k . '`', $unknown ) ) ),
                count( $unknown ) === 1 ? 'is' : 'are',
                count( $unknown ) === 1 ? 'an attribute' : 'attributes',
                esc_html( $type->name ),
                $own === [] ? '(no attributes of its own)' : esc_html( implode( ', ', $own ) )
            ),
            [ 'unknown' => $unknown, 'attributes' => $own ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured error data array, not output.
            'unknown_attribute'
        );
    }

    /** `<!-- wp:name {json} /-->` — a self-closing dynamic-block comment. */
    private static function comment( string $name, array $attributes ): string {
        if ( $attributes === [] ) {
            return '<!-- wp:' . $name . ' /-->';
        }
        $json = wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( $json === false ) {
            throw new McpAbilityException( 'Attributes are not JSON-encodable.' );
        }
        // Core escapes comment delimiters and HTML characters inside attribute strings.
        return get_comment_delimited_block_content( $name, $attributes, '' );
    }
}
