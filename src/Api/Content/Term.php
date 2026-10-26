<?php

namespace Fabrement\Api\Content;

use Fabrement\Api\Support\Quietly;

use Fabrement\Api\Markup\Link;
use Fabrement\Api\Support\OrElse;
use Fabrement\Modules\Translation\Version;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Api\Support\ReadResult;

defined( 'ABSPATH' ) || exit;

/**
 * A term (category, tag, a custom taxonomy) as WordPress and WPML show it, read in the page's
 * language or inside the language asked for (lang:): fabrement()->term(), terms(), a post's terms().
 * A term that is not there is an empty Term.
 */
final class Term {

    use OrElse;

    private const EMPTY = [
        'id'          => 0,
        'name'        => '',
        'slug'        => '',
        'taxonomy'    => '',
        'url'         => '',
        'description' => '',
        'count'       => 0,
        'parent'      => 0,
    ];

    /** @var array{id:int,name:string,slug:string,taxonomy:string,url:string,description:string,count:int,parent:int} */
    private array $term;

    private string $lang;

    /** @param array{id:int,name:string,slug:string,taxonomy:string,url:string,description:string,count:int,parent:int} $term */
    private function __construct( array $term, string $lang ) {
        $this->term = $term;
        $this->lang = $lang;
    }

    public static function none(): self {
        return new self( self::EMPTY, '' );
    }

    /** @internal The version to show, already chosen (Version::term()); $lang as it was asked for, '' = the page's. */
    public static function of( int $id, string $lang = '' ): self {
        $read = static function () use ( $id, $lang ): self {
            $term = $id > 0 ? get_term( $id ) : null;
            if ( ! $term instanceof \WP_Term ) {
                return self::none();
            }
            $description = get_term_field( 'description', $term, $term->taxonomy );
            return new self( [
                'id'          => (int) $term->term_id,
                'name'        => (string) $term->name,
                'slug'        => (string) $term->slug,
                'taxonomy'    => (string) $term->taxonomy,
                'url'         => $lang === '' ? Translation::term_link( $term ) : Translation::bridge()->address_in( Translation::term_link( $term ), $lang ),
                'description' => is_string( $description ) ? trim( $description ) : '',
                'count'       => (int) $term->count,
                'parent'      => (int) $term->parent,
            ], $lang );
        };
        return $lang === '' ? $read() : Version::in( $lang, $read );
    }

    public function not_empty( ...$unknown ): bool {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->term['id'] > 0;
    }

    public function id( ...$unknown ): int {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->term['id'];
    }

    public function name( ...$options ): ?string {
        return ReadResult::read( 'Term::name()', $options, [], fn() => $this->term['name'], '' );
    }

    public function url( ...$options ): ?string {
        return ReadResult::read( 'Term::url()', $options, [], fn() => $this->term['url'], '' );
    }

    public function description( ...$options ): ?string {
        return ReadResult::read( 'Term::description()', $options, [], fn() => $this->term['description'], '' );
    }

    public function slug( ...$options ): ?string {
        return ReadResult::read( 'Term::slug()', $options, [], fn() => $this->term['slug'], '' );
    }

    /** The language of the version shown; null when it has none (one language, an untranslated taxonomy, no term). */
    public function language( ...$unknown ): ?string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return Translation::shown_language_of_term( $this->id(), $this->taxonomy() ) ?: null;
    }

    public function taxonomy( ...$unknown ): string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->term['taxonomy'];
    }

    public function count( ...$unknown ): int {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->term['count'];
    }

    public function parent( ...$unknown ): self {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return self::of( $this->term['parent'], $this->lang );
    }

    public function link( ...$options ): ?Link {
        return ReadResult::read( 'Term::link()', $options, [], fn() => $this->not_empty() ? Link::to_term( $this->term['url'], $this->term['name'] ) : Link::none(), Link::none() );
    }

    /** @return array{id:int,name:string,slug:string,taxonomy:string,url:string,description:string,count:int,parent:int}|array{} */
    public function to_array( ...$unknown ): array {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->not_empty() ? $this->term : [];
    }

}
