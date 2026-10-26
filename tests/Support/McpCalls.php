<?php

namespace Fabrement\Tests;

use WP_REST_Request;

/**
 * Calls the site's MCP server the way Claude does — JSON-RPC to /fabrement/v1/mcp, a session opened
 * with `initialize`, tools run with `tools/call` — through rest_do_request(): the MCP server, the
 * tools' input schemas, their permission checks and the answer's shape are the real ones. Left out:
 * the network, and how the caller signs in — the caller is the current WordPress user, not a checked
 * application password or OAuth token (McpOAuthFlowTest covers those).
 */
trait McpCalls {

    private ?string $mcp_session = null;

    /**
     * Run one tool, named without the server's prefix ('set-field-values'). On success the tool's answer (decoded from the JSON the agent reads); on a
     * refusal the test fails with the message the agent would have seen, unless $expect_error.
     *
     * @return mixed
     */
    protected function mcp( string $tool, array $args = [], bool $expect_error = false ) {
        $answer = $this->mcp_rpc( 'tools/call', [ 'name' => 'fabrement-' . $tool, 'arguments' => (object) $args ] );
        $this->assertArrayHasKey( 'result', $answer, $tool . ': ' . wp_json_encode( $answer ) );
        $result = $answer['result'];
        $text   = '';
        foreach ( (array) ( $result['content'] ?? [] ) as $part ) {
            $text .= (string) ( $part['text'] ?? '' );
        }
        $failed = ! empty( $result['isError'] );
        if ( $failed !== $expect_error ) {
            $this->fail( $tool . ( $failed ? ' refused: ' : ' did not refuse: ' ) . $text );
        }
        if ( $failed ) {
            return $text;
        }
        if ( array_key_exists( 'structuredContent', $result ) ) {
            return $result['structuredContent'];
        }
        $decoded = json_decode( $text, true );
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
    }

    /** What the agent sees when a tool refuses: its message. */
    protected function mcp_error( string $tool, array $args = [] ): string {
        return (string) $this->mcp( $tool, $args, true );
    }

    /** One JSON-RPC call; opens the session first. @return array The decoded answer. */
    protected function mcp_rpc( string $method, array $params ): array {
        if ( $this->mcp_session === null && $method !== 'initialize' ) {
            $this->mcp_session = '';
            $this->mcp_rpc( 'initialize', [
                'protocolVersion' => '2025-06-18',
                'capabilities'    => (object) [],
                'clientInfo'      => [ 'name' => 'fabrement-tests', 'version' => '1.0' ],
            ] );
            $this->mcp_rpc( 'notifications/initialized', [] );
        }
        $request = new WP_REST_Request( 'POST', '/fabrement/v1/mcp' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_header( 'accept', 'application/json, text/event-stream' );
        if ( $this->mcp_session ) {
            $request->set_header( 'mcp-session-id', $this->mcp_session );
        }
        $call = [ 'jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params ];
        if ( strpos( $method, 'notifications/' ) !== 0 ) {
            $call['id'] = wp_rand( 1, PHP_INT_MAX );
        }
        $request->set_body( wp_json_encode( $call ) );
        // As WP_REST_Server::serve_request() does: the adapter puts the session id on through this filter.
        $response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $request ) ), rest_get_server(), $request );
        if ( $method === 'initialize' ) {
            foreach ( $response->get_headers() as $name => $value ) {
                if ( strtolower( $name ) === 'mcp-session-id' ) {
                    $this->mcp_session = (string) $value;
                }
            }
            $this->assertNotSame( '', (string) $this->mcp_session, 'MCP initialize gave no session: ' . wp_json_encode( $response->get_data() ) );
        }
        if ( isset( $call['id'] ) ) {
            $this->assertSame( 200, $response->get_status(), $method . ': ' . wp_json_encode( $response->get_data() ) );
        }
        return (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
    }
}
