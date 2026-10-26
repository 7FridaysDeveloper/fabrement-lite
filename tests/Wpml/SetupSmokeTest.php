<?php

namespace Fabrement\Tests\Wpml;

use Fabrement\Modules\Translation\Translation;

/** The site the suite promises: three languages, and every plugin's wpml-config.xml read. */
final class SetupSmokeTest extends WpmlTestCase {

    public function test_three_languages_english_first(): void {
        $bridge = Translation::bridge();
        $this->assertTrue( $bridge->is_active() );
        $this->assertSame( 'en', $bridge->default_language() );
        $this->assertEqualsCanonicalizing( [ 'en', 'fr', 'pl' ], array_column( $bridge->languages(), 'code' ) );
        $this->assertTrue( Translation::is_multilingual() );
    }

    public function test_the_wpml_config_of_yoast_and_fabrement_is_read(): void {
        global $sitepress;
        $fields = (array) ( $sitepress->get_setting( 'translation-management', [] )['custom_fields_translation'] ?? [] );
        // WPML_COPY_CUSTOM_FIELD = 1, WPML_TRANSLATE_CUSTOM_FIELD = 2, WPML_IGNORE_CUSTOM_FIELD = 0, copy-once = 3.
        $this->assertSame( 2, (int) ( $fields['_yoast_wpseo_title'] ?? -1 ), 'Yoast: title is translated' );
        $this->assertSame( 0, (int) ( $fields['_yoast_wpseo_canonical'] ?? -1 ), 'Yoast: canonical is left alone' );
        $this->assertSame( 1, (int) ( $fields['_yoast_wpseo_meta-robots-noindex'] ?? -1 ), 'Yoast SEO Multilingual: noindex is copied' );
        $this->assertSame( 1, (int) ( $fields['_fabrement_archive_for'] ?? -1 ), 'Fabrement: the archive marker is copied' );
    }

    public function test_translate_content_writes_into_the_manual_editor(): void {
        $page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
        $this->assertTrue( Translation::bridge()->writes_translations_manually( $page ) );
    }
}
