<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use WP_Error;

/**
 * Set (or clear) the DEFAULT template for one post type — the frame every page
 * of that type wears unless it has its own explicit pick. Thin adapter over
 * {@see SiteTemplatePostType::set_default_for()}.
 */
final class SetDefaultTemplateAbility extends AbstractAbility {

    public function name(): string {
        return 'fabrement/set-default-template';
    }

    public function label(): string {
        return 'Set default template';
    }

    public function description(): string {
        return 'Make a template the DEFAULT frame for one post type (one default per type — replaces the '
            . 'previous one; 0 clears). This is the call for a type-wide frame; '
            . 'assign-template-pages is for per-page exceptions. postType "404" = the frame of every '
            . 'not-found address (its body: a contentSource rule with context "404", else the notFoundPage).';
    }

    public function contract(): ?string {
        return 'template-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'templateId' ],
            'properties'           => [
                'templateId' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Template id from list-templates; 0 clears the default.' ],
                'postType'   => [ 'type' => 'string', 'description' => 'Post type the default applies to (default "page"), or "404" for the not-found view.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'postType', 'templateId', 'defaultFor' ],
            'properties' => [
                'postType'   => [ 'type' => 'string' ],
                'templateId' => [ 'type' => 'integer' ],
                'defaultFor' => [ 'type' => 'object' ],
                'note'       => [ 'type' => 'string', 'description' => 'Present only when something about this site keeps the default off a page it would otherwise wrap.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'destructiveHint' => false ];
    }

    public function execute( array $input ) {
        $template_id = (int) ( $input['templateId'] ?? 0 );
        $post_type   = (string) ( $input['postType'] ?? 'page' );

        // ANY PUBLIC TYPE may take a default; having one IS what being covered means, so there is
        // nothing to enable first. What is still refused is a type a template cannot dress at all.
        // CLEARING IS NEVER GATED. A type that was public when it took a default can be made
        // private later, or de-registered; its stored default then survives with no way to reach
        // it, because this very check would refuse the call that removes it. Giving a default out
        // needs a type a template can dress; taking one back needs nothing.
        $dressable = SiteTemplatePostType::available_post_types();
        if ( $template_id > 0 && $post_type !== SiteTemplatePostType::NOT_FOUND && ! isset( $dressable[ $post_type ] ) ) {
            return new WP_Error(
                'fabrement_st_post_type',
                sprintf(
                    /* translators: 1: requested post type, 2: the types a template can dress. */
                    __( 'A template cannot dress "%1$s". Types it can: %2$s — or "404" for the not-found view.', 'fabrement-lite' ),
                    $post_type,
                    implode( ', ', array_keys( $dressable ) )
                ),
                [ 'status' => 400 ]
            );
        }

        // A non-zero id must be a real, READY template; 0 means "clear this type's default".
        if ( $template_id > 0 ) {
            $record = SiteTemplateRegistry::find_assignable( $template_id );
            if ( $record === null ) {
                return new WP_Error( 'fabrement_st_not_found', SiteTemplateRegistry::not_assignable_message( $template_id ), [ 'status' => 404 ] );
            }
        }

        $defaults = SiteTemplatePostType::set_default_for( $post_type, $template_id );

        return array(
            'postType'   => $post_type,
            'templateId' => $template_id,
            'defaultFor' => (object) $defaults,
        // The note only when a default was SET — clearing one has no frame to warn about.
        ) + ( $template_id > 0 ? SiteTemplatePostType::front_page_override_note( $post_type ) : array() );
    }
}
