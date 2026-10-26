<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\Mcp\Support\ContentEdits;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\SiteTemplate\Service\SyncedPatterns;
use Fabrement\Modules\Storage\TextEdits;

/**
 * Create a GLOBAL BLOCK (synced pattern) or change one. The body is the same block vocabulary
 * save-content takes, so a section built for one page becomes a shared one without translation.
 *
 * A pattern is created PUBLISHED: a synced pattern is not a page anyone visits — it is a shared
 * part that renders wherever it is placed — and a draft one would render nowhere, so the first
 * page that places it would show a hole.
 */
final class SaveSyncedPatternAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/save-synced-pattern';
    }

    public function label(): string {
        return 'Save synced pattern';
    }

    public function description(): string {
        return 'Create a GLOBAL BLOCK (synced pattern) from installed blocks, or change one by patternId — every page and template placing '
            . 'it changes with it. `mode` is REQUIRED and has no default: create (new pattern, no patternId) · replace '
            . '(the body becomes exactly `blocks`) · edit (change part of it through `edits`). '
            . 'blocks[] as in save-content ({name, attributes} or {markup}). '
            . 'Change part of an existing one with mode "edit" + edits + expectedHash from '
            . 'get-synced-pattern. Place it as {name:"core/block", attributes:{ref: patternId}}: a section on a page (save-content), '
            . 'a header or footer (`kind`) in a template (save-template).';
    }

    public function contract(): ?string {
        return 'synced-pattern-contract';
    }

    public function input_schema(): array {
        $block = [
            'type'                 => 'object',
            'properties'           => [
                'name'       => [ 'type' => 'string' ],
                'attributes' => [ 'type' => 'object' ],
                'markup'     => [ 'type' => 'string' ],
            ],
            'additionalProperties' => false,
        ];

        return [
            'type'                 => 'object',
            'properties'           => [
                'patternId'    => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Omit to create.' ],
                'title'        => [ 'type' => 'string', 'description' => 'Required on create; the name admins pick it by.' ],
                'blocks'       => [ 'type' => 'array', 'items' => $block, 'description' => 'The whole body (create, or replace an existing one).' ],
                'mode'         => [
                    'type'        => 'string',
                    'enum'        => [ 'create', 'replace', 'edit' ],
                    'description' => 'Required — there is no default. create: a NEW pattern; patternId must be absent. '
                        . 'replace: the body of that pattern becomes exactly `blocks`. edit: change part of it through '
                        . '`edits`, without re-sending every block.',
                ],
                'edits'        => ContentEdits::schema( 'get-synced-pattern' ),
                'expectedHash' => [ 'type' => 'string' ],
                'categories'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'The pattern categories to file it under, so it is found again (list-synced-patterns {category}, the editor\'s pattern list): ' . implode( ', ', array_keys( SyncedPatterns::CATEGORIES ) ) . ' — or the slug of one the site owner made (it shows in `categories`). It becomes EXACTLY this list: send back what get-synced-pattern showed plus or minus your change; [] clears them all; omit to keep. A header / footer is `kind`, not a category here.' ],
                'status'       => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ], 'description' => 'publish: a draft global block is drawn again (with mode "replace" and no blocks, nothing else changes). draft: takes it out of use — refused while a template or a page places it. Omitted: a new one is published, an existing one keeps its status.' ],
                'kind'         => [ 'type' => 'string', 'enum' => [ 'header', 'footer', 'section' ], 'description' => 'header / footer: a template\'s header or footer, placed in save-template blocks; filed under that pattern category (Headers / Footers). section (default on create): placed on pages. Omit on update to keep.' ],
            ] + self::multilingual( [
                // Absent on a single-language site, where they are one more thing to read and rule out.
                'language'    => [ 'type' => 'string', 'description' => 'CREATE only: the language this global block is the original in (list-languages code); the default language when omitted. Never to translate an existing one — that is translate-synced-pattern.' ],
                'newOriginal' => [ 'type' => 'boolean', 'description' => 'Required with a non-default language: you checked list-synced-patterns and no global block like it exists in any language.' ],
            ] ),
            // `mode` has no default. Every page placing this pattern changes with it, so a caller
            // that meant one thing and got another changes the whole site at once — naming the
            // mode is the one place that intent can be checked.
            'required'             => [ 'mode' ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'patternId', 'title', 'blocks', 'usedBy' ],
            'properties' => [
                'patternId' => [ 'type' => 'integer' ],
                'title'     => [ 'type' => 'string' ],
                'kind'      => [ 'type' => 'string', 'description' => 'header / footer (placed by templates) or section (placed on pages).' ],
                'created'   => [ 'type' => 'boolean' ],
                'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'status'    => [ 'type' => 'string', 'description' => 'publish, or draft — a draft draws nothing where it is placed.' ],
                'blocks'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'usedBy'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Records this change reached.' ],
                'shownThrough' => [ 'type' => 'integer', 'description' => 'On a language version only: the id of its original. Nothing places a version itself — it is shown, to readers of its language, wherever the original stands, and `usedBy`' . '' . ' are those places.' ],
                'placement' => [ 'type' => 'string', 'description' => 'The markup that places it — the id of the ORIGINAL, also when a language version was saved.' ],
                'editUrl'   => [ 'type' => 'string' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        try {
            return $this->save( $input );
        } catch ( McpAbilityException $e ) {
            return $e->to_error();
        }
    }

    /**
     * `mode` says what this call does to the pattern. The input schema already requires it and
     * limits it to create / replace / edit; how it pairs with `patternId` is checked here, and
     * each refusal names the mode that fits — this writes to every page that places the pattern.
     *
     * @throws McpAbilityException When the mode disagrees with patternId.
     */
    private static function assert_mode( array $input, int $id ): void {
        $mode = (string) $input['mode'];

        if ( $mode === 'create' && $id > 0 ) {
            throw new McpAbilityException(
                sprintf( 'mode "create" makes a NEW pattern, but patternId %d was passed. To change that pattern send "replace" or "edit"; to create one, drop patternId.', (int) $id )
            );
        }

        if ( $mode !== 'create' && $id <= 0 ) {
            throw new McpAbilityException(
                sprintf( 'mode "%s" changes an existing pattern — pass its patternId (list-synced-patterns). A new pattern is mode "create".', esc_html( $mode ) )
            );
        }
    }

    /**
     * The language a NEW global block is the original in ('' = the request's, the default). The
     * same asking as save-template and save-content: off the default language the caller says it
     * is a new original, since the usual second one is a version made as a pattern.
     *
     * @throws McpAbilityException
     */
    private static function language( array $input, bool $exists ): string {
        $language = isset( $input['language'] ) ? trim( (string) $input['language'] ) : '';
        if ( $language === '' ) {
            return '';
        }
        if ( $exists ) {
            throw new McpAbilityException( '`language` is for CREATE: a global block\'s language is set once. Its other languages are versions — translate-synced-pattern.' );
        }
        $bridge = \Fabrement\Modules\Translation\Translation::bridge();
        if ( ! in_array( $language, array_column( $bridge->languages(), 'code' ), true ) ) {
            throw new McpAbilityException( sprintf( '"%s" is not a language on this site — see list-languages.', esc_html( $language ) ) );
        }
        $default = $bridge->default_language();
        if ( $language !== $default && empty( $input['newOriginal'] ) ) {
            throw new McpAbilityException( sprintf(
                'Creating a %1$s global block on a site whose default language is %2$s. Check list-synced-patterns first: if one like it exists, its %1$s version is made with translate-synced-pattern {patternId: its id, language: "%1$s"}, then edited. If none does, pass newOriginal: true.',
                esc_html( $language ),
                esc_html( $default )
            ) );
        }
        return $language;
    }

    /**
     * The status this call asks for, or null to leave it. A global block that is placed is not
     * made a draft: every place it stands would draw nothing.
     *
     * @throws McpAbilityException
     */
    private static function status( array $input, int $id ): ?string {
        $status = isset( $input['status'] ) ? (string) $input['status'] : '';
        if ( $status === '' ) {
            return null;
        }
        if ( ! in_array( $status, [ 'publish', 'draft' ], true ) ) {
            throw new McpAbilityException( '`status` is "publish" or "draft".' );
        }
        if ( $status === 'draft' && $id > 0 ) {
            // Asked of the original: it is what templates and pages place, whichever language this is.
            $placed = \Fabrement\Modules\SiteTemplate\Service\TemplateParts::original_of( $id ) ?: $id;
            $used   = SyncedPatterns::placements( $placed, 100 );
            if ( $used !== [] ) {
                throw new McpAbilityException(
                    sprintf(
                        'Pattern %1$d is placed in %2$d record(s): %3$s. As a draft it would draw nothing there — take it out of each first (save-template / save-content mode "edit"), then make it a draft' . ( $placed !== $id ? '; to stop showing this language\'s version alone, delete the version (delete-synced-pattern) and its pages show the original' : '' ) . '.',
                        (int) $id,
                        count( $used ),
                        implode( '; ', array_map( static fn( array $p ): string => sprintf( '%d "%s"', $p['id'], $p['title'] ), array_slice( $used, 0, 5 ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a tool's answer, read as JSON text; escaping turns the titles' quotes into entities.
                    ),
                    [ 'usedBy' => $used ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error data array, not output.
                    'pattern_in_use'
                );
            }
        }
        return $status;
    }

    private function save( array $input ): array {
        $id       = isset( $input['patternId'] ) ? (int) $input['patternId'] : 0;
        $existing = null;

        self::assert_mode( $input, $id );

        if ( $id > 0 ) {
            $existing = SyncedPatterns::placeable( $id );
            if ( $existing === null ) {
                throw new McpAbilityException( sprintf( 'No synced pattern with id %d — see list-synced-patterns.', (int) $id ) );
            }
            if ( ! current_user_can( 'edit_post', $id ) ) {
                throw new McpAbilityException( sprintf( 'You cannot edit pattern %d on this site.', (int) $id ) );
            }
            // A language version the translation plugin's own editor owns is rebuilt from the
            // original on its next job — words written here would vanish without a trace.
            if ( \Fabrement\Modules\Translation\Translation::is_multilingual() ) {
                $bridge = \Fabrement\Modules\Translation\Translation::bridge();
                if ( ! $bridge->is_original( $id, SyncedPatterns::POST_TYPE ) && ! $bridge->writes_translations_manually( $id ) ) {
                    $screen = $bridge->translation_screen_url( $id );
                    throw new McpAbilityException(
                        'This language version is written by the site\'s translation plugin: it rebuilds each language from the original, so a change made here would be overwritten by its next job. Do not write; tell the user it is translated in that plugin\'s own editor'
                            . ( $screen !== '' ? ' — ' . esc_url_raw( $screen ) : '' ) . '.',
                        [ 'screenUrl' => $screen ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error data array, not output.
                        'translation_owned_by_plugin'
                    );
                }
            }
        } elseif ( trim( (string) ( $input['title'] ?? '' ) ) === '' ) {
            throw new McpAbilityException( 'A new synced pattern needs a `title`.' );
        }

        $categories = null;
        if ( array_key_exists( 'categories', $input ) ) {
            // A client holding a stale schema sends the list JSON-encoded; anything else is not a list.
            $sent = TextEdits::decode_object( $input['categories'] );
            if ( ! is_array( $sent ) ) {
                throw new McpAbilityException( '`categories` is a list of category slugs, e.g. ["testimonials"] — [] to clear them.' );
            }
            $categories = array_values( array_unique( array_map( 'strval', $sent ) ) );
            $unknown    = array_values( array_filter( $categories, static fn( string $slug ): bool => ! SyncedPatterns::is_category( $slug ) ) );
            if ( $unknown !== [] ) {
                throw new McpAbilityException( sprintf( '`categories`: %s is not a pattern category on this site — use %s, or the slug of one the site owner made (get-synced-pattern `categories`). A header or footer is `kind`.', esc_html( implode( ', ', $unknown ) ), esc_html( implode( ', ', array_keys( SyncedPatterns::CATEGORIES ) ) ) ) );
            }
        }
        $language = self::language( $input, $existing !== null );
        $status   = self::status( $input, $id );

        $is_edit = ( $input['mode'] ?? '' ) === 'edit';
        if ( $is_edit ) {
            if ( $existing === null ) {
                throw new McpAbilityException( 'mode "edit" changes an existing pattern — pass its patternId.' );
            }
            $current = BlockEntry::of_post( $id );
            if ( isset( $input['expectedHash'] ) && (string) $input['expectedHash'] !== TextEdits::hash( $current ) ) {
                throw new McpAbilityException(
                    sprintf( 'Pattern %d changed since you read it (current hash %s). Nothing was saved — get-synced-pattern again.', (int) $id, esc_html( TextEdits::hash( $current ) ) ),
                    [],
                    'content_changed'
                );
            }
            $entries = ContentEdits::apply( $current, $input['edits'] ?? null, 'get-synced-pattern' );
        } elseif ( array_key_exists( 'blocks', $input ) ) {
            $entries = is_array( $input['blocks'] ) ? $input['blocks'] : [];
        } elseif ( $existing === null ) {
            throw new McpAbilityException( 'A new synced pattern needs `blocks`.' );
        } else {
            $entries = null; // title-only change
        }

        $content = null;
        if ( $entries !== null ) {
            $pieces = [];
            foreach ( array_values( $entries ) as $i => $entry ) {
                if ( ! is_array( $entry ) ) {
                    throw new McpAbilityException( sprintf( 'blocks[%d] must be an object.', (int) $i ) );
                }
                $piece    = BlockEntry::serialize( $entry, $i, 'blocks', $is_edit, ! $is_edit );
                $pieces[] = $piece['markup'];
            }
            // A pattern placing itself renders nothing, and WordPress stops it only at render time.
            // BlockEntry writes the full name (wp:core/block); the editor writes the short one (wp:block).
            if ( $id > 0 && preg_match( '/<!-- wp:(?:core\/)?block \{[^}]*"ref":' . $id . '[,}]/', implode( '', $pieces ) ) === 1 ) {
                throw new McpAbilityException( 'A pattern cannot place itself.' );
            }
            $content = implode( "\n\n", $pieces );
        }

        // What it IS — header, footer or section — is the caller's word, kept as the pattern's
        // category. Omitted: a new pattern is a section, an existing one keeps its kind.
        $kind = isset( $input['kind'] ) ? (string) $input['kind'] : null;
        if ( $kind !== null && ! in_array( $kind, [ 'header', 'footer', 'section' ], true ) ) {
            throw new McpAbilityException( '`kind` is "header", "footer" or "section".' );
        }
        // The kind belongs to the whole group and is set on the ORIGINAL — the id templates and pages
        // place, so the checks below only see real placements there. A version follows its original.
        if ( $existing !== null && $kind !== null && \Fabrement\Modules\Translation\Translation::is_multilingual() ) {
            $bridge = \Fabrement\Modules\Translation\Translation::bridge();
            if ( ! $bridge->is_original( $id, SyncedPatterns::POST_TYPE ) ) {
                $original = \Fabrement\Modules\SiteTemplate\Service\TemplateParts::original_of( $id );
                throw new McpAbilityException(
                    sprintf( 'Pattern %d is a language version; its kind is set on the original (id %d) and every version follows it. Send kind with patternId %d.', (int) $id, (int) $original, (int) $original ),
                    [ 'originalId' => (int) $original ],
                    'not_an_original'
                );
            }
        }
        // A header templates place stays a header.
        if ( $existing !== null && $kind !== null ) {
            $was = SyncedPatterns::kind( $id );
            if ( $was !== 'section' && $kind !== $was ) {
                $templates = array_values( array_filter( SyncedPatterns::placements( $id, 100 ), static fn( array $p ): bool => $p['postType'] === \Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType::POST_TYPE ) );
                if ( $templates !== [] ) {
                    throw new McpAbilityException(
                        sprintf( 'Pattern %d is the %s of %d template(s) — it stays a %s. Make a new global block instead.', (int) $id, esc_html( $was ), count( $templates ), esc_html( $was ) ),
                        [ 'templates' => $templates ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error data array, not output.
                        'pattern_kind_change'
                    );
                }
            }
        }
        // A section placed in a page's content cannot become a header: it would stay there, a header
        // in the middle of the page. A template may hold it either way.
        if ( $existing !== null && $kind !== null && $kind !== 'section' && SyncedPatterns::kind( $id ) === 'section' ) {
            $pages = array_values( array_filter(
                SyncedPatterns::placements( $id, 100 ),
                static fn( array $p ): bool => $p['postType'] !== \Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType::POST_TYPE
            ) );
            if ( $pages !== [] ) {
                throw new McpAbilityException(
                    sprintf( 'Pattern %d is placed as a section in %d record(s); a %s is placed in templates only. Remove those placements first, or make a new global block.', (int) $id, count( $pages ), esc_html( $kind ) ),
                    [ 'usedBy' => $pages ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error data array, not output.
                    'pattern_kind_change'
                );
            }
        }

        $postarr = [ 'post_type' => SyncedPatterns::POST_TYPE ];
        if ( trim( (string) ( $input['title'] ?? '' ) ) !== '' ) {
            $postarr['post_title'] = trim( (string) $input['title'] );
        }
        if ( $content !== null ) {
            // See save-content: the attribute JSON must survive wp_unslash().
            $postarr['post_content'] = wp_slash( $content );
        }

        if ( $status !== null ) {
            $postarr['post_status'] = $status;
        }
        if ( $existing === null ) {
            $postarr['post_status'] = $status ?? 'publish';
            $result                 = wp_insert_post( $postarr, true );
        } else {
            $postarr['ID'] = $id;
            $result        = wp_update_post( $postarr, true );
        }
        if ( is_wp_error( $result ) ) {
            throw new McpAbilityException( 'Could not save the pattern: ' . esc_html( $result->get_error_message() ) );
        }
        $id = (int) $result;
        // After the insert, as save-template does: the plugin stamps a new post with the request's
        // language, and this rewrites that stamp to the asked one, in a group of its own.
        if ( $existing === null && $language !== '' ) {
            \Fabrement\Modules\Translation\Translation::bridge()->set_language( $id, $language, SyncedPatterns::POST_TYPE );
        }
        if ( $kind !== null || $categories !== null ) {
            // Every language version is the same kind of thing, filed the same way.
            $group = \Fabrement\Modules\Translation\Translation::is_multilingual()
                ? array_map( 'intval', array_values( \Fabrement\Modules\Translation\Translation::bridge()->all_versions( $id, SyncedPatterns::POST_TYPE ) ) )
                : [];
            foreach ( array_unique( array_merge( [ $id ], $group ) ) as $member ) {
                if ( $categories !== null ) {
                    SyncedPatterns::set_categories( $member, $categories );
                }
                if ( $kind !== null ) {
                    SyncedPatterns::set_kind( $member, $kind );
                }
            }
        }

        $post = get_post( $id );
        // A language version is placed by nothing: its change reaches every place its original stands.
        $placed = \Fabrement\Modules\SiteTemplate\Service\TemplateParts::original_of( $id );

        return ( $placed !== $id ? [ 'shownThrough' => $placed ] : [] ) + [
            'patternId' => $id,
            'title'     => (string) $post->post_title,
            'kind'      => SyncedPatterns::kind( $id ),
            'created'   => $existing === null,
            'categories' => SyncedPatterns::categories( $id ),
            'status'    => (string) $post->post_status,
            'blocks'    => SyncedPatterns::block_names( (string) $post->post_content ),
            'usedBy'    => SyncedPatterns::placements( $placed, 100 ),
            // The original's: it is what a template or a page places, whichever language was saved.
            'placement' => '<!-- wp:block {"ref":' . $placed . '} /-->',
            'editUrl'   => (string) get_edit_post_link( $id, 'raw' ),
        ];
    }
}
