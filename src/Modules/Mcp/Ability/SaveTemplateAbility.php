<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateWriter;
use Fabrement\Modules\SiteTemplate\Service\TemplateParts;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\Mcp\Support\ContentEdits;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateTranslator;
use Fabrement\Modules\Storage\TextEdits;
use Fabrement\Modules\Translation\Translation;

/**
 * Create a site template or change one — written the way content is: `blocks` for its whole
 * frame, or `edits` against the frame the caller read. Checks each block where a bad one is an
 * error the agent can read, then hands the content to {@see SiteTemplateWriter::save()}, the write
 * path for MCP templates. Native Gutenberg saves use the same block validators.
 */
final class SaveTemplateAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/save-template';
    }

    public function label(): string {
        return 'Save template';
    }

    public function description(): string {
        return 'Create a site template — the frame around pages: a header, the content slot, a footer, and anything else you place — or '
            . 'change one by templateId. Written like content: mode "create" / "replace" take `blocks`, the whole frame in order; '
            . 'mode "edit" takes `edits` against what get-template returned. Use exactly one fabrement/content-source or core/post-content block — '
            . 'the content slot, placed where the page\'s own content goes. Prefer GLOBAL BLOCKS for the header and footer '
            . '({"name":"core/block","attributes":{"ref": id}} — list-synced-patterns {kind:"header"}, or save-synced-pattern): one header '
            . 'then serves every template' . self::multilingual_text( ' and is translated once' ) . '. Check list-templates first: edit the existing one rather than make a duplicate.';
    }

    public function contract(): ?string {
        return 'template-contract';
    }

    public function languages_contract(): ?string {
        return 'template-translation';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'templateId'       => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The template to change (list-templates' . self::multilingual_text( ', or a language version\'s id from get-template' ) . '); absent on create.' ],
                'title'            => [ 'type' => 'string', 'description' => 'Display name. Required on create; omit to keep.' ],
                'mode'             => [
                    'type'        => 'string',
                    'enum'        => [ 'create', 'replace', 'edit' ],
                    'description' => 'Required — there is no default. create: a NEW template; templateId must be absent, `blocks` present. '
                        . 'replace: change that template — its frame becomes exactly `blocks` when sent; without `blocks` only the other fields change. '
                        . 'edit: change part of its frame through `edits`, without re-sending every block.',
                ],
                'blocks'           => [
                    'type'        => 'array',
                    'description' => 'The whole frame, in order. Exactly one fabrement/content-source or core/post-content block marks where the page content goes; it may be nested in a group.',
                    'items'       => [
                        'type'                 => 'object',
                        'properties'           => [
                            'name'       => [ 'type' => 'string', 'description' => 'Registered block name (list-blocks); core/block with attributes.ref for a synced pattern; fabrement/content-source or core/post-content for the content slot. See get-block-source for supported attributes.' ],
                            'attributes' => [ 'type' => 'object', 'description' => 'Fills the block\'s fields — text, links, media {id,url}.' ],
                            'markup'     => [ 'type' => 'string', 'description' => 'INSTEAD of name: literal Gutenberg markup for a static core block.' ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'edits'            => ContentEdits::schema( 'get-template' ),
                'expectedHash'     => [ 'type' => 'string', 'description' => 'mode "edit": `hash` from get-template. If the frame changed since, nothing is saved.' ],
                'contentWidth'     => [ 'type' => 'string', 'enum' => [ 'full', 'constrained' ], 'description' => 'Width of the content area; "full" on create.' ],
                'contentSource' => [ 'type' => 'object', 'additionalProperties' => false, 'required' => [ 'rules' ],
                    'description' => 'Existing theme PHP sources shared by this template and all translations. Omit to preserve; rules:[] returns to page content. Set choices here, not in block attributes. Use list-theme-templates; no file creation or PHP source input.',
                    'properties' => [ 'rules' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => false,
                        'required' => [ 'context', 'file' ], 'properties' => [ 'context' => [ 'type' => 'string', 'description' => 'singular:page, singular:<type>, archive:<type>, blog-home, 404, tax, tax:<taxonomy> or tax:<taxonomy>:<termId>.' ],
                        'file' => [ 'type' => 'string', 'description' => 'Relative path returned by list-theme-templates.' ] ] ] ] ] ],
            ] + self::multilingual( [
                // Absent on a single-language site, where they are one more thing to read and rule out.
                'language'    => [ 'type' => 'string', 'description' => 'CREATE only: the language this template is the original in (list-languages code). Never to translate an existing template — that is translate-template.' ],
                'newOriginal' => [ 'type' => 'boolean', 'description' => 'Required with a non-default language: you checked list-templates and no template like it exists in any language.' ],
            ] ),
            // `mode` has no default: a template dresses many pages, and naming the mode is the one
            // place the caller's intent can be checked.
            'required'             => [ 'mode' ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'template' ],
            'properties' => [
                'template' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'            => [ 'type' => 'integer' ],
                        'title'         => [ 'type' => 'string' ],
                        'slug'          => [ 'type' => 'string' ],
                        'hash'          => [ 'type' => 'string', 'description' => 'The frame\'s hash after this save — the expectedHash of the next mode "edit".' ],
                        'contentWidth'  => [ 'type' => 'string' ],
                        'problem'       => [ 'type' => 'string', 'description' => 'What is wrong with the template, in a sentence; "" when nothing is.' ],
                        'pageCount'     => [ 'type' => 'integer' ],
                        'defaultFor'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    ],
                ],

            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        $language = isset( $input['language'] ) ? trim( (string) $input['language'] ) : '';
        if ( $language !== '' ) {
            $codes = array_column( Translation::bridge()->languages(), 'code' );
            if ( ! empty( $input['templateId'] ) ) {
                return ( new McpAbilityException( '`language` is for CREATE: a template\'s language is set once. Its other languages are versions — translate-template.' ) )->to_error();
            }
            if ( ! in_array( $language, $codes, true ) ) {
                return ( new McpAbilityException( sprintf( '"%s" is not a language on this site — see list-languages.', $language ) ) )->to_error();
            }
            // Off the default language the caller has to SAY it is a new original — the same
            // rule save-content keeps: the usual second "Blog" template is a version made as
            // a template. Nothing is inferred; it changes nothing but the asking.
            $default = Translation::bridge()->default_language();
            if ( $language !== $default && empty( $input['newOriginal'] ) ) {
                return ( new McpAbilityException( sprintf(
                    'Creating a %s template on a site whose default language is %s. Check list-templates first: if a template like it exists, its %s version is made with translate-template {templateId: its id, language: "%s"}, then edited. If none does, pass newOriginal: true.',
                    $language,
                    $default,
                    $language,
                    $language
                ) ) )->to_error();
            }
        }

        try {
            $content = self::content( $input );
        } catch ( McpAbilityException $e ) {
            return $e->to_error();
        }
        if ( is_wp_error( $content ) ) {
            return $content;
        }

        $save = [
            'id'            => $input['templateId'] ?? null,
            'title'         => $input['title'] ?? '',
            // null: this call does not touch the frame.
            'content'       => $content,
            'contentWidth'  => $input['contentWidth'] ?? null,
            'contentSource' => $input['contentSource'] ?? null,
        ];

        $result = SiteTemplateWriter::save( $save );
        if ( is_wp_error( $result ) ) {
            // The same name translate-template gives this case, so one code means one thing.
            if ( $result->get_error_code() === 'fabrement_st_translation_not_manual' ) {
                $data = is_array( $result->get_error_data() ) ? $result->get_error_data() : [];
                return \Fabrement\Modules\Mcp\Support\ToolResult::error( 'translation_owned_by_plugin', $result->get_error_message(), $data );
            }
            return $result;
        }
        // After the insert, as save-content does: the plugin stamps a new post with the
        // request's language on insert, and this rewrites that stamp to the asked one, in a
        // group of its own.
        if ( $language !== '' ) {
            Translation::bridge()->set_language( (int) $result['id'], $language, SiteTemplatePostType::POST_TYPE );
        }

        // The hash pins the next edit.
        $result += [ 'hash' => TextEdits::hash( SiteTemplateTranslator::entries( (int) $result['id'] ) ) ];

        return [ 'template' => $result ];
    }

    /**
     * The template's frame this call asks for, as stored block markup — or null when it names none.
     *
     * Read as content is: `blocks` is the whole of it; `edits` are applied to the frame as
     * get-template returned it, pinned by `expectedHash`. Each block is checked here. The content
     * slot is written by the plugin, and stands in the frame exactly once.
     *
     * @return string|null|\WP_Error
     * @throws McpAbilityException
     */
    private static function content( array $input ) {
        $id   = (int) ( $input['templateId'] ?? 0 );
        $mode = (string) ( $input['mode'] ?? '' );

        if ( $mode === 'create' && $id > 0 ) {
            throw new McpAbilityException( sprintf( 'mode "create" makes a NEW template, but templateId %d was passed. To change that template send "replace" or "edit"; to create one, drop templateId.', (int) $id ) );
        }
        if ( $mode !== 'create' && $id <= 0 ) {
            throw new McpAbilityException( sprintf( 'mode "%s" changes an existing template — pass its templateId (list-templates). A new template is mode "create".', esc_html( $mode ) ) );
        }
        $is_edit = $mode === 'edit';
        if ( ! $is_edit && ( ! in_array( $input['edits'] ?? null, [ null, [], '', '[]' ], true ) || (string) ( $input['expectedHash'] ?? '' ) !== '' ) ) {
            throw new McpAbilityException( '`edits` and `expectedHash` belong to mode "edit" — add mode: "edit", or send `blocks`.' );
        }

        if ( $is_edit ) {
            if ( get_post_type( $id ) !== SiteTemplatePostType::POST_TYPE ) {
                return new \WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
            }
            $current = SiteTemplateTranslator::entries( $id );
            if ( isset( $input['expectedHash'] ) && (string) $input['expectedHash'] !== TextEdits::hash( $current ) ) {
                throw new McpAbilityException(
                    sprintf( 'Template %d changed since you read it (current hash %s). Nothing was saved — get-template again and redo the edits on what is there now.', (int) $id, esc_html( TextEdits::hash( $current ) ) ),
                    [],
                    'content_changed'
                );
            }
            $entries = ContentEdits::apply( $current, $input['edits'] ?? null, 'get-template' );
        } elseif ( array_key_exists( 'blocks', $input ) ) {
            $entries = is_array( $input['blocks'] ) ? array_values( $input['blocks'] ) : [];
        } elseif ( $mode === 'create' ) {
            throw new McpAbilityException( sprintf( 'A new template needs `blocks` — its frame in order, with one {"name":"%s"} where the page\'s content goes.', 'fabrement/content-source' ) );
        } else {
            return null;
        }

        $pieces = [];
        foreach ( $entries as $i => $entry ) {
            if ( ! is_array( $entry ) ) {
                throw new McpAbilityException( sprintf( 'blocks[%d] must be an object.', (int) $i ) );
            }
            // In an edit the untouched blocks come from the stored frame: carried, not re-checked.
            $pieces[] = BlockEntry::serialize( $entry, (int) $i, 'blocks', $is_edit, ! $is_edit, true )['markup'];
        }
        $content = implode( "\n\n", $pieces );
        SiteTemplateWriter::assert_slot( $content );

        // A global block this call places — at any depth, written as a name or as markup — must be
        // one WordPress draws. What the frame already placed is carried as it is.
        $placed = $id > 0 ? TemplateParts::refs( (string) get_post_field( 'post_content', $id ) ) : [];
        foreach ( array_diff( TemplateParts::refs( $content ), $placed ) as $ref ) {
            // The original is what is placed and what "where is it used" is asked of; each language is shown its own version.
            $original = TemplateParts::original_of( $ref );
            if ( $original !== $ref && $original > 0 ) {
                throw new McpAbilityException( sprintf( 'Global block %1$d is a language version — place the original, {"ref": %2$d}; each language is shown its own version automatically. Nothing was saved.', (int) $ref, (int) $original ) );
            }
            if ( ! TemplateParts::draws( $ref ) ) {
                throw new McpAbilityException( sprintf( 'Global block %1$d draws nothing in a template: %2$s (To publish a draft: save-synced-pattern {patternId: %1$d, mode: "replace", status: "publish"}.) Nothing was saved.', (int) $ref, esc_html( TemplateParts::problem( $ref ) ) ) );
            }
        }
        return $content;
    }
}
