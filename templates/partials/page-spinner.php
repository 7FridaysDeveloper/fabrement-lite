<?php
/**
 * Full-screen spinner for a Fabrement screen: covers everything right of the admin menu and below
 * the admin bar (the menu stays usable), in the page background. Visible from the first paint, so
 * nothing half-built flashes; the screen hides it (`hidden`) once it is ready, and may raise it
 * again around its own requests (Site General Settings does, around loading and saving the form).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="fabrement-page-loading" role="status" aria-live="polite">
    <div class="fabrement-page-loading__ring" aria-hidden="true"></div>
    <p class="fabrement-page-loading__text"><?php esc_html_e( 'Loading…', 'fabrement-lite' ); ?></p>
</div>
