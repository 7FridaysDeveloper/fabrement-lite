<?php

use Fabrement\Tests\WordPressState;

// In-memory WordPress/HTTP boundaries for import tests; never load the running site.
function get_option( $name, $default = false ) { return WordPressState::$options[ $name ] ?? $default; }
function get_post_meta( $id, $key, $single = false ) { return WordPressState::$post_meta[ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { WordPressState::$post_meta[ $id ][ $key ] = $value; return true; }
function get_the_title( $id ) { return WordPressState::$posts[ $id ]->post_title ?? ''; }
function sanitize_textarea_field( $text ) { return trim( strip_tags( $text ) ); }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $text ) ); }
function esc_url_raw( $url, $protocols = null ) { return $url; }
function untrailingslashit( $path ) { return rtrim( $path, '/\\' ); }
function get_posts( $args ) {
    $posts = array_filter( WordPressState::$posts, static function ( $post ) use ( $args ) {
        foreach ( [ 'post_type', 'post_parent' ] as $key ) {
            if ( isset( $args[ $key ] ) && $post->$key !== $args[ $key ] ) { return false; }
        }
        if ( isset( $args['name'] ) && $post->post_name !== $args['name'] ) { return false; }
        if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] && $post->post_status !== $args['post_status'] ) { return false; }
        if ( in_array( $post->ID, $args['post__not_in'] ?? [], true ) ) { return false; }
        foreach ( $args['meta_query'] ?? [] as $meta ) {
            if ( get_post_meta( $post->ID, $meta['key'], true ) !== $meta['value'] ) { return false; }
        }
        return true;
    } );
    // WordPress reads a non-positive posts_per_page as "every match". Passing it straight to
    // array_slice() instead made -1 drop the LAST row, which looks like a working query.
    $limit = (int) ( $args['posts_per_page'] ?? 0 );
    $posts = array_values( $posts );
    if ( isset( $args['post__in'] ) ) {
        $by_id  = array_column( array_map( static fn( $post ) => [ $post->ID, $post ], $posts ), 1, 0 );
        $posts = array_values( array_filter( array_map( static fn( $id ) => $by_id[ (int) $id ] ?? null, array_unique( (array) $args['post__in'] ) ) ) );
    }
    if ( $limit > 0 ) {
        $posts = array_slice( $posts, 0, $limit );
    }
    return 'ids' === ( $args['fields'] ?? '' ) ? array_map( static fn( $post ) => $post->ID, $posts ) : $posts;
}
function wp_insert_post( $post, $wp_error = false ) {
    if ( null !== WordPressState::$insert_error ) { return WordPressState::$insert_error; }
    $id = max( array_merge( [ 100 ], array_keys( WordPressState::$posts ) ) ) + 1;
    WordPressState::$posts[ $id ] = new WP_Post( (object) ( [ 'ID' => $id, 'post_content' => '' ] + $post ) );
    WordPressState::$inserted_posts[] = $id;
    return $id;
}
function wp_delete_post( $id, $force = false ) {
    WordPressState::$deleted_posts[] = $id;
    unset( WordPressState::$posts[ $id ], WordPressState::$post_meta[ $id ] );
    return true;
}
function wp_remote_get( $url, $args = [] ) {
    WordPressState::$http_requests[] = $url;
    WordPressState::$http_request_args[] = [ 'url' => $url, 'args' => $args ];
    if ( ! array_key_exists( $url, WordPressState::$http_responses ) ) {
        throw new RuntimeException( 'Unexpected HTTP request: ' . $url );
    }
    return WordPressState::$http_responses[ $url ];
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_post( $url, $args = [] ) { return wp_remote_get( $url, $args ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
