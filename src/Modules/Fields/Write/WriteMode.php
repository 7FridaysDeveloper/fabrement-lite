<?php

namespace Fabrement\Modules\Fields\Write;

use Fabrement\Modules\Fields\Types\WordsType;
use Fabrement\Modules\Fields\Values\FieldObject;
use WP_Error;

/** Execution policy is chosen by server code, never by a request parameter. */
final class WriteMode {
    private string $entry;

    private function __construct( string $entry ) { $this->entry = $entry; }

    public static function code(): self { return new self( 'code' ); }
    public static function editor(): self { return new self( 'editor' ); }
    public static function agent(): self { return new self( 'agent' ); }
    public function is_agent(): bool { return $this->entry === 'agent'; }
    public function is_editor(): bool { return $this->entry === 'editor'; }
    public function is_code(): bool { return $this->entry === 'code'; }

    public function run( callable $write ) {
        return WordsType::with_trust( $this->entry === 'code', $write );
    }

    public function authorize( FieldObject $object ): ?WP_Error {
        if ( $this->entry === 'code' || $object->can_edit() ) {
            return null;
        }
        return new WP_Error( 'fields_forbidden', 'You cannot edit the destination of these fields.', [ 'status' => 403,
            'destination' => [ 'type' => $object->type, 'id' => $object->id ] ] );
    }
}
