<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Fields\Values\FieldRead;
use Fabrement\Modules\Fields\Values\FieldValueError;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Modules\Fields\Write\FieldWrite;
use Fabrement\Modules\Fields\Write\WriteRequest;
use Fabrement\Modules\Fields\Write\WriteMode;
use Fabrement\Modules\Fields\Write\ValueWriter;
use WP_Error;
use Fabrement\Modules\Mcp\Support\ToolResult;

final class SetFieldValuesAbility extends FieldsAbility {

    private FieldWrite $writer;

    public function __construct( FieldWrite $writer ) {
        $this->writer = $writer;
    }

    public function name(): string {
        return 'fabrement/set-field-values';
    }

    public function label(): string {
        return 'Set field values';
    }

    public function description(): string {
        return 'Fill the Fabrement fields of one record (post, page or CPT item) or of an options page' . self::multilingual_text( ' in one language' ) . '. Keys of "values" are field names '
            . 'from get-fields; fields you do not send stay as they are; null explicitly clears any field, including sub-fields and lists, without restoring its default'
            . self::multilingual_text( ' (on a translation, null leaves a translate or copy_once field EMPTY in that language; clearing a copy field is routed to the original)' )
            . '. Every value is checked first (type, choices, dates YYYY-MM-DD / date-times YYYY-MM-DDTHH:MM in the site\'s time zone, no offset, number bounds, allowed post types, existing media/records, '
            . 'safe link addresses, required) and one bad value rejects the whole call, with the path of the value '
            . 'to fix. Value shapes: text/textarea/wysiwyg/date → string; select → the choice\'s value (not its label); '
            . 'number → number (0 is a value); true_false → boolean (false is a value); image/file/post_object → attachment or post ID (null = none); '
            . 'relationship → list of post IDs, gallery → list of image IDs (both ordered, at most 50'
            . '); link → {url, label?, newTab?} or {postId, label? | useRecordTitle: true' . self::multilingual_text( ', lang?' ) . '} (send label or useRecordTitle:true, or the link shows no words'
            . self::multilingual_text( '; lang: leave "" = the language being read; a code pins one version and the link disappears where that version does not exist — only when the user asks for it' )
            . '); group → {subName: value}; repeater → the COMPLETE list of rows [{_row?, subName: value}]. Keep the '
            . '"_row" that get-field-values gives each existing row: it is the row\'s identity'
            . self::multilingual_text( ', and translations follow it when rows are inserted, removed or reordered' )
            . '. A row without "_row" is a NEW row, so a list where no row carries "_row" replaces every stored row'
            . self::multilingual_text( ' (and their translations)' )
            . '. A stored row you leave out is deleted.'
            . self::multilingual_text(
                ' Record and media ids (image, file, post_object, relationship, gallery, link postId) may be any language version: they are stored as the ORIGINAL '
                . 'of their translation group, so the response and get-field-values show the original\'s id — expected, do not resend; templates get the version in the reader language.'
            )
            . ' When something changed, a record is saved as with its Update button (modified date, SEO, caches' . self::multilingual_text( ', WPML' ) . ').'
            . self::multilingual_text(
                ' Read each field\'s "translation" in get-fields first. Changing a translate field on the original does not change its translations: it marks them "needs update"; '
                . 'set their words with set-field-values on each translation\'s postId (saving a hand-written translation marks it up to date). Where WPML\'s translation editor manages a translation, '
                . 'its translate-field words are refused here. '
                . 'On a translation, changes to fields copied from the original (translation "copy") and to the ROW LIST of a repeater under copy '
                . '(adding, removing or reordering rows) are routed to the original. You must be allowed to edit both destinations; the response lists routed fields and their destination. Sub-fields under translate stay local in each row. '
                . 'An options page in another language than the original works the same way: its copy fields and followed rows belong to the original language '
                . '.'
            )
            . self::multilingual_text( ' The first valid options write creates a missing values version and seeds it by the same translation policies as post fields, then applies the submitted edits. Existing versions, including empty ones, are never recreated. To translate an initial repeater, read the original explicitly for its _row identities. A validation error creates nothing.' )
            . ' Required fields must be valid in the final result' . self::multilingual_text( ' in every language' ) . ', even when omitted from this call; a removed required choice needs a currently offered replacement.'
            . ' Empty results are returned as null, including lists; an absent stored value uses its schema default, an explicitly cleared value does not. Each destination is saved atomically; a later failure reports already saved destinations and requires rereading. No conflict check: the last save wins.';
    }

    public function input_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'values' ],
            'properties'           => self::target_properties() + [
                'values' => [ 'type' => 'object', 'description' => 'field name => value. See the tool description for shapes.' ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array {
        return [ 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false ];
    }

    public function execute( array $input ) {
        $object = self::target( $input );
        if ( is_wp_error( $object ) ) {
            return $object;
        }
        $values = $input['values'] ?? null;
        if ( ! is_array( $values ) ) {
            return ToolResult::error( 'fields_invalid', 'values: Expected an object keyed by field names.' );
        }
        $all = $object->all_fields();
        $active = ValueCodec::active( $all );
        if ( ! $active ) {
            return FieldRead::public_values( $object );
        }
        if ( ! $values ) {
            return new WP_Error( 'fields_invalid', 'values: Send at least one field.' );
        }
        try {
            $result = $this->writer->save( $object, WriteRequest::from_public( $object, $values ), WriteMode::agent() );
        } catch ( \Exception $e ) {
            $result = ValueWriter::failure( $e );
        }
        if ( is_wp_error( $result ) ) {
            return $result->get_error_code() === 'fields_invalid'
                ? new WP_Error( 'fields_invalid', $result->get_error_message() . self::hint( (array) $result->get_error_data() ) . ' Nothing was saved.', $result->get_error_data() )
                : $result;
        }
        $changed = $result['changed'];
        $out = [ 'saved' => true ];
        if ( $result['routed'] ) {
            $out['routed'] = $result['routed'];
        }
        if ( $changed && $object->type === 'post' && $object->is_translation() && Translation::bridge()->duplicate_of( (int) $object->id ) > 0 ) {
            $out['note'] = 'This record is a WPML duplicate: its fields follow the original again the next time the original is saved. To keep these values, have it translated independently in WPML (its "Translate independently").';
        }
        return $out + FieldRead::public_values( $object );
    }

    private static function hint( array $data ): string {
        if ( ( $data['reason'] ?? '' ) === FieldValueError::SEVERAL ) {
            return implode( '', array_map( [ self::class, 'hint' ], (array) ( $data['errors'] ?? [] ) ) );
        }
        $path = (string) ( $data['path'] ?? '' );
        $params = (array) ( $data['params'] ?? [] );
        $hint = ! empty( $data['saved'] ) && $path !== '' ? ' Send a new value for ' . $path . '.' : '';
        switch ( $data['reason'] ?? '' ) {
            case FieldValueError::REQUIRED:
                return $path !== '' ? ' Send a value for ' . $path . '.' : '';
            case FieldValueError::RECORD_MISSING:
                return $hint . ' Pick one from ' . ( ( $params['what'] ?? '' ) === 'post_object' ? 'list-content' : 'list-media' ) . '.';
            case FieldValueError::CHOICE:
                return $hint . ( ! empty( $params['choices'] ) ? ' One of: ' . implode( ', ', $params['choices'] ) . '.' : '' );
            case FieldValueError::TRANSLATOR_WORDS:
            case FieldValueError::TRANSLATOR_EMPTY_ORIGINAL:
                return ! empty( $params['original'] ) ? ' The original: ' . $params['original'] . '.' : '';
        }
        return $hint;
    }
}
