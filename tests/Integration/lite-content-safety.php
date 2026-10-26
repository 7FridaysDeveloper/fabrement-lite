<?php
/** wp eval-file tests/Integration/lite-content-safety.php — real KSES, no database writes. */
use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\Mcp\Support\ContentEdits;
use Fabrement\Modules\Mcp\Support\ContentSafety;
use Fabrement\Modules\Mcp\Support\McpAbilityException;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
$passed = 0;
$assert = static function ( bool $ok, string $message ) use ( &$passed ): void {
    if ( ! $ok ) { throw new RuntimeException( $message ); }
    ++$passed;
};
$refuses = static function ( callable $fn, string $label ) use ( $assert ): void {
    try { $fn(); } catch ( McpAbilityException $e ) {
        $assert( $e->to_error()->get_error_code() === 'unsafe_content', $label . ': specific refusal' );
        return;
    }
    $assert( false, 'Accepted unsafe input: ' . $label );
};
$wrap = static fn( string $html ) => [ 'markup' => '<!-- wp:html -->' . $html . '<!-- /wp:html -->' ];
foreach ( [
    '<script>alert(1)</script>', '<img src="x" onerror="alert(1)">',
    '<a href="jav&#x61;script:alert(1)">link</a>', '<iframe src="https://example.com"></iframe>',
    '<style>body{display:none}</style>', '<svg onload="alert(1)"></svg>',
    '<p style="background-image:url(javascript:alert(1))">text</p>',
] as $html ) {
    $refuses( static fn() => BlockEntry::serialize( $wrap( $html ), 0 ), $html );
    $refuses( static fn() => BlockEntry::serialize( [ 'markup' => '<!-- wp:group --><div class="wp-block-group">' . $wrap( $html )['markup'] . '</div><!-- /wp:group -->' ], 0 ), 'nested ' . $html );
}
foreach ( [ '<script>alert(1)</script>', 'javascript:alert(1)', "java\nscript:alert(1)", 'data:text/html,test' ] as $value ) {
    $refuses( static fn() => ContentSafety::entry( [ 'attributes' => [ 'data' => [ 'rows_0_body' => $value ] ] ], 'ACF' ), 'attribute ' . $value );
}
$refuses( static fn() => ContentSafety::entry( [ 'attributes' => [ 'tagName' => 'script' ] ], 'wrapper' ), 'dynamic wrapper' );
$safe = [ 'markup' => '<!-- wp:paragraph --><p class="text">A &amp; B <strong>Україна</strong> <a href="https://example.com/?a=1&amp;b=2">Link</a></p><!-- /wp:paragraph -->' ];
$assert( BlockEntry::serialize( $safe, 0 )['markup'] === $safe['markup'], 'Safe core HTML remains byte-identical' );
// The editor writes void tags without a space before the slash; KSES adds one. Both are the same markup.
foreach ( [ '<hr class="wp-block-separator has-alpha-channel-opacity"/>', '<hr class="wp-block-separator" />', '<img src="https://example.com/a.png" alt="" class="wp-image-5"/>', '<p>one<br/>two</p>' ] as $void ) {
    ContentSafety::html( $void, 'void' );
    $assert( true, 'A void tag is accepted as the editor writes it: ' . $void );
}
foreach ( [ '<img src="x" onerror="alert(1)"/>', '<hr onclick="alert(1)" />', '<br/><script>alert(1)</script>' ] as $void ) {
    $refuses( static fn() => ContentSafety::html( $void, 'void' ), 'unsafe void ' . $void );
}
// Judged tag by tag, not as a string: how the markup is written does not matter.
foreach ( [ '<p style="color:red;">x</p>', "<a href='https://example.com/'>x</a>", '<a href=https://example.com/>x</a>', '<p  class="a"   >x</p>', 'a < b', 'I <3 gardens', '5 > 3 < 9', '<img src="https://example.com/a.png" alt="a > b"/>' ] as $fine ) {
    ContentSafety::html( $fine, 'written' );
    $assert( true, 'Harmless text is accepted however it is written: ' . $fine );
}
foreach ( [ 'a<b then stop', '<script src=//x', '<img src=x onerror=alert(1) ', "<a href='javascript:alert(1)'>x</a>", '<p style="background:url(javascript:alert(1))">x</p>', '<svg><script>1</script></svg>' ] as $bad ) {
    $refuses( static fn() => ContentSafety::html( $bad, 'written' ), 'unsafe however written: ' . $bad );
}
try { ContentSafety::html( '<img src="x" onerror="alert(1)">', 'blocks[1]' ); } catch ( McpAbilityException $e ) {
    $assert( str_contains( $e->getMessage(), 'blocks[1]' ) && str_contains( $e->getMessage(), 'onerror attribute on the img element' ), 'The refusal names what and where: ' . $e->getMessage() );
}
// The editor's own inline styles: colour functions, shadows, filters.
foreach ( [ '<p>a <mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color">b</mark></p>', '<p style="color:rgb(10,20,30)">x</p>', '<p style="color:hsl(120, 50%, 50%)">x</p>', '<div style="box-shadow:0 2px 6px rgba(0,0,0,0.2)">x</div>' ] as $styled ) {
    ContentSafety::html( $styled, 'style' );
    $assert( true, 'An editor style is accepted: ' . $styled );
}
foreach ( [ '<div style="background-image:url(javascript:alert(1))">x</div>', '<div style="width:expression(alert(1))">x</div>', '<div style="color:rgba(0,0,0,0);background:url(javascript:alert(1))">x</div>' ] as $styled ) {
    $refuses( static fn() => ContentSafety::html( $styled, 'style' ), 'unsafe style ' . $styled );
}
// A block value is words unless its key holds an address.
ContentSafety::entry( [ 'attributes' => [ 'content' => 'JavaScript: the good parts', 'data' => [ 'title' => 'Data: annual report' ] ] ], 'heading' );
$assert( true, 'Words that start like a scheme are accepted in a block value.' );
foreach ( [ 'url', 'href', 'src' ] as $address_key ) {
    $refuses( static fn() => ContentSafety::entry( [ 'attributes' => [ 'link' => [ $address_key => 'javascript:void 0' ] ] ], 'link' ), 'a script scheme in ' . $address_key );
}
$safe_html = $wrap( '<div class="card"><h2>Title</h2><p>Safe custom HTML</p></div>' );
$assert( BlockEntry::serialize( $safe_html, 0 )['markup'] === $safe_html['markup'], 'Safe core/html supported' );
ContentSafety::entry( [ 'attributes' => [ 'data' => [ 'body' => '<p>Rich <em>text</em></p>', 'title' => 'A & B " --> Україна' ] ] ], 'ACF' );
$assert( true, 'Rich ACF values and comment-sensitive text supported' );
$legacy = $wrap( '<script>oldSiteCode()</script>' );
$entries = [ $legacy, $safe ];
$changed = ContentEdits::apply( $entries, [ [ 'op' => 'text', 'index' => 1, 'name' => 'core/paragraph', 'old' => 'Україна', 'new' => 'Ukraine' ] ] );
$assert( $changed[0] === $legacy, 'An unchanged neighboring legacy block is preserved' );
$assert( BlockEntry::serialize( $changed[0], 0, 'blocks', true, false )['markup'] === $legacy['markup'], 'Stored block transport does not sanitize neighbors' );
$refuses( static fn() => ContentEdits::apply( $entries, [
    [ 'op' => 'text', 'index' => 1, 'name' => 'core/paragraph', 'old' => 'Україна', 'new' => 'Ukraine' ],
    [ 'op' => 'insert', 'at' => 2, 'block' => $legacy ],
] ), 'Multi-operation refusal' );
$assert( $entries === [ $legacy, $safe ], 'Failed batch leaves input unchanged' );
$refuses( static fn() => ContentEdits::apply( [ $safe ], [ [ 'op' => 'text', 'index' => 0, 'name' => 'core/paragraph', 'old' => 'Україна', 'new' => '<img onerror="alert(1)" src="x">' ] ] ), 'Text edit cannot inject events' );
WP_CLI::success( $passed . ' Lite content-safety assertions passed.' );
