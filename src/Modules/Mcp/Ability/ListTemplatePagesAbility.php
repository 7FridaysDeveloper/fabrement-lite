<?php

namespace Fabrement\Modules\Mcp\Ability;

defined( 'ABSPATH' ) || exit;

use Fabrement\Capabilities;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplatePages;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use WP_Error;

/**
 * Read-only: the records a template can be assigned to, each with its current pick — the
 * input for assign-template-pages. Bounded (search + limit); a template's own current picks
 * always return. Only records this user may read are listed.
 */
final class ListTemplatePagesAbility extends AbstractAbility {

    public function check_permission() {
        return parent::check_permission() && current_user_can( Capabilities::TEMPLATES );
    }

    public function name(): string {
        return 'fabrement/list-template-pages';
    }

    public function label(): string {
        return 'List template pages';
    }

    public function description(): string {
        return 'List the records a template can be assigned to — every public type: id, title, status, postType, typeLabel, template (slug in use, "" = '
            . 'default), isFront. Use it to gather ids before assign-template-pages. templateId does not narrow the '
            . 'list; it only guarantees that the picks this template already holds are in it, whatever the search.'
            . self::multilingual_text( ' One row per page in every language — its original, with language and translations; the id '
            . 'stands for the whole group.' );
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'templateId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Does NOT narrow the list; it only guarantees the picks this template already holds are in it.' ],
                'search'     => [ 'type' => 'string', 'description' => 'Title search filter (empty = first pages).' ],
                'limit'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Max rows (default 50).' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'pages' ],
            'properties' => [
                'pages' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'        => [ 'type' => 'integer' ],
                            'title'     => [ 'type' => 'string' ],
                            'status'    => [ 'type' => 'string' ],
                            'template'  => [ 'type' => 'string' ],
                            'isFront'   => [ 'type' => 'boolean' ],
                            'postType'  => [ 'type' => 'string' ],
                            'typeLabel' => [ 'type' => 'string', 'description' => 'The type\'s singular label, shown beside the title.' ],
                        ] + self::multilingual( [
                            'language'     => [ 'type' => 'string', 'description' => 'Language code of this page (the original\'s).' ],
                            'translations' => [ 'type' => 'object', 'description' => 'The same page in every language: code → id, this one included. Assigning the row assigns them all.' ],
                        ] ),
                    ],
                ],
                'note'  => [ 'type' => 'string', 'description' => 'Present when something keeps a page out of the ordinary rules — a classic theme with its own front-page.php.' ],
            ],
        ];
    }

    public function contract(): ?string {
        return 'template-contract';
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $template_id = (int) ( $input['templateId'] ?? 0 );
        $search      = trim( wp_strip_all_tags( (string) ( $input['search'] ?? '' ) ) );
        $limit       = (int) ( $input['limit'] ?? 50 );

        // A given-but-unknown templateId is an error, not a silent unscoped listing.
        if ( $template_id > 0 && SiteTemplateRegistry::find( $template_id ) === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }

        // Not scoped by post type: any record a template can dress may pick one. The template id
        // only makes its current picks always come back, so a replace cannot drop them unseen.
        $include_slug = $template_id > 0 ? SiteTemplatePostType::slug_for( $template_id ) : '';

        return [ 'pages' => SiteTemplatePages::search_rows( $search, $limit, $include_slug ) ]
            + SiteTemplatePostType::front_page_override_note();
    }
}
