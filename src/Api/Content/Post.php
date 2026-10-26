<?php

namespace Fabrement\Api\Content;

use Fabrement\Api\Fabrement;
use Fabrement\Api\FieldsException;
use Fabrement\Api\Fields\Fields;
use Fabrement\Api\Markup\Link;
use Fabrement\Api\Media\Image;
use Fabrement\Api\Support\OrElse;
use Fabrement\Api\Support\Quietly;
use Fabrement\Modules\Translation\Version;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Api\Support\ReadResult;

defined( 'ABSPATH' ) || exit;

/**
 * A record (post, page, a custom type) as WordPress and WPML show it: the version fabrement()->post(),
 * posts(), viewed_post(), loop_post() and the record fields chose, read with WordPress's own
 * functions — in the page's language, or inside the language asked for (lang:). A record that
 * cannot be shown is an empty Post.
 */
final class Post {

    use OrElse;

    private int $id;

    private string $lang;

    /** The records read together with this one (posts()): their featured images load at once. */
    private ?\stdClass $batch = null;

    private function __construct( int $id, string $lang ) {
        $this->id   = max( 0, $id );
        $this->lang = $lang;
    }

    public static function none(): self {
        return new self( 0, '' );
    }

    /** @internal The version to show, already chosen (Version::post()); $lang as it was asked for, '' = the page's. */
    public static function of( int $id, string $lang = '' ): self {
        return new self( $id, $lang );
    }

    /**
     * @internal posts(): the records of one list, which load their featured images together on the
     * first thumbnail() of any of them — as update_post_thumbnail_cache() does in a loop.
     *
     * @param list<int> $ids
     * @return list<self>
     */
    public static function listed( array $ids, string $lang = '' ): array {
        $batch = (object) [ 'ids' => $ids, 'loaded' => false ];
        return array_map( static function ( int $id ) use ( $lang, $batch ): self {
            $post        = new self( $id, $lang );
            $post->batch = $batch;
            return $post;
        }, $ids );
    }

    public function not_empty( ...$unknown ): bool {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->id > 0;
    }

    public function id( ...$unknown ): int {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->id;
    }

    public function type( ...$unknown ): string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->id > 0 ? (string) get_post_type( $this->id ) : '';
    }

    /** The language of the version shown; null when it has none (one language, no record, unknown to WPML). */
    public function language( ...$unknown ): ?string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return Translation::shown_language_of( $this->id, $this->type() ) ?: null;
    }

    public function title( ...$options ): ?string {
        return ReadResult::read( 'Post::title()', $options, [], fn(): string => $this->id > 0 ? (string) $this->asked( fn() => get_the_title( $this->id ) ) : '', '' );
    }

    public function excerpt( ...$options ): ?string {
        return ReadResult::read( 'Post::excerpt()', $options, [], fn(): string => $this->id > 0 ? (string) $this->asked( fn() => get_the_excerpt( $this->id ) ) : '', '' );
    }

    public function url( ...$options ): ?string {
        return ReadResult::read( 'Post::url()', $options, [], fn(): string => $this->id > 0 ? $this->address() : '', '' );
    }

    public function date( ...$options ): ?string {
        return ReadResult::read( 'Post::date()', $options, [ 'lang' => '' ], function ( array $o ): string {
            $lang = $o['lang'] !== '' ? Version::check_language( $o['lang'] ) : '';
            return $this->id > 0 ? (string) Translation::bridge()->in_locale( $lang, fn() => get_the_date( '', $this->id ) ) : '';
        }, '' );
    }

    public function datetime( ...$options ): ?string {
        return ReadResult::read( 'Post::datetime()', $options, [], function (): string {
            $date = $this->id > 0 ? get_post_datetime( $this->id ) : false;
            return $date instanceof \DateTimeInterface ? $date->format( DATE_ATOM ) : '';
        }, '' );
    }

    public function thumbnail( ...$options ): ?Image {
        return ReadResult::read( 'Post::thumbnail()', $options, [], function () {
            if ( $this->id <= 0 ) {
                return Image::none();
            }
            $this->load_thumbnails();
            // The record's own _thumbnail_id, as WordPress draws it: WPML keeps it per language.
            return Image::from( (int) $this->asked( fn() => get_post_thumbnail_id( $this->id ) ), '', $this->lang, false );
        }, Image::none() );
    }

    /**
     * @param mixed $taxonomy A taxonomy name or a Taxonomy.
     * @return list<Term>
     */
    public function terms( $taxonomy = null, ...$unknown ): array {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return Quietly::run( 'Post::terms()', function () use ( $taxonomy ): array {
            if ( $taxonomy instanceof Taxonomy ) {
                if ( ! $taxonomy->not_empty() ) {
                    return [];
                }
                $taxonomy = $taxonomy->name();
            }
            if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
                throw new FieldsException( $taxonomy === null ? 'give a taxonomy — fabrement()->taxonomies( $post ) lists them.' : 'there is no taxonomy ' . ( is_string( $taxonomy ) ? '"' . esc_html( $taxonomy ) . '"' : esc_html( get_debug_type( $taxonomy ) ) ) . '.' );
            }
            if ( $this->id <= 0 ) {
                return [];
            }
            $terms = $this->asked( fn() => get_the_terms( $this->id, $taxonomy ) );
            $out = [];
            foreach ( is_array( $terms ) ? $terms : [] as $term ) {
                $one = Term::of( (int) $term->term_id, $this->lang );
                if ( $one->not_empty() ) {
                    $out[] = $one;
                }
            }
            return $out;
        }, [] );
    }

    public function fields( ...$unknown ): Fields {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return Fabrement::instance()->fields( $this->id > 0 ? $this->id : 0 )->in_language( $this->lang );
    }

    public function link( ...$options ): ?Link {
        return ReadResult::read( 'Post::link()', $options, [], fn() => $this->id > 0 ? Link::to_post( $this->id, $this->lang, $this->url(), self::caption( $this->id, $this->lang ) ) : Link::none(), Link::none() );
    }

    /** @return array{id:int,title:string,url:string,excerpt:string,date:string,postType:string,thumb:array{id:int,url:string}}|array{} */
    public function to_array( ...$unknown ): array {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        if ( $this->id <= 0 ) {
            return [];
        }
        $thumb = $this->thumbnail();
        return [
            'id'       => $this->id,
            'title'    => $this->title(),
            'url'      => $this->url(),
            'excerpt'  => $this->excerpt(),
            'date'     => $this->date(),
            'postType' => $this->type(),
            'thumb'    => [ 'id' => $thumb->id(), 'url' => $thumb->url() ],
        ];
    }

    private function address(): string {
        $url = (string) $this->asked( fn() => get_permalink( $this->id ) );
        return $this->lang === '' ? $url : Translation::bridge()->address_in( $url, $this->lang );
    }

    /**
     * @internal The words of a link to a record, as a WordPress menu names it: the title through
     * the_title, without the "Protected:" / "Private:" mark of get_the_title().
     */
    public static function caption( int $id, string $lang = '' ): string {
        if ( $id <= 0 ) {
            return '';
        }
        $read = static fn(): string => (string) apply_filters( 'the_title', (string) get_post_field( 'post_title', $id, 'raw' ), $id );
        return $lang === '' ? $read() : (string) Version::in( $lang, $read );
    }

    /** The featured images of the whole list in one go, as update_post_thumbnail_cache() loads them. */
    private function load_thumbnails(): void {
        if ( $this->batch === null || $this->batch->loaded ) {
            return;
        }
        $this->batch->loaded = true;
        $ids = $this->batch->ids;
        $this->asked( static function () use ( $ids ): void {
            $thumbs = array_values( array_unique( array_filter( array_map( static fn( int $id ): int => (int) get_post_thumbnail_id( $id ), $ids ) ) ) );
            if ( $thumbs === [] ) {
                return;
            }
            _prime_post_caches( $thumbs, false, true );
        } );
    }

    private function asked( callable $fn ) {
        return $this->lang === '' ? $fn() : Version::in( $this->lang, $fn );
    }

}
