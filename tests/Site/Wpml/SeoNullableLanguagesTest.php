<?php

namespace Fabrement\Tests\Site\Wpml;

use Fabrement\Tests\Site\Support\SeoPage;
use Fabrement\Tests\Site\Support\SiteTestCase;
use Fabrement\Tests\Site\Support\WpmlSite;

/** Real language pages retain nullable fields and resolve SEO references in the visitor's language. */
final class SeoNullableLanguagesTest extends SiteTestCase {

    protected const SITE = 'wpml';

    /** @param list<array{text:string,context?:string,value:string}> $strings */
    private function seed_translations( array $strings ): void {
        $result = $this->site_runs( '$strings = ' . var_export( $strings, true ) . ';' . <<<'PHP'
if (!function_exists('icl_register_string') || !function_exists('icl_add_string_translation')) {
    throw new \RuntimeException('The test needs WPML String Translation.');
}
foreach ($strings as $string) {
    $context = $string['context'] ?? '';
    $domain = $context === '' ? 'fabrement-site' : ['domain' => 'fabrement-site', 'context' => $context];
    $id = (int) icl_register_string($domain, md5($string['text']), $string['text'], false, 'en');
    if ($id <= 0) { throw new \RuntimeException('Could not register a test translation.'); }
    if (!(int) icl_add_string_translation($id, 'fr', $string['value'], ICL_TM_COMPLETE)) {
        throw new \RuntimeException('Could not save a test translation.');
    }
}
return count($strings);
PHP
        );
        $this->assertSame( count( $strings ), $result, 'Native WPML translation fixtures were registered.' );
    }

    private function version( int $id, string $slug, string $title ): int {
        ( new WpmlSite( $this->site() ) )->use_editor( $id, 'native' );
        $fr = (int) $this->mcp( 'translate-content', [ 'contentId' => $id, 'language' => 'fr', 'slug' => $slug ] )['contentId'];
        $this->mcp( 'save-content', [ 'mode' => 'add', 'contentId' => $fr, 'title' => $title ] );
        $this->mcp( 'set-content-status', [ 'contentId' => $fr, 'status' => 'publish' ] );
        return $fr;
    }

    private function url( int $id ): string {
        return (string) $this->mcp( 'get-content', [ 'contentId' => $id ] )['url'];
    }

    private function page( int $id ): SeoPage {
        $url = $this->url( $id );
        $response = $this->get( $url );
        $this->assertSame( 200, $response['status'], $url );
        return SeoPage::of( $response['body'] );
    }

    private function event( int $id ): array {
        $node = $this->page( $id )->node_of_type( 'Event' );
        $this->assertNotNull( $node );
        return $node;
    }

    public function test_a_french_clear_stays_absent_in_schema_when_original_post_and_options_change(): void {
        $flag = self::field( 'wn_flag', 'true_false', [ 'default' => true, 'translation' => 'copy_once' ] );
        $fields = [ $flag, self::field( 'wn_details', 'group', [ 'sub_fields' => [ $flag ] ] ) ];
        $this->fields( 'page', $fields );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-seo-languages', 'expectedRevision' => 0, 'page' => [ 'title' => 'SEO languages' ], 'fields' => $fields ] );
        $en = $this->record( 'page', 'Nullable languages' );
        $this->mcp( 'set-field-values', [ 'postId' => $en, 'values' => [ 'wn_flag' => true, 'wn_details' => [ 'wn_flag' => true ] ] ] );
        $fr = $this->version( $en, 'valeurs-nulles', 'Valeurs nulles' );
        $this->mcp( 'set-schema', [ 'postType' => 'page', 'graph' => [ [
            '@type' => 'Event', '@id' => '{{url}}#event', 'name' => '{{title}}',
            'isAccessibleForFree' => '{{field:wn_flag}}',
            'location' => [ '@type' => 'Place', 'name' => 'Venue', 'isAccessibleForFree' => '{{field:wn_details.wn_flag}}' ],
            'organizer' => [ '@type' => 'Organization', 'name' => 'Organizer', 'isAccessibleForFree' => '{{option:qa-seo-languages.wn_flag}}',
                'additionalProperty' => [ '@type' => 'PropertyValue', 'name' => 'Nested', 'value' => '{{option:qa-seo-languages.wn_details.wn_flag}}' ] ],
        ] ] ] );
        $this->assertSame( 'true', $this->event( $fr )['organizer']['isAccessibleForFree'] );
        $this->assertSame( 'true', $this->event( $fr )['location']['isAccessibleForFree'] );

        $empty = [ 'wn_flag' => null, 'wn_details' => [ 'wn_flag' => null ] ];
        $this->mcp( 'set-field-values', [ 'postId' => $fr, 'values' => $empty ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-seo-languages', 'language' => 'fr', 'values' => $empty ] );
        $original = $this->event( $en );
        $this->assertSame( 'true', $original['isAccessibleForFree'] );
        $this->assertSame( 'true', $original['organizer']['isAccessibleForFree'] );

        foreach ( [ [ 'postId' => $en ], [ 'options' => 'qa-seo-languages', 'language' => 'en' ] ] as $target ) {
            $this->mcp( 'set-field-values', $target + [ 'values' => [ 'wn_flag' => false, 'wn_details' => [ 'wn_flag' => false ] ] ] );
        }
        $translated = $this->event( $fr );
        $this->assertArrayNotHasKey( 'isAccessibleForFree', $translated );
        $this->assertArrayNotHasKey( 'isAccessibleForFree', $translated['location'] );
        $this->assertArrayNotHasKey( 'isAccessibleForFree', $translated['organizer'] );
        $this->assertArrayNotHasKey( 'value', $translated['organizer']['additionalProperty'] );
        $this->assertNull( $this->mcp( 'get-field-values', [ 'postId' => $fr ] )['values']['wn_flag'] );
        $this->assertNull( $this->mcp( 'get-field-values', [ 'options' => 'qa-seo-languages', 'language' => 'fr' ] )['values']['wn_details'] );
        $this->assertSame( 'false', $this->event( $en )['isAccessibleForFree'] );

        $this->mcp( 'set-field-values', [ 'postId' => $fr, 'values' => [ 'wn_flag' => false ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-seo-languages', 'language' => 'fr', 'values' => [ 'wn_flag' => false ] ] );
        $translated = $this->event( $fr );
        $this->assertSame( 'false', $translated['isAccessibleForFree'] );
        $this->assertSame( 'false', $translated['organizer']['isAccessibleForFree'] );
    }

    public function test_schema_resolves_picked_posts_media_and_choice_labels_in_french(): void {
        $this->site_runs( "\\WPML\\Media\\Option::setNewContentSettings(['always_translate_media' => true, 'duplicate_media' => true, 'duplicate_featured' => true]); \\WPML\\Media\\Option::setShouldHandleMediaAuto(false);" );
        $this->fields( 'page', [
            self::field( 'ws_person', 'post_object', [ 'postTypes' => [ 'page' ], 'translation' => 'copy' ] ),
            self::field( 'ws_photo', 'image', [ 'translation' => 'copy' ] ),
            self::field( 'ws_gallery', 'gallery', [ 'translation' => 'copy' ] ),
            self::field( 'ws_people', 'relationship', [ 'postTypes' => [ 'page' ], 'translation' => 'copy' ] ),
            self::field( 'ws_link', 'link', [ 'translation' => 'copy' ] ),
            self::field( 'ws_pinned', 'link', [ 'translation' => 'copy' ] ),
            self::field( 'ws_file', 'file', [ 'translation' => 'copy' ] ),
            self::field( 'ws_rows', 'repeater', [ 'sub_fields' => [
                self::field( 'media', 'group', [ 'sub_fields' => [ self::field( 'photo', 'image', [ 'translation' => 'copy' ] ) ] ] ),
            ] ] ),
            self::field( 'ws_job', 'text', [ 'translation' => 'copy_once' ] ),
            self::field( 'ws_level', 'select', [ 'choices' => [ [ 'value' => 'basic', 'label' => 'Beginner & co' ] ], 'translation' => 'copy' ] ),
        ] );
        $image = $this->image( 'qa-seo-language-photo.png' );
        $fr_image = ( new WpmlSite( $this->site() ) )->version( $image, 'fr', 'attachment' );
        $this->assertGreaterThan( 0, $fr_image );
        $this->assertNotSame( $image, $fr_image );
        $media_url = $this->site_runs( "update_post_meta($fr_image, '_wp_attachment_image_alt', 'Visuel français'); return wp_get_attachment_url($image);" );
        $person = $this->record( 'page', 'English speaker' );
        $this->mcp( 'set-field-values', [ 'postId' => $person, 'values' => [ 'ws_job' => 'Teacher' ] ] );
        $fr_person = $this->version( $person, 'intervenante', 'Intervenante française' );
        $this->mcp( 'set-field-values', [ 'postId' => $fr_person, 'values' => [ 'ws_job' => 'Enseignante' ] ] );
        $en = $this->record( 'page', 'English event' );
        $this->mcp( 'set-field-values', [ 'postId' => $en, 'values' => [
            'ws_person' => $person, 'ws_photo' => $image, 'ws_gallery' => [ $image ], 'ws_level' => 'basic',
            'ws_people' => [ $person ], 'ws_link' => [ 'postId' => $person, 'useRecordTitle' => true ],
            'ws_pinned' => [ 'postId' => $person, 'useRecordTitle' => true, 'lang' => 'en' ],
            'ws_file' => $image, 'ws_rows' => [ [ 'media' => [ 'photo' => $image ] ] ],
        ] ] );
        $fr = $this->version( $en, 'evenement-schema', 'Événement français' );
        $this->seed_translations( [ [ 'text' => 'Beginner & co', 'context' => 'field ws_level', 'value' => 'Débutant & cie' ] ] );
        foreach ( [ $en, $fr ] as $id ) {
            $written = $this->mcp( 'set-seo', [ 'contentId' => $id, 'title' => 'Level %%fab_ws_level%%', 'metaDesc' => 'People %%fab_ws_people%%; link %%fab_ws_link%%.' ] );
            $this->assertSame( $id === $fr ? 'Level Débutant & cie' : 'Level Beginner & co', $written['effective']['title'], 'readback after rebuilding the index uses the record language' );
        }
        $this->mcp( 'set-schema', [ 'postType' => 'page', 'graph' => [ [
            '@type' => 'Event', '@id' => '{{url}}#event', 'name' => '{{title}}', 'image' => '{{field:ws_photo}}', 'description' => '{{field:ws_photo.alt}}',
            'performer' => [ '@type' => 'Person', 'name' => '{{field:ws_person.title}}', 'url' => '{{field:ws_person.url}}', 'jobTitle' => '{{field:ws_person.fields.ws_job}}' ],
            'audience' => [ '@type' => 'Audience', 'audienceType' => '{{field:ws_level.label}}' ],
            'sameAs' => '{{field:ws_link}}', 'alternateName' => '{{field:ws_link.label}}',
            'subjectOf' => [ '@type' => 'CreativeWork', 'url' => '{{field:ws_file}}' ],
            'organizer' => [ '@type' => 'Organization', 'url' => '{{field:ws_pinned}}', 'name' => '{{field:ws_pinned.label}}' ],
        ] ] ] );
        $this->site_code( 'qa-seo-media-cdn', "add_filter('wp_get_attachment_url', static fn(\$url, \$id) => (int) \$id === $fr_image ? \$url . '?qa-copy=fr' : \$url, 10, 2);" );
        try {
            $page = $this->page( $fr );
            $node = $page->node_of_type( 'Event' );
            $this->assertNotNull( $node );
            $this->assertSame( 'Level Débutant & cie', $page->title() );
            $seo = $this->mcp( 'get-seo', [ 'contentId' => $fr ] )['content'];
            $this->assertSame( $page->title(), $seo['effective']['title'], 'MCP establishes the record language itself' );
            $this->assertSame( $page->canonical(), $seo['effective']['canonical'] );
            $this->assertSame( $this->url( $fr ), $seo['url'] );
            $preview = array_values( array_filter( $seo['schema']['graph'], static fn( array $n ): bool => ( $n['@type'] ?? '' ) === 'Event' ) );
            $this->assertSame( [ $node ], $preview, 'MCP and the French page resolve the same related records, media and labels' );
            $this->assertSame( 'Débutant & cie', $node['audience']['audienceType'] );
            $this->assertSame( 'Intervenante française', $node['performer']['name'] );
            $this->assertSame( $this->url( $fr_person ), $node['performer']['url'] );
            $this->assertSame( 'Enseignante', $node['performer']['jobTitle'] );
            $this->assertSame( 'Visuel français', $node['description'] );
            $this->assertSame( $media_url . '?qa-copy=fr', $node['image'] );
            $this->assertSame( [ $media_url . '?qa-copy=fr' ], $page->og_images() );
            $this->assertSame( 'People Intervenante française; link Intervenante française.', $page->description() );
            $this->assertSame( $page->description(), $seo['effective']['description'] );
            $this->assertSame( $this->url( $fr_person ), $node['sameAs'] );
            $this->assertSame( 'Intervenante française', $node['alternateName'] );
            $this->assertSame( $media_url . '?qa-copy=fr', $node['subjectOf']['url'] );
            $this->assertSame( $this->url( $person ), $node['organizer']['url'], 'A link pinned to English keeps that language.' );
            $this->assertSame( 'English speaker', $node['organizer']['name'] );
            $this->assertSame( 'English speaker', $this->event( $en )['performer']['name'] );
            $this->assertSame( 'Level Beginner & co', $this->page( $en )->title() );

            // An import/cron can read several records in one request, without a caller language scope.
            $sequence = $this->site_runs( str_replace( [ 'EN_ID', 'FR_ID' ], [ (string) $en, (string) $fr ], <<<'PHP'
$before = [ \Fabrement\Modules\Translation\Translation::bridge()->current_language(), determine_locale() ];
$results = [];
foreach ( [ EN_ID, FR_ID, EN_ID ] as $id ) {
    $results[] = \Fabrement\Modules\Seo\Seo::bridge()->effective_post( $id );
}
return [ 'results' => $results, 'before' => $before, 'after' => [ \Fabrement\Modules\Translation\Translation::bridge()->current_language(), determine_locale() ] ];
PHP
            ) );
            $this->assertSame( [ 'Level Beginner & co', 'Level Débutant & cie', 'Level Beginner & co' ], array_column( $sequence['results'], 'title' ) );
            $this->assertSame( $sequence['before'], $sequence['after'] );
            $this->assertSame( $sequence['results'][0], $sequence['results'][2], 'Yoast cached values do not leak across languages' );

            $this->mcp( 'set-field-values', [ 'postId' => $en, 'values' => [ 'ws_photo' => null, 'ws_gallery' => null ] ] );
            $this->assertSame( [ $media_url . '?qa-copy=fr' ], $this->page( $fr )->og_images(), 'The image inside a repeater group uses the same translated attachment.' );
            $this->mcp( 'set-field-values', [ 'postId' => $en, 'values' => [ 'ws_rows' => null, 'ws_file' => null ] ] );
            $page = $this->page( $fr );
            $this->assertSame( [], $page->og_images() );
            $this->assertArrayNotHasKey( 'image', $page->node_of_type( 'Event' ) );
            $this->assertArrayNotHasKey( 'description', $page->node_of_type( 'Event' ) );
            $this->assertArrayNotHasKey( 'url', $page->node_of_type( 'Event' )['subjectOf'] );
        } finally {
            $this->site()->exec( 'rm', '-f', '/var/www/html/wp-content/mu-plugins/qa-seo-media-cdn.php' );
        }
    }

    public function test_schema_preview_and_empty_warnings_use_the_same_options_language(): void {
        $this->mcp( 'set-schema', [ 'postType' => 'page', 'graph' => [] ] );
        $this->mcp( 'save-options-page', [ 'slug' => 'qa-context', 'expectedRevision' => 0, 'page' => [ 'title' => 'Context' ],
            'fields' => [ self::field( 'description', 'text', [ 'translation' => 'copy_once' ] ) ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-context', 'language' => 'en', 'values' => [ 'description' => null ] ] );
        $this->mcp( 'set-field-values', [ 'options' => 'qa-context', 'language' => 'fr', 'values' => [ 'description' => 'Description française' ] ] );
        $en = $this->record( 'page', 'Context event' );
        $fr = $this->version( $en, 'contexte-schema', 'Événement contexte' );
        $graph = [ [ '@type' => 'Event', '@id' => '{{url}}#context', 'name' => '{{title}}', 'description' => '{{option:qa-context.description}}' ] ];
        $saved = $this->mcp( 'set-schema', [ 'contentId' => $fr, 'graph' => $graph ] );
        $this->assertStringNotContainsString( 'resolved empty', implode( '\n', $saved['warnings'] ?? [] ) );
        $event = array_values( array_filter( $saved['preview']['graph'], static fn( array $n ): bool => ( $n['@type'] ?? '' ) === 'Event' ) )[0];
        $this->assertSame( 'Description française', $event['description'] );
        $this->assertSame( $this->event( $fr ), $event );
        $direct = $this->site_runs( "return \\Fabrement\\Modules\\Seo\\Schema\\SchemaStore::resolve_for_post($fr);" );
        $this->assertSame( 'Description française', $direct[0]['description'] );
        $saved = $this->mcp( 'set-schema', [ 'contentId' => $en, 'graph' => $graph ] );
        $this->assertStringContainsString( 'graph[0].description resolved empty', implode( '\n', $saved['warnings'] ?? [] ) );
        $this->assertArrayNotHasKey( 'description', $this->event( $en ) );
    }

    public function test_language_context_restores_translations_query_and_locale_after_nested_exceptions(): void {
        // Minimal real WordPress translation catalogs keep this test independent of downloads.
        $catalogs = $this->site_runs( <<<'PHP'
require_once ABSPATH . WPINC . '/pomo/mo.php';
$saved = [];
foreach ( [ 'fr_FR' => 'janvier', 'pl_PL' => 'styczeń' ] as $locale => $month ) {
    $path = WP_LANG_DIR . '/' . $locale . '.mo';
    $saved[$locale] = is_file($path) ? base64_encode(file_get_contents($path)) : null;
    $mo = new MO();
    $mo->set_header('Content-Type', 'text/plain; charset=UTF-8');
    $mo->add_entry(new Translation_Entry(['singular' => 'January', 'translations' => [$month]]));
    $mo->export_to_file($path);
}
return $saved;
PHP
        );
        try {
            foreach ( [ false, true ] as $without_strings ) {
                if ( $without_strings ) {
                    $this->site()->wp( 'plugin', 'deactivate', 'wpml-string-translation' );
                }
                $result = $this->site_runs( <<<'PHP'
$query = $GLOBALS['wp_query'];
$state = static fn() => [ \Fabrement\Modules\Translation\Translation::bridge()->current_language(), determine_locale(), __('January'), wp_date('F', 1704067200), is_locale_switched() ];
$before = $state();
$seen = [];
try {
    \Fabrement\Modules\Translation\Translation::in_context('fr', static function() use (&$seen, $state) {
        $seen['fr'] = $state();
        try {
            \Fabrement\Modules\Translation\Translation::in_context('pl', static function() use (&$seen, $state) {
                $seen['pl'] = $state();
                throw new RuntimeException('nested');
            });
        } catch (RuntimeException $e) {
            $seen['restored_fr'] = $state();
        }
        throw new RuntimeException('outer');
    });
} catch (RuntimeException $e) {
    $seen['exception'] = $e->getMessage();
}
$seen['before'] = $before;
$seen['after'] = $state();
$seen['same_query'] = $query === $GLOBALS['wp_query'];
$throw = static function() { throw new RuntimeException('switch failed'); };
add_action('switch_locale', $throw);
try {
    \Fabrement\Modules\Translation\Translation::in_context('fr', static function() { throw new RuntimeException('callback reached'); });
} catch (RuntimeException $e) {
    $seen['switch_exception'] = $e->getMessage();
} finally {
    remove_action('switch_locale', $throw);
}
$seen['after_switch_exception'] = $state();
// A caller (for example an admin screen) can already be in a different WordPress locale.
switch_to_locale('pl_PL');
try {
    $seen['caller_locale'] = $state();
    $seen['inside_caller_locale'] = \Fabrement\Modules\Translation\Translation::in_context('fr', $state);
    $seen['restored_caller_locale'] = $state();
} finally {
    restore_previous_locale();
}
return $seen;
PHP
                );
                $this->assertSame( [ 'fr', 'fr_FR', 'janvier', 'janvier', true ], $result['fr'], $without_strings ? 'without String Translation' : 'with String Translation' );
                $this->assertSame( [ 'pl', 'pl_PL', 'styczeń', 'styczeń', true ], $result['pl'] );
                $this->assertSame( $result['fr'], $result['restored_fr'] );
                $this->assertSame( 'outer', $result['exception'] );
                $this->assertSame( $result['before'], $result['after'] );
                $this->assertTrue( $result['same_query'] );
                $this->assertSame( 'switch failed', $result['switch_exception'] );
                $this->assertSame( $result['before'], $result['after_switch_exception'] );
                $this->assertSame( $result['fr'], $result['inside_caller_locale'] );
                $this->assertSame( $result['caller_locale'], $result['restored_caller_locale'] );
            }
        } finally {
            $this->site()->wp( 'plugin', 'activate', 'wpml-string-translation' );
            foreach ( $catalogs as $locale => $previous ) {
                $path = '/var/www/html/wp-content/languages/' . $locale . '.mo';
                if ( $previous === null ) {
                    $this->site()->exec( 'rm', '-f', $path );
                } else {
                    $this->site()->write( $path, base64_decode( $previous ) );
                }
            }
        }
    }

    public function test_term_seo_keeps_the_explicit_id_and_reads_back_in_a_new_request(): void {
        $en = (int) $this->mcp( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Context news', 'slug' => 'qa-context-news' ] )['termId'];
        $this->mcp( 'translate-term', [ 'termId' => $en, 'language' => 'fr', 'name' => 'Actualités contexte', 'slug' => 'actualites-contexte' ] );
        $fr = (int) $this->site_runs( "return \\Fabrement\\Modules\\Translation\\Translation::bridge()->version_in($en, 'category', 'fr');" );
        $post = $this->record( 'post', 'Context news post' );
        $this->mcp( 'set-post-terms', [ 'contentId' => $post, 'taxonomy' => 'category', 'terms' => [ $en ], 'mode' => 'replace', 'primary' => $en ] );
        $fr_post = $this->version( $post, 'nouvelle-contexte', 'Nouvelle contexte' );
        $this->mcp( 'set-seo', [ 'termId' => $en, 'title' => 'English news', 'metaDesc' => 'English news description' ] );
        $saved = $this->mcp( 'set-seo', [ 'termId' => $fr, 'title' => 'Actualités françaises', 'metaDesc' => 'Description française des actualités' ] );
        $this->assertArrayNotHasKey( 'effective', $saved, 'term writes retain the stored-only response until a fresh request' );
        $this->assertSame( 'Actualités françaises', $saved['stored']['title'] );
        $read = $this->mcp( 'get-seo', [ 'termId' => $fr ] )['term'];
        $this->assertSame( $fr, $read['id'] );
        $response = $this->get( $read['url'] );
        $this->assertSame( 200, $response['status'] );
        $page = SeoPage::of( $response['body'] );
        $this->assertSame( 'Actualités françaises', $page->title() );
        $this->assertSame( $page->title(), $read['effective']['title'] );
        $this->assertSame( $page->description(), $read['effective']['description'] );
        $this->assertSame( $page->canonical(), $read['effective']['canonical'] );
        $this->assertSame( $fr, $this->mcp( 'get-seo', [ 'contentId' => $fr_post ] )['content']['primaryTerms']['category'] );
        $titles = $this->site_runs( "return array_map(static fn(\$id) => \\Fabrement\\Modules\\Seo\\Seo::bridge()->effective_term(\$id)['title'], [$en, $fr, $en]);" );
        $this->assertSame( [ 'English news', 'Actualités françaises', 'English news' ], $titles );
    }

}
