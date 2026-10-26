<?php

namespace Fabrement\Tests\Wpml;

use Fabrement\Modules\Translation\Translation;
use WP_UnitTestCase;

/**
 * A test on the three-language site: en (default), fr, pl. The database is rolled back after each
 * test; what WPML and Fabrement keep in memory is reset here, and every test starts in English.
 */
abstract class WpmlTestCase extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->forget_memory();
    }

    public function tear_down(): void {
        $this->forget_memory();
        parent::tear_down();
    }

    private function forget_memory(): void {
        do_action( 'wpml_switch_language', 'en' );
        if ( function_exists( 'icl_cache_clear' ) ) {
            icl_cache_clear();
        }
        do_action( 'wpml_cache_clear' );
        Translation::set( null );
        Translation::forget_languages();
    }

    /** An MCP tool, through the Abilities API (schema and permission included). @return mixed */
    protected function tool( string $name, array $input ) {
        return wp_get_ability( 'fabrement/' . $name )->execute( $input );
    }

    /** Record `$id`'s version in `$lang`, 0 when it has none. */
    protected function version( int $id, string $lang ): int {
        $type = (string) get_post_type( $id );
        return (int) apply_filters( 'wpml_object_id', $id, $type, false, $lang );
    }
}
