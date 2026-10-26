<?php

namespace Fabrement\Api\Fields;

use Fabrement\Api\Content\Post;
use Fabrement\Api\FieldsException;
use Fabrement\Api\Markup\Link;
use Fabrement\Api\Media\Attachment;
use Fabrement\Api\Media\File;
use Fabrement\Api\Media\Image;
use Fabrement\Api\Support\Quietly;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Translation\Version;

defined( 'ABSPATH' ) || exit;

/**
 * The typed readers of a place: get_<type>( path…, named options ). Values come raw; record and media fields come as the objects fabrement() gives (Post, Image, File,
 * Link); a group and repeater rows as places of their own. An empty or wrongly asked
 * object or group gives its empty object, an empty value null, a list [].
 */
trait FieldReaders {

    /** @param mixed ...$args */
    public function get_text( $name = null, ...$args ): ?string {
        return $this->show( 'get_text', 'text', $name, $args, [], static fn( $value ): string => (string) $value );
    }

    /** @param mixed ...$args */
    public function get_textarea( $name = null, ...$args ): ?string {
        return $this->show( 'get_textarea', 'textarea', $name, $args, [ 'breaks' ], static fn( $value, array $o ): string => ! empty( $o['breaks'] ) ? fabrement_kses_textarea( (string) $value ) : (string) $value );
    }

    /** @param mixed ...$args */
    public function get_wysiwyg( $name = null, ...$args ): ?string {
        // As WordPress shows content: the images an editor inserted get srcset, sizes and lazy loading.
        return $this->show( 'get_wysiwyg', 'wysiwyg', $name, $args, [], static fn( $value ): string => wp_filter_content_tags( wp_kses_post( (string) $value ), 'fabrement_markup' ) );
    }

    /**
     * @param mixed ...$args
     * @return int|float|null
     */
    public function get_number( $name = null, ...$args ): int|float|null {
        return $this->show( 'get_number', 'number', $name, $args, [], function ( $value, array $o, array $path ) {
            // The type follows the field, not how the value was written: integer → int, decimal → float.
            return $this->fields->number_format_at( $path ) === 'integer' ? (int) $value : (float) $value;
        } );
    }

    /** @param mixed ...$args */
    public function get_true_false( $name = null, ...$args ): ?bool {
        return $this->show( 'get_true_false', 'true_false', $name, $args, [], static fn( $value ): bool => $value === true );
    }

    /** @param mixed ...$args */
    public function get_select( $name = null, ...$args ): ?string {
        return $this->show( 'get_select', 'select', $name, $args, [ 'label' ], function ( $value, array $o, array $path ): string {
            return ! empty( $o['label'] ) ? $this->fields->label_at( $path, (string) $value ) : (string) $value;
        } );
    }

    /** @param mixed ...$args */
    public function get_date( $name = null, ...$args ): ?string {
        return $this->show( 'get_date', 'date_picker', $name, $args, [], static fn( $value ): string => (string) $value );
    }

    /** @param mixed ...$args */
    public function get_date_time( $name = null, ...$args ): ?string {
        return $this->show( 'get_date_time', 'date_time_picker', $name, $args, [], static fn( $value ): string => (string) $value );
    }

    /** @param mixed ...$args */
    public function get_image( $name = null, ...$args ): ?Image {
        return $this->show( 'get_image', 'image', $name, $args, [ 'lang' ], static function ( $value, array $o ): Image {
            Version::check_language( $o['lang'] ?? '' );
            return is_int( $value ) && $value > 0 ? Attachment::pick( $value, Version::lang( $o['lang'] ?? '' ), [ 'image' ] ) : Image::none();
        } );
    }

    /**
     * @param mixed ...$args
     * @return list<Image>
     */
    public function get_gallery( $name = null, ...$args ): array {
        return $this->show( 'get_gallery', 'gallery', $name, $args, [ 'lang' ], static function ( $value, array $o ): array {
            Version::check_language( $o['lang'] ?? '' );
            $ids = array_values( array_filter( is_array( $value ) ? $value : [], static fn( $id ): bool => is_int( $id ) && $id > 0 ) );
            $lang = Version::lang( $o['lang'] ?? '' );
            $out  = [];
            foreach ( $ids === [] ? [] : Version::media_many( Version::ids( $ids, 'get_gallery()' ), $lang ) as $id ) {
                $image = Attachment::pick( $id, $lang, [ 'image' ] );
                if ( $image->not_empty() ) {
                    $out[] = $image;
                }
            }
            return $out;
        } );
    }

    /** @param mixed ...$args */
    public function get_file( $name = null, ...$args ): ?File {
        return $this->show( 'get_file', 'file', $name, $args, [ 'lang' ], static function ( $value, array $o ): File {
            Version::check_language( $o['lang'] ?? '' );
            return is_int( $value ) && $value > 0 ? File::from( $value, $o['lang'] ?? '' ) : File::none();
        } );
    }

    /** @param mixed ...$args */
    public function get_link( $name = null, ...$args ): ?Link {
        return $this->show( 'get_link', 'link', $name, $args, [ 'lang', 'status' ], static function ( $value, array $o ): Link {
            Version::check_language( $o['lang'] ?? '' );
            return Link::from( $value, Version::lang( $o['lang'] ?? '' ), Version::statuses( $o['status'] ?? null ), 'get_link()' );
        } );
    }

    /** @param mixed ...$args */
    public function get_post_object( $name = null, ...$args ): ?Post {
        return $this->show( 'get_post_object', 'post_object', $name, $args, [ 'lang', 'status' ], static function ( $value, array $o ): Post {
            Version::check_language( $o['lang'] ?? '' );
            return is_int( $value ) && $value > 0
                ? Post::of( Version::post( $value, Version::lang( $o['lang'] ?? '' ), Version::statuses( $o['status'] ?? null ) ), Version::lang( $o['lang'] ?? '' ) )
                : Post::none();
        } );
    }

    /**
     * @param mixed ...$args
     * @return list<Post>
     */
    public function get_relationship( $name = null, ...$args ): array {
        return $this->show( 'get_relationship', 'relationship', $name, $args, [ 'lang', 'status' ], static function ( $value, array $o ): array {
            Version::check_language( $o['lang'] ?? '' );
            $lang = Version::lang( $o['lang'] ?? '' );
            $ids  = Version::ids( is_array( $value ) ? $value : [], 'get_relationship()' );
            return $ids === [] ? [] : Post::listed( Version::posts( $ids, $lang, Version::statuses( $o['status'] ?? null ) ), $lang );
        } );
    }

    /**
     * An empty group reads nothing: its readers answer empty, quietly.
     *
     * @param mixed ...$args
     */
    public function get_group( $name = null, ...$args ): Group {
        try {
            $path = $this->call( 'get_group', $name, $args, [] )[0];
            $this->fields->read_at( $path, true, [ 'group' ], 'get_group' );
            return new Group( $this->fields, $path );
        } catch ( FieldsException $e ) {
            if ( $this->loud() ) {
                Quietly::log( $this->where( 'get_group', array_merge( [ $name ], array_values( array_filter( $args, 'is_int', ARRAY_FILTER_USE_KEY ) ) ) ), $e->getMessage() );
            }
            return new Group( Fields::missing( $e->getMessage(), true ), [] );
        }
    }

    /**
     * @param mixed ...$args
     * @return list<Row>
     */
    public function get_rows( $name = null, ...$args ): array {
        return $this->show( 'get_rows', 'repeater', $name, $args, [ 'limit' ], function ( $rows, array $o, array $path ): array {
            $rows = is_array( $rows ) ? array_values( $rows ) : [];
            if ( ( $o['limit'] ?? null ) !== null ) {
                $rows = array_slice( $rows, 0, $o['limit'] );
            }
            $out = [];
            foreach ( $rows as $i => $row ) {
                $out[] = new Row( $this->fields, array_merge( $path, [ (string) $row[ ValueCodec::ROW_KEY ] ] ), $i );
            }
            return $out;
        } );
    }

    /**
     * @param mixed $name A field path, or a list of them.
     * @param mixed               ...$segments
     */
    public function not_empty( $name = null, ...$segments ): bool {
        if ( $name === null && $segments === [] ) {
            return $this->reading( 'not_empty', [], function (): bool {
                foreach ( array_keys( $this->fields->children_at( $this->prefix ) ) as $child ) {
                    if ( $this->not_empty( (string) $child ) ) {
                        return true;
                    }
                }
                return false;
            }, false );
        }
        if ( is_array( $name ) ) {
            return $this->reading( 'not_empty', [ $name ], function () use ( $name, $segments ): ?bool {
                if ( $segments ) {
                    throw new FieldsException( 'a list of fields takes no more path; give each field\'s path in the list.' );
                }
                if ( $name === [] || array_filter( $name, 'is_string' ) !== $name ) {
                    throw new FieldsException( 'give a list of field names, at least one.' );
                }
                $filled = false;
                foreach ( $name as $one ) {
                    $filled = $this->not_empty( $one ) || $filled;
                }
                return $filled;
            }, false );
        }
        return $this->reading( 'not_empty', array_merge( [ $name ], array_values( array_filter( $segments, 'is_int', ARRAY_FILTER_USE_KEY ) ) ), function () use ( $name, $segments ): ?bool {
            foreach ( array_keys( $segments ) as $key ) {
                if ( ! is_int( $key ) ) {
                    throw new FieldsException( 'unknown option "' . esc_html( $key ) . '"; not_empty() takes none.' );
                }
            }
            $path = $this->path( array_merge( [ $name ], $segments ) );
            $type = $this->fields->type_at( $path );
            switch ( $type ) {
                case 'number':
                    return $this->fields->read_at( $path, true ) !== null;
                case 'true_false':
                    return $this->fields->read_at( $path, true ) === true;
                case 'group':
                    $group = new Group( $this->fields, $path );
                    foreach ( array_keys( $this->fields->children_at( $path ) ) as $child ) {
                        if ( $group->not_empty( (string) $child ) ) {
                            return true;
                        }
                    }
                    return false;
                case 'text':
                case 'textarea':
                case 'wysiwyg':
                case 'select':
                case 'date_picker':
                case 'date_time_picker':
                    $value = $this->fields->read_at( $path, true );
                    return is_array( $value ) ? $value !== [] : (string) $value !== '';
            }
            $read = 'get_' . ( self::SUFFIX[ $type ] ?? $type );
            $value = $this->$read( $name, ...$segments );
            return $value !== null && ( is_array( $value ) ? $value !== [] : $value->not_empty() );
        }, false );
    }

}
