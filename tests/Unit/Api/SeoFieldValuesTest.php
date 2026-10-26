<?php

namespace Fabrement\Tests\Api;

use Fabrement\Api\Fabrement;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Seo\Content\ContentImages;
use Fabrement\Modules\Seo\Content\FieldText;
use Fabrement\Modules\Seo\Schema\SchemaFields;
use Fabrement\Modules\Translation\Contract\TranslationBridge;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Tests\WordPressState;

require_once __DIR__ . '/ApiTestCase.php';

/** SEO reads nullable fields while the released helpers retain their older return shapes. */
final class SeoFieldValuesTest extends ApiTestCase {
    private array $schemas = [];

    protected function setUp(): void {
        parent::setUp();
        $field = static fn( string $name, string $type, array $extra = [] ): array => $extra + [
            'name' => $name, 'key' => 'fab_' . $name, 'type' => $type, 'label' => $name,
        ];
        $choice = [ 'choices' => [ [ 'value' => 'pro', 'label' => 'Professional' ] ], 'default' => 'pro' ];
        $fields = [
            $field( 'enabled', 'true_false', [ 'default' => true ] ),
            $field( 'unset', 'true_false' ),
            $field( 'amount', 'number', [ 'default' => 7 ] ),
            $field( 'level', 'select', $choice ),
            $field( 'group', 'group', [ 'sub_fields' => [
                [ 'name' => 'enabled', 'key' => 'enabled', 'type' => 'true_false', 'label' => 'Enabled', 'default' => true ],
                [ 'name' => 'level', 'key' => 'level', 'type' => 'select', 'label' => 'Level' ] + $choice,
            ] ] ),
            $field( 'image', 'image' ), $field( 'gallery', 'gallery' ),
            $field( 'person', 'post_object' ), $field( 'link', 'link' ),
        ];
        wp_mkdir_p( SchemaRepository::dir() );
        foreach ( [ 'options' => 'qa-seo-null', 'post' => 'page' ] as $type => $slug ) {
            $path = SchemaRepository::dir() . '/' . $type . '.' . $slug . '.json';
            $this->schemas[] = $path;
            file_put_contents( $path, json_encode( [
                'schemaVersion' => 1, 'type' => $type, 'slug' => $slug, 'revision' => 1, 'fields' => $fields,
            ] ) );
        }
        SchemaRepository::flush();
        $this->page( 901, 'SEO record' );
    }

    protected function tearDown(): void {
        foreach ( $this->schemas as $path ) {
            unlink( $path );
        }
        SchemaRepository::flush();
        parent::tearDown();
    }

    private function store( array $values ): void {
        WordPressState::$options['fabrement_options_qa-seo-null'] = $values;
        WordPressState::$post_meta[901] = $values;
    }

    public function test_schema_distinguishes_cleared_switch_from_false_for_posts_options_and_groups(): void {
        self::assertSame( 'true', SchemaFields::field( 'enabled', 901 ) );
        self::assertSame( 'true', SchemaFields::option( 'qa-seo-null.enabled' ) );
        self::assertSame( '', SchemaFields::field( 'unset', 901 ) );
        foreach ( [ '' => '', '0' => 'false', '1' => 'true' ] as $stored => $expected ) {
            $this->store( [ 'fab_enabled' => (string) $stored, 'fab_group' => '1', 'fab_group_enabled' => (string) $stored ] );
            foreach ( [ 'enabled', 'group.enabled' ] as $path ) {
                self::assertSame( $expected, SchemaFields::field( $path, 901 ) );
                self::assertSame( $expected, SchemaFields::option( 'qa-seo-null.' . $path ) );
            }
        }
        $this->store( [ 'fab_enabled' => '' ] );
        self::assertNull( Fabrement::instance()->fields( 901 )->get_true_false( 'enabled' ) );
        self::assertFalse( fabrement_get_field( 'enabled', 901 ) );
        self::assertFalse( fabrement_get_option( 'enabled', 'qa-seo-null' ) );
    }

    public function test_schema_preserves_zero_defaults_and_choice_labels_after_switching_readers(): void {
        self::assertSame( '7', SchemaFields::option( 'qa-seo-null.amount' ) );
        self::assertSame( 'Professional', SchemaFields::option( 'qa-seo-null.group.level.label' ) );
        self::assertSame( 'Professional', FieldText::text( [ 'path' => 'group.level', 'count' => false ], 'options:qa-seo-null' ) );
        $this->store( [ 'fab_amount' => '0', 'fab_level' => '' ] );
        self::assertSame( '0', SchemaFields::field( 'amount', 901 ) );
        self::assertSame( '', SchemaFields::field( 'level.label', 901 ) );
        self::assertSame( '', FieldText::text( [ 'path' => 'level', 'count' => false ], 901 ) );
        $this->store( [ 'fab_amount' => '' ] );
        self::assertSame( '', SchemaFields::option( 'qa-seo-null.amount' ) );
    }

    public function test_reference_ids_still_follow_reader_language_and_empty_media_is_skipped(): void {
        $bridge = $this->createStub( TranslationBridge::class );
        $bridge->method( 'current_language' )->willReturn( 'uk' );
        $bridge->method( 'default_language' )->willReturn( 'en' );
        $bridge->method( 'languages' )->willReturn( [ [ 'code' => 'en' ], [ 'code' => 'uk' ] ] );
        $bridge->method( 'translation_mode' )->willReturn( 'translate' );
        $bridge->method( 'version_in' )->willReturnCallback( static fn( $id, $type, $lang = '' ) => $id + 100 );
        $bridge->method( 'all_versions' )->willReturnCallback( static fn( $id, $type ) => [ 'en' => $id, 'uk' => $id + 100 ] );
        $this->page( 10, 'Original person' );
        $this->page( 110, 'Translated person' );
        foreach ( [ 107, 108, 109 ] as $id ) {
            $this->page( $id, 'Translated image' );
            WordPressState::$posts[ $id ]->post_type = 'attachment';
            WordPressState::$posts[ $id ]->post_mime_type = 'image/jpeg';
            WordPressState::$posts[ $id ]->post_status = 'inherit';
        }
        Translation::set( $bridge );
        $this->store( [ 'fab_image' => '7', 'fab_gallery' => [ '8', '9' ], 'fab_person' => '10' ] );
        self::assertSame( 7, Fabrement::instance()->fields( 901 )->get( 'image' ), 'Raw reads keep canonical ids.' );
        self::assertSame( 107, SchemaFields::leaf( [ 'image' ], 901 )['value']->id() );
        self::assertSame( [ 108, 109 ], array_map( static fn( $image ) => $image->id(), SchemaFields::leaf( [ 'gallery' ], 901 )['value'] ) );
        $collect = new \ReflectionMethod( ContentImages::class, 'from_fields' );
        $collect->setAccessible( true );
        $ids = [];
        $collect->invokeArgs( null, [ 901, &$ids ] );
        self::assertSame( [ 107, 108, 109 ], array_keys( $ids ) );
        $this->store( [ 'fab_image' => '0', 'fab_gallery' => null ] );
        $ids = [];
        $collect->invokeArgs( null, [ 901, &$ids ] );
        self::assertSame( [], $ids );
        self::assertNull( SchemaFields::leaf( [ 'image' ], 901 )['value'] );
    }
}
