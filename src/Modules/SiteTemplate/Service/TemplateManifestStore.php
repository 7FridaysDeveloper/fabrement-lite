<?php

namespace Fabrement\Modules\SiteTemplate\Service;

use Fabrement\Modules\Storage\FabrementStorage;
use Fabrement\Modules\Storage\StorageFiles;

/**
 * Shared source choices: `uploads/fabrement-lite/templates/<key>.json`.
 * Every translation holds the same META_TEMPLATE_KEY, so source changes reach the whole group.
 * Translated blocks and words stay in post_content; assignments stay in their own metadata.
 * This writes JSON configuration only. PHP render files are supplied by the active/parent theme.
 */
final class TemplateManifestStore {

    /** Folder under uploads/fabrement-lite/. */
    private const SUBDIR = 'templates';

    /** A template key: a bare, lowercase path segment. */
    private const KEY_RE = '/^[a-z0-9][a-z0-9-]{0,60}$/D';

    /** What a title that survives no character of sanitize_title() falls back to. */
    private const KEY_FALLBACK = 'template';

    /**
     * Manifests already read THIS REQUEST, `key => manifest|null`. Null is cached too: "there is
     * no file" is an answer worth not re-asking the filesystem for.
     *
     * Per request and no further. One key is shared by every language version of a template, so a
     * page that resolves the original and then hands the reader its version asks for the same file
     * twice — and {@see SiteTemplateRegistry::all()} asks once per version besides. Beyond the
     * request it would be wrong: the same tools that read a manifest also write it, and a cached
     * copy surviving a save is the config screen showing what the site no longer holds.
     *
     * @var array<string,array<string,mixed>|null>
     */
    private static array $cache = [];

    /**
     * Forget what was read this request, so a read after a save sees the save.
     *
     * Called by the writers in THIS class and nowhere else — the registry's flush does not reach
     * here, and does not need to: a manifest only ever changes through save() or delete(), and
     * each of them clears this first. Anything that changes a file another way — a folder restored
     * from a backup, a migration that writes JSON directly — has to call this itself, or the rest
     * of the request keeps answering from what it read before.
     */
    public static function flush(): void {
        self::$cache = [];
    }

    /**
     * Absolute path of the folder — RESOLVED ONLY, nothing is created.
     *
     * Ordinary frontend reads must not create directories.
     */
    private static function dir_path(): string {
        return FabrementStorage::path( self::SUBDIR );
    }

    /**
     * The same folder, PREPARED — it exists and carries its guards. Only writes call this.
     *
     * A manifest is never served: readable by anyone, it would hand out the wiring of every view
     * on the site.
     *
     * @return string|null Null when the folder could not be made writable, so the caller can
     *                     report the FOLDER rather than the first file that fails to land.
     */
    private static function dir(): ?string {
        $dir = self::dir_path();

        return StorageFiles::ensure_dir( $dir ) ? $dir : null;
    }

    /**
     * A key as a path segment, or '' when it is not one.
     *
     * The key arrives from post meta, which an admin can edit in the database and an importer can
     * write unread — so it is checked on the way to a path every single time, not once at the
     * point it was minted.
     */
    private static function valid_key( string $key ): string {
        $key = strtolower( trim( $key ) );

        return preg_match( self::KEY_RE, $key ) === 1 ? $key : '';
    }

    /** Absolute path of one manifest, or null when the key is not a usable segment. */
    private static function file_path( string $key ): ?string {
        $key = self::valid_key( $key );

        return $key === '' ? null : self::dir_path() . '/' . $key . '.json';
    }

    /** Does a manifest exist under this key? */
    public static function exists( string $key ): bool {
        $path = self::file_path( $key );

        return $path !== null && is_file( $path );
    }

    /**
     * The whole manifest, or null when there is none.
     *
     * Missing or corrupt files answer null. The slot then shows the page's own content.
     *
     * @return array<string,mixed>|null
     */
    public static function read( string $key ): ?array {
        if ( array_key_exists( $key, self::$cache ) ) {
            return self::$cache[ $key ];
        }

        $path = self::file_path( $key );

        return self::$cache[ $key ] = $path === null ? null : StorageFiles::read_json( $path );
    }

    /**
     * Just the rules, sanitized to the shape the renderer walks.
     *
     * @return list<array{context:string,file:string}>|null Null when there is no manifest at all —
     *                                                      distinct from a manifest with no rules,
     *                                                      which is an empty list and means the
     *                                                      slot draws the page's own content.
     */
    public static function rules( string $key ): ?array {
        $manifest = self::read( $key );
        if ( $manifest === null ) {
            return null;
        }

        $out = [];
        foreach ( (array) ( $manifest['rules'] ?? [] ) as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $context = is_string( $rule['context'] ?? null ) ? trim( (string) $rule['context'] ) : '';
            $file    = is_string( $rule['file'] ?? null ) ? trim( (string) $rule['file'] ) : '';
            if ( $context !== '' && $file !== '' ) {
                $out[] = [ 'context' => $context, 'file' => $file ];
            }
        }

        return $out;
    }

    /**
     * Write the fields given, keeping the ones that are not.
     *
     * Omitted fields are preserved: renaming a template must not drop its rules.
     *
     * @param array<string,mixed> $fields any of rules, title
     */
    public static function save( string $key, array $fields ): bool {
        $dir = self::dir();
        $path = self::file_path( $key );
        if ( $dir === null || $path === null ) {
            return false;
        }

        $current = self::read( $key ) ?? [];
        $now     = time();

        $pick = static function ( string $field, $fallback ) use ( $fields, $current ) {
            if ( array_key_exists( $field, $fields ) && $fields[ $field ] !== null ) {
                return $fields[ $field ];
            }

            return $current[ $field ] ?? $fallback;
        };

        self::flush();

        return StorageFiles::write_json(
            $path,
            [
                'title'       => trim( (string) $pick( 'title', '' ) ),
                'description' => trim( (string) ( $current['description'] ?? '' ) ),
                'rules'       => array_values( (array) $pick( 'rules', [] ) ),
                'history'     => is_array( $current['history'] ?? null ) ? $current['history'] : [],
                'createdAt'   => (int) ( $current['createdAt'] ?? $now ),
                'updatedAt'   => $now,
            ]
        );
    }

    /** Remove one manifest. Missing is success: the caller wanted it gone. */
    public static function delete( string $key ): bool {
        self::flush();

        $path = self::file_path( $key );
        if ( $path === null ) {
            return false;
        }

        return ! is_file( $path ) || StorageFiles::delete( $path );
    }

    /**
     * A key nothing else holds, derived from the title ONCE.
     *
     * Derived once and never again: re-deriving on rename would move the file while every meta
     * that names it stayed put, which is the failure a slug-keyed binding already taught this
     * codebase ({@see ArchiveTemplateBinding}). "Blog" becomes `blog` today and stays `blog` after
     * somebody renames the template to "Blog (old)".
     *
     * A title with no ASCII in it — Cyrillic, CJK — sanitizes to '' or to a percent-escaped run,
     * neither of which is a path segment, so it falls back to a numbered `template-N`.
     */
    public static function mint_key( string $title ): string {
        $base = self::valid_key( (string) sanitize_title( $title ) );
        if ( $base === '' ) {
            $base = self::KEY_FALLBACK;
        }

        if ( ! self::exists( $base ) ) {
            return $base;
        }

        // Suffix, the way WordPress de-collides a post_name. Bounded: a site with 200 templates
        // called the same thing has a naming problem this loop should not sit in.
        for ( $n = 2; $n <= 200; $n++ ) {
            $candidate = $base . '-' . $n;
            if ( ! self::exists( $candidate ) ) {
                return $candidate;
            }
        }

        return $base . '-' . wp_generate_password( 6, false, false );
    }
}
