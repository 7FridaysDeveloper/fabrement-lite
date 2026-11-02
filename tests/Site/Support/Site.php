<?php

namespace Fabrement\Tests\Site\Support;

use RuntimeException;

/**
 * One of the two test sites (tests/Site/docker-compose.yml) seen from outside: where it answers,
 * its container, its database, the administrator's application password and OAuth access tokens
 * the MCP server takes. Nothing of WordPress is
 * loaded in the test process — everything goes through what a real client has: HTTP, the database,
 * and the site's own WP-CLI.
 */
final class Site {

    private const SITES = [
        'plain' => [ 'port' => 19443, 'env' => 'FABREMENT_PLAIN_PORT', 'db' => 'site_plain' ],
        'wpml'  => [ 'port' => 19444, 'env' => 'FABREMENT_WPML_PORT', 'db' => 'site_wpml' ],
    ];

    /** Which copy of the sites (tests/Site/docker-compose.yml, FABREMENT_STACK). */
    public static function stack(): string {
        return getenv( 'FABREMENT_STACK' ) ?: 'fabrement-lite-site-tests';
    }

    public static function port_of( string $name ): int {
        return (int) ( getenv( self::SITES[ $name ]['env'] ) ?: self::SITES[ $name ]['port'] );
    }

    public static function mysql_port(): int {
        return (int) ( getenv( 'FABREMENT_MYSQL_PORT' ) ?: 14308 );
    }

    /** @var array<string,self> */
    private static array $known = [];

    private ?\mysqli $db = null;

    /** @var array<string,array{0:string,1:int}> login => [ access token, when it was made ] */
    private array $tokens = [];

    private function __construct( public readonly string $name, public readonly string $password ) {}

    public static function get( string $name ): self {
        if ( ! isset( self::$known[ $name ] ) ) {
            $state = SiteSetup::state();
            if ( ! isset( $state[ $name ] ) ) {
                throw new RuntimeException( "The $name site is not installed: run composer test:site." );
            }
            self::$known[ $name ] = new self( $name, $state[ $name ] );
        }
        return self::$known[ $name ];
    }

    public function port(): int {
        return self::port_of( $this->name );
    }

    /** The site's address: https://example.org:<port>, plus $path. */
    public function url( string $path = '/' ): string {
        return 'https://example.org:' . $this->port() . '/' . ltrim( $path, '/' );
    }

    public function container(): string {
        return self::stack() . '-' . $this->name . '-1';
    }

    /** Run a command in the site's container; its output, or an exception with it. */
    public function exec( string ...$command ): string {
        [ $code, $text ] = self::run( [ 'docker', 'exec', $this->container(), ...$command ] );
        if ( $code !== 0 ) {
            throw new RuntimeException( "In {$this->name}: " . implode( ' ', $command ) . "
" . $text );
        }
        return $text;
    }

    /**
     * A command run without a shell (no quoting to get wrong on Windows).
     *
     * @param string[] $command
     * @return array{0:int,1:string} Exit code, output (stdout and stderr).
     */
    public static function run( array $command ): array {
        $process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'redirect', 1 ] ], $pipes );
        if ( ! is_resource( $process ) ) {
            throw new RuntimeException( 'Could not run ' . implode( ' ', $command ) );
        }
        $out = (string) stream_get_contents( $pipes[1] );
        fclose( $pipes[1] );
        return [ proc_close( $process ), rtrim( $out ) ];
    }

    /** Put a file into the site, owned by the web server's user. */
    public function write( string $path, string $content ): void {
        $local = tempnam( sys_get_temp_dir(), 'fab' );
        file_put_contents( $local, $content );
        try {
            [ $code, $out ] = self::run( [ 'docker', 'cp', $local, $this->container() . ':' . $path ] );
            if ( $code !== 0 ) {
                throw new RuntimeException( "docker cp to $path: $out" );
            }
        } finally {
            unlink( $local );
        }
        $this->exec( 'chown', 'www-data:www-data', $path );
    }

    /** WP-CLI on the site, as its web server's user: what a cron job, an import or `wp eval` does. */
    public function wp( string ...$args ): string {
        return $this->exec( 'runuser', '-u', 'www-data', '--', 'wp', '--path=/var/www/html', ...$args );
    }

    /** PHP run inside the site (WordPress loaded, no user signed in) — the site's own code. */
    public function php( string $code ): string {
        $this->write( '/tmp/fabrement-eval.php', "<?php
" . $code . "
" );
        return $this->wp( 'eval-file', '/tmp/fabrement-eval.php' );
    }

    /** Back to the state right after install. */
    public function restore(): void {
        $this->exec( 'sh', '/var/www/setup/snapshot.sh', 'restore' );
        // Their rows are gone with the database the snapshot replaced.
        $this->tokens = [];
    }

    /**
     * An OAuth access token for $login, what the MCP server takes: a client registered and the code
     * exchanged on the site, by the plugin's own OAuth code. A user who may not connect gets the rows
     * an approval would have written, as for an Administrator demoted after approving. Made once per
     * user until the next restore(); an access token lasts an hour.
     */
    public function access_token( string $login = 'admin' ): string {
        [ $token, $made ] = $this->tokens[ $login ] ?? [ '', 0 ];
        if ( $token !== '' && $made > time() - 45 * 60 ) {
            return $token;
        }
        $out = $this->php( strtr( <<<'PHP'
use Fabrement\Modules\Mcp\OAuth\{Clients, Config, Protocol, Store};
$store    = new Store();
$protocol = new Protocol( $store );
$callback = 'http://127.0.0.1/callback/fabrement-site-tests';
$client   = ( new Clients( $store ) )->register( [ 'client_name' => 'Fabrement site tests', 'redirect_uris' => [ $callback ], 'token_endpoint_auth_method' => 'none' ] );
$verifier = bin2hex( random_bytes( 32 ) );
$asked    = $protocol->authorization( [ 'client_id' => $client['client_id'], 'redirect_uri' => $callback, 'response_type' => 'code', 'resource' => Config::resource(),
    'code_challenge_method' => 'S256', 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ), 'state' => '' ] );
$user = get_user_by( 'login', LOGIN );
if ( \Fabrement\Capabilities::can_connect( $user ) ) {
    $code   = $protocol->approve( $asked, $user->ID );
    $access = $protocol->token( [ 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $callback,
        'client_id' => $client['client_id'], 'resource' => Config::resource() ] )['access_token'];
} else {
    $family = bin2hex( random_bytes( 32 ) );
    $store->put( 'grant', $family, $asked + [ 'user_id' => $user->ID, 'created' => time() ], time() + Config::IDLE_EXPIRY, $family, $user->ID );
    $access = Config::ACCESS_PREFIX . bin2hex( random_bytes( 32 ) );
    $store->put( 'access', $access, [ 'client_id' => $client['client_id'] ], time() + 3600, $family, $user->ID );
}
echo 'TOKEN=' . $access;
PHP, [ 'LOGIN' => var_export( $login, true ) ] ) );
        if ( ! preg_match( '/TOKEN=(\S+)/', $out, $match ) ) {
            throw new RuntimeException( "No access token for $login on the {$this->name} site:\n$out" );
        }
        $this->tokens[ $login ] = [ $match[1], time() ];
        return $match[1];
    }

    /** What WordPress wrote to wp-content/debug.log since the last restore ('' = nothing). */
    public function debug_log(): string {
        return $this->exec( 'sh', '-c', 'cat /var/www/html/wp-content/debug.log 2>/dev/null || true' );
    }

    /** The site's database, read straight, as it is now. */
    public function db(): \mysqli {
        if ( ! $this->db ) {
            $this->db = new \mysqli( '127.0.0.1', 'root', 'root', self::SITES[ $this->name ]['db'], self::mysql_port() );
            $this->db->set_charset( 'utf8mb4' );
        }
        return $this->db;
    }

    /** @return list<array<string,?string>> */
    public function rows( string $sql, array $params = [] ): array {
        $statement = $this->db()->prepare( $sql );
        if ( $params ) {
            $statement->bind_param( str_repeat( 's', count( $params ) ), ...array_map( 'strval', $params ) );
        }
        $statement->execute();
        $result = $statement->get_result();
        return $result ? $result->fetch_all( MYSQLI_ASSOC ) : [];
    }

    public function value( string $sql, array $params = [] ): ?string {
        $row = $this->rows( $sql, $params )[0] ?? null;
        return $row === null ? null : array_values( $row )[0];
    }

    /** @return list<string> All rows of a post's meta key, in the order they were written. */
    public function meta( int $post, string $key ): array {
        return array_column( $this->rows( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key = ? ORDER BY meta_id', [ $post, $key ] ), 'meta_value' );
    }

    /** An option as WordPress stores it, unserialized; null when the row does not exist. */
    public function option( string $name ) {
        $raw = $this->value( 'SELECT option_value FROM wp_options WHERE option_name = ?', [ $name ] );
        if ( $raw === null ) {
            return null;
        }
        $value = @unserialize( $raw, [ 'allowed_classes' => false ] );
        return $value === false && $raw !== 'b:0;' ? $raw : $value;
    }
}
