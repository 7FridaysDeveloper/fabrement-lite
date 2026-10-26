<?php
/**
 * The web server's copy of what tests/WpSetup/bootstrap.php does: the plugins of the suite, loaded
 * as must-use plugins in folder order (Contact Form 7, Fabrement, Yoast SEO), and core's block theme
 * in place of the "default" theme the test database names but no folder has.
 */
foreach ( [ 'contact-form-7/wp-contact-form-7.php', 'fabrement/fabrement-lite.php', 'wordpress-seo/wp-seo.php' ] as $fabrement_plugin ) {
    if ( is_file( WP_PLUGIN_DIR . '/' . $fabrement_plugin ) ) {
        require WP_PLUGIN_DIR . '/' . $fabrement_plugin;
    }
}

add_filter( 'theme_root', static fn() => ABSPATH . 'wp-content/themes' );
add_filter( 'theme_root_uri', static fn() => site_url( 'wp-content/themes' ) );
add_filter( 'template', static fn() => 'twentytwentyfive' );
add_filter( 'stylesheet', static fn() => 'twentytwentyfive' );
