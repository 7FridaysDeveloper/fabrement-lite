<?php

namespace Fabrement\Tests;

/** Minimal database boundary for OAuth readiness and insert-only activation defaults. */
final class OAuthDatabase {
    public string $prefix = 'wp_test_';
    public string $options = 'wp_test_options';
    public bool $table_exists = true;
    public bool $fail_writes = false;
    public ?int $lock_result = 1;
    public ?\Closure $on_lock = null;
    public array $queries = [];
    private array $arguments = [];

    public function prepare( string $query, ...$arguments ): string {
        $this->arguments = $arguments;
        return $query;
    }

    public function esc_like( string $value ): string { return $value; }

    public function get_var( string $query ) {
        $this->queries[] = $query;
        if ( str_starts_with( $query, 'SELECT GET_LOCK' ) ) {
            if ( $this->on_lock ) { ( $this->on_lock )(); }
            return $this->lock_result;
        }
        if ( str_starts_with( $query, 'SELECT RELEASE_LOCK' ) ) { return 1; }
        if ( str_starts_with( $query, 'SHOW TABLES LIKE' ) ) {
            return $this->table_exists ? $this->prefix . 'fabrement_mcp_oauth' : null;
        }
        throw new \RuntimeException( 'Unexpected OAuth query: ' . $query );
    }

    public function query( string $query ) {
        $this->queries[] = $query;
        if ( ! str_starts_with( $query, 'INSERT IGNORE INTO ' . $this->options ) ) {
            throw new \RuntimeException( 'Unexpected OAuth write: ' . $query );
        }
        if ( $this->fail_writes ) { return false; }
        [ $name, $value ] = $this->arguments;
        if ( array_key_exists( $name, WordPressState::$options ) ) { return 0; }
        WordPressState::$options[ $name ] = $value;
        return 1;
    }

    public function get_results( string $query, $output = null ): array {
        $this->queries[] = $query;
        if ( str_starts_with( $query, 'SELECT family, payload, expires' ) ) { return []; }
        throw new \RuntimeException( 'Unexpected OAuth query: ' . $query );
    }
}
