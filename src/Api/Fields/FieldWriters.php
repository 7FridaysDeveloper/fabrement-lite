<?php

namespace Fabrement\Api\Fields;

use Fabrement\Api\FieldsException;
use Fabrement\Modules\Fields\Write\WriteRequest;
use Fabrement\Modules\Fields\Types\Records;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueSelection;

defined( 'ABSPATH' ) || exit;

/**
 * The writers of a place: set_<type>( path…, value ), set(), clear(), repeater rows and list items
 * one at a time. Each is checked at once and throws FieldsException; the change joins the record's
 * until its save().
 */
trait FieldWriters {

    /**
     * @param mixed $value
     * @throws FieldsException
     */
    public function set( string $path, $value ): static {
        $this->fields->change_at( $this->path( [ $path ] ), [], 'set', static fn( array $field, $current, string $at ) => self::from_public( $field, $value, $current, $at ), WriteRequest::raw_clears( $value ), static fn( array $field, $next ) => ValueSelection::from_public( $field, $value, $next ) );
        return $this;
    }

    /** @throws FieldsException */
    public function clear( string $path ): static {
        $this->fields->clear_at( $this->path( [ $path ] ) );
        return $this;
    }

    /** @param mixed ...$args */
    public function set_text( string $name, ...$args ): static {
        return $this->write( 'set_text', 'text', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_textarea( string $name, ...$args ): static {
        return $this->write( 'set_textarea', 'textarea', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_wysiwyg( string $name, ...$args ): static {
        return $this->write( 'set_wysiwyg', 'wysiwyg', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_number( string $name, ...$args ): static {
        return $this->write( 'set_number', 'number', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_true_false( string $name, ...$args ): static {
        return $this->write( 'set_true_false', 'true_false', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_select( string $name, ...$args ): static {
        return $this->write( 'set_select', 'select', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_date( string $name, ...$args ): static {
        return $this->write( 'set_date', 'date_picker', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_date_time( string $name, ...$args ): static {
        return $this->write( 'set_date_time', 'date_time_picker', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_image( string $name, ...$args ): static {
        return $this->write( 'set_image', 'image', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_gallery( string $name, ...$args ): static {
        return $this->write( 'set_gallery', 'gallery', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_file( string $name, ...$args ): static {
        return $this->write( 'set_file', 'file', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_link( string $name, ...$args ): static {
        return $this->write( 'set_link', 'link', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_post_object( string $name, ...$args ): static {
        return $this->write( 'set_post_object', 'post_object', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_relationship( string $name, ...$args ): static {
        return $this->write( 'set_relationship', 'relationship', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_group( string $name, ...$args ): static {
        return $this->write( 'set_group', 'group', $name, $args );
    }

    /** @param mixed ...$args */
    public function set_rows( string $name, ...$args ): static {
        return $this->write( 'set_rows', 'repeater', $name, $args );
    }

    /**
     * @param array<string, mixed> $values
     * @throws FieldsException
     */
    public function add_row( string $path, array $values, ?string $after = null, ?string $before = null ): string {
        $full = $this->path( [ $path ] );
        $at = implode( '.', $full );
        if ( $values !== [] && array_values( $values ) === $values ) {
            throw new FieldsException( esc_html( $at ) . ': add_row() takes one row, keyed by sub-field names; call it once per row.' );
        }
        if ( array_key_exists( ValueCodec::ROW_KEY, $values ) ) {
            throw new FieldsException( esc_html( $at ) . ': a new row has no _row yet; to change row ' . esc_html( self::shown( $values[ ValueCodec::ROW_KEY ] ) ) . ' use update_row().' );
        }
        $id = '';
        $this->fields->change_at( $full, [ 'repeater' ], 'add_row', static function ( array $repeater, $rows, string $at ) use ( $values, $after, $before, &$id ): array {
            $rows = self::rows( $rows );
            $row = self::from_public( $repeater, [ $values ], [], $at )[0];
            $id = $row['id'];
            $index = $after === null && $before === null ? count( $rows ) : self::place_row( $rows, $after, $before, $at );
            array_splice( $rows, $index, 0, [ $row ] );
            return $rows;
        }, [], static function () use ( &$id ): array { return [ $id => true ]; } );
        $this->fields->remember_clears( array_merge( $full, [ $id ] ), WriteRequest::raw_clears( $values ) );
        return $id;
    }

    /**
     * @param array<string, mixed> $values
     * @throws FieldsException
     */
    public function update_row( string $path, string $row, array $values ): static {
        $full = $this->path( [ $path ] );
        if ( array_key_exists( ValueCodec::ROW_KEY, $values ) && $values[ ValueCodec::ROW_KEY ] !== $row ) {
            throw new FieldsException( esc_html( implode( '.', $full ) ) . ': update_row( \'' . esc_html( $row ) . '\' ) was given the _row of another row.' );
        }
        $this->fields->change_at( $full, [ 'repeater' ], 'update_row', static function ( array $repeater, $rows, string $at ) use ( $row, $values ): array {
            $rows = self::rows( $rows );
            $index = self::index_of( $rows, $row, $at );
            $rows[ $index ] = self::from_public( $repeater, [ [ ValueCodec::ROW_KEY => $row ] + $values ], [ $rows[ $index ] ], $at )[0];
            return $rows;
        }, [], static function ( array $repeater, array $rows ) use ( $row, $values ): array {
            return ValueSelection::from_public( $repeater, [ [ ValueCodec::ROW_KEY => $row ] + $values ], [ $rows[ self::index_of( $rows, $row, $repeater['name'] ) ] ] );
        } );
        $this->fields->remember_clears( array_merge( $full, [ $row ] ), WriteRequest::raw_clears( $values ) );
        return $this;
    }

    /** @throws FieldsException */
    public function move_row( string $path, string $row, ?string $after = null, ?string $before = null ): static {
        $full = $this->path( [ $path ] );
        $at = implode( '.', $full );
        if ( ( $after === null ) === ( $before === null ) ) {
            throw new FieldsException( esc_html( $at ) . ': move_row() needs where to: after: or before: another row, not both.' );
        }
        if ( $row === $after || $row === $before ) {
            throw new FieldsException( esc_html( $at ) . ': a row cannot be moved next to itself.' );
        }
        $this->fields->change_at( $full, [ 'repeater' ], 'move_row', static function ( array $repeater, $rows, string $at ) use ( $row, $after, $before ): array {
            $rows = self::rows( $rows );
            $index = self::index_of( $rows, $row, $at );
            $moved = $rows[ $index ];
            array_splice( $rows, $index, 1 );
            array_splice( $rows, self::place_row( $rows, $after, $before, $at ), 0, [ $moved ] );
            return $rows;
        }, [], static fn(): array => [] );
        return $this;
    }

    /** @throws FieldsException */
    public function delete_row( string $path, string $row ): static {
        $this->fields->change_at( $this->path( [ $path ] ), [ 'repeater' ], 'delete_row', static function ( array $repeater, $rows ) use ( $row ): array {
            return array_values( array_filter( self::rows( $rows ), static fn( array $r ): bool => ( $r['id'] ?? null ) !== $row ) );
        }, [], static fn(): array => [] );
        return $this;
    }

    /** @throws FieldsException */
    public function add_item( string $path, int $id, ?int $after = null, ?int $before = null ): static {
        $this->fields->change_at( $this->path( [ $path ] ), [ 'relationship', 'gallery' ], 'add_item', static function ( array $field, $items, string $at ) use ( $id, $after, $before ): array {
            $items = self::items( $items );
            $id = Records::canonical( $id );
            if ( in_array( $id, $items, true ) ) {
                return $items;
            }
            $index = $after === null && $before === null ? count( $items ) : self::place_item( $items, $after, $before, $at );
            array_splice( $items, $index, 0, [ $id ] );
            return $items;
        } );
        return $this;
    }

    /** @throws FieldsException */
    public function move_item( string $path, int $id, ?int $after = null, ?int $before = null ): static {
        $full = $this->path( [ $path ] );
        if ( ( $after === null ) === ( $before === null ) ) {
            throw new FieldsException( esc_html( implode( '.', $full ) ) . ': move_item() needs where to: after: or before: another item, not both.' );
        }
        $this->fields->change_at( $full, [ 'relationship', 'gallery' ], 'move_item', static function ( array $field, $items, string $at ) use ( $id, $after, $before ): array {
            $items = self::items( $items );
            $index = self::once( $items, Records::canonical( $id ), $at );
            if ( Records::canonical( (int) ( $after ?? $before ) ) === $items[ $index ] ) {
                throw new FieldsException( esc_html( $at ) . ': an item cannot be moved next to itself.' );
            }
            $moved = $items[ $index ];
            array_splice( $items, $index, 1 );
            array_splice( $items, self::place_item( $items, $after, $before, $at ), 0, [ $moved ] );
            return $items;
        } );
        return $this;
    }

    /** @throws FieldsException */
    public function delete_item( string $path, int $id ): static {
        $this->fields->change_at( $this->path( [ $path ] ), [ 'relationship', 'gallery' ], 'delete_item', static function ( array $field, $items ) use ( $id ): array {
            $id = Records::canonical( $id );
            return array_values( array_filter( self::items( $items ), static fn( int $item ): bool => $item !== $id ) );
        } );
        return $this;
    }

    /**
     * @param list<mixed> $args
     * @throws FieldsException
     */
    private function write( string $method, string $type, string $name, array $args ): static {
        $segments = [ $name ];
        foreach ( $args as $key => $arg ) {
            if ( ! is_int( $key ) ) {
                throw new FieldsException( esc_html( $method ) . '(): unknown option "' . esc_html( $key ) . '"; it takes the path, then the value.' );
            }
            $segments[] = $arg;
        }
        if ( count( $segments ) < 2 ) {
            throw new FieldsException( esc_html( $method ) . '( \'' . esc_html( $name ) . '\' ): no value — give the path, then the value: ' . esc_html( $method ) . '( \'' . esc_html( $name ) . '\', $value ).' );
        }
        $value = array_pop( $segments );
        $this->fields->change_at( $this->path( $segments ), [ $type ], $method, static fn( array $field, $current, string $at ) => self::from_public( $field, $value, $current, $at ), WriteRequest::raw_clears( $value ), static fn( array $field, $next ) => ValueSelection::from_public( $field, $value, $next ) );
        return $this;
    }

    /**
     * @param mixed $value
     * @param mixed $current
     * @return mixed
     */
    private static function from_public( array $field, $value, $current, string $at ) {
        return FieldsException::wrap( static fn() => ValueCodec::from_public( $field, $value, $current, $at ) );
    }

    /**
     * @param mixed $value
     * @return list<array>
     */
    private static function rows( $value ): array {
        return is_array( $value ) ? array_values( $value ) : [];
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private static function items( $value ): array {
        return array_values( array_map( 'intval', is_array( $value ) ? $value : [] ) );
    }

    /** @param list<array> $rows */
    private static function place_row( array $rows, ?string $after, ?string $before, string $at ): int {
        if ( $after !== null && $before !== null ) {
            throw new FieldsException( esc_html( $at ) . ': give after: or before:, not both.' );
        }
        return $after !== null ? self::index_of( $rows, $after, $at ) + 1 : self::index_of( $rows, (string) $before, $at );
    }

    /** @param list<int> $items */
    private static function once( array $items, int $id, string $at ): int {
        $found = array_keys( $items, $id, true );
        if ( ! $found ) {
            throw new FieldsException( esc_html( $at ) . ': ' . esc_html( $id ) . ' is not in the list.' );
        }
        if ( count( $found ) > 1 ) {
            throw new FieldsException( esc_html( $at ) . ': ' . esc_html( $id ) . ' is in the list ' . count( $found ) . ' times — order a list with repeats with set().' );
        }
        return $found[0];
    }

    /** @param list<int> $items */
    private static function place_item( array $items, ?int $after, ?int $before, string $at ): int {
        if ( $after !== null && $before !== null ) {
            throw new FieldsException( esc_html( $at ) . ': give after: or before:, not both.' );
        }
        return $after !== null ? self::once( $items, Records::canonical( $after ), $at ) + 1 : self::once( $items, Records::canonical( (int) $before ), $at );
    }
}
