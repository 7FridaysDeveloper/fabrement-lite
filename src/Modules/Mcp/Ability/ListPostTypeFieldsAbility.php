<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Schema\SchemaService;
use WP_Error;

/**
 * The fields a post type DECLARES, and how render code reads each one.
 *
 * Three declarations answer it, none of them by scanning postmeta:
 *  - FABREMENT — the type's field file (what get-fields returns). Read with fabrement()->fields( $id ) — readSnippet names the reader.
 *  - ACF — field groups whose location rules include the type, through ACF's own API, so groups
 *    registered from JSON or PHP count too. Read with get_field, in the field's return format.
 *  - REGISTERED — register_post_meta(), for this type or for every post type.
 *
 * Meta nobody declared (an old plugin writing raw rows) is not here: only the database holds the raw
 * table when that matters. So the answer is fast on any site and never lists storage rows.
 */
final class ListPostTypeFieldsAbility extends AbstractAbility {

    /** ACF choices shown per field: enough to see the values, not a dump of a 500-country list. */
    private const ACF_CHOICES = 50;
    private const ACF_DEPTH   = 3;

    /** Core plumbing registered on every post: noise for a template, shown only under includeInternal. */
    private const CORE_KEYS = [ 'footnotes' ];

    public function contract(): ?string {
        return 'content-model-contract';
    }

    public function name(): string {
        return 'fabrement/list-post-type-fields';
    }

    public function label(): string {
        return 'List post type fields';
    }

    public function description(): string {
        return 'Every custom field a post type declares, and the PHP a theme template uses to read each one: '
            . 'source "fabrement" (our fields: read with fabrement()->fields($post_id) and the readSnippet of each field, never get_post_meta), '
            . '"acf" (ACF field groups on the type: group, returnFormat, choices, sub-fields; read with get_field) and '
            . '"registered" (register_post_meta). Inspect these declarations when an installed block or theme template needs '
            . 'anything beyond title/content/excerpt/featured image/terms, so keys come from the site, not a guess. '
            . '"fabrementFields" at the end is the full definition of our fields, the same as get-fields (use get-fields '
            . 'to change them). It reads declarations only, not the database: meta nobody declared is not listed. '
            . 'Empty = the type declares no custom fields; do not invent keys.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'postType' ],
            'properties'           => [
                'postType'        => [ 'type' => 'string', 'description' => 'Post type slug, e.g. "course" or "page" (list-post-types).' ],
                'includeInternal' => [ 'type' => 'boolean', 'description' => 'Include underscore-prefixed and core registered keys. Default false.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function output_schema(): array {
        return [
            'type'       => 'object',
            'required'   => [ 'postType', 'fields' ],
            'properties' => [
                'postType'        => [ 'type' => 'string' ],
                'fields'          => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'key'         => [ 'type' => 'string', 'description' => 'The meta key (for queries only — SEO templates read %%fab_<name>%%, schema {{field:<name>}}; never to print a Fabrement field).' ],
                            'name'        => [ 'type' => 'string', 'description' => 'Fabrement fields: the name the field readers take (readSnippet).' ],
                            'label'       => [ 'type' => 'string' ],
                            'type'        => [ 'type' => 'string' ],
                            'source'      => [ 'type' => 'string', 'enum' => [ 'fabrement', 'acf', 'registered' ] ],
                            'description' => [ 'type' => 'string' ],
                            'subFields'   => [ 'type' => 'array', 'description' => 'Fabrement group/repeater: its sub-fields, {name, type, subFields?}.' ],
                            'acf'         => [ 'type' => 'object', 'description' => 'ACF: group, returnFormat (what get_field returns: an image as id / array / url), choices, multiple, subFields, layouts.' ],
                            'readSnippet' => [ 'type' => 'string', 'description' => 'How theme PHP reads it; $post_id is the record.' ],
                            'labelSnippet' => [ 'type' => 'string', 'description' => 'A choice field: the selected label in the language being read.' ],
                        ],
                    ],
                ],
                'fabrementFields' => [ 'type' => 'object', 'description' => 'How to read Fabrement fields, and their definitions as get-fields returns them. Absent when the type has none.' ],
            ],
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => true ];
    }

    public function execute( array $input ) {
        $type = sanitize_key( (string) ( $input['postType'] ?? '' ) );
        if ( $type === '' || ! post_type_exists( $type ) ) {
            return new WP_Error( 'unknown_post_type', sprintf( 'postType: "%s" is not registered here — see list-post-types.', $type ) );
        }
        $internal = ! empty( $input['includeInternal'] );

        $fab = $this->fabrement_document( $type );
        $fields = [];
        foreach ( $this->fabrement_fields( $fab ) as $field ) {
            $fields[ $field['key'] ] = $field;
        }
        foreach ( $this->acf_fields( $type ) as $field ) {
            $fields[ $field['key'] ] ??= $field;
        }
        foreach ( $this->registered_fields( $type, $internal ) as $field ) {
            if ( isset( $fields[ $field['key'] ] ) ) {
                // Registered too (ACF can register its fields for REST): keep the richer entry.
                continue;
            }
            $fields[ $field['key'] ] = $field;
        }

        $result = [ 'postType' => $type, 'fields' => array_values( $fields ) ];
        if ( $fab !== null ) {
            $result['fabrementFields'] = [
                'read' => 'Theme PHP reads a value with fabrement()->fields($post_id) and the reader its readSnippet names; a selected label: ->label(\'<name>\'). '
                    . 'Readers return values and print nothing: escape on output (esc_html, esc_url, wp_kses_post). Numbers and dates are raw: $fmt = fabrement()->format(); $fmt->number($value) or $fmt->date($value). '
                    . 'An Image, File, Link or record comes as an object: ->url(), ->alt(), ->text(), ->title(); iterate galleries and record lists. '
                    . 'readSnippet returns the typed value; raw values for logic: ->get(\'<name>\') (all of them: ->values()); never get_post_meta. '
                    . 'Sort/filter records: the top-level key (fab_<name>) — SEO templates use %%fab_<name>%%, schema {{field:<name>}} (seo-contract). '
                    . 'Fields marked "retired" are switched off: not readable. Values of one record: get-field-values {postId}. '
                    . 'Changing the definitions: get-fields, then save-fields. Rules: get-skill-doc {id:"fields-contract"}.',
            ] + $fab;
        }
        return $result;
    }

    /** The type's Fabrement field file as get-fields returns it; null when it has none. */
    private function fabrement_document( string $type ): ?array {
        $document = SchemaService::get( 'post', $type );
        if ( is_wp_error( $document ) || ( ! $document['fields'] && empty( $document['problem'] ) ) ) {
            return null;
        }
        return $document;
    }

    private static function reader( string $type ): string {
        $readers = [ 'group' => 'get_group', 'repeater' => 'get_rows', 'date_picker' => 'get_date', 'date_time_picker' => 'get_date_time' ];
        return $readers[ $type ] ?? 'get_' . $type;
    }

    /** @return list<array<string, mixed>> One entry per active top-level Fabrement field. */
    private function fabrement_fields( ?array $fab ): array {
        $out = [];
        foreach ( $fab['fields'] ?? [] as $field ) {
            if ( ! empty( $field['retired'] ) ) {
                continue;
            }
            $entry = [
                'key'    => $field['key'],
                'name'   => $field['name'],
                'label'  => $field['label'],
                'type'   => $field['type'],
                'source' => 'fabrement',
            ];
            if ( ( $field['description'] ?? '' ) !== '' ) {
                $entry['description'] = $field['description'];
            }
            if ( ! empty( $field['sub_fields'] ) ) {
                $entry['subFields'] = self::fabrement_children( $field['sub_fields'] );
            }
            $entry['readSnippet'] = sprintf( 'fabrement()->fields( $post_id )->%s( %s )', self::reader( (string) $field['type'] ), self::php_string( $field['name'] ) );
            if ( ! empty( $field['choices'] ) ) {
                $entry['labelSnippet'] = sprintf( 'fabrement()->fields( $post_id )->label( %s )', self::php_string( $field['name'] ) );
            }
            $out[] = $entry;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function fabrement_children( array $children ): array {
        $out = [];
        foreach ( $children as $child ) {
            if ( ! empty( $child['retired'] ) ) {
                continue;
            }
            $entry = [ 'name' => $child['name'], 'type' => $child['type'] ];
            if ( ! empty( $child['sub_fields'] ) ) {
                $entry['subFields'] = self::fabrement_children( $child['sub_fields'] );
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * ACF through its own API, not through its tables: `acf_get_field_groups()` resolves location
     * rules and returns groups registered from JSON or PHP as well.
     *
     * @return list<array<string, mixed>>
     */
    private function acf_fields( string $type ): array {
        if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
            return [];
        }
        $out = [];
        foreach ( (array) acf_get_field_groups( [ 'post_type' => $type ] ) as $group ) {
            foreach ( (array) acf_get_fields( $group ) as $field ) {
                $key = (string) ( $field['name'] ?? '' );
                if ( $key === '' ) {
                    continue;
                }
                $entry = [
                    'key'    => $key,
                    'label'  => (string) ( $field['label'] ?? '' ),
                    // ACF's own type: "image" is an attachment, "repeater" has no single value to print.
                    'type'   => (string) ( $field['type'] ?? 'text' ),
                    'source' => 'acf',
                ];
                if ( ! empty( $field['instructions'] ) ) {
                    $entry['description'] = (string) $field['instructions'];
                }
                $entry['acf'] = [ 'group' => (string) ( $group['title'] ?? '' ) ] + self::acf_info( $field, 0 );
                // get_field, not get_post_meta: the raw value is an id where the template expects an array.
                $entry['readSnippet'] = sprintf( 'get_field( %s, $post_id )', self::php_string( $key ) );
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * What decides how an ACF value is printed: its return format (an image as an id, an array or
     * a url), its choices, whether it holds several, and what a container nests.
     */
    private static function acf_info( array $field, int $depth ): array {
        $info = [];
        foreach ( [ 'return_format' => 'returnFormat', 'multiple' => 'multiple', 'display_format' => 'displayFormat', 'post_type' => 'postTypes' ] as $from => $to ) {
            if ( ! empty( $field[ $from ] ) ) {
                $info[ $to ] = $from === 'multiple' ? true : $field[ $from ];
            }
        }
        if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
            $info['choices'] = array_slice( $field['choices'], 0, self::ACF_CHOICES, true );
            if ( count( $field['choices'] ) > self::ACF_CHOICES ) {
                $info['choicesTotal'] = count( $field['choices'] );
            }
        }
        if ( $depth < self::ACF_DEPTH && ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
            $info['subFields'] = self::acf_children( $field['sub_fields'], $depth );
        }
        if ( $depth < self::ACF_DEPTH && ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
            foreach ( $field['layouts'] as $layout ) {
                if ( ! empty( $layout['name'] ) ) {
                    $info['layouts'][ (string) $layout['name'] ] = self::acf_children( (array) ( $layout['sub_fields'] ?? [] ), $depth );
                }
            }
        }
        return $info;
    }

    /** @return list<array<string, mixed>> */
    private static function acf_children( array $children, int $depth ): array {
        $out = [];
        foreach ( $children as $child ) {
            if ( ! empty( $child['name'] ) ) {
                $out[] = [ 'name' => (string) $child['name'], 'type' => (string) ( $child['type'] ?? '' ) ] + self::acf_info( $child, $depth + 1 );
            }
        }
        return $out;
    }

    /**
     * register_post_meta() — for this subtype, plus the keys registered for every post type ('').
     * Underscore keys are protected meta and core plumbing: shown only under includeInternal.
     *
     * @return list<array<string, mixed>>
     */
    private function registered_fields( string $type, bool $internal ): array {
        $out = [];
        foreach ( [ '', $type ] as $subtype ) {
            foreach ( (array) get_registered_meta_keys( 'post', $subtype ) as $key => $args ) {
                $key = (string) $key;
                if ( ! $internal && ( strpos( $key, '_' ) === 0 || in_array( $key, self::CORE_KEYS, true ) ) ) {
                    continue;
                }
                $single = ! empty( $args['single'] );
                $entry = [
                    'key'    => $key,
                    'type'   => (string) ( $args['type'] ?? 'string' ),
                    'source' => 'registered',
                ];
                if ( ! empty( $args['description'] ) ) {
                    $entry['description'] = (string) $args['description'];
                }
                $entry['readSnippet'] = sprintf( 'get_post_meta( $post_id, %s, %s )', self::php_string( $key ), $single ? 'true' : 'false' );
                $out[ $key ] = $entry;
            }
        }
        return array_values( $out );
    }

    /** A single-quoted PHP string literal for the snippets above — what var_export() gives a string. */
    private static function php_string( $value ): string {
        return "'" . addcslashes( (string) $value, "'\\" ) . "'";
    }
}
