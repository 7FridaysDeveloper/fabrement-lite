<?php

namespace Fabrement\Tests\Site\Support;

use RuntimeException;

/**
 * Before a run: the sites' containers are up, the code in them is today's (sync.sh), and each site
 * is installed once (install.sh — its application password is kept in a state file outside the
 * repository). FABREMENT_SITE_REINSTALL=1 installs them again from nothing.
 */
final class SiteSetup {

    private const COMPOSE = __DIR__ . '/../docker-compose.yml';

    public static function state_file(): string {
        return sys_get_temp_dir() . '/' . Site::stack() . '.json';
    }

    /** @return array<string,string> site => application password */
    public static function state(): array {
        $file = self::state_file();
        return is_file( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : [];
    }

    public static function prepare(): void {
        $state     = self::state();
        $reinstall = getenv( 'FABREMENT_SITE_REINSTALL' ) === '1';
        // FABREMENT_SITES=plain (or wpml) prepares one site only — for a run of that suite alone.
        $sites = array_filter( array_map( 'trim', explode( ',', getenv( 'FABREMENT_SITES' ) ?: 'plain,wpml' ) ) );
        foreach ( $sites as $site ) {
            $url       = 'https://example.org:' . Site::port_of( $site );
            $container = Site::stack() . '-' . $site . '-1';
            [ $code, $health ] = Site::run( [ 'docker', 'inspect', '-f', '{{.State.Health.Status}}', $container ] );
            if ( $code !== 0 || trim( $health ) !== 'healthy' ) {
                throw new RuntimeException( "The $site site of " . Site::stack() . " is not running: composer test:site:up (with the same FABREMENT_* variables)" );
            }
            if ( $reinstall || ! isset( $state[ $site ] ) || ! self::snapshot_exists( $container ) ) {
                fwrite( STDERR, "Installing the $site site (once; FABREMENT_SITE_REINSTALL=1 does it again)…\n" );
                $lines          = self::run( $container, 'sh', '/var/www/setup/install.sh', $site, $url );
                $state[ $site ] = trim( (string) end( $lines ) );
            } else {
                self::run( $container, 'sh', '/var/www/setup/sync.sh' );
            }
        }
        file_put_contents( self::state_file(), json_encode( $state ) );
    }

    private static function snapshot_exists( string $container ): bool {
        return Site::run( [ 'docker', 'exec', $container, 'test', '-f', '/var/www/snapshot/database.sql' ] )[0] === 0;
    }

    /** @return string[] The command's output lines. */
    private static function run( string $container, string ...$command ): array {
        [ $code, $text ] = Site::run( [ 'docker', 'exec', $container, ...$command ] );
        $out             = explode( "\n", $text );
        if ( $code !== 0 ) {
            throw new RuntimeException( "In $container: " . implode( ' ', $command ) . "\n" . $text );
        }
        return $out;
    }
}
