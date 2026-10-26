<?php

namespace Fabrement\Tests\Site\Support;

use RuntimeException;

/**
 * HTTPS to a test site, as a browser or an AI client sends it: example.org resolved to the site's
 * container (no hosts file), its self-signed certificate accepted.
 */
final class Http {

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    public static function request( Site $site, string $method, string $url, array $headers = [], ?string $body = null ): array {
        $curl = curl_init( $url );
        $sent = [];
        foreach ( $headers as $name => $value ) {
            $sent[] = $name . ': ' . $value;
        }
        $received = [];
        curl_setopt_array( $curl, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $sent,
            CURLOPT_RESOLVE        => [ 'example.org:' . $site->port() . ':127.0.0.1' ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => 120,
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
        $error    = curl_error( $curl );
        curl_close( $curl );
        if ( $response === false || $status === 0 ) {
            throw new RuntimeException( "$method $url: no answer ($error). Is the site up? composer test:site:up" );
        }
        return [ 'status' => $status, 'body' => (string) $response, 'headers' => $received ];
    }
}
