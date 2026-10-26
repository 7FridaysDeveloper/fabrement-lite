<?php

use Fabrement\Modules\Fields\Schema\OptionsPage;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaError;
use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Values\Store\OptionRows;
use PHPUnit\Framework\TestCase;

final class FieldOptionsTest extends TestCase {

    public function test_an_options_page_is_an_owner_with_a_slug_and_a_file(): void {
        $owner = Owner::of( 'options', 'site' );
        self::assertNotNull( $owner );
        self::assertSame( 'options.site.json', $owner->file() );
        self::assertSame( 'options.site', $owner->id() );
        self::assertTrue( $owner->enabled() );
        self::assertNull( Owner::of( 'options' ), 'a page needs a slug' );
        self::assertNull( Owner::of( 'options', 'Site' ), 'slugs are lower case' );
        self::assertNull( Owner::of( 'options', 'site.uk' ), 'no dot: it separates the language in a row name' );
        self::assertSame( 'options.footer-links', Owner::from_file( 'options.footer-links.json' )->id() );
        self::assertNull( Owner::from_file( 'options.json' ) );
    }

    public function test_row_names_keep_page_and_language_apart(): void {
        self::assertSame( 'fabrement_options_site', Keys::options_page( 'site' ) );
        self::assertSame( 'fabrement_options_site.uk', Keys::options_page( 'site', 'uk' ) );
        // A page called site_uk never meets site in Ukrainian.
        self::assertNotSame( Keys::options_page( 'site_uk' ), Keys::options_page( 'site', 'uk' ) );
        self::assertSame( 'site', OptionRows::slug_of( 'fabrement_options_site.uk' ) );
        self::assertSame( 'site_uk', OptionRows::slug_of( 'fabrement_options_site_uk' ) );
        self::assertSame( '', OptionRows::slug_of( 'blogname' ) );
    }

    public function test_page_settings_are_checked_and_merged_over_the_stored_ones(): void {
        $page = OptionsPage::normalize( [ 'title' => 'Contacts' ], null );
        self::assertSame( [ 'title' => 'Contacts', 'menuTitle' => '', 'description' => '', 'position' => 100, 'autoload' => true ], $page );
        $next = OptionsPage::normalize( [ 'autoload' => false, 'position' => 5 ], $page );
        self::assertSame( 'Contacts', $next['title'], 'a property left out keeps its value' );
        self::assertFalse( $next['autoload'] );
        self::assertSame( 5, $next['position'] );
    }

    /** @dataProvider bad_pages */
    public function test_a_wrong_page_setting_names_its_path( $input, string $path ): void {
        try {
            OptionsPage::normalize( $input, null );
            self::fail( 'accepted' );
        } catch ( SchemaError $e ) {
            self::assertSame( $path, $e->path );
        }
    }

    public static function bad_pages(): array {
        return [
            'no title'          => [ [ 'menuTitle' => 'x' ], 'page.title' ],
            'blank title'       => [ [ 'title' => '  ' ], 'page.title' ],
            'unknown property'  => [ [ 'title' => 'x', 'icon' => 'y' ], 'page.icon' ],
            'autoload not bool' => [ [ 'title' => 'x', 'autoload' => 'yes' ], 'page.autoload' ],
            'position range'    => [ [ 'title' => 'x', 'position' => 5000 ], 'page.position' ],
            'long menu title'   => [ [ 'title' => 'x', 'menuTitle' => str_repeat( 'a', 61 ) ], 'page.menuTitle' ],
            'a list'            => [ [ 'x' ], 'page' ],
        ];
    }

    public function test_a_hand_edited_page_still_reads(): void {
        self::assertSame( 'Footer links', OptionsPage::read( null, 'footer-links' )['title'] );
        $broken = OptionsPage::read( [ 'title' => 'Kept', 'position' => 'first', 'autoload' => false ], 'x' );
        self::assertSame( 'Kept', $broken['title'] );
        self::assertSame( 100, $broken['position'] );
        self::assertFalse( $broken['autoload'] );
    }
}
