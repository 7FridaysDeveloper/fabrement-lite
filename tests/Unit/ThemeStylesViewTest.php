<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\Support\ThemeStylesView;
use PHPUnit\Framework\TestCase;

final class ThemeStylesViewTest extends TestCase {

    private const SETTINGS = [
        'layout'     => [ 'contentSize' => '645px', 'wideSize' => '1340px', 'other' => 'x' ],
        'color'      => [ 'palette' => [
            'default' => [ [ 'slug' => 'base', 'color' => '#eeeeee' ] ],
            'theme'   => [ [ 'slug' => 'base', 'color' => '#FFFFFF' ], [ 'slug' => 'contrast', 'color' => '#111111' ] ],
        ] ],
        'typography' => [ 'fontSizes' => [ 'theme' => [ [ 'slug' => 'large', 'size' => '1.38rem' ] ] ] ],
        'spacing'    => [ 'spacingSizes' => [ [ 'slug' => '50', 'size' => 'clamp(30px, 5vw, 50px)' ] ] ],
    ];

    public function test_presets_read_as_slug_and_value_the_theme_winning_over_default(): void {
        $view = ThemeStylesView::build( [
            'color'      => [ 'background' => 'var(--wp--preset--color--base)', 'text' => 'var:preset|color|contrast' ],
            'typography' => [ 'fontSize' => 'var(--wp--preset--font-size--large)' ],
            'spacing'    => [ 'padding' => [ 'left' => 'var(--wp--preset--spacing--50)' ] ],
        ], self::SETTINGS );

        self::assertSame( 'base (#FFFFFF)', $view['page']['color']['background'] );
        self::assertSame( 'contrast (#111111)', $view['page']['color']['text'] );
        self::assertSame( 'large (1.38rem)', $view['page']['typography']['fontSize'] );
        self::assertSame( '50 (clamp(30px, 5vw, 50px))', $view['page']['spacing']['padding']['left'] );
        self::assertSame( [ 'contentSize' => '645px', 'wideSize' => '1340px' ], $view['layout'] );
    }

    public function test_an_unknown_preset_keeps_its_slug(): void {
        $view = ThemeStylesView::build( [ 'color' => [ 'text' => 'var(--wp--preset--color--missing)' ] ], self::SETTINGS );

        self::assertSame( 'missing', $view['page']['color']['text'] );
    }

    public function test_elements_keep_states_and_blocks_lose_variations_and_css(): void {
        $view = ThemeStylesView::build( [
            'css'      => 'body{}',
            'elements' => [ 'button' => [ 'color' => [ 'text' => 'white' ], ':hover' => [ 'color' => [ 'text' => 'black' ] ], 'css' => 'x' ] ],
            'blocks'   => [
                'core/quote'  => [ 'typography' => [ 'fontSize' => '2rem' ], 'variations' => [ 'plain' => [ 'border' => [] ] ], 'css' => 'q{}' ],
                'core/button' => [ 'variations' => [ 'outline' => [ 'border' => [ 'width' => '1px' ] ] ] ],
            ],
        ], self::SETTINGS );

        self::assertSame( [ 'color' => [ 'text' => 'white' ], ':hover' => [ 'color' => [ 'text' => 'black' ] ] ], $view['elements']['button'] );
        self::assertSame( [ 'core/quote' => [ 'typography' => [ 'fontSize' => '2rem' ] ] ], $view['blocks'] );
        self::assertArrayNotHasKey( 'css', $view['page'] );
    }
}
