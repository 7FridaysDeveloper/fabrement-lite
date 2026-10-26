<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\Service\SyncedPatterns;
use Fabrement\Modules\Translation\Translation;

/**
 * Read-only: the GLOBAL BLOCKS on this site — synced patterns, with what they hold and how many
 * records place them. See {@see SyncedPatterns}.
 */
final class ListSyncedPatternsAbility extends AbstractAbility {

    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT     = 100;

    public function name(): string {
        return 'fabrement/list-synced-patterns';
    }

    public function label(): string {
        return 'List synced patterns';
    }

    public function description(): string {
        return 'The GLOBAL BLOCKS on this site: synced patterns (edit once, every page placing one changes). '
            . 'Each with id, title, the blocks inside, `usedBy` (how many records place it) and `foreign` (blocks '
            . 'that are not core: a theme\'s or a plugin\'s). `kind` — its pattern category: "header" / "footer" (a template\'s header or footer, placed in save-template '
            . 'blocks as {name:"core/block", attributes:{ref: id}}; `templates` lists the templates placing it) or "section" '
            . '(placed on pages). Check here BEFORE saving a new one.';
    }

    public function contract(): ?string {
        return 'synced-pattern-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'kind'   => [ 'type' => 'string', 'enum' => [ 'header', 'footer', 'section' ] ],
                'category' => [ 'type' => 'string', 'description' => 'Only global blocks filed under this pattern category: a slug from a row\'s `categories` ("call-to-action", "testimonials"…).' ],
                'search' => [ 'type' => 'string', 'description' => 'Title substring.' ],
                'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'patterns' ],
            'properties' => [
                'patterns' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'      => [ 'type' => 'integer' ],
                            'title'   => [ 'type' => 'string' ],
                            'kind'    => [ 'type' => 'string' ],
                            'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'The pattern categories it is filed under besides Headers / Footers, as slugs.' ],
                            'status'  => [ 'type' => 'string' ],
                            'templates' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'The templates placing it: {id, title}' . self::multilingual_text( ', `language`, and on a language version `originalId` — rows with an originalId are versions of ONE template; count templates by their originals' ) . '.' ],
                            'blocks'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'foreign' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'usedBy'  => [ 'type' => 'integer' ],
                            'editUrl' => [ 'type' => 'string' ],
                        ] + self::multilingual( [
                            'language'         => [ 'type' => 'string' ],
                            'translations'     => [ 'type' => 'object', 'description' => '{code: id}' ],
                            'missingLanguages' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        ] ),
                    ],
                ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $limit = max( 1, min( (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ), self::MAX_LIMIT ) );
        $kind     = (string) ( $input['kind'] ?? '' );
        $category = trim( (string) ( $input['category'] ?? '' ) );
        // A category the site does not have is a mistake in the asking, not "no such global blocks".
        if ( $category !== '' && ! SyncedPatterns::is_category( $category ) ) {
            return new \WP_Error(
                'unknown_category',
                sprintf( '"%s" is not a pattern category on this site — use one of: %s. Headers and footers are `kind`.', $category, implode( ', ', SyncedPatterns::category_slugs() ) ),
                [ 'status' => 400 ]
            );
        }
        $posts    = SyncedPatterns::find(
            trim( (string) ( $input['search'] ?? '' ) ),
            // The kind and the categories are read per pattern, so a filtered listing reads the wider set and trims.
            $kind === '' && $category === '' ? $limit : self::MAX_LIMIT * 5
        );

        // On a multilingual site one global block in three languages is ONE row: the original, with
        // its versions beside it. Pages place the original's id; the versions are what readers of
        // those languages see.
        $multilingual = \Fabrement\Modules\Translation\Translation::is_multilingual();
        $languages    = $multilingual
            ? \Fabrement\Modules\Translation\Translation::bridge()->version_facts( array_map( static fn( $p ) => (int) $p->ID, $posts ), SyncedPatterns::POST_TYPE )
            : [];

        $out = [];
        foreach ( $posts as $post ) {
            $facts = $languages[ (int) $post->ID ] ?? null;
            if ( $facts !== null && empty( $facts['isOriginal'] ) ) {
                continue;
            }
            $row = SyncedPatterns::describe( $post );
            if ( $kind !== '' && $row['kind'] !== $kind ) {
                continue;
            }
            if ( $category !== '' && ! in_array( $category, $row['categories'], true ) ) {
                continue;
            }
            if ( count( $out ) >= $limit ) {
                break;
            }
            if ( $row['kind'] !== 'section' ) {
                $row['templates'] = \Fabrement\Modules\SiteTemplate\Service\TemplateParts::templates_using( (int) $post->ID );
            }
            $row['editUrl'] = (string) get_edit_post_link( (int) $post->ID, 'raw' );
            if ( $multilingual ) {
                $translations            = $facts['translations'] ?? [];
                $row['language']         = (string) ( $facts['language'] ?? '' );
                $row['translations']     = (object) $translations;
                $row['missingLanguages'] = \Fabrement\Modules\Translation\Translation::missing_languages( $translations );
            }
            $out[] = $row;
        }

        // Where every row is placed, in one search of the site's content for the whole list.
        $placed = SyncedPatterns::placements_of( array_column( $out, 'id' ) );
        foreach ( $out as $i => $row ) {
            $out[ $i ] = array_slice( $row, 0, 3, true ) + [ 'usedBy' => count( $placed[ $row['id'] ] ?? [] ) ] + $row;
        }

        return [ 'patterns' => $out ];
    }
}
