<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\Mcp\Support\Usages;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Delete a site template, cleaning up after it: pages that explicitly picked it are
 * reset to their default, and it is dropped from every post type's default.
 */
final class DeleteTemplateAbility extends DestructiveAbility {

    public function name(): string {
        return 'fabrement/delete-template';
    }

    public function label(): string {
        return 'Delete template';
    }

    public function description(): string {
        return 'Delete a site template (irreversible). Pages that used it explicitly fall back to their post '
            . 'type\'s default and it stops being any type\'s default; its header/footer global blocks stay. Refused '
            . 'while anything still points at it, and the answer says what — pass force:true to delete anyway.'
            . self::multilingual_text( ' A LANGUAGE VERSION deletes freely: its readers fall'
                . ' back to the original\'s words, which is a design, not a break. Deleting the ORIGINAL deletes'
                . ' every language version with it; the answer lists them in deletedTranslations.' );
    }

    public function contract(): ?string {
        return 'template-contract';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'templateId' ],
            'properties'           => [
                'templateId' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Template id from list-templates.' ],
                'force'      => [
                    'type'        => 'boolean',
                    'description' => 'Delete even while pages, post-type defaults or archive views still point at it.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'deleted', 'clearedPages' ],
            'properties' => [
                'deleted'      => [ 'type' => 'boolean' ],
                'clearedPages' => [ 'type' => 'integer' ],
            ] + self::multilingual( [
                'deletedTranslations' => [
                    'type'        => 'object',
                    'description' => 'Language code → id of each language version deleted with the original.',
                ],
            ] ),
        ];
    }

    public function execute( array $input ) {
        $template_id = (int) ( $input['templateId'] ?? 0 );
        // find_any: a draft or trashed template is exactly the one that needs deleting from
        // here, and one language's version is deleted alone — the original and the other
        // languages stay, as when a translated page is deleted.
        if ( SiteTemplateRegistry::find_any( $template_id ) === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }

        // A LANGUAGE VERSION is deleted without a question. Nothing resolves onto it — pages and
        // archives resolve on the ORIGINAL and the reader is handed the version afterwards — so
        // losing it costs that language its own words and nothing else: the original's are served
        // instead, the same fallback the renderer already has for a language nobody translated yet.
        // ONE question, asked once: Usages::template() answers [] for a version, because nothing
        // resolves onto one, so the refusal below simply does not fire for it and this class does
        // not have to know why. What a version COSTS is version_of() — advice, shaped like advice.
        //
        // Deleting a template does NOT break the pages that chose it — they fall back to their
        // type's default, which is why this is a refusal with a force rather than a hard stop.
        // What it breaks is invisible: an archive view bound to it loses its frame and renders in
        // the theme's, and the site-wide default going away changes every page at once.
        $used = Usages::template( $template_id );
        if ( $used !== [] && empty( $input['force'] ) ) {
            return ToolResult::error(
                'template_in_use',
                sprintf(
                    'Still used as: %s. Re-point those first (set-default-template, assign-template-archive, assign-template-pages {mode:"remove"}), or pass force:true.',
                    implode( '; ', $used )
                ),
                [ 'usedBy' => $used ]
            );
        }

        $version = Usages::version_of( $template_id );

        // The versions the deleter will take with an original, read while the group still exists.
        // Same filter as the deleter's, so this lists exactly what it destroys.
        $translations = [];
        if ( $version === '' ) {
            foreach ( Translation::bridge()->all_versions( $template_id, SiteTemplatePostType::POST_TYPE ) as $code => $id ) {
                $id = (int) $id;
                if ( $id > 0 && $id !== $template_id && get_post_type( $id ) === SiteTemplatePostType::POST_TYPE ) {
                    $translations[ (string) $code ] = $id;
                }
            }
        }

        $cleared = \Fabrement\Modules\SiteTemplate\Service\SiteTemplateDeleter::delete( $template_id );
        if ( is_wp_error( $cleared ) ) {
            return $cleared;
        }

        $out = [
            'deleted'      => true,
            'clearedPages' => $cleared,
        ];
        if ( $version !== '' ) {
            $out['note'] = 'That language now reads the original\'s version of this template.';
        } elseif ( $translations !== [] ) {
            $out['deletedTranslations'] = $translations;
            $out['note']                = sprintf( 'Its language versions were deleted with it: %s.', implode( ', ', array_keys( $translations ) ) );
        }

        return $out;
    }
}
