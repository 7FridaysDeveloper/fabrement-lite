<?php

namespace Fabrement\Api\Fields;

use Fabrement\Api\Content\Post;
use Fabrement\Api\FieldsException;
use Fabrement\Api\Markup\Link;
use Fabrement\Api\Media\File;
use Fabrement\Api\Media\Image;
use Fabrement\Api\Support\ReadResult;
use Fabrement\Api\Support\Quietly;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Translation\Version;

defined( 'ABSPATH' ) || exit;

/**
 * What a record, a repeater row and a group share: raw reads — get(),
 * values(), has(), field(), label(), choices() — the typed readers (FieldReaders) and the writers
 * (FieldWriters). A path starts at the place. Reads never throw: what cannot be read is empty and
 * one line in the debug log, "Fields::get_number( 'price' ): …". Writes throw FieldsException and
 * join the record's changes until its save().
 */
abstract class Place {

    use FieldReaders;
    use FieldWriters;

    protected const SUFFIX = [
        'text'             => 'text',
        'textarea'         => 'textarea',
        'wysiwyg'          => 'wysiwyg',
        'number'           => 'number',
        'true_false'       => 'true_false',
        'select'           => 'select',
        'date_picker'      => 'date',
        'date_time_picker' => 'date_time',
        'image'            => 'image',
        'gallery'          => 'gallery',
        'file'             => 'file',
        'link'             => 'link',
        'post_object'      => 'post_object',
        'relationship'     => 'relationship',
        'group'            => 'group',
        'repeater'         => 'rows',
    ];

    private const ROW_CALLS = [ 'add_row', 'update_row', 'move_row', 'delete_row' ];

    private const ITEM_CALLS = [ 'add_item', 'move_item', 'delete_item' ];

    protected Fields $fields;

    /** @var list<string> */
    protected array $prefix;


    /** @param list<string> $prefix */
    protected function __construct( Fields $fields, array $prefix ) {
        $this->fields = $fields;
        $this->prefix = $prefix;
    }

    /**
     * @return mixed
     * @param mixed $path
     * @param mixed $row_ids
     */
    public function get( $path = null, $row_ids = true, ...$unknown ) {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->reading( 'get', [ $path ], function () use ( $path, $row_ids ) {
            self::check( 'row_ids', $row_ids );
            return $this->fields->read_at( $this->path( [ $path ] ), $row_ids );
        }, null );
    }

    /**
     * @param mixed $row_ids
     * @return array<string, mixed>
     */
    public function values( $row_ids = true, ...$unknown ): array {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->reading( 'values', [], function () use ( $row_ids ): array {
            self::check( 'row_ids', $row_ids );
            $prefix = $this->path( [] );
            $out = [];
            foreach ( array_keys( $this->fields->children_at( $prefix ) ) as $name ) {
                $out[ $name ] = $this->fields->read_at( array_merge( $prefix, [ (string) $name ] ), $row_ids );
            }
            return $out;
        }, [] );
    }

    /** @param mixed $name */
    public function has( $name = null, ...$unknown ): bool {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return is_string( $name ) && Quietly::run( '', fn(): bool => isset( $this->fields->children_at( $this->path( [] ) )[ $name ] ), false, false );
    }

    /** @param mixed $name */
    public function field( $name = null, ...$unknown ): ?array {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->reading( 'field', [ $name ], function () use ( $name ): array {
            $this->path( [ $name ] );
            $prefix = $this->path( [] );
            $children = $this->fields->children_at( $prefix );
            $path = array_merge( $prefix, [ $name ] );
            if ( ! isset( $children[ $name ] ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ValueCodec::unknown() escapes the names it quotes.
                throw new FieldsException( esc_html( implode( '.', $path ) ) . ': ' . ValueCodec::unknown( $name, array_keys( $children ) ) );
            }
            return ValueCodec::without_retired( $children[ $name ] ) + [
                'value'  => $this->fields->read_at( $path, true ),
                'exists' => $this->fields->stored( $path ),
            ];
        }, null );
    }

    public function choices( $path = null, ...$unknown ): array {
        return $this->reading( 'choices', [ $path ], function () use ( $path, $unknown ): array {
            if ( $unknown !== [] ) {
                throw new FieldsException( 'choices() takes only a field path, without extra arguments.' );
            }
            return $this->fields->choices_at( $this->path( [ $path ] ) );
        }, [] );
    }

    /** @param mixed $path */
    public function label( $path = null, ...$args ): ?string {
        return $this->reading( 'label', [ $path ], function () use ( $path, $args ) {
            $options = ReadResult::options( $args, [ 'value' => null ], $this->where( 'label', [ $path ] ) );
            // Only an omitted value reads the field.
            $choice = array_key_exists( 'value', $args ) ? ( $options['value'] ?? '' ) : null;
            $value = $this->fields->label_at( $this->path( [ $path ] ), $choice );
            $value = $value === '' ? null : $value;
            return $value;
        }, null );
    }

    /**
     * @param list<mixed> $segments
     * @return list<string>
     * @throws FieldsException
     */
    protected function path( array $segments ): array {
        $out = $this->prefix;
        foreach ( $segments as $segment ) {
            if ( ! is_string( $segment ) ) {
                throw new FieldsException( 'A path is field names and row ids, as strings; got ' . esc_html( self::shown( $segment ) ) . '.' );
            }
            foreach ( explode( '.', $segment ) as $part ) {
                if ( $part !== '' ) {
                    $out[] = $part;
                }
            }
        }
        return $out;
    }

    protected static function wrong_type( string $at, string $type, string $call ): FieldsException {
        $right = ( strpos( $call, 'get' ) === 0 ? 'get_' : 'set_' ) . ( self::SUFFIX[ $type ] ?? $type );
        if ( in_array( $call, self::ROW_CALLS, true ) ) {
            return new FieldsException( $at . ': a ' . $type . ' field, not a repeater — ' . $call . '() is for repeaters; use ' . $right . '().' );
        }
        if ( in_array( $call, self::ITEM_CALLS, true ) ) {
            return new FieldsException( $at . ': a ' . $type . ' field, not a relationship or a gallery — ' . $call . '() is for lists; use ' . $right . '().' );
        }
        return new FieldsException( $at . ': a ' . $type . ' field — use ' . $right . '().' );
    }

    /** @param list<array> $rows */
    protected static function index_of( array $rows, string $id, string $at ): int {
        foreach ( $rows as $i => $row ) {
            if ( ( $row['id'] ?? null ) === $id ) {
                return $i;
            }
        }
        $ids = array_column( $rows, 'id' );
        throw new FieldsException( esc_html( $at ) . ': no row "' . esc_html( $id ) . '" here. ' . ( $ids ? 'Its rows: ' . esc_html( implode( ', ', $ids ) ) . '.' : 'It has no rows.' ) . ' Take id() of a row from get_rows(), or _row from get().' );
    }

    /**
     * @template T
     * @param list<mixed>   $path
     * @param callable(): T $read
     * @param T             $fallback
     * @return T
     */
    private function reading( string $method, array $path, callable $read, $fallback ) {
        return Quietly::run( $this->where( $method, $path ), $read, $fallback, $this->loud() );
    }

    private function loud(): bool {
        return ! $this->fields->quiet;
    }

    /** @param list<mixed> $path */
    private function where( string $method, array $path ): string {
        $class = substr( (string) strrchr( static::class, '\\' ), 1 );
        $args = implode( ', ', array_map( static fn( $one ): string => is_string( $one ) ? '\'' . $one . '\'' : self::shown( $one ), $path ) );
        return $class . '::' . $method . '(' . ( $args === '' ? '' : ' ' . $args . ' ' ) . ')';
    }

    /**
     * @param list<mixed>  $args
     * @param list<string> $allowed
     * @param callable(mixed, array, list<string>): mixed $present
     * @return mixed
     */
    private function show( string $method, string $type, $name, array $args, array $allowed, callable $present ) {
        $positional = array_merge( [ $name ], array_values( array_filter( $args, 'is_int', ARRAY_FILTER_USE_KEY ) ) );
        return $this->reading( $method, $positional, function () use ( $method, $type, $name, $args, $allowed, $present ) {
            [ $path, $options ] = $this->call( $method, $name, $args, $allowed );
            $raw = $this->fields->read_at( $path, true, [ $type ], $method );
            $list   = in_array( $type, [ 'gallery', 'relationship', 'repeater' ], true );
            $object = in_array( $type, [ 'image', 'file', 'link', 'post_object' ], true );
            // An object reader gives an empty object for an empty field, as the factories do:
            // its methods answer empty. A value reader gives null.
            $value = $raw === null && ! $list && ! $object ? null : $present( $raw, $options, $path );
            if ( $value === '' || ( ! $list && $value === [] ) ) {
                $value = null;
            }
            return $value;
        }, self::failed( $type ) );
    }

    /**
     * What a reader gives when the call itself is wrong (logged): a list reader [], an object
     * reader its empty object, a value reader null.
     *
     * @param list<mixed>  $args
     * @param list<string> $allowed
     * @return mixed
     */
    private static function failed( string $type ) {
        if ( in_array( $type, [ 'gallery', 'relationship', 'repeater' ], true ) ) {
            return [];
        }
        return match ( $type ) {
            'image'       => Image::none(),
            'file'        => File::none(),
            'link'        => Link::none(),
            'post_object' => Post::none(),
            default       => null,
        };
    }

    /**
     * @param list<mixed>  $args
     * @param list<string> $allowed
     * @return array{0: list<string>, 1: array<string, mixed>}
     * @throws FieldsException
     */
    private function call( string $method, $name, array $args, array $allowed ): array {
        $segments = [ $name ];
        $options = [];
        foreach ( $args as $key => $arg ) {
            if ( is_int( $key ) ) {
                $segments[] = $arg;
                continue;
            }
            if ( ! in_array( $key, $allowed, true ) ) {
                $this->loud() && Quietly::log( $this->where( $method, [ $name ] ), 'unknown option "' . $key . '" — it takes ' . ( $allowed ? implode( ', ', $allowed ) : 'none' ) . '; left out.' );
                continue;
            }
            if ( $key === 'lang' ) {
                $arg = Version::lang( $arg );
            } elseif ( $key === 'status' ) {
                $arg = Version::statuses( $arg );
            } else {
                self::check( $key, $arg );
            }
            $options[ $key ] = $arg;
        }
        // A reader that shows something in a language takes the one its fields were asked in,
        // unless the call names its own.
        if ( ! isset( $options['lang'] ) && in_array( 'lang', $allowed, true ) && $this->fields->asked_language() !== '' ) {
            $options['lang'] = $this->fields->asked_language();
        }
        return [ $this->path( $segments ), $options ];
    }

    /**
     * @param mixed $value
     * @throws FieldsException
     */
    private static function check( string $option, $value ): void {
        switch ( $option ) {
            case 'limit':
                $ok = $value === null || ( is_int( $value ) && $value >= 1 );
                $expected = 'null or a whole number from 1';
                break;
            default:
                $ok = is_bool( $value );
                $expected = 'true or false';
        }
        if ( ! $ok ) {
            throw new FieldsException( esc_html( $option ) . ': expected ' . esc_html( $expected ) . '; got ' . esc_html( self::shown( $value ) ) . '.' );
        }
    }

    /** @param mixed $value */
    private static function shown( $value ): string {
        if ( is_string( $value ) ) {
            return '"' . ( strlen( $value ) > 40 ? substr( $value, 0, 40 ) . '…' : $value ) . '"';
        }
        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        return is_int( $value ) || is_float( $value ) ? (string) $value : get_debug_type( $value );
    }
}
