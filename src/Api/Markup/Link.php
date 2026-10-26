<?php

namespace Fabrement\Api\Markup;

use Fabrement\Api\Content\Post;
use Fabrement\Api\FieldsException;
use Fabrement\Api\Support\OrElse;
use Fabrement\Api\Support\Quietly;
use Fabrement\Modules\Translation\Version;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Api\Support\ReadResult;

defined( 'ABSPATH' ) || exit;

/** A typed address and caption from a link value, post or term. */
final class Link {

    use OrElse;

    private string $url;

    private string $words;

    private bool $new_tab;

    private int $record;

    private string $lang;

    private function __construct( string $url, string $words, bool $new_tab, int $record, string $lang = '' ) {
        $this->url = $url;
        $this->words = $words;
        $this->new_tab = $new_tab;
        $this->record = $record;
        $this->lang = $lang;
    }

    public static function none(): self {
        return new self( '', '', false, 0 );
    }

    public static function from( $value, string $lang, $status, string $where ): self {
        $statuses = Version::statuses( $status );
        Version::check_language( $lang );
        if ( $value === null || $value === false || $value === '' || $value === 0 ) {
            return self::none();
        }
        if ( is_string( $value ) ) {
            return new self( Attributes::address( $value, $where ), '', false, 0 );
        }
        if ( ! is_array( $value ) ) {
            throw new FieldsException( 'give a url attribute or link field value {url, label, newTab, postId, lang, useRecordTitle}, or an address; got ' . esc_html( get_debug_type( $value ) ) . '.' );
        }
        foreach ( [ 'url' => 'is_string', 'label' => 'is_string', 'text' => 'is_string', 'lang' => 'is_string', 'newTab' => 'is_bool', 'useRecordTitle' => 'is_bool', 'postId' => 'is_int' ] as $key => $check ) {
            if ( array_key_exists( $key, $value ) && ! $check( $value[ $key ] ) ) {
                throw new FieldsException( 'a link value is {url, label, newTab, postId, lang, useRecordTitle} — ' . esc_html( $key ) . ' is ' . ( $check === 'is_string' ? 'text' : ( $check === 'is_bool' ? 'true or false' : 'a record id' ) ) . ', not ' . esc_html( get_debug_type( $value[ $key ] ) ) . '.' );
            }
        }
        $post_id = $value['postId'] ?? 0;
        if ( $post_id < 0 ) {
            throw new FieldsException( 'a link value\'s postId is a record id (an integer from 0), not ' . esc_html( $post_id ) . '.' );
        }
        if ( $post_id <= 0 ) {
            return new self( Attributes::address( $value['url'] ?? '', $where ), self::words( $value ), ( $value['newTab'] ?? false ) === true, 0 );
        }
        $asked  = self::asked( $post_id, $lang, $value['lang'] ?? '', $where );
        $record = Version::post( $post_id, $asked, $statuses );
        $post   = Post::of( $record, $asked );
        $words  = ! empty( $value['useRecordTitle'] ) && $record > 0 ? Post::caption( $record, $asked ) : '';
        return new self( (string) $post->url(), $words !== '' ? $words : self::words( $value ), ( $value['newTab'] ?? false ) === true, $record, $asked );
    }

    public static function to_post( int $id, string $lang, string $url, string $title ): self {
        return new self( $url, $title, false, $id, $lang );
    }

    public static function to_term( string $url, string $name ): self {
        return new self( $url, $name, false, 0 );
    }

    public function not_empty( ...$unknown ): bool {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->url !== '';
    }

    public function url( ...$options ): ?string {
        return ReadResult::read( 'Link::url()', $options, [], fn() => $this->url, '' );
    }

    public function text( ...$options ): ?string {
        return ReadResult::read( 'Link::text()', $options, [], fn() => $this->words, '' );
    }

    public function new_tab( ...$unknown ): bool {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->new_tab;
    }

    public function post( ...$unknown ): Post {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->record > 0 ? Post::of( $this->record, $this->lang ) : Post::none();
    }

    public function to_array( ...$unknown ): array {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return ! $this->not_empty() ? [] : [
            'url'     => $this->url,
            'text'    => $this->words,
            'new_tab' => $this->new_tab,
            'post'    => $this->record,
        ];
    }

    private static function words( array $value ): string {
        foreach ( [ 'label', 'text' ] as $key ) {
            if ( is_string( $value[ $key ] ?? null ) && $value[ $key ] !== '' ) {
                return $value[ $key ];
            }
        }
        return '';
    }

    private static function asked( int $post_id, string $lang, string $pinned, string $where ): string {
        $codes = array_column( Translation::bridge()->languages(), 'code' );
        if ( $lang === '' && $pinned !== '' && $codes && ! in_array( $pinned, $codes, true ) ) {
            Quietly::log( $where, 'the stored link to record ' . $post_id . ' names the language "' . $pinned . '", which the site no longer has (it has ' . implode( ', ', $codes ) . ') — shown in the language of the page; pick the language again in that link (or clear it).' );
            $pinned = '';
        }
        return $lang !== '' ? $lang : $pinned;
    }
}
