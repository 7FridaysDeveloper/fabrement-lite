<?php
/** wp eval-file tests/Integration/lite-runtime.php — no content/schema writes. */
use Fabrement\Framework\App;
use Fabrement\Modules\Mcp\Roster;
use Fabrement\Modules\Fields\Admin\EditorAssets;
use Fabrement\Modules\Mcp\Ability\PreviewAbility;
use Fabrement\Page\McpConnectionPage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local'
    || realpath( FABREMENT_BLOCKS_PATH ) !== realpath( getenv( 'FABREMENT_TEST_PLUGIN_ROOT' ) ?: dirname( __DIR__, 2 ) ) ) { exit( 1 ); }
$passed = 0;
$assert = static function ( bool $ok, string $message ) use ( &$passed ): void {
    if ( ! $ok ) { throw new RuntimeException( $message ); }
    ++$passed;
};
$old_user = get_current_user_id();
$http = 0;
$block_http = static function () use ( &$http ) { ++$http; return new WP_Error( 'test_http_disabled', 'No HTTP in this test.' ); };
add_filter( 'pre_http_request', $block_http, PHP_INT_MAX, 3 );
try {
    $ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
    if ( ! $ids ) { throw new RuntimeException( 'Administrator fixture is required.' ); }
    wp_set_current_user( (int) $ids[0] );
    $expected = [];
    foreach ( Roster::CLASSES as $class ) {
        $reflection = new ReflectionClass( $class );
        $ability = $reflection->newInstanceWithoutConstructor();
        if ( $ability->is_available() ) { $expected[] = $ability->name(); }
    }
    $actual = array_map( static fn( $ability ) => $ability->name(), Roster::instantiate( App::container() ) );
    $assert( $expected === $actual, 'Every retained tool must instantiate; fail-soft must not silently hide tools.' );
    foreach ( $expected as $name ) { $assert( wp_get_ability( $name ) !== null, 'Missing registered ability: ' . $name ); }
    foreach ( Roster::instantiate( App::container() ) as $ability ) {
        $contract = wp_json_encode( [ $ability->description(), $ability->input_schema(), $ability->output_schema() ] );
        $assert( ! preg_match( '/\b(?:save|get|list|delete)-(?:archive-page|single-page|archive-pages|single-pages)\b/', $contract ), 'Tool contracts must not direct clients to removed code authoring: ' . $ability->name() );
    }
    $forms = wp_get_ability( 'fabrement/list-forms' )->execute( [] );
    $assert( is_array( $forms ) && isset( $forms['cf7'] ) && ! isset( $forms['wpforms'] ), 'list-forms answers Contact Form 7 only.' );

    foreach ( [
        'Fabrement\\Page\\BuilderBlock\\BuilderBlockChatPage',
        'Fabrement\\Modules\\NestBackend\\Service\\NestProxyService',
        'Fabrement\\Modules\\DesignSystem\\Service\\DesignSystemService',
        'Fabrement\\Modules\\GbBlock\\Service\\GbBlockLoader',
        'Fabrement\\Modules\\GbBlock\\Service\\GbBlockFileWriter',
        'Fabrement\\Modules\\SitePage\\PageRuntime',
        'Fabrement\\Modules\\SitePage\\BundleWriter',
        'Fabrement\\Modules\\SitePage\\Bundle',
        'Fabrement\\Modules\\SitePage\\BundleStore',
        'Fabrement\\Framework\\Config',
        'Fabrement\\Framework\\CodeWrites',
        'Fabrement\\Modules\\ContentPreview\\PreviewLocale',
        'Fabrement\\Modules\\ContentPreview\\PreviewDiagnostics',
    ] as $removed ) { $assert( ! class_exists( $removed ), 'Removed implementation still shipped: ' . $removed ); }
    $assert( ! post_type_exists( 'fabrement_gb_block' ) && post_type_exists( 'fabrement_template' ), 'Block generation is absent; editable block templates remain.' );
    $assert( WP_Block_Type_Registry::get_instance()->is_registered( 'fabrement/content-source' ), 'The built-in template content slot remains registered.' );
    foreach ( [ 'fabrement/archive-list' ] as $block ) {
        $assert( ! WP_Block_Type_Registry::get_instance()->is_registered( $block ), 'Generated layout block is not registered: ' . $block );
    }
    foreach ( [ 'admin_post_fabrement_builder_logout', 'admin_post_fabrement_native_preview', 'admin_post_nopriv_fabrement_native_preview', 'admin_post_fabrement_template_preview', 'admin_post_nopriv_fabrement_template_preview' ] as $hook ) {
        $assert( ! has_action( $hook ), 'Legacy hook is active: ' . $hook );
    }
    $routes = rest_get_server()->get_routes();
    foreach ( $routes as $route => $definition ) {
        if ( strpos( $route, '/fabrement/v1/' ) !== 0 ) { continue; }
        $assert( (bool) preg_match( '~^/fabrement/v1/(?:mcp$|fields/|post-refs$|media-refs$|site-templates(?:$|/)|content-source/files$)~', $route ), 'Unexpected Lite REST route: ' . $route );
    }
    foreach ( [ '/fabrement/v1/post-refs', '/fabrement/v1/media-refs', '/fabrement/v1/mcp', '/fabrement/v1/site-templates', '/fabrement/v1/site-templates/pages', '/fabrement/v1/site-templates/archives', '/fabrement/v1/site-templates/default', '/fabrement/v1/site-templates/translate', '/fabrement/v1/content-source/files' ] as $route ) {
        $assert( isset( $routes[$route] ), 'Retained route missing: ' . $route );
    }
    $preview = new PreviewAbility();
    foreach ( [ 'block', 'template' ] as $target ) {
        $assert( is_wp_error( $preview->execute( [ 'target' => $target, 'id' => 1 ] ) ), 'A preview target is not an argument any more.' );
    }
    $page = App::container()[ McpConnectionPage::class ];
    ob_start();
    try { $page->render(); $html = ob_get_contents(); } finally { ob_end_clean(); }
    $assert( str_contains( $html, 'MCP Connection' ) && str_contains( $html, 'Fabrement Lite' ), 'Account-free MCP page renders.' );
    foreach ( [ 'data-fabrement-topnav', 'fabrement_builder_logout', 'cannot read its skill docs', 'page=fabrement-designer', 'page=fabrement-api' ] as $removed ) {
        $assert( ! str_contains( $html, $removed ), 'Legacy account/design UI remains: ' . $removed );
    }
    $templates = App::container()[ \Fabrement\Page\TemplatesPage::class ];
    ob_start();
    try { $templates->render(); $html = ob_get_contents(); } finally { ob_end_clean(); }
    $assert( str_contains( $html, 'id="fabrement-templates-app-root"' ), 'Templates screen renders its mount point.' );
    wp_set_current_user( 0 );
    $assert( rest_do_request( new WP_REST_Request( 'GET', '/fabrement/v1/site-templates' ) )->get_status() === 401, 'Anonymous template list is refused.' );
    wp_set_current_user( (int) $ids[0] );
    $assert( rest_do_request( new WP_REST_Request( 'GET', '/fabrement/v1/site-templates' ) )->get_status() === 200, 'Administrator reads the template list.' );
    EditorAssets::register();
    $assert( wp_script_is( 'fabrement-editor-plugin', 'registered' ), 'Field form bundle is registered independently.' );
    $asset = wp_scripts()->registered['fabrement-editor-plugin'];
    $assert( ! in_array( 'fabrement-runtime', $asset->deps, true ), 'Field forms have no generated runtime dependency.' );
    $assert( $http === 0, 'Local UI/discovery made an outbound HTTP request.' );
    wp_set_current_user( 0 );
    $assert( wp_get_ability( 'fabrement/get-fields' )->check_permissions( [ 'postType' => 'page' ] ) !== true, 'Anonymous field access is refused.' );
} finally {
    wp_set_current_user( $old_user );
    remove_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
}
WP_CLI::success( $passed . ' Lite runtime checks passed; no content writes or outbound HTTP.' );
