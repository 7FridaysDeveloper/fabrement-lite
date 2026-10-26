<?php

namespace Fabrement\Api\Media;

use Fabrement\Api\Markup\Link;
use Fabrement\Api\Support\Quietly;
use Fabrement\Api\Support\ReadResult;

defined( 'ABSPATH' ) || exit;

/** An image from the media library or an {id, url} array, shown in the requested language. */
final class Image extends Attachment {

    public static function from( int $id, string $url = '', string $lang = '', bool $translate = true ): self {
        return Attachment::pick( [ 'id' => $id, 'url' => $url ], $lang, [ 'image' ], $translate );
    }

    public function fallback_url( ...$unknown ): string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->url !== '' || $this->id <= 0 ? $this->url : (string) $this->asked( fn() => wp_get_attachment_url( $this->id ) );
    }

    public function url( ...$options ): ?string {
        return ReadResult::read( 'Image::url()', $options, [ 'size' => self::DEFAULT_SIZE ], fn( array $o ) => $this->sized( $o['size'], isset( $options['size'] ) ), '' );
    }

    /** @param mixed $size */
    public function width( $size = null, ...$unknown ): int {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Image::width()', fn(): int => (int) ( $this->source( self::size_name( $size ) )[1] ?? 0 ), 0 );
    }

    /** @param mixed $size */
    public function height( $size = null, ...$unknown ): int {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Image::height()', fn(): int => (int) ( $this->source( self::size_name( $size ) )[2] ?? 0 ), 0 );
    }

    public function alt( ...$options ): ?string {
        return ReadResult::read( 'Image::alt()', $options, [], fn( array $o ) => $this->id > 0 ? $this->library_alt() : $this->alt, '' );
    }

    public function link( ...$options ): ?Link {
        return ReadResult::read( 'Image::link()', $options, [ 'size' => 'full' ], fn( array $o ) => $this->sized( $o['size'] ) === '' ? Link::none() : Link::from( $this->sized( $o['size'] ), '', null, 'Image::link()' ), Link::none() );
    }

    protected function address_for( string $size ): string {
        return $this->sized( $size, false );
    }

    private function sized( string $size, bool $named = true ): string {
        Attachment::known_size( $size, 'Image::url()', $named );
        $address = $this->id > 0 ? (string) $this->asked( fn() => wp_get_attachment_image_url( $this->id, $size ) ) : '';
        return $address !== '' ? $address : $this->fallback_url();
    }

    private function library_alt(): string {
        return trim( wp_strip_all_tags( (string) $this->asked( fn() => get_post_meta( $this->id, '_wp_attachment_image_alt', true ) ) ) );
    }

    private function source( string $size ): array {
        $source = $this->id > 0 ? $this->asked( fn() => wp_get_attachment_image_src( $this->id, $size ) ) : false;
        return is_array( $source ) ? $source : [];
    }
}
