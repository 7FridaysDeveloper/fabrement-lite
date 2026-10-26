<?php
/**
 * The test database names a theme ("default") that exists nowhere: PHPUnit renders no theme, but a
 * page served over HTTP needs one to print its <head>. Core's own block theme, from the core folder,
 * stands in — the PHPUnit run's wp-content is left without themes.
 */
add_filter( 'theme_root', static fn() => ABSPATH . 'wp-content/themes' );
add_filter( 'theme_root_uri', static fn() => site_url( 'wp-content/themes' ) );
add_filter( 'template', static fn() => 'twentytwentyfive' );
add_filter( 'stylesheet', static fn() => 'twentytwentyfive' );
