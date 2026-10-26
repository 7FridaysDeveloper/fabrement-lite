<?php
/** Local Lite only. Creates its own temporary content, removes it in finally. */
use Fabrement\Modules\Mcp\Ability\SaveTemplateAbility;
use Fabrement\Modules\Mcp\Ability\GetTemplateAbility;
use Fabrement\Modules\Mcp\Ability\SaveSyncedPatternAbility;
use Fabrement\Modules\Mcp\Ability\AssignTemplatePagesAbility;
use Fabrement\Modules\Mcp\Ability\DeleteTemplateAbility;
use Fabrement\Modules\SiteTemplate\Service\SiteTemplateRegistry;
use Fabrement\Modules\SiteTemplate\Service\BlockThemeApplier;
use Fabrement\Modules\SiteTemplate\Service\TemplateResolver;

$clean_install = wp_normalize_path( realpath( ABSPATH ) ) === 'C:/7fridays/fabrement-fields/runtime/lite-clean-install'
    && str_starts_with( DB_NAME, 'fabrement_lite_verify_' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local'
    || realpath( FABREMENT_BLOCKS_PATH ) !== realpath( getenv( 'FABREMENT_TEST_PLUGIN_ROOT' ) ?: dirname( __DIR__, 2 ) )
    || ( ! $clean_install && wp_parse_url( home_url(), PHP_URL_HOST ) !== 'plugin-lite.local' ) ) { exit( 1 ); }
$count = 0; $ids = []; $old_user = get_current_user_id(); $old_post = $GLOBALS['post'] ?? null; $old_query = $GLOBALS['wp_query'];
$child_theme = static fn() => dirname( __DIR__ ) . '/Fixtures/theme-source/child';
$parent_theme = static fn() => dirname( __DIR__ ) . '/Fixtures/theme-source/parent';
$assert = static function ( $ok, $message ) use ( &$count ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$count; };
$ok = static function ( $result ) { if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() ); } return $result; };
$paragraph = static fn( $text ) => [ 'markup' => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->' ];
try {
    $users = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
    wp_set_current_user( (int) $users[0] );
    $patterns = new SaveSyncedPatternAbility();
    foreach ( [ 'header', 'footer' ] as $kind ) {
        $made = $ok( $patterns->execute( [ 'mode' => 'create', 'title' => 'Lite template QA ' . $kind, 'kind' => $kind, 'blocks' => [ $paragraph( 'QA ' . $kind ) ] ] ) );
        $parts[ $kind ] = $ids[] = (int) $made['patternId'];
    }
    $blocks = [ [ 'name' => 'core/block', 'attributes' => [ 'ref' => $parts['header'] ] ], [ 'name' => 'core/post-content', 'attributes' => [ 'className' => 'qa-content' ] ], [ 'name' => 'core/block', 'attributes' => [ 'ref' => $parts['footer'] ] ] ];
    $save = new SaveTemplateAbility(); $get = new GetTemplateAbility();
    $made = $ok( $save->execute( [ 'mode' => 'create', 'title' => 'Lite QA template', 'blocks' => $blocks ] ) );
    $id = $ids[] = (int) $made['template']['id'];
    $read = $ok( $get->execute( [ 'templateId' => $id ] ) )['template'];
    $assert( $read['problem'] === '', 'A block template needs no generated rules file.' );
    $assert( $read['blocks'][1]['attributes']['className'] === 'qa-content', 'Native content slot attributes survive reads.' );
    $original = get_post_field( 'post_content', $id );
    $bad = $save->execute( [ 'mode' => 'edit', 'templateId' => $id, 'expectedHash' => 'stale', 'edits' => [ [ 'op' => 'remove', 'index' => 0, 'name' => 'core/block' ] ] ] );
    $assert( is_wp_error( $bad ) && get_post_field( 'post_content', $id ) === $original, 'Stale edits are atomic.' );
    foreach ( [ [ $paragraph( 'No slot' ) ], [ [ 'name' => 'core/post-content' ], [ 'name' => 'core/post-content' ] ], [ $paragraph( '<img src="x" onerror="alert(1)">' ), [ 'name' => 'core/post-content' ] ] ] as $bad_blocks ) {
        $bad = $save->execute( [ 'mode' => 'replace', 'templateId' => $id, 'blocks' => $bad_blocks ] );
        $assert( is_wp_error( $bad ) && get_post_field( 'post_content', $id ) === $original, 'Invalid/unsafe template leaves existing content intact.' );
    }
    $edited = $ok( $save->execute( [ 'mode' => 'edit', 'templateId' => $id, 'expectedHash' => $read['hash'], 'edits' => [ [ 'op' => 'insert', 'at' => 1, 'block' => $paragraph( 'QA before body' ) ] ] ] ) );
    $assert( $edited['template']['hash'] !== $read['hash'], 'Template edit updates its hash.' );
    $page = $ids[] = (int) $ok( wp_insert_post( wp_slash( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Lite QA assigned page', 'post_content' => $paragraph( 'QA page body' )['markup'] ] ), true ) );
    $ok( ( new AssignTemplatePagesAbility() )->execute( [ 'templateId' => $id, 'pageIds' => [ $page ], 'mode' => 'add' ] ) );
    $assert( get_page_template_slug( $page ) === $read['slug'], 'Page receives the template assignment.' );
    $GLOBALS['wp_query'] = new WP_Query( [ 'page_id' => $page, 'post_status' => 'draft' ] );
    $GLOBALS['post'] = get_post( $page ); setup_postdata( $GLOBALS['post'] );
    $assert( TemplateResolver::singular_template()->id === $id, 'Assigned template resolves without bundles.' );
    BlockThemeApplier::register_templates();
    $native = get_block_template( get_stylesheet() . '//' . $read['slug'], 'wp_template' );
    $assert( $native && str_contains( $native->content, 'wp:post-content' ), 'Native block theme receives the frame.' );
    $html = do_blocks( $native->content );
    foreach ( [ 'QA header', 'QA before body', 'QA page body', 'QA footer', 'qa-content' ] as $needle ) { $assert( str_contains( $html, $needle ), 'Rendered frame includes ' . $needle ); }
    $assert( substr_count( $html, 'QA page body' ) === 1, 'Page content renders exactly once.' );
    $assert( get_post_field( 'post_content', $page ) === $paragraph( 'QA page body' )['markup'], 'Assigning and rendering never rewrites page blocks.' );
    $own_blocks = $blocks;
    $own_blocks[1] = [ 'name' => 'fabrement/content-source' ];
    $ok( $save->execute( [ 'mode' => 'replace', 'templateId' => $id, 'blocks' => $own_blocks ] ) );
    $html = do_blocks( SiteTemplateRegistry::find( $id )->render_content() );
    $assert( str_contains( $html, 'QA header' ) && str_contains( $html, 'QA page body' ) && str_contains( $html, 'QA footer' ), 'Our Content Source block renders page content inside the same frame.' );
    $assert( SiteTemplateRegistry::find( $id )->problem() === '', 'The built-in slot needs no manifest or PHP layout.' );
    if ( function_exists( 'acf_register_block_type' ) ) {
        acf_register_block_type( [ 'name' => 'lite-template-qa', 'title' => 'Lite template QA', 'acf_block_version' => 3,
            'render_callback' => static function () { echo '<p>' . esc_html( (string) get_field( 'heading' ) ) . '</p>'; } ] );
        acf_add_local_field_group( [ 'key' => 'group_lite_template_qa', 'title' => 'QA',
            'fields' => [ [ 'key' => 'field_lite_template_qa_heading', 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ] ],
            'location' => [ [ [ 'param' => 'block', 'operator' => '==', 'value' => 'acf/lite-template-qa' ] ] ] ] );
        $acf_frame = $own_blocks;
        array_unshift( $acf_frame, [ 'name' => 'acf/lite-template-qa', 'attributes' => [ 'data' => [ 'heading' => 'QA ACF template' ] ] ] );
        $ok( $save->execute( [ 'mode' => 'replace', 'templateId' => $id, 'blocks' => $acf_frame ] ) );
        $html = do_blocks( SiteTemplateRegistry::find( $id )->render_content() );
        $assert( str_contains( $html, 'QA ACF template' ) && str_contains( $html, 'QA page body' ), 'Existing ACF blocks render alongside Content Source.' );
        $before_acf_edit = $ok( $get->execute( [ 'templateId' => $id ] ) )['template'];
        $ok( $save->execute( [ 'mode' => 'edit', 'templateId' => $id, 'expectedHash' => $before_acf_edit['hash'], 'edits' => [ [ 'op' => 'update', 'index' => 0, 'name' => 'acf/lite-template-qa', 'attributes' => [ 'data' => [ 'heading' => 'QA ACF updated' ] ] ] ] ] ) );
        $html = do_blocks( SiteTemplateRegistry::find( $id )->render_content() );
        $assert( str_contains( $html, 'QA ACF updated' ) && str_contains( $html, 'QA header' ), 'ACF patches preserve neighboring template blocks.' );
        // Leave only persistent registered blocks for the subsequent REST/browser-equivalent save.
        $ok( $save->execute( [ 'mode' => 'replace', 'templateId' => $id, 'blocks' => $own_blocks ] ) );
    }
    add_filter( 'stylesheet_directory', $child_theme );
    add_filter( 'template_directory', $parent_theme );
    $files = ( new \Fabrement\Modules\Mcp\Ability\ListThemeTemplatesAbility() )->execute( [] )['files'];
    $paths = array_column( $files, 'path' );
    $assert( count( array_filter( $paths, static fn( $path ) => $path === 'body.php' ) ) === 1 && in_array( 'parent-only.php', $paths, true ), 'Theme discovery merges child and parent without duplicate paths.' );
    $assert( ! in_array( '../outside.php', $paths, true ), 'Discovery never offers PHP outside templates/.' );
    $assert( \Fabrement\Modules\ContentSource\ThemeTemplateFiles::resolve( '/body.php' ) === null,'Discovery checks reject absolute paths rather than silently trimming them.' );
    $set_source = static function ( string $file ) use ( $save, $id ) { return $save->execute( [ 'mode' => 'replace', 'templateId' => $id, 'contentSource' => [ 'rules' => [ [ 'context' => 'singular:page', 'file' => $file ] ] ] ] ); };
    $ok( $set_source( 'body.php' ) );
    $key = \Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType::manifest_key( $id );
    $assert( $key !== '' && \Fabrement\Modules\SiteTemplate\Service\TemplateManifestStore::rules( $key )[0]['file'] === 'body.php', 'MCP saves choices into the shared JSON manifest.' );
    $assert( ! str_contains( get_post_field( 'post_content', $id ), '"rules"' ), 'Shared rules are absent from stored block attributes.' );
    // Exercise the storage relationship WPML uses, without claiming a real WPML installation.
    $version = $ids[] = (int) $ok( wp_insert_post( wp_slash( [ 'post_type' => 'fabrement_template', 'post_status' => 'publish',
        'post_title' => 'ZZ Lite QA translated frame', 'post_content' => $paragraph( 'Translated heading' )['markup'] .
        '<!-- wp:group --><div class="wp-block-group"><!-- wp:fabrement/content-source {"className":"translated-slot","rules":[{"context":"singular:page","file":"parent-only.php"}]} /--></div><!-- /wp:group -->',
    ] ), true ) );
    update_post_meta( $version, \Fabrement\Framework\Keys::META_TEMPLATE_KEY, $key );
    SiteTemplateRegistry::flush();
    $version_content = get_post_field( 'post_content', $version );
    $assert( \Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig::read( $version )['rules'][0]['file'] === 'body.php', 'A sibling reads shared rules rather than stale embedded copies.' );
    $rendered_version = SiteTemplateRegistry::find( $version )->render_content();
    $assert( str_contains( $rendered_version, '"templateId":' . $version ) && ! str_contains( $rendered_version, 'parent-only.php' ) && str_contains( $rendered_version, 'translated-slot' ), 'The slot is told its template; stale embedded rules are dropped, layout attributes kept.' );
    $version_read = $ok( $get->execute( [ 'templateId' => $version ] ) )['template'];
    $assert( ! str_contains( wp_json_encode( $version_read['blocks'] ), 'parent-only.php' ) && str_contains( wp_json_encode( $version_read['blocks'] ), 'translated-slot' ), 'MCP block reads separate shared choices from nested translated blocks.' );
    $html = do_blocks( SiteTemplateRegistry::find( $id )->render_content() );
    $assert( str_contains( $html, 'Child theme source: ' . $page ) && ! str_contains( $html, 'Parent shadowed' ) && ! str_contains( $html, 'QA page body' ), 'Existing child PHP file fills the source slot using the actual page context.' );
    $assert( str_contains( $html, 'QA header' ) && str_contains( $html, 'QA footer' ), 'Theme source preserves header/footer.' );
    wp_update_post( [ 'ID' => $page, 'post_password' => 'qa-private' ] );
    $GLOBALS['post'] = get_post( $page ); $GLOBALS['wp_query']->queried_object = get_post( $page );
    $html = do_blocks( SiteTemplateRegistry::find( $id )->render_content() );
    $assert( ! str_contains( $html, 'Child theme source' ) && str_contains( $html, 'post-password-form' ), 'A selected theme source does not bypass page password protection.' );
    wp_update_post( [ 'ID' => $page, 'post_password' => '' ] );
    $GLOBALS['post'] = get_post( $page ); $GLOBALS['wp_query']->queried_object = get_post( $page );
    $source_read = $ok( $get->execute( [ 'templateId' => $id ] ) )['template'];
    $assert( $source_read['contentSource']['rules'][0]['file'] === 'body.php', 'get-template returns the current source choice.' );
    $before_source = get_post_field( 'post_content', $id );
    foreach ( [ '../outside.php', '/body.php', 'C:/outside.php', 'php://filter/resource=body.php', '%2e%2e/outside.php', 'render/singular/generated.php', 'missing.php', 'archive.php' ] as $invalid ) {
        $assert( is_wp_error( $set_source( $invalid ) ) && get_post_field( 'post_content', $id ) === $before_source, 'Invalid source refused atomically: ' . $invalid );
    }
    $ok( $set_source( 'parent-only.php' ) );
    $assert( \Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig::read( $version )['rules'][0]['file'] === 'parent-only.php'
        && get_post_field( 'post_content', $version ) === $version_content, 'Changing original rules updates the sibling without rewriting translated content.' );
    $assert( str_contains( do_blocks( SiteTemplateRegistry::find( $id )->render_content() ), 'Parent-only source' ), 'A parent theme source can render.' );
    $ok( $set_source( 'broken.php' ) );
    $html = do_blocks( SiteTemplateRegistry::find( $id )->render_content() );
    $assert( str_contains( $html, 'QA page body' ) && ! str_contains( $html, 'Partial output' ), 'A throwing theme source falls back without leaking partial output.' );
    $ok( $save->execute( [ 'mode' => 'replace', 'templateId' => $id, 'contentSource' => [ 'rules' => [] ] ] ) );
    $assert( str_contains( do_blocks( SiteTemplateRegistry::find( $id )->render_content() ), 'QA page body' ), 'Clearing the source returns to page blocks.' );
    $ok( $set_source( 'parent-only.php' ) );
    // REST_REQUEST remains true after dispatch; finish frontend rendering checks first.
    $request = new WP_REST_Request( 'GET', '/wp/v2/fabrement_template/' . $version );
    $request->set_param( 'context', 'edit' );
    $response = rest_do_request( $request );
    $assert( $response->get_status() === 200 && $response->get_data()['contentSource']['rules'][0]['file'] === 'parent-only.php', 'Gutenberg loads shared source choices in its editable REST field.' );
    $request = new WP_REST_Request( 'POST', '/wp/v2/fabrement_template/' . $version );
    $request->set_param( 'contentSource', [ 'rules' => [ [ 'context' => 'singular:page', 'file' => 'body.php' ] ] ] );
    $response = rest_do_request( $request );
    $assert( $response->get_status() === 200 && \Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig::read( $id )['rules'][0]['file'] === 'body.php', 'Gutenberg source-only edits on a sibling update the original shared choices.' );
    $assert( get_post_field( 'post_content', $version ) === $version_content, 'Source-only REST save preserves translated words and block markup.' );
    $request->set_param( 'content', $paragraph( 'Must not save' )['markup'] . '<!-- wp:fabrement/content-source /-->' );
    $request->set_param( 'contentSource', [ 'rules' => [ [ 'context' => 'singular:page', 'file' => '../outside.php' ] ] ] );
    $response = rest_do_request( $request );
    $assert( $response->get_status() >= 400 && get_post_field( 'post_content', $version ) === $version_content
        && \Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig::read( $id )['rules'][0]['file'] === 'body.php', 'Invalid REST source refuses both frame and shared rules before writing.' );
    $request = new WP_REST_Request( 'POST', '/wp/v2/fabrement_template/' . $version );
    $request->set_param( 'content', $version_content );
    $response = rest_do_request( $request );
    $assert( $response->get_status() === 200 && ! str_contains( get_post_field( 'post_content', $version ), '"rules"' )
        && \Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig::read( $id )['rules'][0]['file'] === 'body.php', 'A block-only save cannot overwrite shared rules with old embedded choices.' );
    $ok( $save->execute( [ 'mode' => 'replace', 'templateId' => $version, 'contentSource' => [ 'rules' => [] ] ] ) );
    $assert( \Fabrement\Modules\SiteTemplate\Service\ContentSourceConfig::read( $id )['rules'] === [], 'Clearing rules from the sibling clears shared choices for the original.' );
    wp_delete_post( $version, true );
    $assert( \Fabrement\Modules\SiteTemplate\Service\TemplateManifestStore::exists( $key ), 'Deleting one version preserves the manifest for its remaining holder.' );
    remove_filter( 'stylesheet_directory', $child_theme ); remove_filter( 'template_directory', $parent_theme );
    $request = new WP_REST_Request( 'POST', '/wp/v2/fabrement_template/' . $id );
    $request->set_param( 'content', get_post_field( 'post_content', $id ) . "\n" . $paragraph( 'QA native editor' )['markup'] );
    $response = rest_do_request( $request );
    $assert( $response->get_status() === 200, 'Native Gutenberg REST can edit the template: ' . wp_json_encode( $response->get_data() ) );
    $stored = get_post_field( 'post_content', $id );
    $request->set_param( 'content', '<!-- wp:html --><script>alert(1)</script><!-- /wp:html --><!-- wp:post-content /-->' );
    $response = rest_do_request( $request );
    $assert( $response->get_status() >= 400 && get_post_field( 'post_content', $id ) === $stored, 'Native REST unsafe edit is atomic.' );
    wp_set_current_user( 0 );
    $response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/fabrement_template/' . $id ) );
    $assert( $response->get_status() === 401, 'Anonymous users cannot read template editor records.' );
    wp_set_current_user( (int) $users[0] );
    $bad = ( new DeleteTemplateAbility() )->execute( [ 'templateId' => $id ] );
    $assert( is_wp_error( $bad ), 'In-use template deletion is guarded.' );
    $ok( ( new DeleteTemplateAbility() )->execute( [ 'templateId' => $id, 'force' => true ] ) );
    $assert( ! get_post( $id ) && ! str_starts_with( (string) get_page_template_slug( $page ), 'fabrement-template-' ), 'Deletion clears page picks.' );
    $assert( ! \Fabrement\Modules\SiteTemplate\Service\TemplateManifestStore::exists( $key ), 'Deleting the last holder removes the shared manifest.' );
    $assert( get_post( $parts['header'] ) !== null && get_post( $page ) !== null, 'Template deletion preserves shared patterns and page content.' );
} finally {
    remove_filter( 'stylesheet_directory', $child_theme ); remove_filter( 'template_directory', $parent_theme );
    foreach ( array_reverse( $ids ) as $id ) { wp_delete_post( $id, true ); }
    SiteTemplateRegistry::flush();
    if ( function_exists( 'acf_remove_local_field_group' ) ) { acf_remove_local_field_group( 'group_lite_template_qa' ); }
    if ( WP_Block_Type_Registry::get_instance()->is_registered( 'acf/lite-template-qa' ) ) { unregister_block_type( 'acf/lite-template-qa' ); acf_get_store( 'block-types' )->remove( 'acf/lite-template-qa' ); }
    $GLOBALS['post'] = $old_post; $GLOBALS['wp_query'] = $old_query; wp_set_current_user( $old_user );
}
WP_CLI::success( $count . ' Lite template checks passed; temporary content removed.' );
