<?php
/** wp eval-file tests/Integration/lite-input-safety.php — writing tools refuse unsafe text; what it creates it removes. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

$passed = 0;
$assert = static function ( bool $ok, string $message ) use ( &$passed ): void {
    if ( ! $ok ) { throw new RuntimeException( $message ); }
    ++$passed;
};
$call = static function ( string $tool, array $input ) {
    $ability = wp_get_ability( 'fabrement/' . $tool );
    if ( ! $ability ) { throw new RuntimeException( 'Tool is not registered: ' . $tool ); }
    return $ability->execute( $input );
};
$refused = static fn( $result ): bool => is_wp_error( $result ) && $result->get_error_code() === 'unsafe_content';
// Plain text through a term description; a term it creates is removed at once.
$text = static function ( string $words ) use ( $call ) {
    $result = $call( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Input safety text', 'description' => $words ] );
    $made   = get_term_by( 'name', 'Input safety text', 'category' );
    if ( $made ) { wp_delete_term( (int) $made->term_id, 'category' ); }
    return $result;
};

$unsafe = [
    'script'  => 'Hello<script>alert(1)</script>',
    'handler' => '<img src="x" onerror="alert(1)">',
    'link'    => '<a href="javascript:alert(1)">go</a>',
    'iframe'  => '<iframe src="https://example.com"></iframe>',
];
$before = [ 'posts' => (int) wp_count_posts( 'page' )->draft ];

foreach ( $unsafe as $kind => $text ) {
    $assert( $refused( $call( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => $text, 'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>ok</p><!-- /wp:paragraph -->' ] ] ] ) ), "A page title with a $kind is refused." );
    $assert( $refused( $call( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Input safety', 'description' => $text ] ) ), "A term description with a $kind is refused." );
    if ( class_exists( \WPCF7_ContactForm::class ) ) {
        $assert( $refused( $call( 'create-cf7-form', [ 'title' => 'Input safety', 'template' => '<label>Name [text* your-name]</label>' . $text . '[submit "Send"]' ] ) ), "A form template with a $kind is refused." );
    }
}
$assert( (int) wp_count_posts( 'page' )->draft === $before['posts'], 'A refused call creates nothing.' );
$assert( ! get_term_by( 'name', 'Input safety', 'category' ), 'A refused term is not created.' );

// Ordinary text and safe markup still pass.
$page = $call( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => 'Input safety: A & B',
    'blocks' => [ [ 'markup' => '<!-- wp:paragraph --><p>Safe <strong>text</strong></p><!-- /wp:paragraph -->' ] ] ] );
$assert( ! is_wp_error( $page ), 'An ordinary page is created: ' . ( is_wp_error( $page ) ? $page->get_error_message() : '' ) );
foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'draft', 'title' => 'Input safety: A & B', 'fields' => 'ids', 'suppress_filters' => false ] ) as $id ) {
    wp_delete_post( (int) $id, true );
}
if ( class_exists( \WPCF7_ContactForm::class ) ) {
    $form = $call( 'create-cf7-form', [ 'title' => 'Input safety form', 'template' => "<label>Your name\n[text* your-name]</label>\n\n[submit \"Send\"]" ] );
    $assert( ! is_wp_error( $form ), 'An ordinary form is created: ' . ( is_wp_error( $form ) ? $form->get_error_message() : '' ) );
    foreach ( get_posts( [ 'post_type' => 'wpcf7_contact_form', 'title' => 'Input safety form', 'fields' => 'ids', 'post_status' => 'any' ] ) as $id ) {
        wp_delete_post( (int) $id, true );
    }
}
// An executable address in plain text is refused; words that merely start like one are not.
foreach ( [ 'javascript:alert(1)', "java\tscript:alert(1)", 'JAVASCRIPT:location=1', 'jav&#x61;script:alert(1)', 'vbscript:msgbox(1)', 'data:text/html,hello', 'data:image/svg+xml;base64,AAAA' ] as $address ) {
    $assert( $refused( $text( $address ) ), 'An executable address is refused: ' . $address );
}
foreach ( [ 'JavaScript: the good parts', 'Data: annual report 2026', 'javascript' ] as $words ) {
    $assert( ! $refused( $text( $words ) ), 'Plain words are not taken for an address: ' . $words );
}

// Plain text with a less-than sign is text; a form template may hold form controls.
$assert( ! $refused( $text( 'Price < 100 and I <3 it' ) ), 'A less-than sign in plain text is not refused.' );
if ( class_exists( \WPCF7_ContactForm::class ) ) {
    $assert( ! $refused( $call( 'create-cf7-form', [ 'title' => '', 'template' => '<label>Name <input type="text" name="n"></label>' ] ) ), 'A form template may hold an input.' );
    $assert( $refused( $call( 'create-cf7-form', [ 'title' => 'x', 'template' => '<input type="text" onfocus="alert(1)">' ] ) ), 'A handler on a form control is refused.' );
}

// Reading tools take the text as it is: searching for a "<" is not writing it.
$assert( ! $refused( $call( 'list-content', [ 'search' => '<script>' ] ) ), 'A reading tool is not refused for its search text.' );

WP_CLI::success( $passed . ' Lite input-safety checks passed; temporary records removed.' );
