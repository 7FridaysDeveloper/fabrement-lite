<?php
/**
 * Prepares the plugin folder of the WPML suite (tests/Wpml): `runtime/lite-wp-plugins` next to the
 * repository, OUTSIDE git — WPML is licensed and never committed.
 *
 *   composer test:wpml:runtime
 *
 * Copies WPML, WPML String Translation and Yoast SEO Multilingual from a site that has them
 * (FABREMENT_WPML_SOURCE, required — only READ from it), and links the
 * plugins that live elsewhere: Fabrement itself (this repository) and Yoast / Contact Form 7 from
 * vendor/wp-plugins. One folder for all of them, because WPML finds a plugin's wpml-config.xml as
 * WP_PLUGIN_DIR/<slug>/wpml-config.xml of the active plugins only.
 */
if ( PHP_SAPI !== 'cli' ) {
    exit;
}

$root   = dirname( __DIR__, 2 );
$target = getenv( 'FABREMENT_WPML_PLUGINS_DIR' ) ?: dirname( $root ) . '/runtime/lite-wp-plugins';
$source = (string) getenv( 'FABREMENT_WPML_SOURCE' );
if ( $source === '' ) {
    fwrite( STDERR, "Set FABREMENT_WPML_SOURCE to the wp-content/plugins folder of a site that has WPML.\n" );
    exit( 1 );
}
$copy   = [ 'sitepress-multilingual-cms', 'wpml-string-translation', 'wp-seo-multilingual' ];
$link   = [
    'fabrement'      => $root,
    'wordpress-seo'  => $root . '/vendor/wp-plugins/wordpress-seo',
    'contact-form-7' => $root . '/vendor/wp-plugins/contact-form-7',
];

if ( strpos( str_replace( '\\', '/', realpath( dirname( $target ) ) ?: dirname( $target ) ), str_replace( '\\', '/', $root ) ) === 0 ) {
    fwrite( STDERR, "Refusing: $target is inside the repository.\n" );
    exit( 1 );
}
if ( ! is_dir( $target ) && ! mkdir( $target, 0777, true ) ) {
    fwrite( STDERR, "Cannot create $target.\n" );
    exit( 1 );
}

$remove = static function ( string $path ) use ( &$remove ): void {
    if ( is_link( $path ) || ( PHP_OS_FAMILY === 'Windows' && is_dir( $path ) && readlink( $path ) !== false && readlink( $path ) !== $path ) ) {
        // A junction or symlink: remove the link, never what it points at.
        PHP_OS_FAMILY === 'Windows' ? rmdir( $path ) : unlink( $path );
        return;
    }
    if ( is_dir( $path ) ) {
        foreach ( scandir( $path ) as $entry ) {
            if ( $entry !== '.' && $entry !== '..' ) {
                $remove( $path . '/' . $entry );
            }
        }
        rmdir( $path );
    } elseif ( is_file( $path ) ) {
        unlink( $path );
    }
};
$copy_dir = static function ( string $from, string $to ) use ( &$copy_dir ): void {
    mkdir( $to, 0777, true );
    foreach ( scandir( $from ) as $entry ) {
        if ( $entry === '.' || $entry === '..' ) {
            continue;
        }
        is_dir( $from . '/' . $entry ) ? $copy_dir( $from . '/' . $entry, $to . '/' . $entry ) : copy( $from . '/' . $entry, $to . '/' . $entry );
    }
};

$versions = [];
foreach ( $copy as $slug ) {
    if ( ! is_dir( $source . '/' . $slug ) ) {
        fwrite( STDERR, "Not found: $source/$slug — set FABREMENT_WPML_SOURCE to a plugins folder that has it.\n" );
        exit( 1 );
    }
    $remove( $target . '/' . $slug );
    $copy_dir( $source . '/' . $slug, $target . '/' . $slug );
    foreach ( glob( $target . '/' . $slug . '/*.php' ) as $file ) {
        if ( preg_match( '/^\s*\*?\s*Version:\s*(\S+)/mi', (string) file_get_contents( $file, false, null, 0, 4096 ), $m ) && preg_match( '/Plugin Name:/i', (string) file_get_contents( $file, false, null, 0, 4096 ) ) ) {
            $versions[ $slug ] = $m[1];
        }
    }
    echo "copied   $slug " . ( $versions[ $slug ] ?? '?' ) . "\n";
}
foreach ( $link as $slug => $from ) {
    if ( ! is_dir( $from ) ) {
        fwrite( STDERR, "Not found: $from — composer install first.\n" );
        exit( 1 );
    }
    $remove( $target . '/' . $slug );
    if ( PHP_OS_FAMILY === 'Windows' ) {
        exec( 'cmd /c mklink /J ' . escapeshellarg( str_replace( '/', '\\', $target . '/' . $slug ) ) . ' ' . escapeshellarg( str_replace( '/', '\\', $from ) ), $out, $code );
    } else {
        $code = symlink( $from, $target . '/' . $slug ) ? 0 : 1;
    }
    if ( $code !== 0 ) {
        fwrite( STDERR, "Cannot link $slug.\n" );
        exit( 1 );
    }
    echo "linked   $slug -> $from\n";
}
file_put_contents( $target . '/VERSIONS.json', json_encode( $versions, JSON_PRETTY_PRINT ) . "\n" );
echo "ready    $target\n";
