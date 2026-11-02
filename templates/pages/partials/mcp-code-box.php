<?php
/**
 * A read-only code block with a labelled header and a copy button: a CLI command, a config
 * file, AI instructions. Returns the renderer; guides hold it as `$fabrement_code_box`.
 */
defined( 'ABSPATH' ) || exit;

return static function ( string $id, string $label, string $value, int $rows, string $copy_label, bool $nowrap = false ): void {
    ?>
    <div class="fabrement-cred__mcp-command fabrement-cred__oauth-code">
        <div class="fabrement-cred__oauth-code-header">
            <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
            <button type="button" class="button fabrement-cred__mcp-copy" data-mcp-copy="<?php echo esc_attr( $id ); ?>" title="<?php echo esc_attr( $copy_label ); ?>" aria-label="<?php echo esc_attr( $copy_label ); ?>">
                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
            </button>
        </div>
        <textarea id="<?php echo esc_attr( $id ); ?>" readonly spellcheck="false"<?php echo $nowrap ? ' wrap="off"' : ''; ?> rows="<?php echo (int) $rows; ?>"><?php echo esc_textarea( $value ); ?></textarea>
    </div>
    <?php
};
