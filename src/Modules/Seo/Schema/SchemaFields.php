<?php

namespace Fabrement\Modules\Seo\Schema;

use Fabrement\Api\Content\Post;
use Fabrement\Api\Fields\Fields;
use Fabrement\Api\Markup\Link;
use Fabrement\Api\Media\File;
use Fabrement\Api\Media\Image;
use Fabrement\Modules\Fields\Types\WordsType;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Fields\Types\NumberType;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\ValueCodec;
use WP_Error;

/**
 * `{{field:…}}` and `{{option:…}}` in a schema.org template: Fabrement field values as a crawler
 * should read them — through the fields API, not raw post meta.
 *
 * Raw meta is storage: a default is not stored, an image is an id, a link a serialized array, a
 * date-time has no time zone. The nullable fields API supplies defaults and explicit empties;
 * reference ids are resolved in the reader's language, and each type becomes the text schema.org
 * expects: an image its URL, a link its address, a date-time ISO 8601 with the site's offset.
 *
 * A path: the field name, then group sub-fields, then at most one modifier for the leaf's type —
 *   {{field:price}}                {{field:venue.city}}          {{field:level.label}}
 *   {{field:start}} (ISO + offset) {{field:start.date}} / .time / .utc
 *   {{field:photo}} (URL) / .alt   {{field:website}} (URL) / .label
 *   {{field:teacher}} (its URL) / .title / .url / .image / .fields.<path into ITS fields>
 *   {{option:site.phone}}          — the same, on an options page: <page>.<path>
 *
 * Lists (repeater, gallery, relationship) have no text form yet and are refused on write.
 */
final class SchemaFields {

    private const MODIFIERS = [
        'select'           => [ 'label' ],
        'date_picker'      => [ 'date' ],
        'date_time_picker' => [ 'date', 'time', 'utc' ],
        'image'            => [ 'url', 'alt' ],
        'file'             => [ 'url' ],
        'link'             => [ 'url', 'label' ],
        'post_object'      => [ 'url', 'title', 'image', 'fields' ],
    ];

    /** Types with no text form in a graph node (yet). */
    private const NO_TEXT = [ 'repeater', 'gallery', 'relationship' ];

    // ───────────────────────────── write: is the path real? ─────────────────────────────

    /**
     * Check a `field` path against the fields a record of `$post_type` has. Null = fine.
     *
     * @param string $where The token as written, for the message.
     */
    public static function check_field( string $path, string $post_type, string $where ): ?WP_Error {
        $owner = Owner::of( 'post', $post_type );
        $doc   = $owner ? SchemaRepository::get( $owner ) : null;
        if ( ! $doc ) {
            return new WP_Error( 'unknown_field', sprintf( '%s: "%s" records have no Fabrement fields (get-fields {type:"post", slug:"%s"}).', $where, $post_type, $post_type ) );
        }

        return self::check_path( explode( '.', $path ), $doc['fields'] ?? [], $where, '"' . $post_type . '"', true );
    }

    /** Check an `option` token: `<page>.<path>`. Null = fine. */
    public static function check_option( string $arg, string $where ): ?WP_Error {
        $parts = explode( '.', $arg );
        $page  = (string) array_shift( $parts );
        $owner = Owner::of( 'options', $page );
        $doc   = $owner ? SchemaRepository::get( $owner ) : null;
        if ( ! $doc ) {
            return new WP_Error( 'unknown_option', sprintf( '%s: there is no options page "%s" (list-options-pages). The token is {{option:<page>.<field>}}.', $where, $page ) );
        }
        if ( $parts === [] ) {
            return new WP_Error( 'unknown_option', sprintf( '%s: name a field of the "%s" options page — {{option:%s.<field>}}.', $where, $page, $page ) );
        }

        return self::check_path( $parts, $doc['fields'] ?? [], $where, 'the "' . $page . '" options page', true );
    }

    /**
     * @param list<string> $parts
     * @param list<array>  $fields The level to look the first part up in.
     */
    private static function check_path( array $parts, array $fields, string $where, string $owner, bool $allow_fields ): ?WP_Error {
        $name  = (string) array_shift( $parts );
        $field = self::named( $fields, $name );
        if ( ! $field ) {
            $names = array_values( array_map( static fn( $f ) => (string) $f['name'], ValueCodec::active( $fields ) ) );
            return new WP_Error(
                'unknown_field',
                sprintf( '%s: %s has no field "%s". Fields: %s.', $where, $owner, $name, $names === [] ? '(none)' : implode( ', ', $names ) )
            );
        }
        // Into a group, one sub-field at a time.
        while ( $field['type'] === 'group' && $parts !== [] ) {
            $sub = self::named( $field['sub_fields'] ?? [], $parts[0] );
            if ( ! $sub ) {
                return new WP_Error( 'unknown_field', sprintf( '%s: group "%s" has no sub-field "%s".', $where, $field['name'], $parts[0] ) );
            }
            array_shift( $parts );
            $field = $sub;
        }
        if ( in_array( $field['type'], self::NO_TEXT, true ) || $field['type'] === 'group' ) {
            return new WP_Error(
                'field_not_text',
                sprintf( '%s: "%s" is a %s — it has no single text value for a graph node. Name a sub-field, or write the value into the node.', $where, $field['name'], $field['type'] )
            );
        }
        if ( $parts === [] ) {
            return null;
        }

        $modifier = (string) array_shift( $parts );
        $allowed  = self::MODIFIERS[ $field['type'] ] ?? [];
        if ( ! in_array( $modifier, $allowed, true ) || ( $modifier === 'fields' && ! $allow_fields ) ) {
            return new WP_Error(
                'unknown_modifier',
                sprintf( '%s: a %s field takes %s after its name, not ".%s".', $where, $field['type'], $allowed === [] ? 'nothing' : '.' . implode( ' / .', $allowed ), $modifier )
            );
        }
        if ( $modifier !== 'fields' ) {
            return $parts === [] ? null : new WP_Error( 'unknown_modifier', sprintf( '%s: nothing follows ".%s".', $where, $modifier ) );
        }

        // .fields.<path> — into the picked record's own fields, one level: checked against every
        // type the picker allows (a type without that field would print nothing for its records).
        if ( $parts === [] ) {
            return new WP_Error( 'unknown_field', sprintf( '%s: name the field of the picked record — .fields.<name>.', $where ) );
        }
        $types = array_values( array_filter( (array) ( $field['postTypes'] ?? [] ) ) );
        if ( $types === [] ) {
            return null; // any public type may be picked: nothing to check against.
        }
        foreach ( $types as $type ) {
            $owner_of = Owner::of( 'post', (string) $type );
            $doc      = $owner_of ? SchemaRepository::get( $owner_of ) : null;
            $error    = $doc
                ? self::check_path( $parts, $doc['fields'] ?? [], $where, 'a picked "' . $type . '"', false )
                : new WP_Error( 'unknown_field', sprintf( '%s: "%s" records (what %s picks) have no Fabrement fields.', $where, $type, $field['name'] ) );
            if ( $error ) {
                return $error;
            }
        }
        return null;
    }

    // ───────────────────────────── render: the text ─────────────────────────────

    /** `{{field:<path>}}` for one record; "" when there is nothing to say. */
    public static function field( string $path, int $post_id ): string {
        return self::resolve( explode( '.', $path ), $post_id, true );
    }

    /** `{{option:<page>.<path>}}`, in the reader's language. */
    public static function option( string $arg ): string {
        $parts = explode( '.', $arg );
        $page  = (string) array_shift( $parts );

        return $parts === [] ? '' : self::resolve( $parts, 'options:' . $page, true );
    }

    /**
     * @param list<string> $parts
     * @param int|string   $ref   A post id or 'options:<page>'.
     */
    private static function resolve( array $parts, $ref, bool $allow_fields ): string {
        $leaf = self::leaf( $parts, $ref );
        if ( $leaf === null ) {
            return '';
        }
        [ 'field' => $field, 'value' => $value, 'names' => $names, 'rest' => $parts ] = $leaf;
        $modifier = $parts !== [] ? (string) array_shift( $parts ) : '';

        switch ( $field['type'] ) {
            case 'text':
            case 'textarea':
                // Words are stored as typed (WordsType::words()): tags are not text here.
                // Schema is plain text: "&lt;" / "&amp;" of words someone saved through kses read as "<" / "&".
                return is_scalar( $value ) ? self::plain( WordsType::strip_tags( (string) $value ) ) : '';
            case 'wysiwyg':
                return is_string( $value ) ? self::plain( wp_strip_all_tags( $value ) ) : '';
            case 'number':
                return is_int( $value ) || is_float( $value ) ? NumberType::to_text( $value ) : '';
            case 'true_false':
                return $value === null ? '' : ( $value ? 'true' : 'false' );
            case 'select':
                if ( $modifier === 'label' ) {
                    return $leaf['reader']->label( implode( '.', $names ), value: $value ) ?? '';
                }
                return is_scalar( $value ) ? (string) $value : '';
            case 'date_picker':
                return is_string( $value ) ? $value : '';
            case 'date_time_picker':
                return is_string( $value ) ? self::date_time( $value, $modifier ) : '';
            case 'image':
            case 'file':
                return self::media( $value, $modifier );
            case 'link':
                return self::link( $value, $modifier );
            case 'post_object':
                return self::record( $value, $modifier, $parts, $allow_fields );
            default:
                return '';
        }
    }

    /**
     * Walk a path to its field: the top-level name, then group sub-fields. The field's definition,
     * its nullable value (references become typed API objects in the reader's language), the
     * names walked, and what is left of the path (a modifier).
     *
     * @param list<string> $parts
     * @param int|string   $ref   A post id or 'options:<page>'.
     * @return array{field:array, value:mixed, names:list<string>, rest:list<string>, reader:Fields}|null
     */
    public static function leaf( array $parts, $ref ): ?array {
        $top    = (string) array_shift( $parts );
        $object = FieldObject::resolve( $ref );
        if ( $object === null ) {
            return null;
        }
        $reader = new Fields( $object );
        if ( ! $reader->has( $top ) ) {
            return null;
        }
        $field = $reader->field( $top );
        $value = $field['value'];
        $names = [ (string) $field['name'] ];
        while ( $field['type'] === 'group' && $parts !== [] ) {
            $sub = self::named( $field['sub_fields'] ?? [], $parts[0] );
            if ( ! $sub ) {
                return null;
            }
            array_shift( $parts );
            $value   = is_array( $value ) ? ( $value[ $sub['name'] ] ?? null ) : null;
            $field   = $sub;
            $names[] = (string) $sub['name'];
        }
        // The typed readers choose the language version. SEO always describes public records,
        // including when an administrator asks for a graph through MCP or a preview.
        $path = implode( '.', $names );
        if ( $value !== null ) {
            switch ( $field['type'] ) {
                case 'image':
                    $value = $reader->get_image( $path );
                    break;
                case 'file':
                    $value = $reader->get_file( $path );
                    break;
                case 'gallery':
                    $value = $reader->get_gallery( $path );
                    break;
                case 'link':
                    $value = $reader->get_link( $path, status: 'publish' );
                    break;
                case 'post_object':
                    $value = $reader->get_post_object( $path, status: 'publish' );
                    break;
                case 'relationship':
                    $value = $reader->get_relationship( $path, status: 'publish' );
                    break;
            }
        }
        return [ 'field' => $field, 'value' => $value, 'names' => $names, 'rest' => $parts, 'reader' => $reader ];
    }

    /** "2026-09-25T18:00" in site time → ISO 8601 with the offset in force ON THAT DATE. */
    private static function date_time( string $value, string $modifier ): string {
        $at = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
        if ( ! $at ) {
            return '';
        }
        switch ( $modifier ) {
            case 'date':
                return $at->format( 'Y-m-d' );
            case 'time':
                return $at->format( 'H:i' );
            case 'utc':
                return $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
            default:
                return $at->format( 'c' );
        }
    }

    private static function media( Image|File|null $media, string $modifier ): string {
        if ( $media === null ) {
            return '';
        }
        if ( $modifier === 'alt' ) {
            return $media instanceof Image ? trim( (string) $media->alt() ) : '';
        }
        return (string) ( $media instanceof Image ? $media->url( size: 'full' ) : $media->url() );
    }

    /** Text for a JSON-LD string: entities read as the characters they stand for, spaces collapsed. */
    private static function plain( string $text ): string {
        return trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
    }

    private static function link( ?Link $value, string $modifier ): string {
        if ( $value === null ) {
            return '';
        }
        if ( $modifier === 'label' ) {
            return self::plain( WordsType::strip_tags( (string) $value->text() ) );
        }
        $href = (string) $value->url();
        // A graph node wants an address a crawler can follow: mailto:/tel: are for people.
        return preg_match( '#^https?://#i', $href ) === 1 ? $href : '';
    }

    /**
     * The picked record, already resolved in the reader's language. Only a PUBLISHED record
     * is named: a draft's title or address in a public graph would announce a page nobody may see.
     *
     * @param list<string> $parts What follows `.fields`.
     */
    private static function record( ?Post $post, string $modifier, array $parts, bool $allow_fields ): string {
        if ( $post === null || ! $post->not_empty() ) {
            return '';
        }
        $id = $post->id();
        switch ( $modifier ) {
            case 'title':
                // The record's name as a menu shows it: the_title, without the "Protected:" mark.
                return html_entity_decode( Post::caption( $id ), ENT_QUOTES );
            case 'image':
                return (string) $post->thumbnail()->url( size: 'full' );
            case 'fields':
                // Its own words stay behind its password too.
                if ( ! $allow_fields || $parts === [] || (string) get_post_field( 'post_password', $id ) !== '' ) {
                    return '';
                }
                return self::resolve( $parts, $id, false );
            default:
                return (string) $post->url();
        }
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** @param list<array> $fields */
    private static function named( array $fields, string $name ): ?array {
        foreach ( ValueCodec::active( $fields ) as $field ) {
            if ( (string) $field['name'] === $name ) {
                return $field;
            }
        }
        return null;
    }

}
