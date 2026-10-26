<?php

namespace Fabrement\Modules\Seo\Content;

use Fabrement\Api\Content\Post;
use Fabrement\Api\Markup\Link;
use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Types\WordsType;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Fields\Schema\StorageLayout;
use Fabrement\Modules\Fields\Types\NumberType;
use Fabrement\Modules\Seo\Schema\SchemaFields;

/**
 * Fabrement fields as WORDS for a title or a description — what an SEO plugin's variable
 * (`%%fab_level%%` in Yoast) should print, independent of which plugin prints it.
 *
 * An SEO plugin's own custom-field variable prints raw storage: a select's value ("beginner"), a
 * picked record's id, a date as "2026-07-15T18:00", nothing where the field was never set. These go
 * through the fields API: the default when unset, a choice's label in the reader's language, dates
 * in the site's date and time formats, a picked record's title.
 *
 * A variable is named after the field's key in the database, so one name is one field for the
 * whole site (save-fields keeps a key meaning the same field on every post type):
 *
 *   fab_<field>                  a top-level field                 fab_level
 *   fab_<group>_<sub>            a sub-field of a group            fab_venue_city
 *   fab_<repeater>               how many rows a repeater has      fab_modules
 *   fabopt_<page>__<key>         a field of an options page        fabopt_site__phone
 *
 * Images, files, galleries and switches have no words for a title and get no variable.
 * SEO plugins need every variable name up front, so names() lists them from the field files. The
 * words come from the field as the record's OWN post type defines it (its choice labels, its
 * format); a type without that field prints nothing. The plugin integration (e.g.
 * Integration\YoastIntegration) registers the names in its own syntax.
 */
final class FieldText {

    private const TEXT_TYPES = [ 'text', 'textarea', 'wysiwyg', 'number', 'select', 'date_picker', 'date_time_picker', 'link', 'post_object', 'relationship' ];

    /** How many picked records a title names before "…". */
    private const MAX_NAMED = 3;

    /** @var array{0:int,1:array<string,array>}|null [generation, names] */
    private static ?array $memo = null;

    /**
     * The words for variable `$name` on a post (its id; 0 for a page with no record — then only
     * options-page variables answer). Null when there is nothing to say: the plugin then drops it.
     */
    public static function for_variable( string $name, int $post_id ): ?string {
        $entry = self::names()[ $name ] ?? null;
        if ( $entry === null ) {
            return null;
        }
        if ( $entry['page'] !== '' ) {
            $spec = $entry['by'][ $entry['page'] ];
            $ref  = 'options:' . $entry['page'];
        } else {
            // A locked record's words stay behind its password, in its title too.
            if ( $post_id <= 0 || (string) get_post_field( 'post_password', $post_id ) !== '' ) {
                return null;
            }
            $spec = $entry['by'][ (string) get_post_type( $post_id ) ] ?? null;
            if ( $spec === null ) {
                return null;
            }
            $ref = $post_id;
        }
        $text = self::text( $spec, $ref );

        return $text === '' ? null : $text;
    }

    /** A person-readable description of a variable, for the plugin's variable picker. */
    public static function describe( string $name ): string {
        $entry = self::names()[ $name ] ?? null;
        if ( $entry === null ) {
            return '';
        }
        $spec  = reset( $entry['by'] );
        $where = $entry['page'] !== ''
            ? sprintf( 'options page "%s"', $entry['page'] )
            : 'post types: ' . implode( ', ', array_keys( $entry['by'] ) );
        return sprintf( 'Fabrement field "%s"%s (%s), as a reader sees it.', $spec['path'], $spec['count'] ? ', number of rows' : '', $where );
    }

    /**
     * The field as words.
     *
     * @param array{path:string,count:bool} $spec
     * @param int|string                    $ref
     */
    public static function text( array $spec, $ref ): string {
        $leaf = SchemaFields::leaf( explode( '.', $spec['path'] ), $ref );
        if ( $leaf === null ) {
            return '';
        }
        if ( $spec['count'] ) {
            // Through leaf() like any other path: a repeater inside a group is `venue.slots`.
            $rows = $leaf['field']['type'] === 'repeater' && $leaf['rest'] === [] ? $leaf['value'] : null;
            return is_array( $rows ) && $rows !== [] ? (string) count( $rows ) : '';
        }
        $value = $leaf['value'];
        switch ( $leaf['field']['type'] ) {
            case 'text':
            case 'textarea':
                // Words are stored as typed (WordsType::words()): tags are not text here.
                return is_scalar( $value ) ? trim( (string) preg_replace( '/\s+/', ' ', WordsType::strip_tags( (string) $value ) ) ) : '';
            case 'wysiwyg':
                return is_string( $value ) ? trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) ) : '';
            case 'number':
                if ( ! is_int( $value ) && ! is_float( $value ) ) {
                    return '';
                }
                $plain = NumberType::to_text( $value );
                $dot   = strpos( $plain, '.' );
                return number_format_i18n( $value, $dot === false ? 0 : strlen( $plain ) - $dot - 1 );
            case 'select':
                $label = $leaf['reader']->label( implode( '.', $leaf['names'] ), value: $value ) ?? '';
                return $label !== '' ? $label : ( is_scalar( $value ) ? (string) $value : '' );
            case 'date_picker':
                return is_string( $value ) ? self::date( $value, '!Y-m-d', (string) get_option( 'date_format' ) ) : '';
            case 'date_time_picker':
                return is_string( $value ) ? self::date( $value, '!Y-m-d\TH:i', get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
            case 'link':
                if ( ! $value instanceof Link ) {
                    return '';
                }
                $label = trim( WordsType::strip_tags( (string) $value->text() ) );
                return $label !== '' ? $label : (string) $value->url();
            case 'post_object':
                return self::title( $value );
            case 'relationship':
                $titles = array_values( array_filter( array_map( [ self::class, 'title' ], $value ?? [] ) ) );
                if ( $titles === [] ) {
                    return '';
                }
                $named = implode( ', ', array_slice( $titles, 0, self::MAX_NAMED ) );
                return count( $titles ) > self::MAX_NAMED ? $named . '…' : $named;
            default:
                return '';
        }
    }

    /** A published record's title; a draft is not named in public. */
    private static function title( ?Post $post ): string {
        // The typed reader already chose a published version. Keep its name without a
        // "Protected:" prefix, as the SEO variables have always done.
        return $post !== null && $post->not_empty()
            ? html_entity_decode( (string) get_post_field( 'post_title', $post->id() ), ENT_QUOTES )
            : '';
    }

    /** A stored date in site time → the site's own format, in the reader's language. */
    private static function date( string $value, string $format_in, string $format_out ): string {
        $at = \DateTimeImmutable::createFromFormat( $format_in, $value, wp_timezone() );
        return $at ? (string) wp_date( $format_out, $at->getTimestamp() ) : '';
    }

    /**
     * Every variable the field files allow: name => the options page it reads ('' for records) and,
     * per post type (or for the page), the field's path. Read once per request and again only when
     * a field file changed in it.
     *
     * @return array<string,array{page:string,by:array<string,array{path:string,count:bool}>}>
     */
    public static function names(): array {
        $generation = SchemaRepository::generation();
        if ( self::$memo !== null && self::$memo[0] === $generation ) {
            return self::$memo[1];
        }
        $out = [];
        foreach ( SchemaRepository::all() as $owner_id => $document ) {
            $owner = Owner::from_file( $owner_id . '.json' );
            if ( ! $owner || ! in_array( $owner->type, [ 'post', 'options' ], true ) ) {
                continue;
            }
            $page = $owner->type === 'options' ? $owner->slug : '';
            foreach ( self::keys( $document['fields'] ?? [], '', '' ) as $key => $spec ) {
                $name = $page === '' ? $key : 'fabopt_' . $page . '__' . substr( $key, strlen( Keys::FIELD_META_PREFIX ) );
                $out[ $name ]['page']               = $page;
                $out[ $name ]['by'][ $owner->slug ] = $spec;
            }
        }
        ksort( $out );
        self::$memo = [ $generation, $out ];

        return $out;
    }

    /**
     * The active fields that have words, by their database key: text-like fields and repeaters
     * (their row count), at the top and inside groups.
     *
     * @return array<string,array{path:string,count:bool}>
     */
    private static function keys( array $fields, string $parent_key, string $parent_path ): array {
        $out = [];
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) || ! empty( $field['retired'] ) ) {
                continue;
            }
            $name = (string) $field['name'];
            $key  = $parent_key === '' ? StorageLayout::key_for( $name, true ) : StorageLayout::child( $parent_key, $name );
            $path = $parent_path === '' ? $name : $parent_path . '.' . $name;
            if ( in_array( $field['type'], self::TEXT_TYPES, true ) ) {
                $out[ $key ] = [ 'path' => $path, 'count' => false ];
            } elseif ( $field['type'] === 'repeater' ) {
                $out[ $key ] = [ 'path' => $path, 'count' => true ];
            } elseif ( $field['type'] === 'group' ) {
                $out += self::keys( $field['sub_fields'] ?? [], $key, $path );
            }
        }
        return $out;
    }
}
