<?php

use Fabrement\Framework\Keys;
use PHPUnit\Framework\TestCase;

final class KeysTest extends TestCase {

    /** The same letters, not a stored name: the API-key form's POST field names ('fabrement_builder_<provider>_key'). */
    private const NOT_STORED = [ 'ApiConnectionPage.php fabrement_builder_' ];

    /**
     * A stored name spelled outside Keys is a second place to keep in step — the drift Keys ends.
     * Code strings only (single quotes); prose may mention a name.
     */
    public function test_no_stored_name_is_spelled_outside_keys(): void {
        $names = array_filter( ( new ReflectionClass( Keys::class ) )->getConstants(), 'is_string' );
        $src   = dirname( __DIR__, 2 ) . '/src';
        $found = [];
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
            if ( $file->getExtension() !== 'php' || $file->getFilename() === 'Keys.php' ) {
                continue;
            }
            $code = (string) file_get_contents( $file->getPathname() );
            foreach ( $names as $const => $name ) {
                if ( strpos( $code, "'" . $name . "'" ) !== false && ! in_array( $file->getFilename() . ' ' . $name, self::NOT_STORED, true ) ) {
                    $found[] = substr( $file->getPathname(), strlen( $src ) + 1 ) . ': ' . $name . ' (Keys::' . $const . ')';
                }
            }
        }
        self::assertSame( [], $found );
    }

    /** wpml-config.xml cannot read Keys: every meta of ours it names must still be one Keys holds. */
    public function test_wpml_config_names_only_known_meta(): void {
        $xml = (string) file_get_contents( dirname( __DIR__, 2 ) . '/wpml-config.xml' );
        preg_match_all( '#<custom-field[^>]*>(_?fab[^<]*)</custom-field>#', $xml, $m );
        $document = simplexml_load_string( $xml );
        self::assertInstanceOf( SimpleXMLElement::class, $document );
        self::assertCount( 1, $document->xpath( '//custom-type[text()="fabrement_template"]' ) );
        $known = array_values( ( new ReflectionClass( Keys::class ) )->getConstants() );
        self::assertSame( [], array_values( array_diff( $m[1], $known ) ) );
    }

    public function test_names_built_at_run_time(): void {
        self::assertSame( 'fab_city', Keys::field_meta( 'city' ) );
        self::assertSame( 'fabrement_options_site', Keys::options_page( 'site' ) );
        self::assertSame( 'fabrement_options_site.uk', Keys::options_page( 'site', 'uk' ) );
        self::assertSame( 'fabrement_content_preview_abc', Keys::content_preview( 'abc' ) );
        self::assertSame( 'fabrement_mcp_command_7', Keys::for_user( Keys::TRANSIENT_MCP_COMMAND, 7 ) );
    }
}
