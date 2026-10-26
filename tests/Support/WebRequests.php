<?php

namespace Fabrement\Tests;

/**
 * Real HTTP requests to the suite's web server (service "web" of its docker-compose.yml, at the
 * address the suite's bootstrap names in FABREMENT_TEST_WEB):
 * the same WordPress, plugins and database, answering as a visitor's browser or an AI client gets
 * it — WPML reads the language from the address, the theme prints the page, nothing is simulated.
 *
 * The server is another database connection: it sees what is COMMITTED, never a test's open
 * transaction. publish() commits what the test made so far; CommittedCleanup removes it after.
 */
trait WebRequests {

    /** Commit the test's transaction so the web server sees it. */
    protected function publish(): void {
        global $wpdb;
        $wpdb->query( 'COMMIT' );
    }

    /**
     * GET an address of the test site (http://example.org/…) from the web server.
     *
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    protected function web( string $url, array $headers = [] ): array {
        $parts = wp_parse_url( $url );
        $path  = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
        $curl  = curl_init( FABREMENT_TEST_WEB . $path );
        $sent  = [ 'Host: ' . ( $parts['host'] ?? 'example.org' ) ];
        foreach ( $headers as $name => $value ) {
            $sent[] = $name . ': ' . $value;
        }
        $received = [];
        curl_setopt_array( $curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $sent,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => static function ( $handle, string $line ) use ( &$received ): int {
                if ( strpos( $line, ':' ) !== false ) {
                    [ $name, $value ]                       = explode( ':', $line, 2 );
                    $received[ strtolower( trim( $name ) ) ] = trim( $value );
                }
                return strlen( $line );
            },
        ] );
        $body   = curl_exec( $curl );
        $status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        $error  = curl_error( $curl );
        curl_close( $curl );
        if ( $body === false || $status === 0 ) {
            $this->fail( 'The web server does not answer at ' . FABREMENT_TEST_WEB . ' (' . $error . '). Start it: composer test:wp:db / test:wpml:db.' );
        }
        return [ 'status' => $status, 'body' => (string) $body, 'headers' => $received ];
    }
}
