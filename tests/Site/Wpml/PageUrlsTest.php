<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\ThemeFields;
use Fabrement\Tests\Site\Support\WpmlSite;

/** Actual page addresses across edits, translation linking and a change of the site's default. */
final class PageUrlsTest extends SiteTestCase {

    protected const SITE = 'wpml';

    protected function setUp(): void {
        parent::setUp();
        ThemeFields::install( $this->site() );
    }

    private function page( string $slug, string $lang = 'en' ): int {
        $id = (int) $this->mcp( 'save-content', [ 'mode' => 'create', 'postType' => 'page', 'title' => $slug, 'slug' => $slug,
            'language' => $lang, 'newOriginal' => true, 'blocks' => [] ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $id, 'status' => 'publish' ] );
        return $id;
    }

    private function translate( int $id, string $lang, string $slug ): int {
        $translated = (int) $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => $lang, 'slug' => $slug ] )['contentId'];
        $this->mcp( 'set-content-status', [ 'contentId' => $translated, 'status' => 'publish' ] );
        return $translated;
    }

    private function url( int $id ): string {
        return $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    private function edit( int $id, array $changes ): array {
        return $this->mcp( 'save-content', [ 'mode' => 'add', 'blocks' => [], 'contentId' => $id ] + $changes );
    }

    private function change_default( string $language ): void {
        $this->assertSame( $language, $this->site_runs( "global \$sitepress;
            \$sitepress->set_default_language( '$language' ); wp_cache_flush();
            return apply_filters( 'wpml_default_language', null );" ), 'the site default actually changes' );
    }

    private function assert_page( string $url, int $id, string $lang ): void {
        $response = $this->get( $url );
        $this->assertSame( 200, $response['status'], $url );
        $page = ThemeFields::read( $response['body'] );
        $this->assertSame( $id, $page['id'] ?? null, 'the URL opens the intended record' );
        $this->assertSame( $lang, $page['lang'] ?? null, 'the URL opens the intended language' );
    }

    public function test_linking_independent_pages_keeps_their_slugs_and_addresses(): void {
        $en = $this->page( 'qa-independent-en' );
        $fr = $this->page( 'qa-independante-fr', 'fr' );
        $before = [ $this->url( $en ), $this->url( $fr ) ];
        $wpml = new WpmlSite( $this->site() );
        $wpml->link( $fr, $wpml->trid( $en ), 'fr' );
        $this->assertSame( $before, [ $this->url( $en ), $this->url( $fr ) ] );
        $this->assertSame( $wpml->trid( $en ), $wpml->trid( $fr ) );
        $this->assert_page( $before[0], $en, 'en' );
        $this->assert_page( $before[1], $fr, 'fr' );

        $this->page( 'qa-taken-fr', 'fr' );
        $changed = $this->edit( $fr, [ 'slug' => 'qa-taken-fr' ] );
        $this->assertSame( 'qa-taken-fr-2', $changed['slug'], 'a taken slug gets a unique suffix' );
        $this->assert_page( $changed['url'], $fr, 'fr' );
        $this->assertSame( $before[0], $this->url( $en ) );
    }

    /** @group default-language */
    public function test_a_shared_slug_at_the_unprefixed_address_now_opens_the_new_default_language(): void {
        $en = $this->page( 'qa-shared-address' );
        $fr = $this->translate( $en, 'fr', 'qa-shared-address' );
        // The create path reserves a unique slug before assigning the language; once linked,
        // WPML accepts this explicit shared slug on the existing translation.
        $this->edit( $fr, [ 'slug' => 'qa-shared-address' ] );
        $unprefixed = $this->url( $en );
        $this->assertSame( $this->site()->url( '/fr/qa-shared-address/' ), $this->url( $fr ), 'WPML allows the same slug in different languages' );
        $this->assert_page( $unprefixed, $en, 'en' );
        try {
            $this->change_default( 'fr' );
            $this->assertSame( $unprefixed, $this->url( $fr ) );
            $this->assert_page( $unprefixed, $fr, 'fr' );
            $this->assert_page( $this->site()->url( '/en/qa-shared-address/' ), $en, 'en' );
        } finally {
            $this->change_default( 'en' );
        }
        $this->assert_page( $unprefixed, $en, 'en' );
    }
}
