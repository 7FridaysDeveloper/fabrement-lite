<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Mcp\Ability\GetSkillDocAbility;
use Fabrement\Modules\Mcp\Roster;
use Fabrement\Modules\Mcp\Support\LocalSkillDocs;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use PHPUnit\Framework\TestCase;

final class GetSkillDocAbilityTest extends TestCase {

    protected function setUp(): void {
        WordPressState::reset();
    }

    public function test_signed_out_site_reads_bundled_contract_without_http(): void {
        $result = ( new GetSkillDocAbility() )->execute( [ 'id' => 'content-contract' ] );
        self::assertIsArray( $result );
        self::assertSame( 'content-contract', $result['id'] );
        self::assertStringContainsString( 'REGISTERED BLOCKS / ACF', $result['content'] );
        self::assertStringContainsString( 'expectedHash', $result['content'] );
        self::assertSame( [], WordPressState::$http_requests );
    }

    /** @dataProvider invalid_ids */
    public function test_invalid_ids_cannot_select_files( $id ): void {
        $result = ( new GetSkillDocAbility() )->execute( [ 'id' => $id ] );
        self::assertInstanceOf( \WP_Error::class, $result );
        self::assertSame( 'invalid_skill_id', $result->get_error_code() );
        self::assertSame( [], WordPressState::$http_requests );
    }

    public static function invalid_ids(): array {
        return array_map( static fn( $id ) => [ $id ], [
            null, [], 123, '', '../composer', '..\\composer', 'content-contract.xml',
            'php://filter/resource=wp-config.php', 'https://example.com/doc',
            'C:\\wp-config.php', 'content-contract%00', "content\0contract", str_repeat( 'a', 101 ),
        ] );
    }

    public function test_unknown_id_has_no_network_fallback(): void {
        $result = ( new GetSkillDocAbility() )->execute( [ 'id' => 'no-such-skill' ] );
        self::assertInstanceOf( \WP_Error::class, $result );
        self::assertSame( 'not_found', $result->get_error_code() );
        self::assertSame( [], WordPressState::$http_requests );
    }

    public function test_multilingual_content_and_area_only_docs_are_selected_locally(): void {
        $base = LocalSkillDocs::read( 'content-contract', false );
        $translation = LocalSkillDocs::read( 'content-contract', true );
        self::assertSame( $base, $translation );
        self::assertStringContainsString( 'WPML CONTENT', LocalSkillDocs::read( 'translation-contract', true )['content'] );
        try {
            LocalSkillDocs::read( 'translation-contract', false );
            self::fail( 'A single-language site must not receive translation-only instructions.' );
        } catch ( McpAbilityException $error ) {
            self::assertSame( 'not_found', $error->to_error()->get_error_code() );
        }
        self::assertSame( [], WordPressState::$http_requests );
    }

    public function test_every_indexed_variant_is_shipped_and_fully_expanded(): void {
        $index = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/skills/index.json' ), true );
        self::assertNotEmpty( $index['documents'] );
        foreach ( $index['documents'] as $id => $variants ) {
            foreach ( [ false, true ] as $multilingual ) {
                if ( ! $multilingual && ! $variants['base'] ) { continue; }
                $doc = LocalSkillDocs::read( $id, $multilingual );
                self::assertSame( $id, $doc['id'] );
                self::assertNotEmpty( $doc['content'], $id );
                self::assertDoesNotMatchRegularExpression( '/\{\{(?:catalog|languages):/', $doc['content'], $id );
                preg_match_all( '/get-skill-doc\s*\{\s*id:\s*"([a-z0-9-]+)"/', $doc['content'], $matches );
                foreach ( array_unique( $matches[1] ) as $reference ) {
                    self::assertNotEmpty( LocalSkillDocs::read( $reference, $multilingual )['content'], $id . ' -> ' . $reference );
                }
                preg_match_all( '/\b[a-z][a-z0-9-]*-contract\b/', $doc['content'], $references );
                foreach ( array_unique( $references[0] ) as $reference ) {
                    self::assertArrayHasKey( $reference, $index['documents'], $id . ' references a removed contract' );
                }
                self::assertDoesNotMatchRegularExpression( '/submit-version|import-block|create-block|save-global-(?:css|js)|save-(?:single|archive)-page/', $doc['content'], $id );
            }
        }
    }

    public function test_removed_generator_skills_are_neither_indexed_nor_shipped(): void {
        $root = dirname( __DIR__, 2 ) . '/skills';
        $index = json_decode( file_get_contents( $root . '/index.json' ), true );
        foreach ( [ 'block-contract', 'header-contract', 'footer-contract', 'design-system-contract', 'global-css-contract',
            'global-js-contract', 'php-helpers', 'attributes', 'slider', 'page-hooks' ] as $id ) {
            self::assertArrayNotHasKey( $id, $index['documents'] );
            $result = ( new GetSkillDocAbility() )->execute( [ 'id' => $id ] );
            self::assertInstanceOf( \WP_Error::class, $result );
            self::assertSame( 'not_found', $result->get_error_code() );
        }
        foreach ( [ 'base', 'translation' ] as $folder ) {
            foreach ( glob( $root . '/' . $folder . '/*.xml' ) as $file ) {
                $id = basename( $file, '.xml' );
                self::assertTrue( $index['documents'][ $id ][ $folder ] ?? false, 'Unindexed skill file: ' . $file );
            }
        }
    }

    public function test_every_advertised_tool_contract_exists_locally(): void {
        foreach ( Roster::CLASSES as $class ) {
            // Metadata methods need neither the tool's write services nor a WordPress database.
            $ability = ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
            if ( $ability->contract() !== null ) {
                self::assertNotEmpty( LocalSkillDocs::read( $ability->contract(), true )['content'], $class );
            }
            if ( $ability->languages_contract() !== null ) {
                self::assertNotEmpty( LocalSkillDocs::read( $ability->languages_contract(), true )['content'], $class );
            }
        }
    }
}
