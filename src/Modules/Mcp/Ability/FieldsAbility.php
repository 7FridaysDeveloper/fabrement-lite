<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Schema\LayoutValidator;
use Fabrement\Modules\Fields\Schema\SchemaValidator;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Mcp\Support\ToolResult;
use Fabrement\Modules\Translation\Translation;

/** Shared input shape of the field tools: whose fields (type + slug), and which object's values. */
abstract class FieldsAbility extends AbstractAbility {

    public function contract(): ?string {
        return 'fields-contract';
    }

    protected static function owner_properties(): array {
        return [
            'type' => [
                'type'        => 'string',
                'enum'        => [ 'post' ],
                'description' => 'Owner kind: "post", any post type with an admin screen (page, post, or a type the theme or a plugin registers). '
                    . 'Site-wide settings are not fields of a type: they are options pages (get-options-page / save-options-page).',
            ],
            'slug' => [
                'type'        => 'string',
                'pattern'     => '^[a-z0-9][a-z0-9_-]{0,31}$',
                'description' => 'Post type slug, e.g. "project" or "page".',
            ],
        ];
    }

    protected static function fields_property(): array {
        return [
            'type'        => 'array',
            'description' => 'The COMPLETE list of active fields, as a whole. A field you leave out is retired '
                . '(hidden, values kept), never deleted. get-fields / get-options-page list retired ones with "retired": true: leave them out (they stay retired), '
                . 'or send one without "retired" to restore it. "key" may be left out. A field IS its name: '
                . 'a new name is a new, EMPTY field (there is no rename: to rename what editors see, change the label; a real name change is a migration). '
                . 'Each field, sub-fields included: {name, label (required, non-empty, ≤200: what editors see), '
                . 'description (required, non-empty, ≤2000: the hint editors see under the field), type (' . implode( '|', SchemaValidator::TYPES ) . ')' . self::multilingual_text( ', translation? '
                . '(translate|copy|copy_once|ignore; what a language version gets: translate = its own words, seeded from the original and sent to translators (text/textarea/wysiwyg/link only); '
                . 'copy = one shared value, edits from a translation route to the original; copy_once = seeded once, then each language has its own (a price, a date or a picked record that differs per language); '
                . 'ignore = never touched. A repeater under copy takes its ROW LIST from the original, each sub-field following its own policy; '
                . 'a group under copy is one set whose sub-fields each follow their own policy (clearing it on the original clears only its copy sub-fields in translations). '
                . 'Omitted: translate for text/textarea/wysiwyg/link, copy for everything else; an existing field keeps its policy. '
                . 'It only matters once the site has several languages)' ) . ', required?, default?, format? (number: integer|decimal), '
                . 'min?/max? (number bounds; repeater/relationship/gallery item counts: max enforced, min only a hint shown in the form), choices? (select: [{value,label}]), postTypes? (post_object/relationship: the post type slugs that may be picked, e.g. ["project"]; omitted = any public type; see list-post-types), '
                . 'sub_fields? (group/repeater)}. name: a-z, digits, single underscores, starts with a letter, not with "fab_", at most 24 characters, no part only digits (step_2 is refused, step2 is fine); unique among siblings; '
                . 'The type and number format of a '
                . 'name never change (a retired field keeps its name too): add a new field instead. The same top-level name '
                . 'on another post type is the same meta key, so it must have the same type and number format there.',
            // The four the validator refuses to do without, declared so a client sees them before
            // the first call; everything else about a field is checked by SchemaValidator.
            'items'       => [
                'type'     => 'object',
                'required' => [ 'name', 'label', 'description', 'type' ],
            ],
        ];
    }

    protected static function options_slug_property(): array {
        return [
            'type'        => 'string',
            'pattern'     => '^[a-z0-9][a-z0-9_-]{0,31}$',
            'description' => 'The options page slug, e.g. "site", "header", "footer" (list-options-pages); a-z, 0-9, _ and -, no "__", at most 32.',
        ];
    }

    protected static function page_property(): array {
        return [
            'type'        => 'object',
            'description' => 'How the page shows in wp-admin. {title (required for a new page), menuTitle? (tab label, "" = title), '
                . 'description? (a line above the form), position? (0–1000, lower first among the tabs of Fabrement → Site General Settings; default 100), '
                . 'autoload? (true, the default: its values load with every request — right for what the header, footer or most pages show; '
                . 'false for a big page read on a few screens only)}. A property left out keeps its value.',
        ];
    }

    /** The values target of get/set-field-values: a record, or one language of an options page. */
    protected static function target_properties(): array {
        return [
            'postId'   => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of the post, page or CPT record. Send this OR options.' ],
            'options'  => [ 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,31}$', 'description' => 'Slug of an options page (list-options-pages). Send this OR postId.' ],
        ] + self::multilingual( [
            'language' => [ 'type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{0,20}$', 'description' => 'options only, on a site with several languages: which language\'s values ("" = the original language). '
                . 'Reads use only that language\'s stored values and schema defaults, without creating it or falling back to the original. The first valid write creates and seeds a missing version by the field translation policies.' ],
        ] );
    }

    /** @return FieldObject|\WP_Error */
    protected static function target( array $input ) {
        $has_post = isset( $input['postId'] );
        $has_options = isset( $input['options'] );
        if ( $has_post === $has_options ) {
            return ToolResult::error( 'fields_object', 'Send postId (a record) or options (an options page slug), not both.' );
        }
        if ( $has_post ) {
            if ( isset( $input['language'] ) ) {
                return ToolResult::error( 'fields_object', 'language: Only for options. A record\'s translation is its own postId.' );
            }
            $object = FieldObject::post( (int) $input['postId'] );
            if ( ! $object ) {
                return ToolResult::error( 'fields_object', 'postId: No such record.' );
            }
            return $object->can_edit() ? $object : ToolResult::error( 'fields_forbidden', 'You cannot edit this record.' );
        }
        $language = (string) ( $input['language'] ?? '' );
        $object = FieldObject::options( (string) $input['options'], $language !== '' ? $language : Translation::bridge()->default_language() );
        if ( ! $object ) {
            return ToolResult::error( 'fields_object', $language !== '' && FieldObject::options( (string) $input['options'] )
                ? 'language: Not a language of this site. See list-languages.'
                : 'options: No options page "' . $input['options'] . '". See list-options-pages.' );
        }
        // The service under the tools asks no one (the PHP API is the site's own code): a person
        // coming in through MCP is asked here, as the fields box asks on its REST route.
        return $object->can_edit() ? $object : ToolResult::error( 'fields_forbidden', 'You cannot edit this options page (needs manage_options).' );
    }

    protected static function layout_property(): array {
        return [
            'type'        => 'object',
            'description' => 'Form layout only; never affects stored values. {tabs: [{label, items: [{field: <name or key of an '
                . 'active top-level field>, width?: ' . implode( '|', LayoutValidator::WIDTHS ) . ', display?: '
                . implode( '|', LayoutValidator::DISPLAYS ) . ' (repeater only)}]}]}. Fields not placed are shown after '
                . 'the tabs. Short numbers, dates and switches: 1/2 or 1/3; long text, wysiwyg and repeaters: 1. '
                . 'Omit layout for an automatic grid.',
        ];
    }
}
