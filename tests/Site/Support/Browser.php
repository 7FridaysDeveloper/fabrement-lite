<?php

namespace Fabrement\Tests\Site\Support;

use RuntimeException;

/**
 * A person at a browser, without the browser: cookies kept between requests, wp-login.php to sign
 * in, forms sent as a browser sends them, redirects NOT followed (a test reads where they go).
 * For the steps a person takes — approving an OAuth connection — not for testing what a screen looks like.
 */
final class Browser {

    private string $cookies;

    public function __construct( private Site $site ) {
        $this->cookies = (string) tempnam( sys_get_temp_dir(), 'fabcookies' );
    }

    public function __destruct() {
        @unlink( $this->cookies );
    }

    public function login( string $user, string $password ): void {
        $this->get( $this->site->url( '/wp-login.php' ) );   // WordPress's test cookie
        $answer = $this->post( $this->site->url( '/wp-login.php' ), [ 'log' => $user, 'pwd' => $password, 'wp-submit' => 'Log In', 'testcookie' => '1' ] );
        if ( $answer['status'] !== 302 ) {
            throw new RuntimeException( "Could not sign in as $user (status {$answer['status']})." );
        }
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function get( string $url ): array {
        return $this->send( 'GET', $url, null );
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function post( string $url, array $fields ): array {
        return $this->send( 'POST', $url, http_build_query( $fields ) );
    }

    /** @return array<string,string> The named inputs of the first form in $html, as a browser would send them. */
    public static function form( string $html ): array {
        preg_match( '#<form.*?</form>#s', $html, $form );
        preg_match_all( '#<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"#', $form[0] ?? '', $m, PREG_SET_ORDER );
        $fields = [];
        foreach ( $m as $input ) {
            $fields[ html_entity_decode( $input[1] ) ] = html_entity_decode( $input[2] );
        }
        return $fields;
    }

    private function send( string $method, string $url, ?string $body ): array {
        $parts = parse_url( $url );
        $curl  = curl_init( $url );
        $received = [];
        curl_setopt_array( $curl, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE     => $this->cookies,
            CURLOPT_COOKIEJAR      => $this->cookies,
            CURLOPT_RESOLVE        => [ ( $parts['host'] ?? 'example.org' ) . ':' . ( $parts['port'] ?? 443 ) . ':127.0.0.1' ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $body !== null ? [ 'Content-Type: application/x-www-form-urlencoded' ] : [],
            CURLOPT_HEADERFUNCTION => static function ( $handle, string $line ) use ( &$received ): int {
                if ( strpos( $line, ':' ) !== false ) {
                    [ $name, $value ]                       = explode( ':', $line, 2 );
                    $received[ strtolower( trim( $name ) ) ] = trim( $value );
                }
                return strlen( $line );
            },
        ] );
        if ( $body !== null ) {
            curl_setopt( $curl, CURLOPT_POSTFIELDS, $body );
        }
        $response = curl_exec( $curl );
        $status   = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        curl_close( $curl );
        // Cookies are written to the jar when the handle closes; curl keeps them in memory meanwhile.
        return [ 'status' => $status, 'body' => (string) $response, 'headers' => $received ];
    }
}
