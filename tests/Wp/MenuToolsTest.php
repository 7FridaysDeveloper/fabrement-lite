<?php

namespace Fabrement\Tests\Wp;

use WP_Error;
use WP_UnitTestCase;

/**
 * Menu labels are saved as WordPress's menu screen saves them: as typed by someone who may post
 * unfiltered HTML, through kses for anyone else; list-menus reads them back as the words they show.
 */
final class MenuToolsTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    /** @return mixed */
    private function tool( string $name, array $input = [] ) {
        return wp_get_ability( 'fabrement/' . $name )->execute( $input );
    }

    private function labels( string $name ): array {
        foreach ( $this->tool( 'list-menus' )['menus'] as $menu ) {
            if ( $menu['name'] === $name ) {
                return array_column( $menu['items'], 'label' );
            }
        }
        return [];
    }

    public function test_labels_keep_what_was_typed(): void {
        $made = $this->tool( 'create-menu', [ 'name' => 'QA menu words', 'items' => [
            [ 'label' => 'Kids <12 & more', 'url' => '/kids' ],
            [ 'label' => 'Child <&>', 'url' => '/child' ],
            [ 'label' => 'A\\B', 'url' => '/ab' ],
        ] ] );
        $this->assertNotInstanceOf( WP_Error::class, $made, $made instanceof WP_Error ? $made->get_error_message() : '' );
        $this->assertSame( [ 'Kids <12 & more', 'Child <&>', 'A\\B' ], $this->labels( 'QA menu words' ) );
    }

    public function test_labels_of_someone_without_unfiltered_html_are_kept_safe_and_read_as_words(): void {
        $deny = static fn( array $caps, string $cap ): array => $cap === 'unfiltered_html' ? [ 'do_not_allow' ] : $caps;
        add_filter( 'map_meta_cap', $deny, 10, 2 );
        try {
            $made = $this->tool( 'create-menu', [ 'name' => 'QA menu safe', 'items' => [
                [ 'label' => 'Kids <12 & more', 'url' => '/kids' ],
                [ 'label' => '<img src=x onerror=alert(1)>Go', 'url' => '/go' ],
            ] ] );
            $this->assertNotInstanceOf( WP_Error::class, $made, $made instanceof WP_Error ? $made->get_error_message() : '' );
            [ $kids, $go ] = $this->labels( 'QA menu safe' );
            $this->assertSame( 'Kids <12 & more', $kids, 'read back as the words it shows' );
            $this->assertStringNotContainsString( 'onerror', $go );
        } finally {
            remove_filter( 'map_meta_cap', $deny, 10 );
        }
    }
}
