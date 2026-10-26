<?php
/**
 * Virtual page template for classic themes: the site template's own header and
 * footer around the page content. Served via ClassicThemeApplier's
 * template_include swap — the theme's header.php/footer.php never run here,
 * but wp_head/wp_body_open/wp_footer fire so plugins (and the block CSS
 * enqueues) keep working.
 *
 * @package Fabrement\Modules\SiteTemplate
 */

defined( 'ABSPATH' ) || exit;

$fabrement_site_template = \Fabrement\Modules\SiteTemplate\Service\ClassicThemeApplier::current_template();
if ( $fabrement_site_template === null ) {
    // Defensive: template_include only routes here after setting the record.
    get_header();
    the_post();
    the_content();
    get_footer();
    return;
}
// Render the template's OWN block arrangement (header, the content-source slot,
// footer, and anything the admin added between them), in order. On a SINGULAR
// view, set up the queried post first so the content-source slot resolves it —
// `if`, NOT `while`: a file included via content-source may run its own
// while(have_posts()) loop, which rewinds the query; a while here would then
// re-enter forever. On an ARCHIVE view the main query must stay untouched — a
// theme file runs the full while(have_posts()) loop itself, and an early
// the_post() here would silently swallow its first post.
if ( is_singular() && have_posts() ) {
    the_post();
}
$fabrement_template_html = do_blocks( \Fabrement\Modules\SiteTemplate\Service\TemplateContent::for_reader( $fabrement_site_template ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block render output.

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'fabrement-template-canvas' ); ?>>
<?php wp_body_open(); ?>

<?php
echo $fabrement_template_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks/theme output.
?>

<?php wp_footer(); ?>
</body>
</html>
