<?php

namespace Fabrement\Tests;

/**
 * A field save COMMITs its own transaction (ValueRepository), so whatever a test made before it —
 * posts, attachments, users, options — outlives the test's rollback. This deletes what the test
 * made, and puts back the options it names, whether or not a save committed them. Only what this
 * test made: nothing an earlier test or the bootstrap left. For a WP_UnitTestCase.
 */
trait CommittedCleanup {

    /** @var int[] */
    private array $made_posts = [];

    /** @var int[] */
    private array $made_users = [];

    /** @var array<string,mixed> Option => its value before the test (null: it did not exist). */
    private array $kept_options = [];

    /**
     * @param string[] $options Options the test changes, put back as they were.
     * @param int      ...$users Users made before this call (a base class's set_up) that go too.
     */
    protected function start_cleanup( array $options = [], int ...$users ): void {
        $this->made_posts = [];
        $this->made_users = array_values( array_filter( $users ) );
        $this->kept_options = [];
        foreach ( $options as $option ) {
            $this->kept_options[ $option ] = get_option( $option, null );
        }
        add_action( 'wp_insert_post', function ( $id ): void {
            $this->made_posts[] = (int) $id;
        } );
        add_action( 'add_attachment', function ( $id ): void {
            $this->made_posts[] = (int) $id;
        } );
        add_action( 'user_register', function ( $id ): void {
            $this->made_users[] = (int) $id;
        } );
    }

    protected function end_cleanup(): void {
        foreach ( array_reverse( array_unique( $this->made_posts ) ) as $id ) {
            if ( get_post_type( $id ) === 'attachment' ) {
                wp_delete_attachment( $id, true );
            } elseif ( get_post( $id ) ) {
                wp_delete_post( $id, true );
            }
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ( array_unique( $this->made_users ) as $id ) {
            wp_delete_user( $id );
        }
        foreach ( $this->kept_options as $option => $value ) {
            $value === null ? delete_option( $option ) : update_option( $option, $value );
        }
        $this->made_posts = [];
        $this->made_users = [];
        // The test's transaction is rolled back after this: the clean-up must not go with it.
        global $wpdb;
        $wpdb->query( 'COMMIT' );
    }
}
