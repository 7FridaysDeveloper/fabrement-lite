<?php

namespace Fabrement\Modules\ContentSource;

/**
 * The set of PHP files the Content Source block may render — every `.php` under
 * the active + parent theme's `templates/` folder, path RELATIVE to that folder.
 * The editor picker, list-theme-templates and the template writer share this
 * resolver. No source-code writing or generated-bundle loading is provided.
 *
 * KIND — which view shape a file fills. A singular body assumes the queried post
 * is already set up (canvas.php ran the_post()); an archive body runs the main
 * loop itself. The two are not interchangeable: a singular body on an archive
 * renders one arbitrary post, an archive body on a singular renders nothing.
 * A file DECLARES its kind with a WordPress-style file header, read through
 * get_file_data() exactly like core reads `Template Name:`:
 *
 *     <?php
 *     /**
 *      * Fabrement: archive
 *      *\/
 *
 * Accepted values are listed in {@see RenderKindHeader}. A file with no header (or an
 * unrecognised value) is UNCLASSIFIED, not "singular": the header is opt-in, and
 * refusing an undeclared file would break every theme body that works today.
 * Unclassified files stay selectable for every view — the admin, who can read the
 * file, carries the judgement the scanner cannot.
 */
final class ThemeTemplateFiles {

    /** The only theme sub-folder a render file may live in (relative to the theme root). */
    public const TEMPLATES_DIR = 'templates';

    private const MAX_DEPTH = 4;
    private const MAX_FILES = 400;

    /**
     * Per-request memo. all() now opens every file to read its header, and it is
     * called repeatedly in one request (the picker route and the block resolver
     * both ask). Themes cannot change mid-request, so one
     * scan per request is both correct and enough.
     *
     * @var list<array{path:string,label:string,theme:string,kind:?string}>|null
     */
    private static ?array $cache = null;

    /**
     * Per-request memo for SINGLE header lookups (declares_of), kept apart from $cache:
     * the render path asks about one file and must not pay for scanning the rest.
     * all() fills it as it goes, so a later lookup after a full scan is free.
     *
     * @var array<string,?string>
     */
    private static array $declared = [];

    /**
     * Every renderable file: { path (relative to templates/), label, theme, kind }.
     * A child theme's file shadows a parent file at the same path. `kind` is
     * "singular", "archive", or null when the file declares nothing.
     *
     * @return list<array{path:string,label:string,theme:string,kind:?string}>
     */
    public static function all(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }

        $files = [];
        $seen  = [];

        foreach ( array_unique( [ get_stylesheet_directory(), get_template_directory() ] ) as $theme_root ) {
            $root = $theme_root . '/' . self::TEMPLATES_DIR;
            $theme_real = realpath( $theme_root );
            $root_real = realpath( $root );
            if ( $theme_real === false || $root_real === false || strpos( $root_real, $theme_real . DIRECTORY_SEPARATOR ) !== 0 ) {
                continue;
            }
            $theme = wp_basename( $theme_root );
            foreach ( self::scan( $root, '', 0 ) as $rel ) {
                $resolved = self::resolve( $rel );
                if ( $resolved === null ) { continue; }
                if ( isset( $seen[ $rel ] ) ) {
                    continue; // child theme shadows the parent's same-path file.
                }
                $seen[ $rel ] = true;
                // `declares` is what the file itself says — a bare kind ("archive") or an
                // exact view ("archive:product"). `kind` is the coarse shape derived from
                // it, kept alongside because that is the question the renderer asks.
                $declares     = RenderKindHeader::from_file( $resolved );
                $files[]      = [
                    'path'     => $rel,
                    'label'    => $rel,
                    'theme'    => $theme,
                    'declares' => $declares,
                    'kind'     => $declares === null ? null : RenderKindHeader::kind_of( $declares ),
                ];
                self::$declared[ $rel ] = $declares;
                if ( count( $files ) >= self::MAX_FILES ) {
                    break 2;
                }
            }
        }

        usort( $files, static fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );
        self::$cache = $files;
        return $files;
    }

    /**
     * What $rel DECLARES — a bare kind ("archive") or an exact view
     * ("archive:product") — or null when it declares nothing, or is not a theme file
     * at all. Null means "cannot tell", never "singular"; see the class docblock for
     * why that distinction is load-bearing.
     */
    public static function declares_of( string $rel ): ?string {
        $key = self::normalise_rel( $rel );
        if ( $key === '' ) {
            return null;
        }
        // Resolve the ONE file rather than asking all(). This is called from the render
        // path (the slot checks a rule's file against the current view), and all() opens
        // every .php under templates/ to read its header — up to MAX_FILES of them. That
        // cost belongs to the picker, which genuinely needs the whole list; a single
        // lookup needs a single file.
        if ( ! array_key_exists( $key, self::$declared ) ) {
            $full                   = self::resolve( $key );
            self::$declared[ $key ] = $full === null ? null : RenderKindHeader::from_file( $full );
        }
        return self::$declared[ $key ];
    }

    /**
     * Absolute path of a theme render file, or null when $rel is unsafe or absent.
     *
     * Same guarantee the scan gives, for one path: it must be a .php sitting genuinely
     * inside some theme's templates/ folder. realpath() is what makes that true rather
     * than merely likely — traversal, symlinks and encoded escapes all collapse before
     * the prefix test. The active theme shadows the parent, matching all()'s ordering.
     */
    public static function resolve( string $rel ): ?string {
        $rel = self::normalise_rel( $rel );
        if ( $rel === '' || strpos( $rel, '..' ) !== false || strpos( $rel, ':' ) !== false || strpos( $rel, '%' ) !== false || substr( strtolower( $rel ), -4 ) !== '.php' ) {
            return null;
        }
        foreach ( array_unique( [ get_stylesheet_directory(), get_template_directory() ] ) as $theme ) {
            $root      = $theme . '/' . self::TEMPLATES_DIR;
            $root_real = realpath( $root );
            $theme_real = realpath( $theme );
            if ( $root_real === false || $theme_real === false || strpos( $root_real, $theme_real . DIRECTORY_SEPARATOR ) !== 0 ) {
                continue;
            }
            $real = realpath( $root . '/' . $rel );
            if ( $real !== false && is_file( $real ) && strpos( $real, $root_real . DIRECTORY_SEPARATOR ) === 0 ) {
                return $real;
            }
        }
        return null;
    }

    private static function normalise_rel( string $rel ): string {
        $rel = str_replace( '\\', '/', trim( $rel ) );
        return str_starts_with( $rel, '/' ) ? '' : $rel;
    }

    /**
     * Recursively collects .php paths RELATIVE to the templates/ root.
     *
     * @return list<string>
     */
    private static function scan( string $root, string $prefix, int $depth ): array {
        if ( $depth > self::MAX_DEPTH ) {
            return [];
        }
        $dir = $prefix === '' ? $root : $root . '/' . $prefix;
        $out = [];

        $real = realpath( $dir );
        $root_real = realpath( $root );
        if ( $real === false || $root_real === false || ( $real !== $root_real && strpos( $real, $root_real . DIRECTORY_SEPARATOR ) !== 0 ) ) { return []; }

        if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
            return [];
        }
        $entries = scandir( $dir );
        if ( $entries === false ) {
            return [];
        }

        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' || $entry[0] === '.' ) {
                continue;
            }
            $rel  = $prefix === '' ? $entry : $prefix . '/' . $entry;
            $full = $root . '/' . $rel;

            if ( is_dir( $full ) ) {
                $out = array_merge( $out, self::scan( $root, $rel, $depth + 1 ) );
            } elseif ( substr( strtolower( $entry ), -4 ) === '.php' ) {
                $file_real = realpath( $full );
                if ( $file_real !== false && is_file( $file_real ) && strpos( $file_real, $root_real . DIRECTORY_SEPARATOR ) === 0 ) {
                    $out[] = $rel;
                }
            }
        }

        return $out;
    }
}
