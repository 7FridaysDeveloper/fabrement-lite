<?php
/** Local Lite regression: temporary drafts/tokens and real anonymous HTTP. */
use Fabrement\Framework\Keys;
use Fabrement\Modules\ContentPreview\ContentPreview;
use Fabrement\Modules\Mcp\Ability\PreviewAbility;

$clean = wp_normalize_path( realpath( ABSPATH ) ) === 'C:/7fridays/fabrement-fields/runtime/lite-clean-install'
    && str_starts_with( DB_NAME, 'fabrement_lite_verify_' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local'
    || realpath( FABREMENT_BLOCKS_PATH ) !== realpath( getenv( 'FABREMENT_TEST_PLUGIN_ROOT' ) ?: dirname( __DIR__, 2 ) )
    || ( ! $clean && wp_parse_url( home_url(), PHP_URL_HOST ) !== 'plugin-lite.local' ) ) { exit( 1 ); }

$count = 0; $ids = []; $tokens = []; $old_user = get_current_user_id(); $old_get = $_GET;
$old_statuses = ContentPreview::statuses( null );
$assert = static function ( bool $ok, string $why ) use ( &$count ): void {
    if ( ! $ok ) { throw new RuntimeException( $why ); } ++$count;
};
$ok = static function ( $result ) {
    if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } return $result;
};
$use_token = static function ( string $url ) use ( &$tokens ): string {
    parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
    $token = $args[ ContentPreview::TOKEN_ARG ];
    $tokens[] = $token;
    $_GET = [ ContentPreview::TOKEN_ARG => $token ];
    return $token;
};
$http = static function ( string $url ) use ( $ok ): array {
    // No cookies or authentication headers: a holder of the preview link only.
    $response = $ok( wp_remote_get( $url, [ 'timeout' => 20, 'redirection' => 3 ] ) );
    return [ wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ), wp_remote_retrieve_headers( $response ) ];
};
try {
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
    wp_set_current_user( (int) $admins[0] );
    $marker = 'Lite preview QA ' . wp_generate_password( 12, false, false );
    foreach ( [ 'page', 'post' ] as $post_type ) {
        $id = (int) $ok( wp_insert_post( wp_slash( [ 'post_type' => $post_type, 'post_status' => 'draft',
            'post_title' => $marker . ' ' . $post_type, 'post_content' => '<!-- wp:paragraph --><p>' . $marker . '</p><!-- /wp:paragraph -->',
        ] ), true ) );
        $ids[] = $id;
        $preview = $ok( ( new PreviewAbility() )->execute( [ 'id' => $id ] ) );
        $url = $preview['url']; $token = $use_token( $url );
        $assert( $preview['expiresIn'] === 600 && $preview['status'] === 'draft', 'Preview preserves its lifetime and draft status.' );
        $assert( ContentPreview::token_id() === $id && ContentPreview::token_statuses() === null, 'Live token stays bound to its content record.' );
        [ $status, $body, $headers ] = $http( $url );
        $assert( $status === 200 && str_contains( $body, $marker ), 'Anonymous HTTP renders the draft ' . $post_type . ' with its token.' );
        $assert( str_contains( (string) ( $headers['x-robots-tag'] ?? '' ), 'noindex' ), 'A preview is excluded from indexing.' );
        $assert( get_post_status( $id ) === 'draft', 'Rendering the preview does not publish the record.' );
        [ $status, $body ] = $http( get_permalink( $id ) );
        $assert( $status !== 200 && ! str_contains( $body, $marker ), 'The draft remains hidden without a token.' );
        delete_transient( Keys::content_preview( $token ) );
        [ $status, $body ] = $http( $url );
        $assert( $status !== 200 && ! str_contains( $body, $marker ), 'A revoked token no longer reveals the draft.' );
    }
    [ $page, $other ] = $ids;
    $url = ContentPreview::url( $page ); $token = $use_token( $url );
    [ $status, $body ] = $http( add_query_arg( ContentPreview::TOKEN_ARG, $token, get_permalink( $other ) ) );
    $assert( $status !== 200 && ! str_contains( $body, $marker ), 'The page token cannot reveal another record.' );
    wp_update_post( [ 'ID' => $page, 'post_password' => 'qa-only' ] );
    $assert( is_wp_error( ( new PreviewAbility() )->execute( [ 'id' => $page ] ) ), 'Password-protected records cannot receive shareable links.' );
    [ , $body ] = $http( $url );
    $assert( ! str_contains( $body, $marker ), 'Adding a password revokes access through a previously minted link.' );
    wp_update_post( [ 'ID' => $page, 'post_password' => '', 'post_status' => 'private' ] );
    $assert( is_wp_error( ( new PreviewAbility() )->execute( [ 'id' => $page ] ) ), 'Private records cannot receive shareable links.' );
    [ , $body ] = $http( $url );
    $assert( ! str_contains( $body, $marker ), 'A previously minted link cannot expose content made private.' );
    wp_update_post( [ 'ID' => $page, 'post_status' => 'draft' ] );

    $assert( ContentPreview::statuses( null ) === [ 'publish', 'draft' ] && ContentPreview::statuses( [ 'publish' ] ) === [ 'publish' ], 'Retained token status helpers preserve defaults and explicit query statuses.' );
} finally {
    foreach ( $tokens as $token ) { delete_transient( Keys::content_preview( $token ) ); }
    foreach ( array_reverse( $ids ) as $id ) { wp_delete_post( $id, true ); }
    ContentPreview::open( $old_statuses );
    $_GET = $old_get; wp_set_current_user( $old_user );
}
WP_CLI::success( $count . ' Lite preview checks passed; temporary content and tokens removed.' );
