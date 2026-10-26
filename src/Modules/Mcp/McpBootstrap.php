<?php

namespace Fabrement\Modules\Mcp;

use Fabrement\Framework\Keys;
use Fabrement\Framework\App;

/**
 * Entry point of the MCP module. Feature-gated: the whole module is inert on
 * sites that cannot serve it (no Abilities API, or an incompatible adapter).
 *
 * Wiring:
 *  - `wp_abilities_api_categories_init` → category (must precede abilities)
 *  - `wp_abilities_api_init`            → Roster abilities
 *  - `mcp_adapter_init`                 → the Fabrement custom server
 */
final class McpBootstrap {

    public static function register(): void {
        OAuth\Bootstrap::register();
        // On every request: an MCP application password is refused outside the MCP route.
        McpPasswordScope::register();

        // Only a request to the Fabrement MCP route (or WP-CLI) registers the tools. The adapter
        // otherwise initialises on EVERY REST request and, creating our server, builds every tool
        // with its description and schemas — work nothing but this route ever uses.
        $cli = defined( 'WP_CLI' ) && WP_CLI;
        if ( ! $cli && ! self::is_mcp_request() ) {
            return;
        }
        // WP-CLI: the tools are abilities, which need no adapter. The adapter is looked for only when
        // `wp mcp-adapter …` runs or the code asks for the REST server: a command that loads another
        // plugin later (`wp plugin activate woocommerce`) would otherwise meet Lite's copy already
        // loaded beside that plugin's own, and the two copies' classes do not mix.
        if ( $cli && ! self::is_mcp_cli() ) {
            if ( function_exists( 'wp_register_ability' ) && App::has_container() ) {
                self::register_abilities();
                self::register_server();
                add_action( 'rest_api_init', [ self::class, 'boot_adapter_in_cli' ], 0 );
            }
            return;
        }

        if ( ! self::is_supported() ) {
            return;
        }

        BearerApplicationPasswordAuth::register();
        self::register_abilities();
        self::register_server();
        self::boot_adapter();
    }

    /** The Fabrement server, created when the adapter initialises. */
    private static function register_server(): void {
        add_action(
            'mcp_adapter_init',
            static function ( $adapter ): void {
                // WP-CLI hooks this before any adapter was looked at: check the one that fired.
                if ( ! self::is_supported() ) {
                    return;
                }
                App::container()[ ServerDefinition::class ]->register( $adapter );
            }
        );
    }

    /** Boot the adapter; a second call is a no-op. A copy another plugin loaded must not take the site down. */
    private static function boot_adapter(): ?\WP\MCP\Core\McpAdapter {
        try {
            return \WP\MCP\Core\McpAdapter::instance();
        } catch ( \Throwable $e ) {
            \Fabrement\Framework\Log::debug( '[fabrement][mcp] adapter boot failed: ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * WP-CLI code reaching the REST server (rest_do_request()…) gets the MCP route too. In WP-CLI the
     * adapter initialises on `init`, which has passed, so it is initialised here (a no-op once done).
     */
    public static function boot_adapter_in_cli(): void {
        if ( ! self::is_supported() ) {
            return;
        }
        $adapter = self::boot_adapter();
        if ( $adapter === null ) {
            return;
        }
        try {
            $adapter->init();
        } catch ( \Throwable $e ) {
            \Fabrement\Framework\Log::debug( '[fabrement][mcp] adapter init failed: ' . $e->getMessage() );
        }
    }

    private static function register_abilities(): void {
        add_action(
            'wp_abilities_api_categories_init',
            static function (): void {
                App::container()[ Registrar::class ]->register_category();
            }
        );

        add_action(
            'wp_abilities_api_init',
            static function (): void {
                App::container()[ Registrar::class ]->register_abilities();
            }
        );
    }

    /** A WP-CLI run of the adapter's own commands (`wp mcp-adapter serve`…). */
    private static function is_mcp_cli(): bool {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return false;
        }
        // The command itself, not the word anywhere: `wp plugin activate mcp-adapter` is not one.
        $args = class_exists( \WP_CLI::class ) ? (array) \WP_CLI::get_runner()->arguments : [];
        return ( $args[0] ?? '' ) === 'mcp-adapter';
    }

    /**
     * Is this request addressed to the Fabrement MCP route? Read from the raw request, because it
     * is asked on `plugins_loaded`, before WordPress has parsed the request: `?rest_route=` for
     * plain permalinks, the path under the REST prefix otherwise.
     */
    public static function is_mcp_request(): bool {
        $route = ServerDefinition::ROUTE_NAMESPACE . '/' . ServerDefinition::ROUTE;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route detection; nothing is changed.
        $rest_route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] )
            ? trim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; nothing is changed.
            : '';
        // WordPress runs the ?rest_route= route whatever the path says: when it is there, it alone
        // decides — /wp-json/fabrement/v1/mcp?rest_route=/other/route is not an MCP call.
        if ( $rest_route !== '' ) {
            return $rest_route === $route;
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
            ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
            : '';

        return str_ends_with( rtrim( rawurldecode( $uri ), '/' ), '/' . rest_get_url_prefix() . '/' . $route );
    }

    /** What this module calls on the adapter: checked as such, not by its version number. */
    private const ADAPTER_NEEDS = [
        'WP\MCP\Transport\HttpTransport',
        'WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface',
        'WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler',
    ];

    /**
     * WP 6.9+ (Abilities API in core) + an adapter (another plugin's, else Lite's own,
     * {@see BundledAdapter}) that has what this module calls + a booted
     * plugin container.
     */
    public static function is_supported(): bool {
        BundledAdapter::ensure();
        return function_exists( 'wp_register_ability' )
            && class_exists( \WP\MCP\Core\McpAdapter::class )
            && self::adapter_is_usable()
            && App::has_container();
    }

    /**
     * The adapter in memory may be another plugin's copy, of any version: the MCP Adapter plugin's,
     * or an older one WooCommerce or Elementor bundles. Its VERSION is no promise about
     * its API (WooCommerce's 0.3.0 says 0.1.0), so what this module calls is checked instead;
     * without it MCP stays off and the rest of the plugin works.
     */
    private static function adapter_is_usable(): bool {
        $missing = [];
        foreach ( [ 'instance', 'create_server' ] as $method ) {
            if ( ! method_exists( \WP\MCP\Core\McpAdapter::class, $method ) ) {
                $missing[] = 'McpAdapter::' . $method . '()';
            }
        }
        foreach ( self::ADAPTER_NEEDS as $class ) {
            if ( ! class_exists( $class ) && ! interface_exists( $class ) ) {
                $missing[] = $class;
            }
        }
        if ( $missing === [] ) {
            return true;
        }

        $message = sprintf(
            '[fabrement][mcp] MCP disabled: the MCP adapter loaded from %s has no %s.',
            (string) ( new \ReflectionClass( \WP\MCP\Core\McpAdapter::class ) )->getFileName(),
            implode( ', ', $missing )
        );

        // This runs on every request; the log gets the line once a day per distinct cause, not
        // once per hit.
        $seen = Keys::mcp_adapter_notice( $message );
        if ( get_transient( $seen ) === false ) {
            \Fabrement\Framework\Log::debug( $message );
            set_transient( $seen, 1, DAY_IN_SECONDS );
        }

        return false;
    }
}
