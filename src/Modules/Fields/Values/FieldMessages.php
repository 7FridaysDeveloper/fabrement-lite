<?php

namespace Fabrement\Modules\Fields\Values;

use LogicException;

/** Every wording of a refused field value: plain English for agents and site code, translated for a person in the fields box. */
final class FieldMessages {

    public static function english( FieldValueError $error ): string {
        if ( $error->key === FieldValueError::SEVERAL ) {
            return 'Several fields need a value or a fix: ' . implode( ' ', array_map( static fn( FieldValueError $one ): string => $one->getMessage(), $error->errors ) );
        }
        $detail = self::plain( self::detail( $error ) );
        if ( $error->field === null ) {
            return $detail;
        }
        $prefix = self::plain( $error->at !== '' ? $error->at . ': ' : '' ) . self::plain( $error->field['label'] ) . ': ';
        return $error->saved()
            ? $prefix . 'the value stored earlier no longer passes the field\'s rules: ' . $detail
            : $prefix . $detail;
    }

    private static function plain( string $text ): string {
        return htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' );
    }

    public static function person( FieldValueError $error ): string {
        if ( $error->key === FieldValueError::SEVERAL ) {
            return __( 'Several fields need attention before saving:', 'fabrement-lite' ) . "\n" . implode( "\n", array_map( [ self::class, 'person' ], $error->errors ) );
        }
        $label = self::label( $error );
        $message = self::sentence( $error, $label );
        if ( $error->saved() ) {
            /* translators: %s: field label. */
            $message .= ' ' . sprintf( __( 'This is the value saved earlier. Change %s to save.', 'fabrement-lite' ), $label );
        }
        return $message;
    }

    public static function row( string $label, int $index ): string {
        /* translators: 1: repeater field label, 2: row number, from 1. */
        return sprintf( __( '%1$s, row %2$d', 'fabrement-lite' ), $label, $index + 1 );
    }

    private static function detail( FieldValueError $error ): string {
        $p = $error->params;
        switch ( $error->key ) {
            case FieldValueError::REQUIRED:
                return 'This field is required.';
            case FieldValueError::TOO_LONG:
                return 'Expected text up to 256 KiB.';
            case FieldValueError::TRUE_FALSE:
                return 'Expected true, false or empty.';
            case FieldValueError::NOT_NUMBER:
                return 'Expected a finite number or empty.';
            case FieldValueError::NUMBER_TOO_LARGE:
                return 'The number is too large: at most ±9007199254740991. A number that long (a phone, an ID, a code) belongs in a text field.';
            case FieldValueError::WHOLE_NUMBER:
                return 'Expected a whole number.';
            case FieldValueError::OUT_OF_RANGE:
                return 'The number is outside the allowed range' . ValueCodec::range( $p ) . '.';
            case FieldValueError::DATE_INVALID:
                return 'Enter a valid date' . ( ! empty( $p['time'] ) ? ' and time (YYYY-MM-DDTHH:MM).' : ' (YYYY-MM-DD).' );
            case FieldValueError::CHOICE:
                return ( isset( $p['part'] ) ? $p['part'] . ': ' : '' ) . 'Select one of the choices.';
            case FieldValueError::LINK_SHAPE:
                return 'Invalid link. Expected {url, label?, newTab?} or {postId, label? | useRecordTitle: true, lang?}.';
            case FieldValueError::LINK_TEXT:
                return 'Invalid link text.';
            case FieldValueError::LINK_OPTIONS:
                return 'Invalid link options: newTab and useRecordTitle are true or false, lang a language code or "".';
            case FieldValueError::LINK_LANG:
                return ! empty( $p['codes'] )
                    ? sprintf( 'lang "%s" is not a language of this site (%s); "" follows the reader.', $p['lang'] ?? '', implode( ', ', $p['codes'] ) )
                    : 'This site has one language: leave lang empty.';
            case FieldValueError::LINK_ADDRESS:
                return 'Unsupported link address.';
            case FieldValueError::LINK_SITE_PATH:
                return 'Unsupported link address. A path on this site starts with one "/" (or "#"); another site needs https://.';
            case FieldValueError::LINK_SCHEME:
                return 'Use an http(s), mailto: or tel: address, or a path starting with /.';
            case FieldValueError::RECORD_ID:
                return 'Expected a record ID.';
            case FieldValueError::ITEM_ZERO:
                return 'Send null to clear it; 0 is not an ID.';
            case FieldValueError::RECORD_EMPTY:
                return ( $p['what'] ?? '' ) === 'image' ? 'Select an image from the media library.' : 'Select an existing record.';
            case FieldValueError::RECORD_MISSING:
                return sprintf( '%s %d does not exist, is trashed or is an auto-draft.', ( $p['what'] ?? '' ) === 'post_object' ? 'Record' : 'Media', (int) ( $p['id'] ?? 0 ) );
            case FieldValueError::RECORD_TYPE:
                return 'Select a record of type ' . implode( ' or ', (array) ( $p['types'] ?? [] ) ) . '.';
            case FieldValueError::RECORD_NOT_PUBLIC:
                return 'Select a record of a public post type (page, post, …), not a ' . ( $p['type'] ?? '' ) . '.';
            case FieldValueError::NOT_MEDIA:
                return 'Select a media file.';
            case FieldValueError::NOT_IMAGE:
                return 'Select an image.';
            case FieldValueError::LIST_SHAPE:
            case FieldValueError::LIST_TOO_LONG:
                return 'Expected an ordered list of at most ' . ValueCodec::MAX_LIST . ' ' . ( $p['what'] ?? 'record' ) . 's.';
            case FieldValueError::UNIQUE:
                return 'The same ' . ( $p['what'] ?? 'record' ) . ' is listed twice; this field lists each once.';
            case FieldValueError::TOO_MANY:
                return 'The number of items is outside the allowed range' . ValueCodec::range( $p ) . '.';
            case FieldValueError::ROWS_SHAPE:
                return 'Expected at most 500 rows.';
            case FieldValueError::ROW_ID:
                return 'Each row needs its own identity (row_ + 12 hex; an older row\'s 32 stay).';
            case FieldValueError::SUB_FIELD_UNKNOWN:
                return 'Unknown or retired sub-field.';
            case FieldValueError::TRANSLATOR_WORDS:
                return 'Its words are translated in WPML\'s Translation Editor, which manages this translation: ask the user, or change the original\'s words and let the WPML translation job carry them. copy_once and ignore values can be set here.';
            case FieldValueError::TRANSLATOR_EMPTY_ORIGINAL:
                return 'the original has no words here and WPML\'s Translation Editor manages this translation, so its next translation job would empty it: add the words on the original or leave this empty.';
        }
        throw new LogicException( esc_html( 'No wording for the field error "' . $error->key . '".' ) );
    }

    private static function label( FieldValueError $error ): string {
        $parts = $error->trail ?? [ $error->field['label'] ?? '' ];
        $label = implode( ' › ', array_map( 'esc_html', $parts ) );
        if ( isset( $error->params['item'] ) ) {
            /* translators: 1: field label, 2: position of the item in the list, from 1. */
            $label = sprintf( __( '%1$s, item %2$d', 'fabrement-lite' ), $label, (int) $error->params['item'] );
        }
        return $label;
    }

    private static function sentence( FieldValueError $error, string $label ): string {
        $p = $error->params;
        switch ( $error->key ) {
            case FieldValueError::REQUIRED:
                /* translators: %s: field label. */
                return sprintf( __( '%s is required. Fill it in to save.', 'fabrement-lite' ), $label );
            case FieldValueError::TOO_LONG:
                /* translators: %s: field label. */
                return sprintf( __( '%s: the text is too long.', 'fabrement-lite' ), $label );
            case FieldValueError::NOT_NUMBER:
                /* translators: %s: field label. */
                return sprintf( __( '%s: enter a number.', 'fabrement-lite' ), $label );
            case FieldValueError::NUMBER_TOO_LARGE:
                /* translators: %s: field label. */
                return sprintf( __( '%s: the number is too large. Phone numbers, IDs and codes belong in a text field.', 'fabrement-lite' ), $label );
            case FieldValueError::WHOLE_NUMBER:
                /* translators: %s: field label. */
                return sprintf( __( '%s: enter a whole number.', 'fabrement-lite' ), $label );
            case FieldValueError::OUT_OF_RANGE:
                /* translators: 1: field label, 2: allowed range, e.g. "between 0 and 100". */
                return sprintf( __( '%1$s: the number must be %2$s.', 'fabrement-lite' ), $label, self::range( $p ) );
            case FieldValueError::DATE_INVALID:
                return ! empty( $p['time'] )
                    /* translators: %s: field label. */
                    ? sprintf( __( '%s: enter a valid date and time.', 'fabrement-lite' ), $label )
                    /* translators: %s: field label. */
                    : sprintf( __( '%s: enter a valid date.', 'fabrement-lite' ), $label );
            case FieldValueError::CHOICE:
                /* translators: 1: field label, 2: the stored or sent option value. */
                return sprintf( __( '%1$s: "%2$s" is not one of the options. Choose another one.', 'fabrement-lite' ), $label, esc_html( (string) ( $p['value'] ?? '' ) ) );
            case FieldValueError::LINK_TEXT:
                /* translators: %s: field label. */
                return sprintf( __( '%s: the link text cannot be saved.', 'fabrement-lite' ), $label );
            case FieldValueError::LINK_LANG:
                /* translators: 1: field label, 2: language code. */
                return sprintf( __( '%1$s: the link opens the "%2$s" version, but the site has no such language. Choose another language or leave it empty.', 'fabrement-lite' ), $label, esc_html( (string) ( $p['lang'] ?? '' ) ) );
            case FieldValueError::LINK_ADDRESS:
            case FieldValueError::LINK_SITE_PATH:
            case FieldValueError::LINK_SCHEME:
                /* translators: %s: field label. */
                return sprintf( __( '%s: enter a web address (https://…), an email (mailto:…), a phone (tel:…) or a path on this site starting with /.', 'fabrement-lite' ), $label );
            case FieldValueError::RECORD_EMPTY:
                /* translators: %s: field label. */
                return sprintf( __( '%s: nothing is chosen here. Choose an item or remove it.', 'fabrement-lite' ), $label );
            case FieldValueError::RECORD_MISSING:
                /* translators: %s: field label. */
                return sprintf( __( '%s: the chosen item was deleted or moved to the trash. Choose another one.', 'fabrement-lite' ), $label );
            case FieldValueError::RECORD_TYPE:
                /* translators: 1: field label, 2: allowed post types, comma separated. */
                return sprintf( __( '%1$s: choose an item of type %2$s.', 'fabrement-lite' ), $label, esc_html( implode( ', ', (array) ( $p['types'] ?? [] ) ) ) );
            case FieldValueError::RECORD_NOT_PUBLIC:
                /* translators: %s: field label. */
                return sprintf( __( '%s: choose a page, a post or another public item.', 'fabrement-lite' ), $label );
            case FieldValueError::NOT_MEDIA:
                /* translators: %s: field label. */
                return sprintf( __( '%s: choose a file from the media library.', 'fabrement-lite' ), $label );
            case FieldValueError::NOT_IMAGE:
                /* translators: %s: field label. */
                return sprintf( __( '%s: choose an image.', 'fabrement-lite' ), $label );
            case FieldValueError::UNIQUE:
                /* translators: %s: field label. */
                return sprintf( __( '%s: the same item is listed twice. Remove the repeat.', 'fabrement-lite' ), $label );
            case FieldValueError::TOO_MANY:
            case FieldValueError::LIST_TOO_LONG:
                /* translators: 1: field label, 2: allowed number of items, 3: number of items now. */
                return sprintf( __( '%1$s: at most %2$d items are allowed, there are %3$d. Remove some.', 'fabrement-lite' ), $label, (int) ( $p['max'] ?? 0 ), (int) ( $p['count'] ?? 0 ) );
            case FieldValueError::TRANSLATOR_WORDS:
                /* translators: %s: field label. */
                return sprintf( __( '%s: these words are translated in WPML\'s translation editor. Change them in the original language and translate them there.', 'fabrement-lite' ), $label );
            case FieldValueError::TRANSLATOR_EMPTY_ORIGINAL:
                /* translators: %s: field label. */
                return sprintf( __( '%s: the original language has nothing here, so the next translation in WPML\'s translation editor would remove these words. Add them in the original language or leave this empty.', 'fabrement-lite' ), $label );
            case FieldValueError::TRUE_FALSE:
            case FieldValueError::LINK_SHAPE:
            case FieldValueError::LINK_OPTIONS:
            case FieldValueError::RECORD_ID:
            case FieldValueError::ITEM_ZERO:
            case FieldValueError::LIST_SHAPE:
            case FieldValueError::ROWS_SHAPE:
            case FieldValueError::ROW_ID:
            case FieldValueError::SUB_FIELD_UNKNOWN:
                /* translators: %s: field label. */
                return sprintf( __( '%s: this value cannot be saved.', 'fabrement-lite' ), $label );
        }
        throw new LogicException( esc_html( 'No wording for the field error "' . $error->key . '".' ) );
    }

    private static function range( array $p ): string {
        if ( isset( $p['min'], $p['max'] ) ) {
            /* translators: 1: lowest allowed number, 2: highest allowed number. */
            return sprintf( __( 'between %1$s and %2$s', 'fabrement-lite' ), $p['min'], $p['max'] );
        }
        if ( isset( $p['min'] ) ) {
            /* translators: %s: lowest allowed number. */
            return sprintf( __( 'at least %s', 'fabrement-lite' ), $p['min'] );
        }
        /* translators: %s: highest allowed number. */
        return sprintf( __( 'at most %s', 'fabrement-lite' ), $p['max'] ?? '' );
    }
}
