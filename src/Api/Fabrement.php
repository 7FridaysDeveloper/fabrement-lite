<?php

namespace Fabrement\Api;

use Fabrement\Api\Content\Post;
use Fabrement\Api\Content\Taxonomy;
use Fabrement\Api\Content\Term;
use Fabrement\Api\Fields\Fields;
use Fabrement\Api\Markup\Link;
use Fabrement\Api\Media\Attachment;
use Fabrement\Api\Support\Format;
use Fabrement\Api\Support\Quietly;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Media\MediaKind;
use Fabrement\Modules\Menu\MenuTree;
use Fabrement\Modules\Translation\Version;
use Fabrement\Modules\Translation\Translation;
use Fabrement\Api\Support\ReadResult;

defined( 'ABSPATH' ) || exit;

/** Data, language-aware readers and formatting, reached through fabrement(). */
final class Fabrement {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * @param mixed $post A record id (Version::id()), a WP_Post or a Post; null for the loop's
     *                    record. No record (0, '', -5, one that does not exist) reads nothing,
     *                    quietly; a mistake is logged once, here, not on every read.
     */
    public function fields( $post = null, ...$unknown ): Fields {
        Quietly::unknown( __METHOD__, $unknown );
        if ( $post === null ) {
            $id = (int) get_the_ID();
            if ( $id <= 0 ) {
                return self::no_fields( 'Fabrement::fields()', 'there is no current post here; pass the post id.' );
            }
        } else {
            try {
                $id = self::record_id( $post );
            } catch ( FieldsException $e ) {
                return self::no_fields( 'Fabrement::fields()', $e->getMessage() );
            }
            if ( $id <= 0 ) {
                return Fields::missing( 'fields(): no record.', true );
            }
        }
        $object = FieldObject::post( $id );
        if ( $object ) {
            return new Fields( $object );
        }
        $wp = get_post( $id );
        return ! $wp || wp_is_post_revision( $wp )
            ? Fields::missing( 'fields(): there is no post ' . $id . '.', true )
            : self::no_fields( 'Fabrement::fields()', 'the post type "' . $wp->post_type . '" cannot have Fabrement fields.' );
    }

    /**
     * @param mixed $page An options page slug.
     * @param mixed $lang
     */
    public function options( $page = null, $lang = '', ...$unknown ): Fields {
        Quietly::unknown( __METHOD__, $unknown );
        if ( ! is_string( $page ) ) {
            return self::no_fields( 'Fabrement::options()', 'give an options page slug, not ' . get_debug_type( $page ) . '.' );
        }
        try {
            $lang = Version::lang( $lang );
            Version::check_language( $lang );
        } catch ( FieldsException $e ) {
            return self::no_fields( 'Fabrement::options()', $e->getMessage() );
        }
        $object = FieldObject::options( $page, $lang );
        return $object ? ( new Fields( $object ) )->in_language( $lang ) : self::no_fields( 'Fabrement::options()', 'there is no options page "' . $page . '"' . ( $lang !== '' ? ' in the language "' . $lang . '"' : '' ) . '.' );
    }

    /** A mistake in a fields()/options() call: one log line now, then nothing on each read. */
    private static function no_fields( string $where, string $why ): Fields {
        Quietly::log( $where, $why );
        return Fields::missing( $where . ': ' . $why, true );
    }

    /**
     * A record id from an id (Version::id()), a WP_Post or a Post.
     *
     * @param mixed $value
     * @throws FieldsException
     */
    private static function record_id( $value ): int {
        if ( $value instanceof \WP_Post ) {
            return (int) $value->ID;
        }
        if ( $value instanceof Post ) {
            return $value->id();
        }
        return Version::id( $value );
    }

    /**
     * A media id from an id (Version::id()), a WP_Post or an Attachment.
     *
     * @param mixed $value
     * @throws FieldsException
     */
    private static function media_id( $value ): int {
        if ( $value instanceof \WP_Post ) {
            return (int) $value->ID;
        }
        if ( $value instanceof Attachment ) {
            return $value->id();
        }
        return Version::id( $value );
    }

    /**
     * A term id from an id (Version::id()), a WP_Term or a Term.
     *
     * @param mixed $value
     * @throws FieldsException
     */
    private static function term_id( $value ): int {
        if ( $value instanceof \WP_Term ) {
            return (int) $value->term_id;
        }
        if ( $value instanceof Term ) {
            return $value->id();
        }
        return Version::id( $value );
    }

    /**
     * @param mixed $id     A record id (Version::id()), a WP_Post or a Post.
     * @param mixed $lang   A language code; null or '' = the page's.
     * @param mixed $status A status, a list of them, 'all'; null = as WordPress shows them.
     */
    public function post( $id = null, $lang = '', $status = null, ...$unknown ): Post {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Fabrement::post()', static function () use ( $id, $lang, $status ): Post {
            $statuses = Version::statuses( $status );
            $lang     = Version::lang( $lang );
            Version::check_language( $lang );
            return Post::of( Version::post( self::record_id( $id ), $lang, $statuses ), $lang );
        }, Post::none() );
    }

    /** @param mixed $ids Record ids, each as post() reads one; $lang and $status as in post(). */
    public function posts( $ids = [], $lang = '', $status = null, ...$unknown ): array {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Fabrement::posts()', static function () use ( $ids, $lang, $status ): array {
            $statuses = Version::statuses( $status );
            $lang     = Version::lang( $lang );
            Version::check_language( $lang );
            $valid = Version::ids( $ids, 'Fabrement::posts()', static fn( $one ): int => self::record_id( $one ) );
            if ( $valid === [] ) {
                return [];
            }
            return Post::listed( Version::posts( $valid, $lang, $statuses ), $lang );
        }, [] );
    }

    public function viewed_post( ...$unknown ): Post {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        $id = is_singular() ? (int) get_queried_object_id() : 0;
        return $id > 0 ? Post::of( $id ) : Post::none();
    }

    public function loop_post( ...$unknown ): Post {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        $id = Version::looped();
        return $id > 0 ? Post::of( $id ) : Post::none();
    }

    /**
     * The taxonomies of a post type, as get_object_taxonomies() lists them: of the record the page
     * shows (no argument), of a Post, of the record with this id, or of a post type named.
     *
     * @param mixed $of
     * @param mixed $public true: only public ones, false: only the others, null: all (as WordPress lists them).
     * @return list<Taxonomy>
     */
    public function taxonomies( $of = null, $public = null, ...$unknown ): array {
        Quietly::unknown( __METHOD__, $unknown );
        $viewed = $of === null || $of === '';
        return Quietly::run( 'Fabrement::taxonomies()', static function () use ( $of, $viewed, $public ): array {
            if ( $public === '' ) {
                $public = null;
            }
            if ( $public !== null && ! is_bool( $public ) ) {
                throw new FieldsException( 'public: true or false, not ' . esc_html( get_debug_type( $public ) ) . '.' );
            }
            if ( $viewed ) {
                $of = self::instance()->viewed_post();
            }
            if ( $of instanceof \WP_Post ) {
                $type = $of->post_type;
            } elseif ( $of instanceof Post ) {
                $type = $of->not_empty() ? $of->type() : '';
            } elseif ( is_string( $of ) && $of !== '' && preg_match( '/^-?\d+$/', $of ) !== 1 ) {
                if ( ! post_type_exists( $of ) ) {
                    throw new FieldsException( 'there is no post type "' . esc_html( $of ) . '".' );
                }
                $type = $of;
            } else {
                $id   = Version::id( $of );
                $type = $id > 0 ? (string) get_post_type( $id ) : '';
            }
            if ( $type === '' ) {
                return [];
            }
            $out = [];
            foreach ( get_object_taxonomies( $type, 'objects' ) as $name => $object ) {
                if ( $public === null || (bool) $object->public === $public ) {
                    $out[] = Taxonomy::of( (string) $name );
                }
            }
            return $out;
        }, [] );
    }

    /** @param mixed $id A term id (Version::id()), a WP_Term or a Term; $lang as in post(). */
    public function term( $id = null, $lang = '', ...$unknown ): Term {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Fabrement::term()', static function () use ( $id, $lang ): Term {
            $lang = Version::lang( $lang );
            Version::check_language( $lang );
            return Term::of( Version::term( self::term_id( $id ), $lang ), $lang );
        }, Term::none() );
    }

    /**
     * @param mixed $ids        Term ids, each as term() reads one; left out = the taxonomy's terms; [] = nothing.
     * @param mixed $taxonomy   A taxonomy (its name or a Taxonomy) or a list of them; null = every one.
     * @param mixed $hide_empty true / false; null = as WP_Term_Query decides.
     * @param mixed $lang       As in post().
     */
    public function terms( $ids = null, $taxonomy = null, $hide_empty = null, $lang = '', ...$unknown ): array {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Fabrement::terms()', static function () use ( $ids, $taxonomy, $hide_empty, $lang ): array {
            $lang = Version::lang( $lang );
            Version::check_language( $lang );
            if ( $hide_empty === '' ) {
                $hide_empty = null;
            }
            if ( $taxonomy === '' ) {
                $taxonomy = null;
            }
            if ( $hide_empty !== null && ! is_bool( $hide_empty ) ) {
                throw new FieldsException( 'hide_empty: true or false, not ' . esc_html( get_debug_type( $hide_empty ) ) . '.' );
            }
            // An empty Taxonomy is no taxonomy to read: nothing, quietly, as any empty object.
            $listed = is_array( $taxonomy ) ? $taxonomy : [ $taxonomy ];
            $kept   = array_values( array_filter( $listed, static fn( $one ): bool => ! ( $one instanceof Taxonomy ) || $one->not_empty() ) );
            if ( $taxonomy !== null && $listed !== [] && $kept === [] ) {
                return [];
            }
            $taxonomy = is_array( $taxonomy ) ? $kept : $taxonomy;
            $valid = Version::ids( $ids, 'Fabrement::terms()', static fn( $one ): int => self::term_id( $one ) );
            // Ids given, none usable or none at all (an empty picker): nothing, quietly — as posts( [] ).
            if ( $ids !== null && $valid === [] ) {
                return [];
            }
            if ( $valid === [] && $taxonomy === null ) {
                throw new FieldsException( 'name a taxonomy, or give term ids — fabrement()->taxonomies() lists a record\'s or a type\'s.' );
            }
            return array_values( array_filter( array_map( static fn( int $id ): Term => Term::of( $id, $lang ), Version::terms( $valid, $lang, self::taxonomy_option( $taxonomy ), $hide_empty ) ), static fn( Term $term ): bool => $term->not_empty() ) );
        }, [] );
    }

    /**
     * @param mixed $taxonomy
     * @return list<string>|null
     * @throws FieldsException
     */
    private static function taxonomy_option( $taxonomy ): ?array {
        if ( $taxonomy === null || $taxonomy === '' ) {
            return null;
        }
        $list = array_map( static fn( $one ) => $one instanceof Taxonomy ? $one->name() : $one, is_array( $taxonomy ) ? $taxonomy : [ $taxonomy ] );
        if ( $list === [] || array_values( $list ) !== $list ) {
            throw new FieldsException( 'taxonomy: give a taxonomy, or a list of them.' );
        }
        foreach ( $list as $one ) {
            if ( ! is_string( $one ) || ! in_array( $one, Version::taxonomies(), true ) ) {
                throw new FieldsException( 'taxonomy: there is no taxonomy ' . ( is_string( $one ) ? '"' . esc_html( $one ) . '"' : esc_html( get_debug_type( $one ) ) ) . '.' );
            }
        }
        return array_values( array_unique( $list ) );
    }

    public function link( $value = null, ...$options ): ?Link {
        return ReadResult::read( 'Fabrement::link()', $options, [ 'lang' => '', 'status' => null ], static fn( array $o ) => Link::from( $value, $o['lang'], $o['status'], 'Fabrement::link()' ), Link::none() );
    }

    /** @param mixed $value An attachment id (an int or a string of digits), or an {id, url} / {id, url, filename, fileSize, mimeType, label} array. */
    public function attachment( $value = null, ...$options ): ?Attachment {
        return ReadResult::read( 'Fabrement::attachment()', $options, [ 'kind' => null, 'lang' => '' ], static function ( array $o ) use ( $value ) {
            Version::check_language( $o['lang'] );
            return Attachment::pick( is_array( $value ) ? $value : self::media_id( $value ), $o['lang'], $o['kind'] );
        }, Attachment::empty_for( MediaKind::try_parse( $options['kind'] ?? null ) ) );
    }

    /** @param mixed $ids Attachment ids, each as attachment() reads one; $kind a media kind or a list; $lang as in post(). */
    public function attachments( $ids = [], $kind = null, $lang = '', ...$unknown ): array {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Fabrement::attachments()', static function () use ( $ids, $kind, $lang ): array {
            $lang = Version::lang( $lang );
            Version::check_language( $lang );
            $kinds = Attachment::kinds( $kind );
            $valid = Version::ids( $ids, 'Fabrement::attachments()', static fn( $one ): int => self::media_id( $one ) );
            if ( $valid === [] ) {
                return [];
            }
            $out = [];
            foreach ( Version::media_many( $valid, $lang ) as $id ) {
                $attachment = Attachment::pick( $id, $lang, $kinds );
                if ( $attachment->not_empty() ) {
                    $out[] = $attachment;
                }
            }
            return $out;
        }, [] );
    }

    /** @param mixed $lang As in post(). */
    public function format( $lang = '', ...$unknown ): Format {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Fabrement::format()', static function () use ( $lang ): Format {
            $checked = Version::check_language( Version::lang( $lang ) );
            return Format::in( $checked !== '' ? $checked : Translation::bridge()->current_language() );
        }, Format::none() );
    }

    /** @param mixed $lang As in post(). */
    public function menu( $value = null, $lang = '', ...$unknown ): array {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Fabrement::menu()', static function () use ( $value, $lang ): array {
            $lang = Version::lang( $lang );
            Version::check_language( $lang );
            if ( ! is_array( $value ) ) {
                $value = self::menu_control( $value );
            }
            return Version::in( $lang, static fn() => MenuTree::tree( $value ) );
        }, [] );
    }

    /**
     * A menu named as wp_nav_menu( [ 'menu' => … ] ) takes it — an id, a slug, a name or a WP_Term —
     * as the array {source: wp, menuId}. Nothing (null, '', 0) is no menu, quietly.
     *
     * @param mixed $value
     * @return array<string,mixed>
     * @throws FieldsException
     */
    private static function menu_control( $value ): array {
        if ( $value instanceof \WP_Term ) {
            $value = (int) $value->term_id;
        }
        if ( is_string( $value ) && $value !== '' && preg_match( '/^-?\d+$/', $value ) !== 1 ) {
            $menu = wp_get_nav_menu_object( $value );
            if ( ! $menu ) {
                throw new FieldsException( 'there is no menu "' . esc_html( $value ) . '" (list-menus).' );
            }
            return [ 'source' => 'wp', 'menuId' => (int) $menu->term_id ];
        }
        $id = Version::id( $value );
        if ( $id > 0 && ! wp_get_nav_menu_object( $id ) ) {
            return [];
        }
        return $id > 0 ? [ 'source' => 'wp', 'menuId' => $id ] : [];
    }

    public function home( ...$options ): ?string {
        return ReadResult::read( 'Fabrement::home()', $options, [ 'lang' => '' ], static fn( array $o ) => (string) Translation::bridge()->home_url( Version::check_language( $o['lang'] ) ?: Translation::bridge()->current_language() ), '' );
    }

    /** @param mixed $type A post type slug. */
    public function archive_url( $type = null, ...$options ): ?string {
        return ReadResult::read( 'Fabrement::archive_url()', $options, [ 'lang' => '' ], static function ( array $o ) use ( $type ): string {
            if ( ! is_string( $type ) || ! post_type_exists( $type ) ) {
                throw new FieldsException( 'there is no post type ' . ( is_string( $type ) ? '"' . esc_html( $type ) . '"' : esc_html( get_debug_type( $type ) ) ) . '.' );
            }
            $url = Version::in( $o['lang'], static fn() => get_post_type_archive_link( $type ) );
            return is_string( $url ) ? $url : '';
        }, '' );
    }

    /** The page's language; null on a site with one language. */
    public function language( ...$unknown ): ?string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->has_language( Translation::bridge()->current_language() );
    }

    /**
     * $code when the site has that language, else null. Takes anything, logs nothing: it is the
     * check for a code from an address — fabrement()->post( $id, lang: fabrement()->has_language( $_GET['lang'] ?? '' ) ).
     *
     * @param mixed $code
     */
    public function has_language( $code = null, ...$unknown ): ?string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return is_string( $code ) && in_array( $code, array_column( Translation::bridge()->languages(), 'code' ), true ) ? $code : null;
    }

    /** @param mixed $translated_only true / false. */
    public function languages( $translated_only = false, ...$unknown ): array {
        Quietly::unknown( __METHOD__, $unknown );
        return Quietly::run( 'Fabrement::languages()', static function () use ( $translated_only ): array {
            $translated_only ??= false;
            if ( ! is_bool( $translated_only ) ) {
                throw new FieldsException( 'translated_only: true or false, not ' . esc_html( get_debug_type( $translated_only ) ) . '.' );
            }
            return array_map( static fn( array $l ): array => [
                'code'       => $l['code'],
                'name'       => $l['label'],
                'url'        => $l['url'],
                'active'     => $l['isCurrent'],
                'translated' => $l['hasTranslation'],
            ], Translation::bridge()->switcher( $translated_only ) );
        }, [] );
    }
}
