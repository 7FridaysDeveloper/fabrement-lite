<?php

namespace Fabrement\Modules\Fields\Values;

use InvalidArgumentException;

/** A refused field value: a key and its data, the field and path attached where it is known; FieldMessages words it. */
final class FieldValueError extends InvalidArgumentException {

    public const REQUIRED          = 'required';
    public const TOO_LONG          = 'too_long';
    public const TRUE_FALSE        = 'true_false';
    public const NOT_NUMBER        = 'not_number';
    public const NUMBER_TOO_LARGE  = 'number_too_large';
    public const WHOLE_NUMBER      = 'whole_number';
    public const OUT_OF_RANGE      = 'out_of_range';
    public const DATE_INVALID      = 'date_invalid';
    public const CHOICE            = 'choice';
    public const LINK_SHAPE        = 'link_shape';
    public const LINK_TEXT         = 'link_text';
    public const LINK_OPTIONS      = 'link_options';
    public const LINK_LANG         = 'link_lang';
    public const LINK_ADDRESS      = 'link_address';
    public const LINK_SITE_PATH    = 'link_site_path';
    public const LINK_SCHEME       = 'link_scheme';
    public const RECORD_ID         = 'record_id';
    public const RECORD_EMPTY      = 'record_empty';
    public const RECORD_MISSING    = 'record_missing';
    public const RECORD_TYPE       = 'record_type';
    public const RECORD_NOT_PUBLIC = 'record_not_public';
    public const NOT_MEDIA         = 'not_media';
    public const NOT_IMAGE         = 'not_image';
    public const LIST_SHAPE        = 'list_shape';
    public const LIST_TOO_LONG     = 'list_too_long';
    public const UNIQUE            = 'unique';
    public const TOO_MANY          = 'too_many';
    public const ROWS_SHAPE        = 'rows_shape';
    public const ROW_ID            = 'row_id';
    public const SUB_FIELD_UNKNOWN = 'sub_field_unknown';
    public const TRANSLATOR_WORDS = 'translator_words';
    public const TRANSLATOR_EMPTY_ORIGINAL = 'translator_empty_original';
    public const SEVERAL           = 'several';
    public const ITEM_ZERO         = 'item_zero';

    public string $key;
    public array $params;
    public ?array $field = null;
    public string $at = '';
    public ?array $trail = null;
    public array $errors = [];

    public function __construct( string $key, array $params = [] ) {
        $this->key = $key;
        $this->params = $params;
        parent::__construct( FieldMessages::english( $this ) );
    }

    public static function several( array $errors ): self {
        $error = new self( self::SEVERAL );
        $error->errors = $errors;
        $error->message = FieldMessages::english( $error );
        return $error;
    }

    public function at( array $field, string $at ): self {
        if ( $this->field === null ) {
            $this->field = $field;
            $this->at = $at;
            $this->message = FieldMessages::english( $this );
        }
        return $this;
    }

    public function with( array $params ): self {
        $this->params = $params + $this->params;
        $this->message = FieldMessages::english( $this );
        return $this;
    }

    public function as_saved( array $trail ): self {
        $this->trail = $trail;
        $this->message = FieldMessages::english( $this );
        return $this;
    }

    public function saved(): bool {
        return $this->trail !== null && $this->key !== self::REQUIRED;
    }
}
