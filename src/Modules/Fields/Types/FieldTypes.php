<?php

namespace Fabrement\Modules\Fields\Types;

use InvalidArgumentException;

/** The field types, by name. A new type is a class and a line here. */
final class FieldTypes {

    /** In the order the tools list them. */
    public const NAMES = [
        'text', 'textarea', 'wysiwyg', 'number', 'true_false', 'select', 'date_picker',
        'date_time_picker', 'image', 'gallery', 'file', 'link', 'post_object', 'relationship', 'group', 'repeater',
    ];

    /** @var array<string, FieldType>|null */
    private static ?array $types = null;

    public static function of( array $field ): FieldType {
        return self::get( (string) ( $field['type'] ?? '' ) );
    }

    public static function get( string $name ): FieldType {
        if ( self::$types === null ) {
            self::$types = [];
            foreach ( [
                new WordsType( 'text' ), new WordsType( 'textarea' ), new WordsType( 'wysiwyg' ),
                new NumberType(), new TrueFalseType(), new SelectType(), new DateType( false ), new DateType( true ),
                new ItemType( 'image' ), new ListType( 'gallery' ), new ItemType( 'file' ), new LinkType(),
                new ItemType( 'post_object' ), new ListType( 'relationship' ), new GroupType(), new RepeaterType(),
            ] as $type ) {
                self::$types[ $type->name() ] = $type;
            }
        }
        if ( ! isset( self::$types[ $name ] ) ) {
            throw new InvalidArgumentException( 'Unknown field type "' . esc_html( $name ) . '".' );
        }
        return self::$types[ $name ];
    }
}
