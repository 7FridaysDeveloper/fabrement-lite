<?php

namespace Fabrement\Tests\Wp;

use Fabrement\Modules\Translation\Contract\TranslationBridge;
use Fabrement\Modules\Translation\Translation;

/**
 * A two-language site without WPML: the bridge methods the content tools ask, answered from an
 * in-memory table of which record is in which language and which records are one group.
 *
 * It keeps WPML's two habits that matter to the code under test: version_facts() leaves out
 * trashed and deleted records (WPML's own query JOINs wp_posts), while visible_versions() still
 * names them (WPML keeps the rows). Real WPML is checked separately, on a Local site with WPML
 * (tests/Cases/Wpml/ContentParentTest.php).
 */
trait FakeLanguages {

    /** @var array<int,string> post id → language */
    private array $fake_language = [];
    /** @var array<int,int> post id → the original of its group */
    private array $fake_original = [];
    /** @var list<string> every language in_language() was asked to run in, in order */
    private array $fake_switched_to = [];

    private function use_languages( string $default = 'en', array $others = [ 'pl' ] ): void {
        $bridge = $this->createStub( TranslationBridge::class );
        $codes  = array_merge( [ $default ], $others );

        $group = function ( int $id ): array {
            $original = $this->fake_original[ $id ] ?? null;
            if ( $original === null ) {
                return [];
            }
            $out = [];
            foreach ( $this->fake_original as $member => $of ) {
                if ( $of === $original ) {
                    $out[ $this->fake_language[ $member ] ] = $member;
                }
            }
            return $out;
        };

        $bridge->method( 'is_active' )->willReturn( true );
        $bridge->method( 'languages' )->willReturn( array_map( static fn( string $c ) => [ 'code' => $c, 'name' => $c ], $codes ) );
        $bridge->method( 'default_language' )->willReturn( $default );
        $bridge->method( 'current_language' )->willReturn( $default );
        $bridge->method( 'writes_translations_manually' )->willReturn( true );
        $bridge->method( 'is_post_type_translatable' )->willReturn( true );
        $bridge->method( 'all_languages_args' )->willReturnArgument( 0 );
        $bridge->method( 'in_locale' )->willReturnCallback( static fn( string $lang, callable $fn ) => $fn() );
        $bridge->method( 'in_language' )->willReturnCallback(
            function ( string $lang, callable $fn ) {
                $this->fake_switched_to[] = $lang;
                return $fn();
            }
        );
        $bridge->method( 'permalink' )->willReturnCallback( static fn( int $id ) => (string) get_permalink( $id ) );
        $bridge->method( 'permalinks_of_many' )->willReturnCallback(
            static fn( array $map ) => array_map( static fn( $id ) => (string) get_permalink( (int) $id ), array_combine( array_keys( $map ), array_keys( $map ) ) )
        );
        $bridge->method( 'visible_versions' )->willReturnCallback( static fn( int $id ) => $group( $id ) );
        $bridge->method( 'all_versions' )->willReturnCallback( static fn( int $id ) => $group( $id ) );
        // As WpmlBridge: the current language (the default here) falls back to the record itself,
        // a named one does not — 0 where the group has no version in it.
        $bridge->method( 'version_in' )->willReturnCallback(
            static fn( int $id, string $type, string $lang = '' ): int => $lang === ''
                ? (int) ( $group( $id )[ $default ] ?? $id )
                : (int) ( $group( $id )[ $lang ] ?? 0 )
        );
        $bridge->method( 'version_facts' )->willReturnCallback(
            function ( array $ids ) use ( $group ): array {
                $out = [];
                foreach ( $ids as $id ) {
                    $id     = (int) $id;
                    $status = get_post_status( $id );
                    if ( ! isset( $this->fake_language[ $id ] ) || $status === false || in_array( $status, [ 'trash', 'auto-draft' ], true ) ) {
                        continue;
                    }
                    $out[ $id ] = [
                        'language'     => $this->fake_language[ $id ],
                        'translations' => $group( $id ),
                        'statuses'     => [],
                        'isOriginal'   => $this->fake_original[ $id ] === $id,
                    ];
                }
                return $out;
            }
        );
        $bridge->method( 'is_original' )->willReturnCallback( fn( int $id ) => ( $this->fake_original[ $id ] ?? $id ) === $id );
        $bridge->method( 'original_of' )->willReturnCallback( fn( int $id ) => $this->fake_original[ $id ] ?? $id );
        $bridge->method( 'set_language' )->willReturnCallback(
            function ( int $id, string $lang ): void {
                $this->fake_language[ $id ] = $lang;
                $this->fake_original[ $id ] = $id;
            }
        );
        $bridge->method( 'link_translation' )->willReturnCallback(
            function ( int $id, int $original, string $lang ): void {
                $this->fake_language[ $id ] = $lang;
                $this->fake_original[ $id ] = $this->fake_original[ $original ] ?? $original;
            }
        );

        Translation::set( $bridge );
    }

    /** File an existing record under a language, as the original of its own group. */
    private function in_language( int $id, string $lang ): void {
        $this->fake_language[ $id ] = $lang;
        $this->fake_original[ $id ] = $id;
    }

    /** Make `$version` the `$lang` version of `$original`. */
    private function link_version( int $version, int $original, string $lang ): void {
        $this->fake_language[ $version ] = $lang;
        $this->fake_original[ $version ] = $this->fake_original[ $original ] ?? $original;
    }

    private function forget_languages(): void {
        $this->fake_language = [];
        $this->fake_original   = [];
        $this->fake_switched_to = [];
        Translation::set( null );
    }
}
