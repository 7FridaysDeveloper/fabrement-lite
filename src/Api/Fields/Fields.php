<?php

namespace Fabrement\Api\Fields;

use Fabrement\Api\FieldsException;
use Fabrement\Modules\Fields\Types\WordsType;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Types\Choices;
use Fabrement\Modules\Fields\Write\FieldWrite;
use Fabrement\Modules\Fields\Write\WriteMode;
use Fabrement\Modules\Fields\Write\WriteRequest;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueSelection;
use Fabrement\Modules\Fields\Schema\StorageLayout;

defined( 'ABSPATH' ) || exit;

/**
 * The fields of one post, or of one language of an options page: fabrement()->fields( $id ),
 * fabrement()->options( 'site' ). It holds the record's changes not saved
 * yet — its own and those of its rows and groups — reads them as they will be stored, and save()
 * sends them through the shared FieldWrite pipeline. Each object's storage is atomic. The site's
 * own code writes like update_post_meta(): no user's rights, words as written, no save_post.
 */
final class Fields extends Place {

    protected ?FieldObject $object;

    protected string $missing = '';

    protected bool $quiet = false;

    /** @var array<string, mixed> */
    protected array $work = [];

    protected array $selection = [];

    /** @var array<string, list<list<string|int>>> Explicit paths must own empty values in translations. */
    protected array $clear_intent = [];

    /** @var array<string, array{0: mixed, 1: bool}> */
    protected array $loaded = [];

    /** @var array<string, mixed> Stored values already decoded, by field key, until save(). */
    protected array $decoded = [];

    /** The language these fields were asked in (lang:), '' for the page's — what their readers show records, media and links in. */
    protected string $asked = '';

    /** @internal */
    public function __construct( ?FieldObject $object ) {
        parent::__construct( $this, [] );
        $this->object = $object;
    }

    /**
     * @internal Fields reached with a language — options( lang: ), a Post read with lang: — keep it,
     * as the Post, the Term and the Attachment do: what a field points at is shown in it too.
     */
    public function in_language( string $lang ): self {
        $this->asked = $lang;
        return $this;
    }

    /** @internal */
    public function asked_language(): string {
        return $this->asked;
    }

    /** @internal */
    public static function missing( string $why, bool $quiet = false ): self {
        $fields = new self( null );
        $fields->missing = $why;
        $fields->quiet = $quiet;
        return $fields;
    }

    /** @throws FieldsException */
    public function save(): bool {
        $this->assert_record();
        $paths = [];
        foreach ( array_keys( $this->work ) as $key ) {
            $paths[ $key ] = $this->object->all_fields()[ $key ]['name'] ?? $key;
        }
        $clear_intent = [];
        foreach ( $this->clear_intent as $key => $clears ) {
            $field = $this->object->all_fields()[ $key ];
            foreach ( $clears as $clear ) {
                foreach ( WriteRequest::effective_clears( $field, $this->current( $field ), $clear ) as $effective ) {
                    $clear_intent[ $key ][] = $effective;
                }
            }
        }
        $request = new WriteRequest( $this->work, $paths, $clear_intent, $this->selection );
        $saved = FieldWrite::standard()->save( $this->object, $request, WriteMode::code() );
        FieldsException::check( $saved );
        $this->work = [];
        $this->selection = [];
        $this->clear_intent = [];
        $this->loaded = [];
        $this->decoded = [];
        return $saved['changed'];
    }

    /**
     * @param list<string> $path
     * @throws FieldsException
     */
    protected function type_at( array $path ): string {
        return (string) $this->schema_at( $path )[0]['type'];
    }

    /**
     * A number field's format: integer | decimal.
     *
     * @param list<string> $path
     * @throws FieldsException
     */
    protected function number_format_at( array $path ): string {
        return (string) ( $this->schema_at( $path )[0]['format'] ?? 'decimal' );
    }

    /**
     * @param list<string> $path
     * @param list<string> $types
     * @return mixed
     * @throws FieldsException
     */
    protected function read_at( array $path, bool $row_ids, array $types = [], string $call = 'get' ) {
        return $this->value_at( $this->top( (string) ( $path[0] ?? '' ) ), array_slice( $path, 1 ), $row_ids, $types, $call );
    }

    /**
     * @param list<string>                          $path
     * @param list<string>                          $types
     * @param callable(array, mixed, string): mixed $change
     * @throws FieldsException
     */
    protected function change_at( array $path, array $types, string $call, callable $change, array $clear_paths = [], ?callable $select = null ): void {
        $top = $this->top( (string) ( $path[0] ?? '' ) );
        $selection = true;
        $apply = static function ( array $field, $value, string $at ) use ( $change, $select, &$selection ) {
            $next = $change( $field, $value, $at );
            $selection = $select ? $select( $field, $next ) : true;
            return $next;
        };
        $this->put( $top, $this->apply_at( $top, $this->current( $top ), array_slice( $path, 1 ), $apply, $top['name'], $types, $call ) );
        $selection = $this->selection_at( $top, array_slice( $path, 1 ), $selection );
        // Cancel intent when the operation succeeds, not from the final value at save():
        // clear -> set -> empty must not revive the first clear. Omitted siblings keep theirs.
        $this->clear_intent[ $top['key'] ] = array_values( array_filter(
            $this->clear_intent[ $top['key'] ] ?? [],
            fn( array $clear ): bool => ! WriteRequest::selects_path( $top, $selection, $clear )
                && WriteRequest::effective_clears( $top, $this->current( $top ), $clear ) !== []
        ) );
        $this->selection[ $top['key'] ] = ValueSelection::merge( $this->selection[ $top['key'] ] ?? [], $selection );
        $this->remember_clears( $path, $clear_paths );
    }

    private function selection_at( array $field, array $segments, $selection ) {
        if ( $segments === [] ) {
            return $selection;
        }
        if ( $field['type'] === 'repeater' ) {
            $id = array_shift( $segments );
            $child = $this->child( $field, array_shift( $segments ), $field['name'] );
            return [ $id => [ $child['key'] => $this->selection_at( $child, $segments, $selection ) ] ];
        }
        $child = $this->child( $field, array_shift( $segments ), $field['name'] );
        return [ $child['key'] => $this->selection_at( $child, $segments, $selection ) ];
    }

    /** @param list<list<string|int>> $relative Paths inside the changed field, before normalization. */
    protected function remember_clears( array $path, array $relative ): void {
        if ( $relative === [] ) {
            return;
        }
        $top = $this->top( $path[0] );
        foreach ( $relative as $clear ) {
            foreach ( WriteRequest::effective_clears( $top, $this->current( $top ), array_merge( array_slice( $path, 1 ), $clear ) ) as $effective ) {
                $this->clear_intent[ $top['key'] ][] = $effective;
            }
        }
    }

    /**
     * @param list<string> $path
     * @throws FieldsException
     */
    protected function clear_at( array $path ): void {
        $this->change_at( $path, [], 'clear', static fn( array $field ) => ValueCodec::editor_value( $field, ValueCodec::blank_value( $field ) ), [ [] ] );
    }

    /**
     * @param list<string> $path
     * @param mixed        $value
     * @throws FieldsException
     */
    protected function label_at( array $path, $value ): string {
        [ $field, $at, $names, $through_rows, $at_row ] = $this->schema_at( $path );
        if ( $at_row ) {
            throw new FieldsException( esc_html( $at ) . ': a row is not a field; name the field inside it.' );
        }
        if ( empty( $field['choices'] ) ) {
            throw new FieldsException( esc_html( $at ) . ': ' . esc_html( $field['label'] ) . ' has no choices to label; read it with get().' );
        }
        if ( $value === null ) {
            if ( $through_rows ) {
                throw new FieldsException( esc_html( $at ) . ': a field of a repeater row has one value per row; pass it: label( \'' . esc_html( implode( '.', $names ) ) . '\', value: $row[\'' . esc_html( end( $names ) ) . '\'] ), or ask the row: $row->label( \'' . esc_html( end( $names ) ) . '\' ).' );
            }
            $value = $this->value_at( $this->top( (string) $path[0] ), array_slice( $path, 1 ), false );
        }
        return Choices::label( $field, $value ) ?? '';
    }

    protected function choices_at( array $path ): array {
        [ $field, $at, , , $at_row ] = $this->schema_at( $path );
        if ( $at_row ) {
            throw new FieldsException( esc_html( $at ) . ': a row is not a field; name the field inside it.' );
        }
        if ( empty( $field['choices'] ) ) {
            throw new FieldsException( esc_html( $at ) . ': ' . esc_html( $field['label'] ) . ' has no choices; choices() is for fields with predefined choices.' );
        }
        $choices = [];
        foreach ( $field['choices'] as $choice ) {
            $choices[] = [
                'value' => $choice['value'],
                'label' => Choices::label( $field, $choice['value'] ) ?? '',
            ];
        }
        return $choices;
    }

    /**
     * @param list<string> $prefix
     * @return array<string, array>
     * @throws FieldsException
     */
    protected function children_at( array $prefix ): array {
        $this->assert_record();
        if ( ! $prefix ) {
            return $this->active();
        }
        return array_column( ValueCodec::active( $this->schema_at( $prefix )[0]['sub_fields'] ?? [] ), null, 'name' );
    }

    /** @throws FieldsException */
    protected function stored( array $path ): bool {
        $field = $this->top( $path[0] );
        if ( array_key_exists( $field['key'], $this->work ) ) {
            return true;
        }
        if ( count( $path ) === 1 ) {
            return $this->load( $field )[1];
        }
        $read = $this->object->editor_values( [ $field['key'] => $field ] );
        $value = ValueCodec::editor_value( $field, $read['values'][ $field['key'] ] );
        $schema = $field;
        $storage = $field['key'];
        $row = null;
        foreach ( array_slice( $path, 1 ) as $segment ) {
            if ( $schema['type'] === 'repeater' && $row === null ) {
                $row = array_search( $segment, array_column( (array) $value, 'id' ), true );
                if ( $row === false ) {
                    return false;
                }
                $value = $value[ $row ]['values'];
                continue;
            }
            $children = array_column( ValueCodec::active( $schema['sub_fields'] ?? [] ), null, 'name' );
            if ( ! isset( $children[ $segment ] ) ) {
                return false;
            }
            $schema = $children[ $segment ];
            $storage = $row === null ? StorageLayout::child( $storage, $schema['key'] ) : StorageLayout::row_child( $storage, (int) $row, $schema['key'] );
            $value = is_array( $value ) ? ( $value[ $schema['key'] ] ?? null ) : null;
            $row = null;
        }
        return isset( $read['present'][ $storage ] );
    }

    private function assert_record(): void {
        if ( ! $this->object ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a plain-text message for the calling code and the debug log; never printed as HTML.
            throw new FieldsException( $this->missing );
        }
    }

    /** @return array<string, array> */
    private function active(): array {
        return $this->object ? array_column( ValueCodec::active( $this->object->all_fields() ), null, 'name' ) : [];
    }

    private function top( string $name ): array {
        $this->assert_record();
        $active = $this->active();
        if ( ! isset( $active[ $name ] ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ValueCodec::unknown() escapes the names it quotes.
            throw new FieldsException( esc_html( $name ) . ': ' . ValueCodec::unknown( $name, array_keys( $active ) ) );
        }
        return $active[ $name ];
    }

    private function child( array $field, string $name, string $at ): array {
        $children = array_column( ValueCodec::active( $field['sub_fields'] ?? [] ), null, 'name' );
        if ( ! isset( $children[ $name ] ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ValueCodec::unknown() escapes the names it quotes.
            throw new FieldsException( esc_html( $at ) . '.' . esc_html( $name ) . ': ' . ValueCodec::unknown( $name, array_keys( $children ) ) );
        }
        return $children[ $name ];
    }

    /**
     * @param list<string> $path
     * @return array{0: array, 1: string, 2: list<string>, 3: bool, 4: bool}
     * @throws FieldsException
     */
    private function schema_at( array $path ): array {
        $field = $this->top( (string) ( $path[0] ?? '' ) );
        $at = $field['name'];
        $names = [ $field['name'] ];
        $through_rows = false;
        $at_row = false;
        $rest = array_slice( $path, 1 );
        for ( $i = 0, $n = count( $rest ); $i < $n; $i++ ) {
            if ( ! in_array( $field['type'], [ 'group', 'repeater' ], true ) ) {
                throw new FieldsException( esc_html( $at ) . ': not a group or a repeater; it has no sub-fields.' );
            }
            if ( $field['type'] === 'repeater' ) {
                if ( strpos( $rest[ $i ], 'row_' ) === 0 ) {
                    $at .= '.' . $rest[ $i ];
                    $i++;
                    if ( $i >= $n ) {
                        $at_row = true;
                        break;
                    }
                } else {
                    $through_rows = true;
                }
            }
            $field = $this->child( $field, $rest[ $i ], $at );
            $at .= '.' . $field['name'];
            $names[] = $field['name'];
        }
        return [ $field, $at, $names, $through_rows, $at_row ];
    }

    /** @return array{0: mixed, 1: bool} */
    private function load( array $field ): array {
        $key = $field['key'];
        if ( ! isset( $this->loaded[ $key ] ) ) {
            $read = $this->object->editor_values( [ $key => $field ] );
            $this->loaded[ $key ] = [ ValueCodec::editor_value( $field, $read['values'][ $key ] ), (bool) $read['exists'][ $key ] ];
        }
        return $this->loaded[ $key ];
    }

    /** @return mixed */
    private function current( array $field ) {
        $key = $field['key'];
        if ( array_key_exists( $key, $this->work ) ) {
            return $this->work[ $key ];
        }
        $read = $this->object->write_values( [ $key => $field ] );
        return ValueCodec::editor_value( $field, $read['values'][ $key ] );
    }

    /**
     * @param list<string> $segments
     * @param list<string> $types
     * @return mixed
     */
    private function value_at( array $top, array $segments, bool $row_ids, array $types = [], string $call = 'get' ) {
        $key = $top['key'];
        if ( array_key_exists( $key, $this->work ) ) {
            $editor = $this->current( $top );
        } else {
            if ( ! array_key_exists( $key, $this->decoded ) ) {
                $this->decoded[ $key ] = $this->object->values( [ $key => $top ] )['values'][ $key ];
            }
            $editor = $this->decoded[ $key ];
        }
        $found = null;
        $this->apply_at( $top, $editor, $segments, static function ( array $field, $value ) use ( &$found ) {
            $found = [ $field, $value ];
            return $value;
        }, $top['name'], $types, $call );
        return ValueCodec::api_value( $found[0], ValueCodec::public_value( $found[0], $found[1], $row_ids ) );
    }

    /** @param mixed $editor */
    private function put( array $field, $editor ): void {
        $read = $this->object->write_values( [ $field['key'] => $field ] );
        $before = $read['exists'][ $field['key'] ] ? [ ValueCodec::editor_value( $field, $read['values'][ $field['key'] ] ) ] : null;
        $value = FieldsException::wrap( static fn() => WordsType::as_site_code( static fn() => ValueCodec::normalize( $field, $editor, $field['name'], $before ) ) );
        $this->work[ $field['key'] ] = $value;
    }

    /**
     * @param mixed        $value
     * @param list<string> $segments
     * @param list<string> $types
     * @return mixed
     */
    private function apply_at( array $field, $value, array $segments, callable $change, string $at, array $types, string $call ) {
        if ( ! $segments ) {
            if ( $types && ! in_array( $field['type'], $types, true ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception object built elsewhere is thrown on; nothing is printed here.
                throw self::wrong_type( $at, $field['type'], $call );
            }
            return $change( $field, $value, $at );
        }
        if ( $field['type'] === 'group' ) {
            $child = $this->child( $field, (string) array_shift( $segments ), $at );
            $value = is_array( $value ) ? $value : [];
            $value[ $child['key'] ] = $this->apply_at( $child, array_key_exists( $child['key'], $value ) ? $value[ $child['key'] ] : ValueCodec::blank_value( $child ), $segments, $change, $at . '.' . $child['name'], $types, $call );
            return $value;
        }
        if ( $field['type'] !== 'repeater' ) {
            throw new FieldsException( esc_html( $at ) . ': not a group or a repeater; it has no sub-fields.' );
        }
        $row_id = (string) array_shift( $segments );
        if ( strpos( $row_id, 'row_' ) !== 0 ) {
            throw new FieldsException( esc_html( $at ) . ': a repeater has one value per row — name the row: ( \'' . esc_html( $at ) . '\', $row_id, \'' . esc_html( $row_id ) . '\' ), with id() of a row from get_rows() or _row from get().' );
        }
        $rows = is_array( $value ) ? array_values( $value ) : [];
        $index = self::index_of( $rows, $row_id, $at );
        if ( ! $segments ) {
            throw new FieldsException( esc_html( $at ) . '.' . esc_html( $row_id ) . ': a row is not a field; name the field inside it: ( …, \'' . esc_html( $row_id ) . '\', \'<sub-field>\' ).' );
        }
        $child = $this->child( $field, (string) array_shift( $segments ), $at . '.' . $row_id );
        $rows[ $index ]['values'][ $child['key'] ] = $this->apply_at( $child, array_key_exists( $child['key'], $rows[ $index ]['values'] ) ? $rows[ $index ]['values'][ $child['key'] ] : ValueCodec::blank_value( $child ), $segments, $change, $at . '.' . $row_id . '.' . $child['name'], $types, $call );
        return $rows;
    }

}
