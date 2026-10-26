<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\Mcp\Support\ContentEdits;
use Fabrement\Modules\SiteTemplate\Service\SyncedPatterns;
use Fabrement\Modules\Storage\TextEdits;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Read-only: one global block in full — its body as save-synced-pattern takes it back, and every
 * record that places it (the list of what an edit changes).
 */
final class GetSyncedPatternAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/get-synced-pattern';
    }

    public function label(): string {
        return 'Get synced pattern';
    }

    public function description(): string {
        return 'One global block (synced pattern) in full: title, `blocks` (its body as save-synced-pattern takes it: '
            . '{name, attributes} or {markup}), `outline`, `hash` for an edit, `foreign` blocks, and `usedBy` — every '
            . 'record placing it, i.e. everything an edit changes. Read it before changing the pattern.';
    }

    public function contract(): ?string {
        return 'synced-pattern-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'patternId' ],
            'properties'           => [
                'patternId' => [ 'type' => 'integer', 'minimum' => 1 ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'patternId', 'title', 'blocks', 'hash', 'usedBy' ],
            'properties' => [
                'patternId' => [ 'type' => 'integer' ],
                'title'     => [ 'type' => 'string' ],
                'status'    => [ 'type' => 'string' ],
                'kind'      => [ 'type' => 'string', 'enum' => [ 'header', 'footer', 'section' ] ],
                'templates' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'The templates placing it: {id, title}' . self::multilingual_text( ', `language`, and on a language version `originalId` — rows with an originalId are versions of ONE template; count templates by their originals' ) . '.' ],
                'blocks'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'outline'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'hash'      => [ 'type' => 'string', 'description' => 'expectedHash for save-synced-pattern mode "edit".' ],
                'foreign'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'usedBy'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'shownThrough' => [ 'type' => 'integer', 'description' => 'On a language version only: the id of its original. Nothing places a version itself — it is shown, to readers of its language, wherever the original stands, and `usedBy`' . ' and `templates`' . ' are those places.' ],
                'editUrl'   => [ 'type' => 'string' ],
            ] + self::multilingual( [
                'language'         => [ 'type' => 'string' ],
                'isOriginal'       => [ 'type' => 'boolean' ],
                'originalId'       => [ 'type' => 'integer' ],
                'translations'     => [ 'type' => 'object', 'description' => '{code: id}, this record included.' ],
                'missingLanguages' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ] ),
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $id   = (int) ( $input['patternId'] ?? 0 );
        $post = SyncedPatterns::placeable( $id );
        if ( $post === null ) {
            return new WP_Error( 'pattern_not_found', sprintf( 'No synced pattern with id %d — see list-synced-patterns.', $id ) );
        }

        $blocks = BlockEntry::of_post( $id );
        $info   = SyncedPatterns::describe( $post );

        // A language version is placed by nothing: it is shown wherever its original stands.
        $placed     = \Fabrement\Modules\SiteTemplate\Service\TemplateParts::original_of( $id );
        $placements = SyncedPatterns::placements( $placed, 100 );
        $out        = [
            'patternId' => $id,
            'title'     => $info['title'],
            'status'    => $info['status'],
            'kind'      => $info['kind'],
            'categories' => $info['categories'],
            'blocks'    => $blocks,
            'outline'   => ContentEdits::outline( $blocks ),
            'hash'      => TextEdits::hash( $blocks ),
            'foreign'   => $info['foreign'],
            'usedBy'    => $placements,
            'editUrl'   => (string) get_edit_post_link( $id, 'raw' ),
        ];
        if ( $info['kind'] !== 'section' ) {
            $out['templates'] = \Fabrement\Modules\SiteTemplate\Service\TemplateParts::templates_using( $placed );
        }
        if ( $placed !== $id ) {
            $out['shownThrough'] = $placed;
        }

        // Language facts exist only on a multilingual site; elsewhere the keys are absent, not empty.
        if ( \Fabrement\Modules\Translation\Translation::is_multilingual() ) {
            $facts = \Fabrement\Modules\Translation\Translation::bridge()->version_facts( [ $id ], SyncedPatterns::POST_TYPE )[ $id ] ?? null;
            if ( $facts !== null ) {
                $translations       = $facts['translations'] ?? [];
                $out['language']    = (string) ( $facts['language'] ?? '' );
                $out['isOriginal']  = ! empty( $facts['isOriginal'] );
                if ( ! $out['isOriginal'] ) {
                    $bridge = \Fabrement\Modules\Translation\Translation::bridge();
                    foreach ( $translations as $member ) {
                        if ( $bridge->is_original( (int) $member, SyncedPatterns::POST_TYPE ) ) {
                            $out['originalId'] = (int) $member;
                            break;
                        }
                    }
                }
                $out['translations']     = (object) $translations;
                $out['missingLanguages'] = \Fabrement\Modules\Translation\Translation::missing_languages( $translations );
            }
        }

        return $out;
    }
}
