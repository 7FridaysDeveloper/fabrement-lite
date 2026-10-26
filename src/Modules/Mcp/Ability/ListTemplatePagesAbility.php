<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplatePages;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use WP_Error;

/**
 * Read-only: the pages you can assign a template to, each with its current
 * template pick — the input for assign-template-pages. Bounded (search + limit)
 * so large sites stay responsive; a template's own current picks always return.
 */
final class ListTemplatePagesAbility extends AbstractAbility {

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
                            'id'       => [ 'type' => 'integer' ],
                            'title'    => [ 'type' => 'string' ],
                            'status'   => [ 'type' => 'string' ],
                            'template' => [ 'type' => 'string' ],
                            'isFront'  => [ 'type' => 'boolean' ],
                            'postType' => [ 'type' => 'string' ],
                            'typeLabel' => [ 'type' => 'string', 'description' => 'The type singular label, for showing beside the title — a listing mixes types now.' ],
                        ] + self::multilingual( [
                            'language'     => [ 'type' => 'string', 'description' => 'Language code of this page (the original\'s).' ],
                            'translations' => [ 'type' => 'object', 'description' => 'The same page in every language: code → id, this one included. Assigning the row assigns them all.' ],
                        ] ),
                    ],
                ],
                'note'  => [ 'type' => 'string', 'description' => 'Present when something about this site keeps a page out of the ordinary rules — a classic theme with its own front-page.php, for one.' ],
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
        $search      = (string) ( $input['search'] ?? '' );
        $limit       = (int) ( $input['limit'] ?? 50 );

        // A given-but-unknown templateId is an error, not a silent unscoped listing.
        if ( $template_id > 0 && SiteTemplateRegistry::find( $template_id ) === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }

        // NOT scoped by post type. A record of any type a template can dress may pick one — the
        // editor offers the dropdown there and the renderer honours it — so a picker that only
        // listed the template's own types could not show the page somebody wants to pick. What the
        // template id still does is make its CURRENT picks always come back, whatever the search,
        // so their checkbox state is right and a save cannot silently drop them.
        $include_slug = $template_id > 0 ? SiteTemplatePostType::slug_for( $template_id ) : '';

        $pages = SiteTemplatePages::search_rows( $search, $limit, $include_slug );

        // The front page comes back like any other row — `template: ""`, no pick — and on a classic
        // theme with a front-page.php that reading is wrong: it will not take the default either.
        // This listing is where an agent looks to work out what dresses what, so the exception is
        // said here rather than left to be discovered on the rendered page.
        return [ 'pages' => $pages ] + SiteTemplatePostType::front_page_override_note();
    }
}
