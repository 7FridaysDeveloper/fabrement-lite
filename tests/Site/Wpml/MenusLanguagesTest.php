<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * A menu of another language as an agent reads and writes it. A menu is drawn on pages of its own
 * language, and WordPress works an item's address out in the language of the request — the front
 * page's French version is the French home there, and the English home when asked in English. So
 * list-menus answers, and update-menu compares, with the addresses of the menu's own language.
 */
final class MenusLanguagesTest extends SiteTestCase {

    protected const SITE = 'wpml';

    /** @return array|null The menu as list-menus gives it. */
    private function menu( string $name ): ?array {
        return array_column( $this->mcp( 'list-menus' )['menus'], null, 'name' )[ $name ] ?? null;
    }

    public function test_a_menu_of_another_language_answers_with_that_languages_addresses(): void {
        $home    = $this->record( 'page', 'QA menu home' );
        $about   = $this->record( 'page', 'QA menu about' );
        $home_fr  = (int) $this->mcp( 'translate-content', [ 'contentId' => $home, 'language' => 'fr', 'slug' => 'qa-menu-accueil' ] )['contentId'];
        $about_fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $about, 'language' => 'fr', 'slug' => 'qa-menu-a-propos' ] )['contentId'];
        foreach ( [ $home_fr, $about_fr ] as $id ) {
            $this->mcp( 'set-content-status', [ 'contentId' => $id, 'status' => 'publish' ] );
        }
        $kept = $this->site_runs( "\$kept = [ get_option( 'show_on_front' ), get_option( 'page_on_front' ) ];
            update_option( 'show_on_front', 'page' ); update_option( 'page_on_front', $home );
            return \$kept;" );

        try {
            $en = (int) $this->mcp( 'create-menu', [ 'name' => 'QA menu EN', 'items' => [ [ 'postId' => $home ], [ 'postId' => $about ] ] ] )['menu']['id'];
            // A French menu as WPML's menu screen makes one: marked French while empty, then filled.
            $fr = (int) $this->mcp( 'create-menu', [ 'name' => 'QA menu FR' ] )['menu']['id'];
            $this->site_runs( "
                \$term = get_term( $fr, 'nav_menu' );
                do_action( 'wpml_set_element_language_details', [ 'element_id' => (int) \$term->term_taxonomy_id, 'element_type' => 'tax_nav_menu', 'trid' => false, 'language_code' => 'fr', 'source_language_code' => null ] );
                return true;" );
            $filled = $this->mcp( 'update-menu', [ 'menuId' => $fr, 'items' => [ [ 'postId' => $home_fr ], [ 'postId' => $about_fr, 'children' => [ [ 'label' => 'Ext', 'url' => 'https://example.com/fr' ] ] ] ] ] )['menu'];
            $this->assertSame( [ $home_fr, $about_fr ], array_column( $filled['items'], 'postId' ), 'the French menu took its French pages' );
            $truth = $this->site_runs( "
                \$term = get_term( $fr, 'nav_menu' );
                \$asked = [ 'language' => apply_filters( 'wpml_element_language_code', null, [ 'element_id' => (int) \$term->term_taxonomy_id, 'element_type' => 'tax_nav_menu' ] ) ];
                // What a French page prints for these items.
                do_action( 'wpml_switch_language', 'fr' );
                \$asked['fr'] = [ get_permalink( $home_fr ), get_permalink( $about_fr ) ];
                do_action( 'wpml_switch_language', null );
                \$asked['en'] = [ get_permalink( $home_fr ), get_permalink( $home ), get_permalink( $about ) ];
                return \$asked;" );
            $this->assertSame( 'fr', $truth['language'], 'the menu is French' );
            $this->assertSame( $this->site()->url( '/fr/' ), $truth['fr'][0], 'on a French page the front page\'s version is the French home' );
            $this->assertNotSame( $truth['fr'][0], $truth['en'][0], 'asked in English it is another address — the reason this test exists' );

            $read = $this->menu( 'QA menu FR' );
            $this->assertSame( [ $home_fr, $about_fr ], array_column( $read['items'], 'postId' ) );
            $this->assertSame( $truth['fr'], array_column( $read['items'], 'url' ), 'the addresses a French page prints' );
            $this->assertSame( 'https://example.com/fr', $read['items'][1]['children'][0]['url'], 'a custom address is as given' );

            // The default language's menu is as it was.
            $this->assertSame( [ $truth['en'][1], $truth['en'][2] ], array_column( $this->menu( 'QA menu EN' )['items'], 'url' ) );
            $this->assertSame( $this->site()->url( '/' ), $truth['en'][1] );

            // Sent back as read: accepted, the items still attached, the answer the same.
            $saved = $this->mcp( 'update-menu', [ 'menuId' => $fr, 'items' => $read['items'] ] )['menu'];
            $this->assertSame( $read['items'], $saved['items'] );
            $this->assertSame( $read['items'], $this->menu( 'QA menu FR' )['items'] );
            $this->assertSame( [ 'fr' ], array_values( array_unique( $this->site_runs( "
                \$term = get_term( $fr, 'nav_menu' );
                return [ (string) apply_filters( 'wpml_element_language_code', null, [ 'element_id' => (int) \$term->term_taxonomy_id, 'element_type' => 'tax_nav_menu' ] ) ];" ) ) ), 'the menu stays French' );

            // An address of another language beside the page is not its address here.
            $wrong = $read['items'];
            $wrong[0]['url'] = $truth['en'][0];
            $this->assertStringContainsString( 'is not the address of its postId (' . $truth['fr'][0] . ')', $this->mcp_error( 'update-menu', [ 'menuId' => $fr, 'items' => $wrong ] ) );
            $this->assertSame( $read['items'], $this->menu( 'QA menu FR' )['items'], 'a refused change writes nothing' );

            // The language of the request is the one it was.
            $this->assertSame( 'en', $this->site_runs( "return (string) apply_filters( 'wpml_current_language', null );" ) );
        } finally {
            $this->site_runs( "update_option( 'show_on_front', " . var_export( (string) $kept[0], true ) . " ); update_option( 'page_on_front', " . (int) $kept[1] . " ); return true;" );
            foreach ( [ 'QA menu EN', 'QA menu FR' ] as $name ) {
                $menu = $this->menu( $name );
                if ( $menu !== null ) {
                    $this->mcp( 'delete-menu', [ 'menuId' => (int) $menu['id'] ] );
                }
            }
        }
    }
}
