<?php

namespace Fabrement\Modules\Mcp\Support;

/** Local discovery; no project membership or backend credentials required. */
final class RegisteredBlocks {
    public static function write_mode( \WP_Block_Type $type ): string {
        // Only known save contracts can be written without HTML. A PHP render callback on
        // any other block may decorate saved markup or consume InnerBlocks.
        $known = str_starts_with( $type->name, 'fabrement/' )
            || AcfBlockData::is_block( $type->name ) || in_array( $type->name, [ 'core/block', 'core/post-content' ], true );
        return $type->is_dynamic() && $known ? 'attributes' : 'markup';
    }
    public static function source( string $name ): string {
        if ( AcfBlockData::is_block( $name ) ) { return 'acf'; }
        return str_starts_with( $name, 'core/' ) ? 'core' : 'registered';
    }

    public static function summaries( array $exclude = [] ): array {
        $rows = [];
        foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
            if ( in_array( $name, $exclude, true ) ) { continue; }
            $rows[] = [
                'name' => $name, 'title' => $type->title ?: $name,
                'description' => (string) $type->description, 'source' => self::source( $name ),
                'dynamic' => $type->is_dynamic(), 'writeMode' => self::write_mode( $type ),
                'guide' => LocalBlockGuides::has( $name ),
            ];
        }
        usort( $rows, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
        return $rows;
    }

    public static function describe( string $name ): array {
        $type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
        if ( ! $type ) { throw new McpAbilityException( 'No registered block named ' . esc_html( $name ) . '.', [], 'unknown_block' ); }
        $out = [
            'name' => $name, 'title' => $type->title ?: $name, 'description' => (string) $type->description,
            'source' => self::source( $name ), 'dynamic' => $type->is_dynamic(),
            'attributes' => (object) $type->get_attributes(), 'supports' => (object) $type->supports,
            'parent' => $type->parent ?? [], 'ancestor' => $type->ancestor ?? [],
            'writeMode' => self::write_mode( $type ),
            'note' => 'This is the server-registered schema, not PHP/JS source. Follow writeMode: unverified save contracts require complete saved markup even with a PHP render callback. Blocks with InnerBlocks also need complete saved markup; this tool does not run JavaScript save(). Editor-only registrations are not visible here.',
        ];
        $theme = BlockThemeSettings::for_block( $type );
        if ( $theme !== [] ) { $out['themeSettings'] = $theme; }
        $guide = LocalBlockGuides::for_block( $name );
        if ( $guide !== null ) { $out['guide'] = $guide; }
        if ( AcfBlockData::is_block( $name ) ) {
            $out['attributes']->id = [ 'type' => 'string', 'description' => 'Optional legacy ACF block_ identifier; preserved when editing existing blocks.' ];
            $out['acf'] = AcfBlockData::schema( $name );
            if ( ! $out['acf']['writable'] ) { $out['writeMode'] = 'unsupported'; }
        }
        return $out;
    }

    /** Refuse values WordPress would silently replace with defaults when rendering. */
    public static function validate( \WP_Block_Type $type, array $attrs, string $where ): array {
        if ( $type->name === 'fabrement/content-source' && ( array_key_exists( 'rules', $attrs ) || array_key_exists( 'templateId', $attrs ) ) ) {
            throw new McpAbilityException( esc_html( $where ) . ': the Content Source slot takes no rules or templateId. Choose theme files with save-template contentSource.', [], 'invalid_attribute' );
        }
        $keys = array_keys( $attrs );
        if ( AcfBlockData::is_block( $type->name ) && array_key_exists( 'id', $attrs ) ) {
            if ( ! is_string( $attrs['id'] ) || ! preg_match( '/^block_[a-zA-Z0-9_-]+$/D', $attrs['id'] ) ) {
                throw new McpAbilityException( 'Invalid legacy ACF block id.', [], 'invalid_acf_data' );
            }
            $keys = array_diff( $keys, [ 'id' ] );
        }
        BlockEntry::assert_known_attributes( $type, $keys, $where );
        foreach ( $attrs as $key => $value ) {
            $schema = $type->get_attributes()[ $key ] ?? [];
            if ( isset( $schema['type'] ) ) {
                // WP rich-text is serialized as a string, though block metadata calls it rich-text.
                if ( $schema['type'] === 'rich-text' ) { $schema['type'] = 'string'; }
                $valid = rest_validate_value_from_schema( $value, $schema, $where . '.' . $key );
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WordPress's own validation message, returned to the MCP client as JSON text; never printed as HTML.
                if ( is_wp_error( $valid ) ) { throw new McpAbilityException( $valid->get_error_message(), [], 'invalid_attribute' ); }
            }
        }
        if ( AcfBlockData::is_block( $type->name ) ) {
            if ( isset( $attrs['name'] ) && $attrs['name'] !== $type->name ) {
                throw new McpAbilityException( 'ACF attributes.name must match the registered block name.', [], 'invalid_acf_data' );
            }
            $attrs['data'] = AcfBlockData::validate( $type->name, (array) ( $attrs['data'] ?? [] ), $where );
        }
        return $attrs;
    }
}
