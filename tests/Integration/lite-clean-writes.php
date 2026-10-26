<?php
/** Only on the disposable ZIP installation; creates and deletes its own test drafts. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local'
    || ! str_starts_with( DB_NAME, 'fabrement_lite_verify_' )
    || wp_normalize_path( realpath( ABSPATH ) ) !== 'C:/7fridays/fabrement-fields/runtime/lite-clean-install' ) { exit( 1 ); }
$passed = 0;
$assert = static function ( bool $ok, string $message ) use ( &$passed ): void {
    if ( ! $ok ) { throw new RuntimeException( $message ); }
    ++$passed;
};
$ids = [];
$previous = get_current_user_id();
wp_set_current_user( 1 );
try {
    $safe = [ 'markup' => '<!-- wp:paragraph --><p>Safe content</p><!-- /wp:paragraph -->' ];
    $unsafe = [ 'markup' => '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->' ];
    $save = wp_get_ability( 'fabrement/save-content' );
    $get = wp_get_ability( 'fabrement/get-content' );
    $created = $save->execute( [ 'mode' => 'create', 'title' => 'Disposable Lite ZIP test', 'postType' => 'page', 'blocks' => [ $safe ] ] );
    $assert( ! is_wp_error( $created ) && isset( $created['contentId'] ), 'Packaged MCP ability creates a draft' );
    $id = $ids[] = $created['contentId'];
    $assert( get_post_status( $id ) === 'draft' && get_post_field( 'post_content', $id ) === $safe['markup'], 'Draft content stored exactly' );
    $before = $get->execute( [ 'contentId' => $id ] );
    $bad = $save->execute( [ 'mode' => 'edit', 'contentId' => $id, 'expectedHash' => $before['hash'], 'edits' => [
        [ 'op' => 'text', 'index' => 0, 'name' => 'core/paragraph', 'old' => 'Safe content', 'new' => 'Changed' ],
        [ 'op' => 'insert', 'at' => 1, 'block' => $unsafe ],
    ] ] );
    $assert( is_wp_error( $bad ) && $bad->get_error_code() === 'unsafe_content', 'Unsafe batch refused through registered MCP ability' );
    $assert( get_post_field( 'post_content', $id ) === $safe['markup'], 'Database unchanged after partial valid / invalid batch' );
    $good = $save->execute( [ 'mode' => 'edit', 'contentId' => $id, 'expectedHash' => $before['hash'], 'edits' => [
        [ 'op' => 'text', 'index' => 0, 'name' => 'core/paragraph', 'old' => 'Safe content', 'new' => 'Changed safely' ],
    ] ] );
    $assert( ! is_wp_error( $good ) && str_contains( get_post_field( 'post_content', $id ), 'Changed safely' ), 'Normal core edit succeeds' );
    $stale = $save->execute( [ 'mode' => 'edit', 'contentId' => $id, 'expectedHash' => $before['hash'], 'edits' => [ [ 'op' => 'remove', 'index' => 0, 'name' => 'core/paragraph' ] ] ] );
    $assert( is_wp_error( $stale ) && str_contains( get_post_field( 'post_content', $id ), 'Changed safely' ), 'Stale hash cannot overwrite content' );
    $pattern = wp_get_ability( 'fabrement/save-synced-pattern' );
    $bad_pattern = $pattern->execute( [ 'mode' => 'create', 'title' => 'Unsafe pattern must not exist', 'blocks' => [ $unsafe ] ] );
    $assert( is_wp_error( $bad_pattern ) && $bad_pattern->get_error_code() === 'unsafe_content', 'Synced patterns use the same safety boundary' );
    wp_set_current_user( 0 );
    $anonymous = $save->execute( [ 'mode' => 'create', 'title' => 'Anonymous must not exist', 'blocks' => [ $safe ] ] );
    $assert( is_wp_error( $anonymous ), 'Anonymous write refused' );
    WP_CLI::success( $passed . ' clean ZIP content-write assertions passed.' );
} finally {
    wp_set_current_user( 1 );
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
    wp_set_current_user( $previous );
}
