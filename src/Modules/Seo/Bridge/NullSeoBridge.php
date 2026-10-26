<?php

namespace Fabrement\Modules\Seo\Bridge;

use Fabrement\Modules\Seo\Contract\SeoBridge;

/**
 * A site with no supported SEO plugin: nothing is stored and nothing is read. The SEO tools refuse
 * to write on it (is_active() is false), so these answers are what a read sees.
 */
final class NullSeoBridge implements SeoBridge {

    public function is_active(): bool {
        return false;
    }

    public function version(): string {
        return '';
    }

    public function term_fields(): array {
        return [];
    }

    public function page_types(): array {
        return [];
    }

    public function article_types(): array {
        return [];
    }

    public function robots_advanced(): array {
        return [];
    }

    public function separators(): array {
        return [];
    }

    public function knowledge_graph(): ?array {
        return null;
    }

    public function has_variables( string $text ): bool {
        return false;
    }

    public function default_fields( string $scope ): array {
        return [];
    }

    public function translatable_fields( string $scope ): array {
        return [];
    }

    public function read_post( int $post_id ): array {
        return [];
    }

    public function write_post( int $post_id, array $fields ): array {
        return [];
    }

    public function effective_post( int $post_id, bool $with_schema = true ): array {
        return [];
    }

    public function reindex_post( int $post_id ): bool {
        return false;
    }

    public function post_listing( array $post_types ): array {
        return [];
    }

    public function schema_values( int $post_id, $context = null ): array {
        return [];
    }

    public function is_own_schema_id( string $id, string $page, string $site ): bool {
        return false;
    }

    public function article_supported( string $post_type ): bool {
        return false;
    }

    public function primary_taxonomies( string $post_type ): array {
        return [];
    }

    public function breadcrumb_taxonomy( string $post_type ): string {
        return '';
    }

    public function not_kept_post( string $post_type, array $fields ): array {
        return [];
    }

    public function not_kept_term( \WP_Term $term, array $fields ): array {
        return [];
    }

    public function not_kept_defaults( array $fields ): array {
        return [];
    }

    public function primary_term( int $post_id, string $taxonomy ): int {
        return 0;
    }

    public function set_primary_term( int $post_id, string $taxonomy, int $term_id ): void {
    }

    public function rebuild_primary_terms( int $post_id ): void {
    }

    public function terms_supported(): bool {
        return false;
    }

    public function read_term( \WP_Term $term ): array {
        return [];
    }

    public function write_term( \WP_Term $term, array $fields ): array {
        return [];
    }

    public function effective_term( int $term_id ): array {
        return [];
    }

    public function term_listing( array $terms ): array {
        return [];
    }

    public function read_defaults( string $scope, string $slug = '' ): array {
        return [];
    }

    public function write_defaults( string $scope, string $slug, array $fields ): array {
        return [];
    }

    public function read_defaults_translations( string $scope, string $slug = '' ): array {
        return [];
    }

    public function write_defaults_translation( string $scope, string $slug, string $lang, array $fields ): array {
        return [ 'written' => [], 'emptyOriginal' => [] ];
    }

    public function read_archive_defaults( string $post_type ): array {
        return [];
    }

    public function write_archive_defaults( string $post_type, array $fields ): array {
        return [];
    }
}
