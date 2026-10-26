<?php

namespace Fabrement\Modules\Translation\Bridge;

use Fabrement\Modules\Translation\Contract\TranslationBridge;

/**
 * The site has no translation plugin — so there is one language, and everything already is in it.
 *
 * This exists so that the ordinary site is an OBJECT rather than a condition. Without it every
 * caller would carry an `if` that is false on almost every install, and each of those is a place
 * for the two paths to drift: the guarded one gets fixed, the unguarded one does not.
 *
 * Every answer here is the identity: an id is its own counterpart, a permalink is just the
 * permalink, there are no languages to choose between, and there is nobody to hand declarations
 * to. A caller cannot tell it apart from a translation plugin that has one language configured,
 * which is correct — for its purposes there is no difference.
 */
final class NullBridge implements TranslationBridge {

    public function is_active(): bool {
        return false;
    }

    public function languages(): array {
        return [];
    }

    public function switcher( bool $translated_only ): array {
        return [];
    }

    public function current_language(): string {
        return '';
    }

    public function in_language( string $lang, callable $fn ) {
        return $fn();
    }

    public function in_locale( string $lang, callable $fn ) {
        return $fn();
    }

    public function default_language(): string {
        return '';
    }

    public function version_in( int $object_id, string $type, string $lang = '' ): int {
        return $object_id;
    }

    public function original_of( int $post_id, string $type ): int {
        return $post_id;
    }

    public function home_url( string $lang ): string {
        return home_url( '/' );
    }

    public function address_in( string $url, string $lang ): string {
        return $url;
    }

    public function permalink( int $post_id, string $lang = '' ): string {
        $permalink = get_permalink( $post_id );

        return is_string( $permalink ) ? $permalink : '';
    }

    public function all_languages_args( array $args ): array {
        return $args;
    }

    public function permalinks_of_many( array $post_ids_to_lang ): array {
        // No languages to switch between: each id is simply its own address.
        $out = [];
        foreach ( array_keys( $post_ids_to_lang ) as $id ) {
            $out[ (int) $id ] = (string) get_permalink( (int) $id );
        }
        return $out;
    }

    public function version_facts( array $post_ids, string $type ): array {
        return [];
    }

    public function originals_of_many( array $post_ids, string $type ): array {
        return [];
    }

    public function originals_of_any( array $post_ids ): array {
        $out = [];
        foreach ( $post_ids as $id ) {
            if ( (int) $id > 0 ) {
                $out[ (int) $id ] = (int) $id;
            }
        }
        return $out;
    }

    public function visible_versions( int $post_id, string $type ): array {
        return [];
    }

    public function all_versions( int $post_id, string $type ): array {
        return [];
    }

    public function load_versions( array $post_ids ): void {
    }

    public function writes_translations_manually( int $post_id ): bool {
        return true;
    }

    public function original_changed( int $post_id ): void {
        // No translations to mark outdated.
    }

    public function register_field_hooks(): void {
        // No provider lifecycle to register.
    }

    public function pending_original( int $post_id ): int {
        return 0;
    }

    public function duplicate_of( int $post_id ): int {
        return 0;
    }

    public function is_original( int $post_id, string $type ): bool {
        return true;
    }

    public function set_language( int $post_id, string $lang, string $type ): void {
        // One language; a record is already in it.
    }

    public function link_translation( int $post_id, int $original_id, string $lang, string $type ): void {
        // Nothing to link to.
    }

    public function translation_screen_url( int $post_id ): string {
        // No plugin, no screen — and an empty string is what the callers read as "say nothing".
        return '';
    }

    public function translation_mode( string $kind, string $name ): ?string {
        return null; // One language: the question does not exist.
    }

    public function is_post_type_translatable( string $post_type ): ?bool {
        return null;  // One language: the question does not exist, and "false" would answer it.
    }

    public function forget_term_link( int $term_id, string $taxonomy ): void {
        // Nothing filters term links here, so core's own cache clearing is the whole story.
    }

    public function translate_option( string $option, string $key, string $original, string $lang, string $value ): bool {
        return false;
    }

    public function option_translations( string $option, string $key, string $original ): array {
        return [];
    }

    public function term_language( int $term_id, string $taxonomy ): string {
        return '';
    }

    /** One language: every term is its own original. */
    public function original_term_id( int $term_id, string $taxonomy ): int {
        return $term_id;
    }

    public function translate_term( int $term_id, string $taxonomy, string $lang, array $fields ): int {
        return 0;
    }

    public function forget_languages(): void {
    }

    /** One language: nothing to translate strings into. */
    public function supports_strings(): bool {
        return false;
    }

    public function without_language( string $post_type ): array {
        return [];
    }

}
