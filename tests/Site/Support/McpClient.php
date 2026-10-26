<?php

namespace Fabrement\Tests\Site\Support;

use RuntimeException;

/**
 * An MCP client of a test site, as Claude connects: HTTPS to /wp-json/fabrement/v1/mcp, signed in
 * with the administrator's application password, a session opened with `initialize`, tools run
 * with `tools/call`. Answers are what the agent reads.
 */
final class McpClient {

    private ?string $session = null;

    /** Where the server answers: the pretty address, or ?rest_route= on a site with plain permalinks. */
    private string $path = '/wp-json/fabrement/v1/mcp';

    /** Signed in with an application password (user + password), or with an OAuth access token ($bearer). */
    public function __construct( private Site $site, private string $user = 'admin', private ?string $password = null, private ?string $bearer = null ) {}

    /** The same client at the address a site with plain permalinks gives (rest_url(): ?rest_route=). */
    public function via_rest_route(): self {
        $client          = clone $this;
        $client->path    = '/?rest_route=/fabrement/v1/mcp';
        $client->session = null;
        return $client;
    }

    /**
     * Run a tool, named without the server's prefix ('set-field-values').
     *
     * @return array{error:bool, text:string, data:mixed} data: the structured answer, else the text decoded
     */
    public function call( string $tool, array $arguments = [] ): array {
        $answer = $this->rpc( 'tools/call', [ 'name' => 'fabrement-' . $tool, 'arguments' => (object) $arguments ] );
        if ( ! isset( $answer['result'] ) ) {
            throw new RuntimeException( "$tool: no result — " . json_encode( $answer ) );
        }
        $result = $answer['result'];
        $text   = '';
        foreach ( (array) ( $result['content'] ?? [] ) as $part ) {
            $text .= (string) ( $part['text'] ?? '' );
        }
        $data = $result['structuredContent'] ?? json_decode( $text, true );
        return [ 'error' => ! empty( $result['isError'] ), 'text' => $text, 'data' => $data ];
    }

    /** @return array[] The tools the server lists (name, description, inputSchema, annotations…). */
    public function tools(): array {
        return $this->rpc( 'tools/list', [] )['result']['tools'] ?? [];
    }

    /**
     * One JSON-RPC call; opens the session first.
     *
     * @return array The decoded answer; status and headers under '_http'.
     */
    public function rpc( string $method, array $params ): array {
        if ( $this->session === null && $method !== 'initialize' ) {
            $opened = $this->rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'fabrement-site-tests', 'version' => '1.0' ] ] );
            $this->session = (string) ( $opened['_http']['headers']['mcp-session-id'] ?? '' );
            if ( $this->session === '' ) {
                throw new RuntimeException( 'initialize gave no Mcp-Session-Id: ' . json_encode( $opened ) );
            }
            $this->rpc( 'notifications/initialized', [] );
        }
        $call = [ 'jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params ];
        if ( strpos( $method, 'notifications/' ) !== 0 ) {
            $call['id'] = random_int( 1, PHP_INT_MAX );
        }
        $headers = [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json, text/event-stream',
            'Authorization' => $this->bearer !== null ? 'Bearer ' . $this->bearer : 'Basic ' . base64_encode( $this->user . ':' . ( $this->password ?? $this->site->password ) ),
        ];
        if ( $this->session ) {
            // MCP 2025-06-18: every request after initialize names the negotiated version.
            $headers['Mcp-Session-Id']       = $this->session;
            $headers['MCP-Protocol-Version'] = '2025-06-18';
        }
        $response = Http::request( $this->site, 'POST', $this->site->url( $this->path ), $headers, (string) json_encode( $call ) );
        $answer   = (array) json_decode( $response['body'], true );
        return $answer + [ '_http' => $response ];
    }
}
