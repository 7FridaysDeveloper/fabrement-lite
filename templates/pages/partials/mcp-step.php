<?php
/**
 * One numbered step of a setup guide: the number, the instruction with whatever the step
 * needs to be done (a button, a command, fields to copy), and optional screenshots beside it.
 *
 * Returns the renderer instead of printing, so each guide holds it as `$fabrement_step` and
 * wraps its calls in `<ol class="fabrement-cred__steps">`. The number is the list's own
 * counter (see .fabrement-cred__step::before), so a step never carries it by hand.
 *
 * $shots: one screenshot — [ 'src' => file in assets/images, 'width', 'height', 'alt',
 * 'data' => attribute suffix ] — or a list of them, shown one under another in the order
 * the step's clicks happen.
 *
 * A shot with 'placeholder' => what it will show, and no 'src', reserves the place for a
 * screenshot not taken yet: a dashed box carrying that description (data-shot-placeholder,
 * so none can ship unnoticed — a test reports itself incomplete while any is left).
 */
defined( 'ABSPATH' ) || exit;

return static function ( string $title, callable $body, array $shots = [] ): void {
    $shots = isset( $shots['src'] ) || isset( $shots['placeholder'] ) ? [ $shots ] : array_values( $shots );
    ?>
    <li class="fabrement-cred__step<?php echo $shots !== [] ? ' has-shot' : ''; ?>">
        <div class="fabrement-cred__step-body">
            <h4 class="fabrement-cred__step-title"><?php echo esc_html( $title ); ?></h4>
            <?php $body(); ?>
        </div>
        <?php if ( $shots !== [] ) : ?>
            <div class="fabrement-cred__step-shots">
                <?php foreach ( $shots as $shot ) :
                    if ( ! isset( $shot['src'] ) ) :
                        ?>
                        <div class="fabrement-cred__step-shot fabrement-cred__step-shot--placeholder" data-shot-placeholder>
                            <span class="dashicons dashicons-format-image" aria-hidden="true"></span>
                            <?php echo esc_html( $shot['placeholder'] ); ?>
                        </div>
                        <?php
                        continue;
                    endif;
                    $src = \Fabrement\Framework\App::url( 'assets/images/' . $shot['src'] );
                    ?>
                    <a class="fabrement-cred__step-shot" href="<?php echo esc_url( $src ); ?>" target="_blank" rel="noopener noreferrer">
                        <img <?php echo isset( $shot['data'] ) ? 'data-' . esc_attr( $shot['data'] ) : ''; ?> src="<?php echo esc_url( $src ); ?>"
                            width="<?php echo (int) $shot['width']; ?>" height="<?php echo (int) $shot['height']; ?>" loading="lazy"
                            alt="<?php echo esc_attr( $shot['alt'] ); ?>">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </li>
    <?php
};
