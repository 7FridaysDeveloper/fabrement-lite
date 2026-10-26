<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\Support\ContentParent;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\Translation\Bridge\NullBridge;
use Fabrement\Modules\Translation\Contract\TranslationBridge;
use Fabrement\Modules\Translation\Translation;
use PHPUnit\Framework\TestCase;

/**
 * save-content `parent`, judged without WordPress: every refusal WordPress itself would answer by
 * quietly moving the record. What needs real queries and slugs is tests/Wp/ContentParentTest.
 */
final class ContentParentTest extends TestCase {

    protected function setUp(): void {
        WordPressState::reset();
        WordPressState::$types['page']  = (object) [ 'name' => 'page', 'hierarchical' => true ];
        WordPressState::$types['guide'] = (object) [ 'name' => 'guide', 'hierarchical' => true ];
        WordPressState::$types['post']  = (object) [ 'name' => 'post', 'hierarchical' => false ];
        // Tree A: 10 → 11 → 12. Tree B: 13. Elsewhere: a guide 20, a trashed page 30, a post 40.
        $this->post( 10, 'page', 0 );
        $this->post( 11, 'page', 10 );
        $this->post( 12, 'page', 11 );
        $this->post( 13, 'page', 0 );
        $this->post( 20, 'guide', 0 );
        $this->post( 30, 'page', 0, 'trash' );
        $this->post( 40, 'post', 0 );
        Translation::set( new NullBridge() );
    }

    protected function tearDown(): void {
        Translation::set( null );
    }

    private function post( int $id, string $type, int $parent, string $status = 'publish' ): void {
        $post              = new \WP_Post( (object) [ 'ID' => $id ] );
        $post->post_type   = $type;
        $post->post_parent = $parent;
        $post->post_status = $status;
        WordPressState::$posts[ $id ] = $post;
    }

    private function refused( callable $call, string $code ): McpAbilityException {
        try {
            $call();
        } catch ( McpAbilityException $e ) {
            self::assertSame( $code, $e->to_error()->get_error_code(), $e->getMessage() );
            return $e;
        }
        self::fail( 'Expected a refusal with code "' . $code . '".' );
    }

    public function test_top_level_and_a_valid_parent_pass(): void {
        self::assertSame( 0, ContentParent::resolve( 0, 'page', 0, '' ) );
        self::assertSame( 10, ContentParent::resolve( 10, 'page', 0, '' ) );
        self::assertSame( 11, ContentParent::resolve( '11', 'page', 0, '' ) );
        // A grandchild moved up under the root of its own tree.
        self::assertSame( 10, ContentParent::resolve( 10, 'page', 12, '' ) );
        // A record moved into ANOTHER tree.
        self::assertSame( 13, ContentParent::resolve( 13, 'page', 11, '' ) );
    }

    public function test_a_parent_may_be_a_draft_pending_private_or_scheduled(): void {
        foreach ( [ 'draft', 'pending', 'private', 'future' ] as $i => $status ) {
            $this->post( 50 + $i, 'page', 0, $status );
            self::assertSame( 50 + $i, ContentParent::resolve( 50 + $i, 'page', 0, '' ), $status );
        }
    }

    public function test_a_flat_type_takes_no_parent_but_zero_is_fine(): void {
        self::assertSame( 0, ContentParent::resolve( 0, 'post', 40, '' ) );
        $this->refused( static fn() => ContentParent::resolve( 40, 'post', 0, '' ), 'not_hierarchical' );
    }

    public function test_the_parent_must_exist_share_the_type_and_be_content_that_stays(): void {
        $this->refused( static fn() => ContentParent::resolve( 999, 'page', 0, '' ), 'invalid_parent' );
        $this->refused( static fn() => ContentParent::resolve( 20, 'page', 0, '' ), 'invalid_parent' );
        $trash = $this->refused( static fn() => ContentParent::resolve( 30, 'page', 0, '' ), 'invalid_parent' );
        self::assertStringContainsString( '"trash"', $trash->getMessage() );
        foreach ( [ 'auto-draft', 'inherit' ] as $i => $status ) {
            $this->post( 60 + $i, 'page', 0, $status );
            $this->refused( static fn() => ContentParent::resolve( 60 + $i, 'page', 0, '' ), 'invalid_parent' );
        }
    }

    public function test_input_must_be_a_non_negative_whole_id(): void {
        foreach ( [ -1, 'root', null, 10.0, '+10', ' 10', '1e1' ] as $raw ) {
            $this->refused( static fn() => ContentParent::resolve( $raw, 'page', 0, '' ), 'invalid_parent' );
        }
    }

    public function test_no_loops(): void {
        $this->refused( static fn() => ContentParent::resolve( 10, 'page', 10, '' ), 'parent_loop' );
        // The root under its own grandchild, then under its own child.
        $this->refused( static fn() => ContentParent::resolve( 12, 'page', 10, '' ), 'parent_loop' );
        $this->refused( static fn() => ContentParent::resolve( 11, 'page', 10, '' ), 'parent_loop' );
    }

    /** 10 (en) has a Polish version 70; 11 (en) has none; 13 (en) had one, 71, now trashed; 72 is gone. */
    private function languages(): void {
        $this->post( 70, 'page', 0 );
        $this->post( 71, 'page', 0, 'trash' );
        $lang   = [ 10 => 'en', 70 => 'pl', 11 => 'en', 13 => 'en', 71 => 'pl', 14 => 'en' ];
        $groups = [ [ 'en' => 10, 'pl' => 70 ], [ 'en' => 11 ], [ 'en' => 13, 'pl' => 71 ], [ 'en' => 14, 'pl' => 72 ] ];
        $this->post( 14, 'page', 0 );

        $bridge = $this->createStub( TranslationBridge::class );
        $bridge->method( 'version_facts' )->willReturnCallback(
            static function ( array $ids ) use ( $lang ): array {
                $out = [];
                foreach ( $ids as $id ) {
                    if ( isset( $lang[ $id ] ) ) {
                        $out[ $id ] = [ 'language' => $lang[ $id ] ];
                    }
                }
                return $out;
            }
        );
        $bridge->method( 'visible_versions' )->willReturnCallback(
            static function ( int $id ) use ( $groups ): array {
                foreach ( $groups as $group ) {
                    if ( in_array( $id, $group, true ) ) {
                        return $group;
                    }
                }
                return [];
            }
        );
        Translation::set( $bridge );
    }

    public function test_the_parent_is_taken_in_the_records_language(): void {
        $this->languages();

        self::assertSame( 70, ContentParent::resolve( 10, 'page', 0, 'pl' ), 'the English parent → its Polish version' );
        self::assertSame( 70, ContentParent::resolve( 70, 'page', 0, 'pl' ), 'already in Polish' );
        self::assertSame( 10, ContentParent::resolve( 10, 'page', 0, 'en' ) );
        self::assertSame( 10, ContentParent::resolve( 70, 'page', 0, 'en' ), 'and back' );
    }

    public function test_a_missing_trashed_or_deleted_version_is_refused(): void {
        $this->languages();

        $this->refused( static fn() => ContentParent::resolve( 11, 'page', 0, 'pl' ), 'parent_language' );
        $this->refused( static fn() => ContentParent::resolve( 13, 'page', 0, 'pl' ), 'parent_language' );
        $this->refused( static fn() => ContentParent::resolve( 14, 'page', 0, 'pl' ), 'parent_language' );
    }

    public function test_a_loop_through_the_language_mapping_names_both_ids(): void {
        $this->languages();

        $e = $this->refused( static fn() => ContentParent::resolve( 10, 'page', 70, 'pl' ), 'parent_loop' );
        self::assertStringContainsString( '10 (its version in this language, 70)', $e->getMessage() );
    }

    public function test_menu_order_takes_integers_only(): void {
        self::assertSame( 3, ContentParent::menu_order( 3 ) );
        self::assertSame( -2, ContentParent::menu_order( '-2' ) );
        foreach ( [ 'first', 1.5, null, '1.0' ] as $raw ) {
            $this->refused( static fn() => ContentParent::menu_order( $raw ), 'invalid_menu_order' );
        }
    }

    public function test_usable_is_what_translate_content_asks_of_a_parents_version(): void {
        self::assertTrue( ContentParent::usable( 10, 'page' ) );
        self::assertFalse( ContentParent::usable( 10, 'guide' ), 'another type' );
        self::assertFalse( ContentParent::usable( 30, 'page' ), 'trashed' );
        self::assertFalse( ContentParent::usable( 999, 'page' ), 'gone' );
        self::assertFalse( ContentParent::usable( 0, 'page' ) );
    }
}
