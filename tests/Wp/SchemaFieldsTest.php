<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Seo\Schema\SchemaStore;
use WP_Error;
use WP_UnitTestCase;

/**
 * {{field:…}} / {{option:…}} in schema templates, on real WordPress: field files written by the
 * real tools, values set through the fields API, the graph resolved as a crawler gets it.
 */
final class SchemaFieldsTest extends WP_UnitTestCase {

    private int $teacher;
    private int $course;
    private int $photo;

    public function set_up(): void {
        parent::set_up();
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            $this->markTestSkipped( 'Structured data is the output of the SEO plugin: Yoast SEO is not loaded in this run.' );
        }
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        update_option( 'timezone_string', 'Europe/Kyiv' );
        register_post_type( 'qa_teacher', [ 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'label' => 'Teachers', 'supports' => [ 'title', 'editor', 'thumbnail' ] ] );
        register_post_type( 'qa_course', [ 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'label' => 'Courses', 'supports' => [ 'title', 'editor', 'thumbnail' ] ] );

        $this->fields( 'qa_teacher', [
            $this->f( 'position', 'text' ),
        ] );
        $this->fields( 'qa_course', [
            $this->f( 'price', 'number', [ 'format' => 'decimal' ] ),
            $this->f( 'level', 'select', [ 'choices' => [ [ 'value' => 'beginner', 'label' => 'For beginners' ], [ 'value' => 'pro', 'label' => 'For pros' ] ], 'default' => 'beginner' ] ),
            $this->f( 'starts', 'date_time_picker' ),
            $this->f( 'day', 'date_picker' ),
            $this->f( 'free', 'true_false' ),
            $this->f( 'photo', 'image' ),
            $this->f( 'site', 'link' ),
            $this->f( 'notes', 'wysiwyg' ),
            $this->f( 'teacher', 'post_object', [ 'postTypes' => [ 'qa_teacher' ] ] ),
            $this->f( 'venue', 'group', [ 'sub_fields' => [ $this->f( 'city', 'text' ), $this->f( 'kind', 'select', [ 'choices' => [ [ 'value' => 'hall', 'label' => 'Concert hall' ] ] ] ) ] ] ),
            $this->f( 'modules', 'repeater', [ 'sub_fields' => [ $this->f( 'title', 'text' ) ] ] ),
        ] );

        $this->photo   = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
        update_post_meta( $this->photo, '_wp_attachment_image_alt', 'A canola field' );
        $this->teacher = self::factory()->post->create( [ 'post_type' => 'qa_teacher', 'post_title' => 'Olena', 'post_status' => 'publish' ] );
        set_post_thumbnail( $this->teacher, $this->photo );
        fabrement()->fields( $this->teacher )->set( 'position', 'Lead designer' )->save();

        $this->course = self::factory()->post->create( [ 'post_type' => 'qa_course', 'post_title' => 'UX basics', 'post_status' => 'publish' ] );
        foreach ( [
            'price'   => 59.9,
            'starts'  => '2026-07-15T18:00',
            'day'     => '2026-07-01',
            'free'    => false,
            'photo'   => $this->photo,
            'site'    => [ 'url' => 'https://example.com/ux', 'label' => 'Course site' ],
            'notes'   => '<p>Bring a <strong>laptop</strong>.</p>',
            'teacher' => $this->teacher,
            'venue'   => [ 'city' => 'Lviv', 'kind' => 'hall' ],
        ] as $name => $value ) {
            $ok = fabrement()->fields( $this->course )->set( $name, $value )->save();
            $this->assertTrue( $ok, $name );
        }
    }

    public function tear_down(): void {
        foreach ( [ 'qa_teacher', 'qa_course' ] as $type ) {
            SchemaRepository::delete( Owner::of( 'post', $type ) );
        }
        if ( $owner = Owner::of( 'options', 'qa-site' ) ) {
            SchemaRepository::delete( $owner );
        }
        SchemaRepository::flush();
        parent::tear_down();
    }

    private function f( string $name, string $type, array $extra = [] ): array {
        return $extra + [ 'name' => $name, 'label' => ucfirst( $name ), 'description' => 'About ' . $name, 'type' => $type ];
    }

    private function fields( string $type, array $fields ): void {
        $out = wp_get_ability( 'fabrement/save-fields' )->execute( [ 'type' => 'post', 'slug' => $type, 'expectedRevision' => 0, 'fields' => $fields ] );
        $this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
    }

    private function set( array $input ) {
        return wp_get_ability( 'fabrement/set-schema' )->execute( $input );
    }

    /** Resolve one property template on the course. */
    private function on_course( array $props ): array {
        $out = $this->set( [ 'contentId' => $this->course, 'graph' => [ [ '@type' => 'Course' ] + $props ] ] );
        $this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_code() . ': ' . $out->get_error_message() : '' );

        return SchemaStore::resolve_for_post( $this->course )[0];
    }

    public function test_each_type_as_schema_text(): void {
        $node = $this->on_course( [
            'price'  => '{{field:price}}',
            'level'  => '{{field:level}}',
            'label'  => '{{field:level.label}}',
            'free'   => '{{field:free}}',
            'day'    => '{{field:day}}',
            'image'  => '{{field:photo}}',
            'alt'    => '{{field:photo.alt}}',
            'url'    => '{{field:site}}',
            'anchor' => '{{field:site.label}}',
            'notes'  => '{{field:notes}}',
            'city'   => '{{field:venue.city}}',
            'kind'   => '{{field:venue.kind.label}}',
        ] );

        $this->assertSame( '59.9', $node['price'] );
        $this->assertSame( 'beginner', $node['level'], 'never set: the default, as the page shows it' );
        $this->assertSame( 'For beginners', $node['label'] );
        $this->assertSame( 'false', $node['free'] );
        $this->assertSame( '2026-07-01', $node['day'] );
        $this->assertSame( wp_get_attachment_image_url( $this->photo, 'full' ), $node['image'] );
        $this->assertSame( 'A canola field', $node['alt'] );
        $this->assertSame( 'https://example.com/ux', $node['url'] );
        $this->assertSame( 'Course site', $node['anchor'] );
        $this->assertSame( 'Bring a laptop.', $node['notes'] );
        $this->assertSame( 'Lviv', $node['city'] );
        $this->assertSame( 'Concert hall', $node['kind'] );
    }

    public function test_date_time_carries_the_sites_offset_on_that_date(): void {
        fabrement()->fields( $this->course )->set( 'starts', '2026-07-15T18:00' )->save();
        $summer = $this->on_course( [ 'startDate' => '{{field:starts}}', 'd' => '{{field:starts.date}}', 't' => '{{field:starts.time}}', 'u' => '{{field:starts.utc}}' ] );
        $this->assertSame( '2026-07-15T18:00:00+03:00', $summer['startDate'] );
        $this->assertSame( '2026-07-15', $summer['d'] );
        $this->assertSame( '18:00', $summer['t'] );
        $this->assertSame( '2026-07-15T15:00:00Z', $summer['u'] );

        fabrement()->fields( $this->course )->set( 'starts', '2026-12-15T18:00' )->save();
        $winter = $this->on_course( [ 'startDate' => '{{field:starts}}' ] );
        $this->assertSame( '2026-12-15T18:00:00+02:00', $winter['startDate'], 'winter time: another offset' );
    }

    public function test_a_picked_record_by_url_title_image_and_its_own_fields(): void {
        $node = $this->on_course( [ 'instructor' => [
            '@type'    => 'Person',
            'url'      => '{{field:teacher}}',
            'name'     => '{{field:teacher.title}}',
            'image'    => '{{field:teacher.image}}',
            'jobTitle' => '{{field:teacher.fields.position}}',
        ] ] );

        $this->assertSame( [
            '@type'    => 'Person',
            'url'      => get_permalink( $this->teacher ),
            'name'     => 'Olena',
            'image'    => wp_get_attachment_image_url( $this->photo, 'full' ),
            'jobTitle' => 'Lead designer',
        ], $node['instructor'] );
    }

    public function test_an_unpublished_or_locked_picked_record_says_nothing(): void {
        wp_update_post( [ 'ID' => $this->teacher, 'post_status' => 'draft' ] );
        $node = $this->on_course( [ 'instructor' => [ '@type' => 'Person', 'name' => '{{field:teacher.title}}', 'url' => '{{field:teacher}}' ] ] );
        $this->assertSame( [ '@type' => 'Person' ], $node['instructor'], 'a draft is not named in a public graph' );

        wp_update_post( [ 'ID' => $this->teacher, 'post_status' => 'publish', 'post_password' => 'x' ] );
        $node = $this->on_course( [ 'instructor' => [ '@type' => 'Person', 'name' => '{{field:teacher.title}}', 'jobTitle' => '{{field:teacher.fields.position}}' ] ] );
        $this->assertSame( 'Olena', $node['instructor']['name'] );
        $this->assertArrayNotHasKey( 'jobTitle', $node['instructor'], 'its fields stay behind its password' );
    }

    public function test_unknown_paths_are_refused_with_what_exists(): void {
        $cases = [
            '{{field:prise}}'                   => 'unknown_field',
            '{{field:venue.town}}'              => 'unknown_field',
            '{{field:modules}}'                 => 'field_not_text',
            '{{field:venue}}'                   => 'field_not_text',
            '{{field:price.label}}'             => 'unknown_modifier',
            '{{field:starts.year}}'             => 'unknown_modifier',
            '{{field:teacher.fields.salary}}'   => 'unknown_field',
            '{{option:nope.phone}}'             => 'unknown_option',
        ];
        foreach ( $cases as $token => $code ) {
            $out = $this->set( [ 'contentId' => $this->course, 'graph' => [ [ '@type' => 'Course', 'name' => $token ] ] ] );
            $this->assertInstanceOf( WP_Error::class, $out, $token );
            $this->assertSame( $code, $out->get_error_code(), $token . ': ' . $out->get_error_message() );
        }
        $out = $this->set( [ 'postType' => 'qa_course', 'graph' => [ [ '@type' => 'Course', 'name' => '{{field:prise}}' ] ] ] );
        $this->assertStringContainsString( 'price', $out->get_error_message(), 'the message lists the real fields' );
        $this->assertSame( [], SchemaStore::read_type( 'qa_course' ) );
    }

    public function test_options_page_values(): void {
        $page = wp_get_ability( 'fabrement/save-options-page' )->execute( [
            'slug'             => 'qa-site',
            'expectedRevision' => 0,
            'page'             => [ 'title' => 'QA site' ],
            'fields'           => [ $this->f( 'phone', 'text' ), $this->f( 'logo', 'image' ) ],
        ] );
        $this->assertIsArray( $page, is_wp_error( $page ) ? $page->get_error_message() : '' );
        fabrement()->options( 'qa-site' )->set( 'phone', '+380 44 000 00 00' )->save();

        $node = $this->on_course( [ 'provider' => [ '@type' => 'Organization', 'telephone' => '{{option:qa-site.phone}}', 'logo' => '{{option:qa-site.logo}}' ] ] );

        $this->assertSame( [ '@type' => 'Organization', 'telephone' => '+380 44 000 00 00' ], $node['provider'], 'an unset image drops out' );
        $this->assertSame( 'unknown_field', $this->set( [ 'contentId' => $this->course, 'graph' => [ [ '@type' => 'Course', 'name' => '{{option:qa-site.fax}}' ] ] ] )->get_error_code() );
    }

    public function test_warnings_say_what_will_be_thinner_than_it_looks(): void {
        $this->assertTrue( fabrement()->fields( $this->course )->clear( 'site' )->save() );
        $out = $this->set( [ 'postType' => 'qa_course', 'graph' => [ [
            '@type'     => 'Event',
            'name'      => '{{title}}',
            'startDate' => '{{field:starts}}',
            'url'       => '{{field:site}}',
        ] ] ] );
        $this->assertIsArray( $out );
        $all = implode( "\n", $out['warnings'] ?? [] );

        $this->assertStringContainsString( 'Event has no location', $all );
        $this->assertStringContainsString( 'graph[0].url resolved empty on record ' . $this->course, $all );
        $this->assertStringNotContainsString( 'startDate', $all, 'present and filled: nothing to say' );
    }
}
