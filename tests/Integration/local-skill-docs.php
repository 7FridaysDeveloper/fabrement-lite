<?php
/** wp eval-file tests/Integration/local-skill-docs.php — local development site only. */
use Fabrement\Modules\Mcp\Support\LocalSkillDocs;
use Fabrement\Framework\Keys;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local' ) { exit( 1 ); }
if ( realpath( FABREMENT_BLOCKS_PATH ) !== realpath( getenv( 'FABREMENT_TEST_PLUGIN_ROOT' ) ?: dirname( __DIR__, 2 ) ) ) {
    throw new RuntimeException( 'The site is not running this plugin checkout.' );
}
if ( get_option( 'fabrement_builder_api_access_token', '' ) !== '' ) {
    throw new RuntimeException( 'Use a site without a Fabrement account to verify local instructions.' );
}
$passed = 0;
$assert = static function ( bool $condition, string $message ) use ( &$passed ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$passed;
};
$outbound = 0;
$block_http = static function () use ( &$outbound ) {
    ++$outbound;
    return new WP_Error( 'test_network_disabled', 'HTTP requests are disabled for this test.' );
};
$original_user = get_current_user_id();
add_filter( 'pre_http_request', $block_http, PHP_INT_MAX, 3 );
try {
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
    if ( ! $admins ) { throw new RuntimeException( 'A test administrator is required.' ); }
    wp_set_current_user( (int) $admins[0] );
    $ability = wp_get_ability( 'fabrement/get-skill-doc' );
    $assert( $ability !== null, 'Ability is registered through the container/roster.' );
    $doc = $ability->execute( [ 'id' => 'content-contract' ] );
    $assert( is_array( $doc ) && $doc === LocalSkillDocs::read( 'content-contract', false ), 'Registered ability reads exact bundled text while signed out.' );

    $session = '';
    $rpc = static function ( string $method, array $params ) use ( &$session ): array {
        $request = new WP_REST_Request( 'POST', '/fabrement/v1/mcp' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_header( 'accept', 'application/json, text/event-stream' );
        if ( $session !== '' ) { $request->set_header( 'mcp-session-id', $session ); $request->set_header( 'mcp-protocol-version', '2025-06-18' ); }
        $request->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => wp_rand(), 'method' => $method, 'params' => (object) $params ] ) );
        $response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $request ) ), rest_get_server(), $request );
        foreach ( $response->get_headers() as $key => $value ) {
            if ( strtolower( $key ) === 'mcp-session-id' ) { $session = $value; }
        }
        $data = json_decode( wp_json_encode( $response->get_data() ), true );
        if ( $response->get_status() !== 200 || isset( $data['error'] ) ) {
            throw new RuntimeException( wp_json_encode( $data ) );
        }
        return $data['result'];
    };
    $rpc( 'initialize', [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => 'local-skills-test', 'version' => '1.0' ] ] );
    $list = $rpc( 'tools/list', [] );
    $tools = array_column( $list['tools'], null, 'name' );
    $assert( isset( $tools['fabrement-get-skill-doc'] ), 'Local docs appear in MCP tools/list.' );
    foreach ( [ 'create-block', 'submit-version', 'import-block', 'rollback-version', 'get-design-system',
        'save-global-js', 'save-global-css', 'save-single-page', 'save-archive-page', 'get-figma' ] as $removed ) {
        $assert( ! isset( $tools[ 'fabrement-' . $removed ] ), 'Removed tool is not advertised: ' . $removed );
    }
    $assert( ! isset( $tools['fabrement-get-block-source']['inputSchema']['properties']['blockId'] ), 'Lite schema does not advertise cloud source lookup.' );
    $blocks = $rpc( 'tools/call', [ 'name' => 'fabrement-list-blocks', 'arguments' => [ 'search' => 'core/paragraph' ] ] );
    $block_list = $blocks['structuredContent'] ?? json_decode( $blocks['content'][0]['text'] ?? '', true );
    $assert( empty( $blocks['isError'] ) && in_array( 'core/paragraph', array_column( $block_list['blocks'] ?? [], 'name' ), true ), 'Default discovery works locally with HTTP blocked.' );
    $block = $rpc( 'tools/call', [ 'name' => 'fabrement-get-block-source', 'arguments' => [ 'name' => 'core/paragraph' ] ] );
    $assert( empty( $block['isError'] ) && str_contains( wp_json_encode( $block ), 'markup' ), 'Local core schema explains saved-markup requirements.' );
    $page = ( new \Fabrement\Modules\Mcp\Ability\ListRegisteredBlocksAbility() )->execute( [ 'source' => 'core', 'limit' => 1, 'page' => 1 ] );
    $next = ( new \Fabrement\Modules\Mcp\Ability\ListRegisteredBlocksAbility() )->execute( [ 'source' => 'core', 'limit' => 1, 'page' => 2 ] );
    $assert( count( $page['blocks'] ) === 1 && $page['total'] > 1 && $page['total'] === $next['total']
        && $page['blocks'][0]['name'] !== $next['blocks'][0]['name'], 'Local pagination does not duplicate or lose the total.' );
    $assert( $page['blocks'][0]['source'] === 'core' && ! array_key_exists( 'projectSync', $page ) && ! array_key_exists( 'kebabName', $page['blocks'][0] ), 'Provider filter works and no cloud-catalog fields are returned.' );
    $result = $rpc( 'tools/call', [ 'name' => 'fabrement-get-skill-doc', 'arguments' => [ 'id' => 'content-contract' ] ] );
    $assert( empty( $result['isError'] ), 'MCP tools/call succeeds without backend credentials.' );
    $assert( str_contains( wp_json_encode( $result ), 'REGISTERED BLOCKS' ), 'MCP response contains the bundled contract.' );
    $missing = $rpc( 'tools/call', [ 'name' => 'fabrement-get-skill-doc', 'arguments' => [ 'id' => 'no-such-skill' ] ] );
    $assert( ! empty( $missing['isError'] ), 'Unknown document returns a tool error.' );
    $obsolete = $rpc( 'tools/call', [ 'name' => 'fabrement-get-skill-doc', 'arguments' => [ 'id' => 'design-system-contract' ] ] );
    $assert( ! empty( $obsolete['isError'] ), 'Removed skill cannot be read through MCP.' );
    $invalid = $rpc( 'tools/call', [ 'name' => 'fabrement-get-skill-doc', 'arguments' => [ 'id' => '../wp-config' ] ] );
    $assert( ! empty( $invalid['isError'] ), 'MCP rejects file traversal.' );

    wp_set_current_user( 0 );
    $assert( is_wp_error( $ability->execute( [ 'id' => 'content-contract' ] ) ), 'Anonymous ability execution remains forbidden.' );
    $assert( $outbound === 0, 'No outbound HTTP was attempted.' );
    WP_CLI::success( $passed . ' local-skills checks passed, including MCP REST dispatch; no outbound HTTP. Network login flow not exercised.' );
} finally {
    remove_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
    wp_set_current_user( $original_user );
}
