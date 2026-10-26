<?php

namespace Fabrement\Route;

use WP_REST_Request;

/**
 * One REST route: subclasses define path, HTTP methods, {@see permission_callback()}, and {@see handle()}.
 * Shared API namespace is {@see self::API_NAMESPACE} (override {@see get_namespace()} for a different namespace).
 */
abstract class AbstractRestRoute {

    public const API_NAMESPACE = 'fabrement/v1';

    public function get_namespace(): string {
        return self::API_NAMESPACE;
    }

    abstract public function get_route(): string;

    /**
     * Bitmask / method string per {@see \WP_REST_Server} constants.
     *
     * @return string|string[]
     */
    abstract public function get_methods();

    abstract public function permission_callback( WP_REST_Request $request ): bool;

    /**
     * @return \WP_REST_Response|\WP_Error|mixed
     */
    abstract public function handle( WP_REST_Request $request );

    /**
     * Optional {@see register_rest_route()} argument definitions (JSON Schema, sanitization, validation).
     * JSON request bodies map these keys to {@see WP_REST_Request::get_param()}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_route_args(): array {
        return [];
    }

    /** All Lite routes use their own WordPress capability checks. */
    public function check_access( WP_REST_Request $request ) {
        return $this->permission_callback( $request );
    }

    public function register(): void {
        register_rest_route(
            $this->get_namespace(),
            $this->get_route(),
            [
                'methods'             => $this->get_methods(),
                'callback'            => [ $this, 'handle' ],
                'permission_callback' => [ $this, 'check_access' ],
                'args'                => $this->get_route_args(),
            ]
        );
    }

}
