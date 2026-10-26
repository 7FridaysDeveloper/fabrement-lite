<?php
/**
 * Page head — the title + one-line description every Fabrement screen opens with.
 *
 * One structure for every tab in the menu, so a person always lands on the same
 * shape: what this screen is, then one sentence on what it is for. The styling
 * lives with the rest of the tab shell in assets/admin/tab-layout.css, which the
 * React screen (Templates) use through the same class names —
 * there is no second copy of this head anywhere.
 *
 * @var string $fabrement_head_title Screen name, in the words the menu uses.
 * @var string $fabrement_head_sub   One short sentence: what the screen is for.
 */

defined( 'ABSPATH' ) || exit;

?>
<header class="fabrement-head">
	<div class="fabrement-head__text">
		<h1 class="fabrement-head__title"><?php echo esc_html( $fabrement_head_title ); ?></h1>
		<p class="fabrement-head__sub"><?php echo esc_html( $fabrement_head_sub ); ?></p>
	</div>
</header>
