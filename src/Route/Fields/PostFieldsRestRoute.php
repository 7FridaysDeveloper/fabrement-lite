<?php

namespace Fabrement\Route\Fields;

use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\FieldRead;
use Fabrement\Modules\Fields\Write\FieldWrite;
use Fabrement\Modules\Fields\Write\WriteMode;
use Fabrement\Modules\Fields\Write\WriteRequest;
use Fabrement\Modules\Fields\Write\ValueWriter;
use Fabrement\Route\AbstractRestRoute;
use WP_Error;
use WP_REST_Request;

/** The editor's HTTP adapter; the injected writer owns validation, routing and persistence. */
class PostFieldsRestRoute extends AbstractRestRoute {
    private FieldWrite $writer;

    public function __construct( FieldWrite $writer ) {
        $this->writer = $writer;
    }

    public function get_route(): string {
        return '/fields/(?P<type>post)/(?P<id>\d+)';
    }

    public function get_methods(): string {
        return 'GET, PUT';
    }

    public function get_route_args(): array {
        return [
            'type' => [ 'type' => 'string', 'enum' => [ 'post' ], 'required' => true ],
            'id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
            'lang' => [ 'type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{0,20}$', 'default' => '' ],
        ];
    }

    /** Keep method-specific input validation and the existing permission error responses. */
    public function register(): void {
        register_rest_route( $this->get_namespace(), $this->get_route(), [
            'args' => $this->get_route_args(),
            [ 'methods' => 'GET', 'permission_callback' => [ $this, 'check_access' ], 'callback' => [ $this, 'handle' ] ],
            [
                'methods' => 'PUT', 'permission_callback' => [ $this, 'check_access' ], 'callback' => [ $this, 'handle' ],
                'args' => [
                    'values' => [ 'type' => 'object', 'default' => [] ],
                    'delete' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'default' => [] ],
                    'intent' => [ 'type' => 'object' ],
                    'intentVersion' => [ 'type' => 'integer', 'enum' => [ 1 ] ],
                ],
            ],
        ] );
    }

    public function permission_callback( WP_REST_Request $request ): bool {
        $object = $this->object( $request );
        return $object && $object->can_edit();
    }

    public function check_access( WP_REST_Request $request ) {
        return $this->permission_callback( $request ) ? true : new WP_Error( 'fields_forbidden',
            isset( $request['slug'] ) ? 'You cannot edit these options.' : 'You cannot edit this record.',
            [ 'status' => rest_authorization_required_code() ] );
    }

    protected function object( WP_REST_Request $request ): ?FieldObject {
        return FieldObject::post( (int) $request['id'] );
    }

    public function handle( WP_REST_Request $request ) {
        $object = $this->object( $request );
        if ( $request->get_method() === 'GET' ) {
            return FieldRead::for_editor( $object, (string) $request['lang'] );
        }
        if ( ! isset( $request['intent'] ) || (int) $request['intentVersion'] !== 1 ) {
            return new WP_Error( 'fields_invalid', 'intent: Send the changed fields with intent and intentVersion 1.', [ 'status' => 400 ] );
        }
        $saved = $this->save_values( $object, (array) $request['values'], (array) $request['delete'], (array) $request['intent'] );
        return is_wp_error( $saved ) ? $saved : FieldRead::for_editor( $object, (string) $request['lang'] ) + [ 'routed' => $saved['routed'] ];
    }

    public function save_values( FieldObject $object, array $values, array $deletes, array $intent ) {
        try {
            return $this->writer->save( $object, WriteRequest::from_editor( $object, $values, $deletes, $intent ), WriteMode::editor() );
        } catch ( \Exception $e ) {
            return ValueWriter::failure( $e, WriteMode::editor() );
        }
    }
}
