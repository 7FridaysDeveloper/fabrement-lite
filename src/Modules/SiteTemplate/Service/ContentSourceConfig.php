<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;
use Fabrement\Framework\Keys;
use Fabrement\Modules\ContentSource\ThemeSourceRules;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\Translation\Translation;

/**
 * One rules manifest serves the original template and all its translations.
 * Blocks and words stay in each post; source rules never live in post_content.
 */
final class ContentSourceConfig {

    /**
     * The slot's current config. Without a rule for the view, the slot renders the page's own
     * content — rules are the only override.
     *
     * @return array{rules:list<array{context:string,file:string}>}
     */
    public static function read( int $template_id ): array {
        $key   = SiteTemplatePostType::manifest_key( $template_id );
        $rules = $key === '' ? null : TemplateManifestStore::rules( $key );

        return [
            'rules' => self::sanitize_rules( $rules ?? [] ),
        ];
    }

    /** Validate before either the frame or the shared file is changed. */
    public static function validate( $config, string $content, int $template_id = 0 ): array {
        if ( ! is_array( $config ) || ! isset( $config['rules'] ) || ! is_array( $config['rules'] )
            || array_diff( array_keys( $config ), [ 'rules' ] ) !== [] ) {
            throw new McpAbilityException( 'contentSource must contain only a rules array.' );
        }
        $current = $template_id > 0 ? self::read( $template_id )['rules'] : [];
        // An unchanged choice may be temporarily unavailable after switching themes.
        $rules = $config['rules'] === $current ? $current : ThemeSourceRules::validate( $config['rules'] );
        if ( $rules !== [] ) { ThemeSourceRules::assert_slot( $content ); }
        return [ 'rules' => $rules ];
    }

    /** Save JSON choices, never PHP. Editing any manually editable version updates the group. */
    public static function write( int $template_id, array $config ) {
        $post = get_post( $template_id );
        if ( ! $post || $post->post_type !== SiteTemplatePostType::POST_TYPE ) {
            return new \WP_Error( 'fabrement_st_not_found', 'Template not found.', [ 'status' => 404 ] );
        }
        $refusal = SiteTemplateTranslator::refusal_for_version( $template_id );
        if ( $refusal !== null ) { return $refusal; }
        try { $config = self::validate( $config, $post->post_content, $template_id ); }
        catch ( McpAbilityException $e ) { return $e->to_error(); }
        $owner = SiteTemplateTranslator::original_of( $template_id );
        $owner_id = $owner ? $owner->id : $template_id;
        $key = SiteTemplatePostType::manifest_key( $owner_id );
        if ( $key === '' ) { $key = SiteTemplatePostType::manifest_key( $template_id ); }
        if ( $key === '' ) { $key = TemplateManifestStore::mint_key( $owner ? $owner->title : $post->post_title ); }
        if ( ! TemplateManifestStore::save( $key, [ 'title' => $owner ? $owner->title : $post->post_title, 'rules' => $config['rules'] ] ) ) {
            return new \WP_Error( 'fabrement_st_manifest_write', 'The template frame was saved, but its shared source rules could not be saved. Check the templates storage directory and retry.', [ 'status' => 500 ] );
        }
        $ids = Translation::bridge()->all_versions( $owner_id, SiteTemplatePostType::POST_TYPE );
        $ids[] = $owner_id; $ids[] = $template_id;
        foreach ( array_unique( $ids ) as $id ) {
            if ( get_post_type( $id ) === SiteTemplatePostType::POST_TYPE ) { update_post_meta( $id, Keys::META_TEMPLATE_KEY, $key ); }
        }
        SiteTemplateRegistry::flush();
        return $config;
    }

    /** Remove stale copies recursively, retaining all other attributes and saved markup. */
    public static function strip_block_rules( string $content ): string {
        $blocks = parse_blocks( $content );
        $changed = false;
        $walk = static function ( array &$items ) use ( &$walk, &$changed ): void {
            foreach ( $items as &$block ) {
                if ( ( $block['blockName'] ?? '' ) === 'fabrement/content-source' && array_key_exists( 'rules', $block['attrs'] ?? [] ) ) {
                    unset( $block['attrs']['rules'] ); $changed = true;
                }
                if ( ! empty( $block['innerBlocks'] ) ) { $walk( $block['innerBlocks'] ); }
            }
        };
        $walk( $blocks );
        return $changed ? serialize_blocks( $blocks ) : $content;
    }


    /**
     * Drops malformed rows, rules with no file, and DUPLICATE contexts (one view
     * has one body — the renderer takes the first match, so a second row for the
     * same view would be silently dead).
     *
     * ThemeSourceRules validates files before writes; the renderer checks them again.
     *
     * @return list<array{context:string,file:string}>
     */
    public static function sanitize_rules( $rules ): array {
        if ( ! is_array( $rules ) ) {
            return [];
        }
        $out  = [];
        $seen = [];
        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            // Lowercased: the live context is lowercased at resolve time, and a mixed-case rule
            // would silently never match it.
            $context = is_string( $rule['context'] ?? null ) ? strtolower( trim( (string) $rule['context'] ) ) : '';
            $file    = is_string( $rule['file'] ?? null ) ? trim( (string) $rule['file'] ) : '';
            if ( $context === '' || $file === '' || isset( $seen[ $context ] ) ) {
                continue;
            }
            // post_format is a theme feature, not a browsable view — nothing dresses it. Dropped
            // rather than refused, so a template that stored one under older validation can
            // still be re-saved.
            if ( strncmp( $context, 'tax:post_format', 15 ) === 0 ) {
                continue;
            }
            // A rule for a file of the other view is NOT dropped here. A new one is refused before
            // it gets this far (ThemeSourceRules); stale stored choices remain readable
            // while ContentSourceBlock skips files that no longer match.
            $seen[ $context ] = true;
            $out[]            = [ 'context' => $context, 'file' => $file ];
        }
        return $out;
    }
}
