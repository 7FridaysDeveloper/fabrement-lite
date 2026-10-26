<?php

namespace Fabrement\Api\Media;

use Fabrement\Api\FieldsException;
use Fabrement\Api\Support\ReadResult;
use Fabrement\Api\Support\OrElse;
use Fabrement\Api\Support\Quietly;
use Fabrement\Modules\Media\MediaKind;
use Fabrement\Modules\Translation\Version;
use Fabrement\Modules\Translation\Translation;

defined( 'ABSPATH' ) || exit;

/** A media library item or an {id, url} array, as the class its mime says: Image, Video, Audio or File. */
abstract class Attachment {

    use OrElse;

    private const CLASSES = [ 'image' => Image::class, 'video' => Video::class, 'audio' => Audio::class, 'document' => File::class ];

    private const DOWNLOAD_KEYS = [ 'filename', 'fileSize', 'mimeType', 'label' ];

    protected int $id;

    protected string $url;

    protected string $lang;

    protected string $mime;

    protected string $filename;

    protected string $label;

    protected int $size;

    protected string $alt;

    protected function __construct( int $id, string $url, string $lang, string $mime, string $filename = '', string $label = '', int $size = 0, string $alt = '' ) {
        $this->id       = $id;
        $this->url      = $url;
        $this->lang     = $lang;
        $this->mime     = $mime;
        $this->filename = $filename;
        $this->label    = $label;
        $this->size     = max( 0, $size );
        $this->alt      = $alt;
    }

    public static function none(): static {
        return new static( 0, '', '', '' );
    }

    /**
     * The kinds a caller named, checked: null when none were named.
     *
     * @param mixed $kind
     * @return list<string>|null
     */
    public static function kinds( $kind ): ?array {
        if ( $kind === null || $kind === '' ) {
            return null;
        }
        try {
            return MediaKind::parse( $kind );
        } catch ( \InvalidArgumentException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- MediaKind::parse() escapes what it quotes.
            throw new FieldsException( 'kind: ' . $e->getMessage() );
        }
    }

    /**
     * The attachment as of(), or the empty one of empty_for() when it is not of the kinds asked for.
     *
     * @param mixed             $value
     * @param list<string>|null $kinds
     */
    public static function pick( $value, string $lang, ?array $kinds, bool $translate = true ): self {
        $attachment = self::of( $value, $lang, $translate );
        return $kinds === null || in_array( $attachment->kind(), $kinds, true ) ? $attachment : self::empty_for( $kinds );
    }

    /**
     * Nothing, as the class code expects when it asked for kinds: that kind's class for one kind,
     * else a File.
     *
     * @param list<string>|null $kinds
     */
    public static function empty_for( ?array $kinds ): self {
        return $kinds !== null && count( $kinds ) === 1 ? self::CLASSES[ $kinds[0] ]::none() : File::none();
    }

    /**
     * @param mixed $value     an attachment id, an {id, url} array or an {id, url, filename, fileSize, mimeType, label} array
     * @param bool  $translate false: the id is already the one to show (a record's _thumbnail_id, kept per language by WPML)
     */
    public static function of( $value, string $lang = '', bool $translate = true ): self {
        $stored = self::stored( $value );
        if ( $stored['id'] > 0 ) {
            $id   = $translate ? Version::media( $stored['id'], $lang ) : $stored['id'];
            $post = $id > 0 ? get_post( $id ) : null;
            if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
                return File::none();
            }
            $mime = (string) $post->post_mime_type;
            $kind = MediaKind::of( $mime );
        } elseif ( $stored['url'] !== '' ) {
            $id   = 0;
            $mime = $stored['mimeType'] !== '' ? $stored['mimeType'] : self::mime_of_name( $stored['filename'] !== '' ? $stored['filename'] : $stored['url'] );
            $kind = $mime !== '' ? MediaKind::of( $mime ) : ( $stored['download'] ? 'document' : 'image' );
        } else {
            return File::none();
        }
        $class = self::CLASSES[ $kind ];
        return new $class( $id, $stored['url'], $lang, $mime, $stored['filename'], $stored['label'], $stored['fileSize'], $stored['alt'] );
    }

    public function id( ...$unknown ): int {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->id;
    }

    /** The language of the version shown; null when it has none (one language, no id, unknown to WPML). */
    public function language( ...$unknown ): ?string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return Translation::shown_language_of( $this->id, 'attachment' ) ?: null;
    }

    public function not_empty( ...$unknown ): bool {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->id > 0 || $this->url !== '';
    }

    public function kind( ...$unknown ): string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->not_empty() ? (string) array_search( static::class, self::CLASSES, true ) : '';
    }

    public function url( ...$options ): ?string {
        return ReadResult::read( $this->where( 'url' ), $options, [], fn() => $this->address(), '' );
    }

    public function mime( ...$options ): ?string {
        return ReadResult::read( $this->where( 'mime' ), $options, [], fn() => $this->mime, '' );
    }

    public function name( ...$options ): ?string {
        return ReadResult::read( $this->where( 'name' ), $options, [], fn() => $this->file_name(), '' );
    }

    public function ext( ...$options ): ?string {
        return ReadResult::read( $this->where( 'ext' ), $options, [], fn() => $this->extension(), '' );
    }

    public function title( ...$options ): ?string {
        return ReadResult::read( $this->where( 'title' ), $options, [], fn() => $this->id > 0 ? (string) $this->asked( fn() => get_the_title( $this->id ) ) : '', '' );
    }

    /** The caption as WordPress returns it. */
    public function caption( ...$options ): ?string {
        return ReadResult::read( $this->where( 'caption' ), $options, [], fn() => $this->id > 0 ? (string) $this->asked( fn() => wp_get_attachment_caption( $this->id ) ) : '', '' );
    }

    public function text( ...$options ): ?string {
        return ReadResult::read( $this->where( 'text' ), $options, [], fn() => $this->label !== '' ? $this->label : $this->file_name(), '' );
    }

    public function alt( ...$options ): ?string {
        return ReadResult::read( $this->where( 'alt' ), $options, [], static fn() => '', '' );
    }

    public function bytes( ...$unknown ): int {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        if ( $this->id <= 0 ) {
            return $this->size;
        }
        $meta = $this->metadata();
        $size = (int) ( $meta['filesize'] ?? 0 );
        if ( $size <= 0 ) {
            $path = (string) $this->asked( fn() => get_attached_file( $this->id ) );
            $size = $path !== '' ? (int) wp_filesize( $path ) : 0;
        }
        return $size;
    }

    /** @param mixed $size */
    public function width( $size = null, ...$unknown ): int {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return Quietly::run( static::class . '::width()', fn(): int => self::size_name( $size ) !== '' ? (int) ( $this->metadata()['width'] ?? 0 ) : 0, 0 );
    }

    /** @param mixed $size */
    public function height( $size = null, ...$unknown ): int {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return Quietly::run( static::class . '::height()', fn(): int => self::size_name( $size ) !== '' ? (int) ( $this->metadata()['height'] ?? 0 ) : 0, 0 );
    }

    /** @internal The size used when none is named. */
    public const DEFAULT_SIZE = 'large';

    /**
     * An image size name, as wp_get_attachment_image_src() takes one.
     *
     * @param mixed $size
     * @throws FieldsException Anything that is not a name.
     */
    protected static function size_name( $size ): string {
        // Not given: the default, which nobody wrote — a site without it gets the original quietly.
        if ( $size === null ) {
            return self::DEFAULT_SIZE;
        }
        if ( is_string( $size ) && $size !== '' ) {
            return self::known_size( $size, self::short_name( static::class ) . '()' );
        }
        throw new FieldsException( 'size: give an image size name (thumbnail, medium, large, full…), not ' . ( is_string( $size ) ? '""' : esc_html( get_debug_type( $size ) ) ) . '.' );
    }

    /**
     * @internal A size the site has (its registered image sizes, and full); another one is one
     * debug-log line, and WordPress then returns the original, as it does. Only a size the code
     * named is checked ($named): a default the site lacks is nobody's mistake.
     */
    public static function known_size( string $size, string $where, bool $named = true ): string {
        static $names = null;
        if ( ! $named ) {
            return $size;
        }
        $names ??= array_merge( array_keys( wp_get_registered_image_subsizes() ), [ 'full' ] );
        if ( ! in_array( $size, $names, true ) ) {
            Quietly::log( $where, 'size: the site has no image size "' . $size . '" — it has ' . implode( ', ', $names ) . '; the original is used.' );
        }
        return $size;
    }

    /** @param mixed $size */
    public function to_array( $size = null, ...$unknown ): array {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return Quietly::run( static::class . '::to_array()', function () use ( $size ): array {
            return $this->not_empty() ? $this->array_of( self::size_name( $size ), $size ) : [];
        }, [] );
    }

    /**
     * @param mixed $asked The size as given, null for the default.
     * @return array<string,mixed>
     */
    protected function array_of( string $size, $asked = null ): array {
        return [
            'id'      => $this->id,
            'kind'    => $this->kind(),
            'url'     => $this->address_for( $size ),
            'mime'    => $this->mime,
            'name'    => $this->file_name(),
            'title'   => (string) $this->title(),
            'caption' => (string) $this->caption(),
            'alt'     => (string) $this->alt(),
            'width'   => $this->width( $asked ),
            'height'  => $this->height( $asked ),
            'bytes'   => $this->bytes(),
        ];
    }

    protected function address_for( string $size ): string {
        return $this->address();
    }

    protected function address(): string {
        $address = $this->id > 0 ? (string) $this->asked( fn() => wp_get_attachment_url( $this->id ) ) : '';
        return $address !== '' ? $address : $this->url;
    }

    protected function file_name(): string {
        if ( $this->id <= 0 && $this->filename !== '' ) {
            return $this->filename;
        }
        $address = $this->address();
        return $address === '' ? '' : wp_basename( (string) wp_parse_url( $address, PHP_URL_PATH ) );
    }

    protected function extension(): string {
        return strtoupper( (string) pathinfo( $this->file_name(), PATHINFO_EXTENSION ) );
    }

    protected function where( string $method ): string {
        return self::short_name( static::class ) . '::' . $method . '()';
    }

    private function metadata(): array {
        $meta = $this->id > 0 ? $this->asked( fn() => wp_get_attachment_metadata( $this->id ) ) : false;
        return is_array( $meta ) ? $meta : [];
    }

    /** @internal Runs $fn as this item is read: in the page's language, or inside the language it was asked in. */
    public function asked( callable $fn ) {
        return $this->lang === '' ? $fn() : Version::in( $this->lang, $fn );
    }

    private static function mime_of_name( string $name ): string {
        $path = (string) wp_parse_url( $name, PHP_URL_PATH );
        return (string) ( wp_check_filetype( $path !== '' ? $path : $name, wp_get_mime_types() )['type'] ?: '' );
    }

    /** @param mixed $value */
    private static function stored( $value ): array {
        if ( is_array( $value ) && array_intersect_key( $value, array_flip( self::DOWNLOAD_KEYS ) ) !== [] ) {
            return [ 'download' => true, 'alt' => '' ] + MediaValue::download( $value );
        }
        [ $id, $url, $alt ] = MediaValue::media( $value );
        return [ 'download' => false, 'id' => $id, 'url' => $url, 'alt' => $alt, 'filename' => '', 'label' => '', 'fileSize' => 0, 'mimeType' => '' ];
    }
}
