<?php

namespace {
    require_once ABSPATH . WPINC . '/class-wp-term.php';
    require_once ABSPATH . WPINC . '/class-wp-taxonomy.php';

    // The pattern category taxonomy and its terms, as the test sets them.
    if ( ! function_exists( 'get_taxonomy' ) ) {
        function get_taxonomy( $name ) { return $name === 'wp_pattern_category' ? Fabrement\Tests\SyncedPatternsAccessTest::$taxonomy : false; }
    }
    if ( ! function_exists( 'taxonomy_exists' ) ) {
        function taxonomy_exists( $name ) { return $name === 'wp_pattern_category'; }
    }
    if ( ! function_exists( 'get_term_by' ) ) {
        function get_term_by( $field, $value, $taxonomy ) {
            foreach ( Fabrement\Tests\SyncedPatternsAccessTest::$terms as $term ) {
                if ( ( $field === 'slug' ? $term->slug : $term->name ) === $value ) {
                    return $term;
                }
            }
            return false;
        }
    }
}

namespace Fabrement\Tests {

    use Fabrement\Modules\SiteTemplate\Service\SyncedPatterns;
    use PHPUnit\Framework\TestCase;

    /** What a user who may not see or make something is told about it. */
    final class SyncedPatternsAccessTest extends TestCase {

        public static ?\WP_Taxonomy $taxonomy = null;

        /** @var list<\WP_Term> */
        public static array $terms = [];

        protected function setUp(): void {
            WordPressState::reset();
            // Types that do not map meta caps: read_post is the type's own cap, nothing else asked.
            WordPressState::$types['open']   = (object) [ 'name' => 'open', 'map_meta_cap' => false, 'cap' => (object) [ 'read_post' => 'read_open' ] ];
            WordPressState::$types['secret'] = (object) [ 'name' => 'secret', 'map_meta_cap' => false, 'cap' => (object) [ 'read_post' => 'read_secret' ] ];
            foreach ( [ 1 => 'open', 2 => 'secret', 3 => 'secret' ] as $id => $type ) {
                $post            = new \WP_Post( (object) [ 'ID' => $id ] );
                $post->post_type = $type;
                WordPressState::$posts[ $id ] = $post;
            }
            self::$taxonomy      = ( new \ReflectionClass( \WP_Taxonomy::class ) )->newInstanceWithoutConstructor();
            self::$taxonomy->cap = (object) [ 'edit_terms' => 'edit_qa_categories' ];
            self::$terms         = [];
        }

        public function test_a_refusal_names_what_the_user_may_read_and_counts_the_rest(): void {
            WordPressState::select_user( 7, [ 'read_open' ] );
            $listed = SyncedPatterns::refusal_list(
                [ [ 'id' => 1, 'title' => 'Home' ], [ 'id' => 2, 'title' => 'Payroll' ], [ 'id' => 3, 'title' => 'Board' ] ],
                static fn( array $p ): string => $p['title']
            );

            self::assertSame( 'Home; and 2 you cannot open', $listed['text'] );
            self::assertSame( [ 1 ], array_column( $listed['readable'], 'id' ) );
            self::assertSame( 2, $listed['hidden'] );
        }

        public function test_making_a_pattern_category_needs_the_taxonomys_edit_terms(): void {
            self::$terms = [ $this->term( 'header', 'Headers' ) ];

            self::assertSame( [ 'Testimonials' ], SyncedPatterns::terms_to_write( 'header', [ 'testimonials' ] ), 'Headers exists; Testimonials would be made' );
            self::assertSame( [], SyncedPatterns::terms_to_write( 'section', [] ) );

            WordPressState::select_user( 7, [ 'read' ] );
            self::assertFalse( SyncedPatterns::may_edit_categories() );
            WordPressState::select_user( 7, [ 'edit_qa_categories' ] );
            self::assertTrue( SyncedPatterns::may_edit_categories() );
        }

        public function test_an_old_kind_name_is_a_rename(): void {
            self::$terms = [ $this->term( 'header', 'Header' ) ];

            self::assertSame( [ 'Headers' ], SyncedPatterns::terms_to_write( 'header', null ) );
        }

        private function term( string $slug, string $name ): \WP_Term {
            $term       = ( new \ReflectionClass( \WP_Term::class ) )->newInstanceWithoutConstructor();
            $term->slug = $slug;
            $term->name = $name;
            return $term;
        }
    }
}
