<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;

/**
 * The WPML test site the suite promises, seen from outside: three languages with English first and
 * each in its directory, every plugin's wpml-config.xml read, translations written by hand (the
 * WordPress editor), and the MCP server describing the languages to the agent.
 */
final class SiteTest extends SiteTestCase {

    protected const SITE = 'wpml';

    public function test_three_languages_english_first_each_at_its_own_address(): void {
        $languages = $this->mcp( 'list-languages' );
        $this->assertTrue( $languages['active'] );
        $this->assertSame( 'en', $languages['default'] );
        $this->assertSame( [ 'en', 'fr', 'pl' ], array_column( $languages['languages'], 'code' ) );
        $this->assertSame( [ true, false, false ], array_column( $languages['languages'], 'isDefault' ) );
        $this->assertTrue( $languages['capabilities']['stringTranslation'], 'String Translation is active' );

        foreach ( [ '/' => 'en-US', '/fr/' => 'fr-FR', '/pl/' => 'pl-PL' ] as $path => $locale ) {
            $home = $this->get( $path );
            $this->assertSame( 200, $home['status'], $path );
            $this->assertMatchesRegularExpression( '/<html[^>]+lang="' . $locale . '"/', $home['body'], $path );
            $this->assertNotNull( self::node( self::json_ld( $home['body'] ), 'WebSite' ), 'Yoast prints its graph at ' . $path );
        }
    }

    public function test_the_wpml_config_of_yoast_and_fabrement_is_read(): void {
        $settings = (array) $this->site()->option( 'icl_sitepress_settings' );
        $fields   = (array) ( $settings['translation-management']['custom_fields_translation'] ?? [] );
        // WPML_IGNORE_CUSTOM_FIELD = 0, WPML_COPY_CUSTOM_FIELD = 1, WPML_TRANSLATE_CUSTOM_FIELD = 2.
        $this->assertSame( 2, (int) ( $fields['_yoast_wpseo_title'] ?? -1 ), 'Yoast: title is translated' );
        $this->assertSame( 0, (int) ( $fields['_yoast_wpseo_canonical'] ?? -1 ), 'Yoast: canonical is left alone' );
        $this->assertSame( 1, (int) ( $fields['_yoast_wpseo_meta-robots-noindex'] ?? -1 ), 'Yoast SEO Multilingual: noindex is copied' );
    }

    public function test_translations_are_written_by_hand_in_the_wordpress_editor(): void {
        $settings = (array) $this->site()->option( 'icl_sitepress_settings' );
        $this->assertSame( 0, (int) ( $settings['translation-management']['doc_translation_method'] ?? -1 ), 'the manual editor, which translate-content writes into' );

        $page = $this->record( 'page', 'Hand written' );
        $fr   = (int) $this->mcp( 'translate-content', [ 'contentId' => $page, 'language' => 'fr' ] )['contentId'];
        // The version's text is the agent's to write: no translation editor owns it.
        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $fr, 'title' => 'Écrit à la main' ] );
        $this->assertSame( 'Écrit à la main', $this->site()->value( 'SELECT post_title FROM wp_posts WHERE ID = ?', [ $fr ] ) );
    }

    public function test_the_agent_is_told_about_languages_only_where_they_exist(): void {
        $tools = array_column( $this->client()->tools(), null, 'name' );
        $this->assertArrayHasKey( 'fabrement-translate-content', $tools );
        $this->assertArrayHasKey( 'language', $tools['fabrement-set-field-values']['inputSchema']['properties'], 'an options page has a row per language' );
    }
}
