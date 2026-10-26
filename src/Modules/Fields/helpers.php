<?php
/**
 * Released compatibility API for Fabrement fields. Named like ACF's, prefixed so both can coexist.
 * Only this entry depends on FieldReader; modern fabrement()->fields()/options() use the core
 * directly. Future removal of this entry and its reader does not remove shared field logic.
 *
 * `$object` is a post ID; left out, the current post of the loop; 0, no record (null / [] back);
 * 'options:<page>' an options page in the reader's language (fabrement_get_option() for short).
 * Fields exist on post types (post, page, CPTs) and options pages; terms, users and comments are
 * not supported yet.
 * Fields are found by `name` in the file of the object's owner, so one template works for every
 * post type that has a field with that name. Values are not escaped: escape for the context.
 */

use Fabrement\Modules\Fields\Values\FieldReader;

defined( 'ABSPATH' ) || exit;

/**
 * Which record a call means. Left out (null): the current post of the loop. An explicit 0 (or
 * "0" / ""): NO record — a template that computes `is_singular() ? get_queried_object_id() : 0`
 * must get nothing on an archive, not the first post of the archive's list, which is what the
 * current post is there.
 *
 * @param int|string|null $object
 */
function fabrement_fields_object_ref( $object ) {
    if ( $object === null ) {
        return (int) get_the_ID();
    }
    return $object === '' || $object === '0' ? 0 : $object;
}

/**
 * The value of one field; null when the record has no such field.
 *
 * @param int|string|null $object
 */
function fabrement_get_field( string $name, $object = null ) {
    return FieldReader::get( $name, fabrement_fields_object_ref( $object ) );
}

/**
 * Every field of a record, keyed by name.
 *
 * @param int|string|null $object
 */
function fabrement_get_fields( $object = null ): array {
    return FieldReader::all( fabrement_fields_object_ref( $object ) );
}

/**
 * The field's definition with `value` and `exists` (false = the value shown is the default).
 *
 * @param int|string|null $object
 */
function fabrement_get_field_object( string $name, $object = null ): ?array {
    return FieldReader::object( $name, fabrement_fields_object_ref( $object ) );
}

/**
 * The LABEL of a choice field's value, as stored — what a visitor should read,
 * where fabrement_get_field() gives the stored value ("beginner", for conditions in code). Choice
 * fields are the ones with `choices`: select today.
 *
 * $selector is the field's name, or a dotted path to one inside a group or repeater
 * ("modules.level"); for a sub-field pass the row's value as $value. "" for no value, an unknown
 * value, or a field without choices.
 *
 * @param int|string|null $object
 * @param mixed           $value  The value to label; left out, the top-level field's own value.
 */
function fabrement_get_choice_label( string $selector, $object = null, $value = null ): string {
    return FieldReader::choice_label( $selector, fabrement_fields_object_ref( $object ), $value );
}

/**
 * One field of an options page (wp-admin → "Site General Settings"), in the reader's language: the value
 * that language stores, with schema defaults only for absent values. Copying happens on writes.
 * Null when the page or the field does not exist. The same as
 * fabrement_get_field( $name, 'options:' . $page ); every fabrement_*_field function takes
 * 'options:<page>' (or 'options:<page>:<lang>' for one language) as its object.
 */
function fabrement_get_option( string $name, string $page ) {
    return FieldReader::get( $name, 'options:' . $page );
}

/**
 * The LABEL of a choice field on an options page (a select), as stored —
 * fabrement_get_choice_label() for an options page. $selector may be a dotted path to a sub-field
 * ("socials.kind"); for a sub-field pass the row's value as $value.
 *
 * @param mixed $value The value to label; left out, the top-level field's own value.
 */
function fabrement_get_option_label( string $selector, string $page, $value = null ): string {
    return FieldReader::choice_label( $selector, 'options:' . $page, $value );
}

/** Every field of an options page, keyed by name, in the reader's language. */
function fabrement_get_options( string $page ): array {
    return FieldReader::all( 'options:' . $page );
}
