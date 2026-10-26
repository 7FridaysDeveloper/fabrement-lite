<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use WP_Error;

/**
 * Read-only: which records place a block. Asked before a block's fields or template change in the
 * theme, and when the same block has to be edited everywhere it stands.
 */
final class ListBlockUsageAbility extends AbstractAbility {

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 100;
    private const SEARCHED      = 300;

    /** Not content anyone placed a block in. */
    private const SKIPPED_STATUSES = [ 'trash', 'auto-draft', 'inherit' ];

    public function name(): string {
        return 'fabrement/list-block-usage';
    }

    public function label(): string {
        return 'List block usage';
    }

    public function description(): string {
        return 'Where a block is PLACED: every page, post, custom record, global block (synced pattern) and template whose content '
            . 'holds it, at any depth, with how many times. Give the registered name as list-blocks returns it ("acf/hero", '
            . '"core/quote"). A global block in the answer is itself placed elsewhere: get-synced-pattern lists those records. '
            . 'Inspect hasMore: a truncated result is incomplete. Empty means no record places it.';
    }

    public function contract(): ?string {
        return 'block-discovery-contract';
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'name' ],
            'properties'           => [
                'name'  => [ 'type' => 'string', 'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$', 'description' => 'Exact block name, namespace included: "acf/hero", "core/quote".' ],
                'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Most records to return (default 50, at most 100).' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'name', 'registered', 'usedBy', 'hasMore' ],
            'properties' => [
                'name'       => [ 'type' => 'string' ],
                'registered' => [ 'type' => 'boolean', 'description' => 'False: no theme or plugin registers this block now, yet content may still hold it.' ],
                'usedBy'     => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'       => [ 'type' => 'integer' ],
                            'title'    => [ 'type' => 'string' ],
                            'postType' => [ 'type' => 'string', 'description' => 'wp_block = a global block; ' . SiteTemplatePostType::POST_TYPE . ' = a site template.' ],
                            'status'   => [ 'type' => 'string' ],
                            'count'    => [ 'type' => 'integer', 'description' => 'Times the block stands in this record, nested ones included.' ],
                        ],
                    ],
                ],
                'hasMore'    => [ 'type' => 'boolean', 'description' => 'True when more records place it than `limit` returned.' ],
            ],
        ];
    }

    public function execute( array $input ) {
        $name = is_string( $input['name'] ?? null ) ? $input['name'] : '';
        if ( preg_match( '~^[a-z0-9-]+/[a-z0-9-]+$~D', $name ) !== 1 ) {
            return new WP_Error( 'invalid_block', 'Give the block name with its namespace, as list-blocks returns it: "acf/hero", "core/quote".' );
        }
        $limit = max( 1, min( (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ), self::MAX_LIMIT ) );

        $used = [];
        $rows = self::candidates( $name );
        foreach ( $rows as $row ) {
            $count = self::count( parse_blocks( (string) $row->post_content ), $name );
            if ( $count === 0 ) {
                continue; // the name stood in text, not in a block
            }
            if ( count( $used ) === $limit ) {
                return self::answer( $name, $used, true );
            }
            $used[] = [
                'id'       => (int) $row->ID,
                'title'    => (string) $row->post_title,
                'postType' => (string) $row->post_type,
                'status'   => (string) $row->post_status,
                'count'    => $count,
            ];
        }
        // The search itself is bounded: at its bound there may be records it never reached.
        return self::answer( $name, $used, count( $rows ) === self::SEARCHED );
    }

    private static function answer( string $name, array $used, bool $more ): array {
        return [
            'name'       => $name,
            'registered' => \WP_Block_Type_Registry::get_instance()->is_registered( $name ),
            'usedBy'     => $used,
            'hasMore'    => $more,
        ];
    }

    /** Records whose stored markup opens this block: `<!-- wp:quote `, a core name also in full. */
    private static function candidates( string $name ): array {
        global $wpdb;
        $spellings = [ $name ];
        if ( strpos( $name, 'core/' ) === 0 ) {
            $spellings[] = substr( $name, 5 );
        }
        $types = array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
        $types = array_merge( $types, [ 'wp_block', SiteTemplatePostType::POST_TYPE, 'wp_template', 'wp_template_part' ] );

        $likes  = implode( ' OR ', array_fill( 0, count( $spellings ), 'post_content LIKE %s' ) );
        $in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $not_in = implode( ',', array_fill( 0, count( self::SKIPPED_STATUSES ), '%s' ) );
        $params = array_merge(
            array_map( static fn( string $spelling ): string => '%' . $wpdb->esc_like( '<!-- wp:' . $spelling . ' ' ) . '%', $spellings ),
            $types,
            self::SKIPPED_STATUSES,
            [ self::SEARCHED ]
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- a search of stored markup across post types, which WP_Query cannot express; the interpolated parts are runs of placeholders counted from the lists.
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts} WHERE ( {$likes} ) AND post_type IN ( {$in} ) AND post_status NOT IN ( {$not_in} ) ORDER BY post_type ASC, post_title ASC, ID ASC LIMIT %d", $params ) );
    }

    /** parse_blocks() names a core block in full, however the markup spelled it. */
    private static function count( array $blocks, string $name ): int {
        $count = 0;
        foreach ( $blocks as $block ) {
            $count += ( ( $block['blockName'] ?? null ) === $name ? 1 : 0 ) + self::count( $block['innerBlocks'] ?? [], $name );
        }
        return $count;
    }
}
