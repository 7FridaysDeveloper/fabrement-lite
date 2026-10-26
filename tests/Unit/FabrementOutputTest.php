<?php

namespace Fabrement\Tests;

use Fabrement\Api\Fabrement;
use Fabrement\Api\FieldsException;
use Fabrement\Api\Markup\Link;
use Fabrement\Modules\Translation\Bridge\NullBridge;
use Fabrement\Modules\Translation\Translation;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/ConnectionSettingsRender.php';
require_once dirname( __DIR__, 2 ) . '/src/Api/helpers.php';

/**
 * fabrement()'s `escape` and `echo`, Link's address, words and attributes, and the per-request
 * memory of Version, on a single-language site without WordPress loaded.
 */
final class FabrementOutputTest extends TestCase {

    private const TITLE = 'Fish <b>&</b> Chips';

    protected function setUp(): void {
        if ( ! defined( 'WP_CONTENT_DIR' ) ) {
            define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/fabrement-tests-' . getmypid() );
        }
        WordPressState::reset();
        WordPressState::select_user( 0, [] );
        Translation::set( new NullBridge() );
    }

    protected function tearDown(): void {
        WordPressState::reset();
        Translation::set( null );
    }

    private function page( int $id, string $title, string $status = 'publish' ): void {
        WordPressState::$posts[ $id ] = new \WP_Post( (object) [
            'ID'          => $id,
            'post_title'  => $title,
            'post_type'   => 'page',
            'post_status' => $status,
        ] );
    }

    private static function printed( callable $fn ): array {
        ob_start();
        $returned = $fn();
        return [ $returned, (string) ob_get_clean() ];
    }

    public function test_posts_take_ids_only(): void {
        $this->page( 5, self::TITLE );
        $api = Fabrement::instance();
        $ids = static fn( array $posts ): array => array_map( static fn( $p ): int => $p->id(), $posts );
        self::assertSame( [ 5 ], $ids( $api->posts( [ 5 ], status: 'publish' ) ) );
        self::assertSame( [ 5 ], $ids( $api->posts( [ 5 ], type: 'page', limit: 5, status: 'publish' ) ), 'type and limit are unknown options, left out' );
        self::assertSame( [], $api->posts(), 'nothing asked: nothing' );
    }
}
