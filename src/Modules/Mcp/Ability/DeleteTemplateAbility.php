<?php

namespace Fabrement\Modules\Mcp\Ability;

defined( 'ABSPATH' ) || exit;

use Fabrement\Capabilities;
use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\Mcp\Support\Usages;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateDeleter;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplatePages;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\SiteTemplate\Service\SyncedPatterns;
use Fabrement\Modules\Translation\Translation;
use WP_Error;

/**
 * Delete a site template. Records that picked it fall back to their default; it clears no
 * site-wide assignment — while one points at it (a post-type default, the 404 view, an
 * archive), the delete is refused and the assignment is changed in wp-admin first.
 */
final class DeleteTemplateAbility extends DestructiveAbility {

    public function check_permission() {
        return parent::check_permission() && current_user_can( Capabilities::TEMPLATES );
    }

    public function name(): string {
        return 'fabrement/delete-template';
    }

    public function label(): string {
        return 'Delete template';
    }

    public function description(): string {
        return 'Delete a site template (irreversible). Refused while it is a site-wide default, the 404 template or bound '
            . 'to an archive: those are changed in wp-admin first. Refused while records chose it, and the answer says how many — '
            . 'pass force:true to delete anyway: they fall back to their post type\'s default (you must be able to edit each). '
            . 'Its header/footer global blocks stay.'
            . self::multilingual_text( ' A LANGUAGE VERSION deletes freely: its readers fall'
                . ' back to the original\'s words. Deleting the ORIGINAL deletes'
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
                    'description' => 'Delete even while records chose it; they fall back to their default. Not for a site-wide assignment — that is changed in wp-admin.',
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
                'note'         => [ 'type' => 'string' ],
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
        // find_any: a draft or trashed template is exactly the one that needs deleting, and one
        // language's version is deleted alone.
        if ( SiteTemplateRegistry::find_any( $template_id ) === null ) {
            return new WP_Error( 'fabrement_st_not_found', __( 'Template not found.', 'fabrement-lite' ), [ 'status' => 404 ] );
        }

        $original = Translation::bridge()->is_original( $template_id, SiteTemplatePostType::POST_TYPE );

        // The records the deleter takes: an original goes with every language version.
        $translations = [];
        if ( $original ) {
            foreach ( Translation::bridge()->all_versions( $template_id, SiteTemplatePostType::POST_TYPE ) as $code => $id ) {
                $id = (int) $id;
                if ( $id > 0 && $id !== $template_id && get_post_type( $id ) === SiteTemplatePostType::POST_TYPE ) {
                    $translations[ (string) $code ] = $id;
                }
            }
        }
        $group = array_merge( [ $template_id ], array_values( $translations ) );

        // Site-wide assignments are wp-admin's: never cleared from here, and force does not apply.
        $site_wide = [];
        foreach ( $group as $id ) {
            $site_wide = array_merge( $site_wide, Usages::template_site_wide( $id ) );
        }
        if ( $site_wide !== [] ) {
            return ToolResult::error(
                'template_assigned_site_wide',
                sprintf(
                    'Still used as: %s. Site-wide assignments are changed in wp-admin (Fabrement → Templates): ask the user to point them at another template there, then delete this one.',
                    implode( '; ', $site_wide )
                ),
                [ 'usedBy' => $site_wide ]
            );
        }

        $pages = [];
        foreach ( $group as $id ) {
            $pages = array_merge( $pages, Usages::template_pages( $id ) );
        }
        if ( $pages !== [] && empty( $input['force'] ) ) {
            return ToolResult::error(
                'template_in_use',
                sprintf(
                    'Still used by %s. Move them first (assign-template-pages {mode:"remove"}, or another template with mode "add"), or pass force:true — they then fall back to their default.',
                    implode( '; ', $pages )
                ),
                [ 'usedBy' => $pages ]
            );
        }

        // Deleting rewrites each record's own template pick: edit_post on every one of them.
        $locked = [];
        foreach ( $group as $id ) {
            $locked = array_merge( $locked, SiteTemplatePages::not_editable( SiteTemplatePostType::slug_for( $id ), [] ) );
        }
        if ( $locked !== [] ) {
            $listed = SyncedPatterns::refusal_list(
                array_map( static fn( int $id ): array => [ 'id' => $id, 'title' => get_the_title( $id ) ], array_values( array_unique( $locked ) ) ),
                static fn( array $p ): string => sprintf( '%d "%s"', $p['id'], $p['title'] )
            );
            return ToolResult::error(
                'forbidden',
                sprintf( 'Nothing was deleted: records you may not edit (edit_post) chose this template: %s.', $listed['text'] ),
                [ 'notEditable' => array_column( $listed['readable'], 'id' ), 'hidden' => $listed['hidden'] ]
            );
        }

        if ( ! current_user_can( 'delete_post', $template_id ) ) {
            return ToolResult::error( 'forbidden', 'Nothing was deleted: you may not delete this template (delete_post).' );
        }
        $cleared = SiteTemplateDeleter::delete( $template_id );
        if ( is_wp_error( $cleared ) ) {
            return $cleared;
        }

        $out = [
            'deleted'      => true,
            'clearedPages' => $cleared,
        ];
        if ( ! $original ) {
            $out['note'] = 'That language now reads the original\'s version of this template.';
        } elseif ( $translations !== [] ) {
            $out['deletedTranslations'] = $translations;
            $out['note']                = sprintf( 'Its language versions were deleted with it: %s.', implode( ', ', array_keys( $translations ) ) );
        }

        return $out;
    }
}
