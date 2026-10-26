<?php

namespace Fabrement\Modules\Translation;

use Fabrement\Api\FieldsException;
use Fabrement\Api\Support\Quietly;
use Fabrement\Modules\SiteTemplate\PostType\SiteTemplatePostType;

defined( 'ABSPATH' ) || exit;

/**
 * Which version of a record, term or attachment a page shows, as WordPress and WPML show it.
 * Records: WP_Query over the ids, which WPML turns into the page's language (or the one asked for,
 * run inside it), with the statuses of the filter `fabrement_posts_statuses` ( $statuses, $ids ):
 * it gets the statuses asked for (null when none were: WordPress decides — published, private to
 * whoever may read it, what wp-admin lists) and, as any WordPress filter, returns them unchanged or
 * a list the query then shows exactly (an agent's preview link returns the statuses it was made with). An attachment: wpml_object_id() — its
 * translation in that language, else itself — and a list read as ACF reads one (get_posts(), each once).
 * Terms: WP_Term_Query over the ids and the taxonomies asked for (with ids and none asked, every
 * taxonomy a page may show, so that WPML turns the ids into that language).
 */
final class Version {

    private const STATUSES = [ 'publish', 'draft', 'pending', 'future', 'trash', 'private' ];

    /** @var list<\WeakReference> The loops started (loop_start), outermost first; a query nothing else holds is let go. */
    private static array $loops = [];

    /** @param list<string>|null $statuses null = as WordPress shows records here */
    public static function post( int $id, string $lang, ?array $statuses = null ): int {
        return self::posts( [ $id ], $lang, $statuses )[0] ?? 0;
    }

    /**
     * @param list<int>         $ids
     * @param list<string>|null $statuses null = as WordPress shows records here
     * @return list<int>
     */
    public static function posts( array $ids, string $lang, ?array $statuses = null ): array {
        $code = self::language( $lang );
        $ids = array_values( array_filter( $ids, static fn( $id ): bool => is_int( $id ) && $id > 0 ) );
        if ( $ids === [] ) {
            return [];
        }
        $args = [
            'post__in'            => $ids,
            'orderby'             => 'post__in',
            'post_type'           => self::post_types(),
            'posts_per_page'      => -1,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'suppress_filters'    => false,
        ];
        $statuses = apply_filters( 'fabrement_posts_statuses', $statuses, $ids );
        if ( $statuses !== null ) {
            $args['post_status'] = self::statuses( $statuses );
        }
        return self::in( $lang, static function () use ( $ids, $args, $code ): array {
            $bridge = Translation::bridge();
            if ( $code !== '' ) {
                _prime_post_caches( $ids, false, false );
                $bridge->load_versions( $ids );
                $args['post__in'] = array_values( array_unique( array_map( static fn( int $id ): int => $bridge->version_in( $id, 'any' ), $ids ) ) );
            }
            return array_map( static fn( \WP_Post $post ): int => (int) $post->ID, ( new \WP_Query( $args ) )->posts );
        } );
    }

    public static function term( int $id, string $lang ): int {
        return $id > 0 ? self::terms( [ $id ], $lang )[0] ?? 0 : 0;
    }

    /**
     * @param list<int>         $ids        none = every term of $taxonomies
     * @param list<string>|null $taxonomies null = every taxonomy
     * @param bool|null         $hide_empty  null = as WordPress lists a taxonomy (terms with records) or, given ids, every one of them
     * @return list<int>
     */
    public static function terms( array $ids, string $lang, ?array $taxonomies = null, ?bool $hide_empty = null ): array {
        $code = self::language( $lang );
        // Ids asked for and none of them an id is nothing, as with records; no ids at all lists
        // the taxonomies' terms.
        $asked = $ids !== [];
        $ids   = array_values( array_filter( $ids, static fn( $id ): bool => is_int( $id ) && $id > 0 ) );
        if ( $asked && $ids === [] ) {
            return [];
        }
        // WPML turns the ids into the language only with a taxonomy named: every one a page may show.
        $taxonomies = $taxonomies ?? self::taxonomies();
        if ( $taxonomies === [] ) {
            return [];
        }
        $args = [ 'taxonomy' => $taxonomies ];
        if ( $ids !== [] ) {
            $args += [ 'include' => $ids, 'orderby' => 'include', 'hide_empty' => false ];
        }
        if ( $hide_empty !== null ) {
            $args['hide_empty'] = $hide_empty;
        }
        $terms = self::in( $lang, static function () use ( $args, $code ) {
            // Each id asked WPML for its version in that language first, as records are — not left
            // to WPML's "Adjust IDs" setting, which may be off.
            if ( $code !== '' && isset( $args['include'] ) ) {
                _prime_term_caches( $args['include'], false );
                $bridge          = Translation::bridge();
                $args['include'] = array_values( array_unique( array_map( static function ( int $id ) use ( $bridge ): int {
                    $term = get_term( $id );
                    return $term instanceof \WP_Term ? ( $bridge->version_in( $id, $term->taxonomy ) ?: $id ) : $id;
                }, $args['include'] ) ) );
            }
            return ( new \WP_Term_Query() )->query( $args );
        } );
        return array_map( static fn( \WP_Term $term ): int => (int) $term->term_id, is_array( $terms ) ? $terms : [] );
    }

    public static function media( int $id, string $lang ): int {
        $lang = self::language( $lang );
        if ( $id <= 0 || $lang === '' ) {
            return max( 0, $id );
        }
        return Translation::bridge()->version_in( $id, 'attachment', $lang ) ?: $id;
    }

    /**
     * Attachments as ACF reads a gallery: each id in that language, one get_posts() in their order,
     * each attachment once. Gives back the ids asked for whose version was found, in that order.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function media_many( array $ids, string $lang ): array {
        $code = self::language( $lang );
        $ids = array_values( array_filter( $ids, static fn( $id ): bool => is_int( $id ) && $id > 0 ) );
        if ( $ids === [] ) {
            return [];
        }
        if ( $code !== '' ) {
            Translation::bridge()->load_versions( $ids );
        }
        $shown = array_map( static fn( int $id ): int => self::media( $id, $code ), $ids );
        $posts = get_posts( [
            'post__in'               => array_values( array_unique( $shown ) ),
            'post_type'              => 'attachment',
            // As WordPress's [gallery] lists attachments: the ordinary ones, no private or trashed.
            'post_status'            => 'inherit',
            'posts_per_page'         => -1,
            'orderby'                => 'post__in',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ] );
        $found = array_flip( array_map( static fn( $post ): int => (int) $post->ID, $posts ) );
        $out   = [];
        foreach ( $ids as $i => $id ) {
            if ( isset( $found[ $shown[ $i ] ] ) ) {
                unset( $found[ $shown[ $i ] ] );
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * An id as WordPress reads one: a whole number, also written as a string ('7' from an address).
     * null, false, '' and a number that is no id (0, -5) are none (0), quietly.
     *
     * @param mixed $value
     * @throws FieldsException Anything that is not a number ("about", 7.5, true, an array): a mistake.
     */
    public static function id( $value ): int {
        if ( $value === null || $value === false || $value === '' ) {
            return 0;
        }
        if ( is_int( $value ) ) {
            return max( 0, $value );
        }
        if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
            return max( 0, (int) $value );
        }
        if ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) === 1 ) {
            return max( 0, (int) $value );
        }
        throw new FieldsException( 'give an id (a number), not ' . ( is_string( $value ) ? '"' . esc_html( $value ) . '"' : ( is_scalar( $value ) ? esc_html( (string) wp_json_encode( $value ) ) : esc_html( get_debug_type( $value ) ) ) ) . '.' );
    }

    /**
     * A list of ids, each read as id() reads one. null, false, '' and 0 are no list. An item that is no number is
     * left out with one line in error-log; the rest are kept.
     *
     * @param mixed                      $value
     * @param (callable(mixed): int)|null $one Reads one item; default id().
     * @return list<int>
     * @throws FieldsException Not a list.
     */
    public static function ids( $value, string $where, ?callable $one = null ): array {
        if ( $value === null || $value === false || $value === '' || $value === 0 ) {
            return [];
        }
        if ( ! is_array( $value ) ) {
            throw new FieldsException( 'give a list of ids, not ' . esc_html( get_debug_type( $value ) ) . '.' );
        }
        $read = $one ?? [ self::class, 'id' ];
        $out  = [];
        foreach ( $value as $item ) {
            try {
                $id = $read( $item );
            } catch ( FieldsException $e ) {
                Quietly::log( $where, $e->getMessage() );
                continue;
            }
            if ( $id > 0 ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * The lang: option as given: a language code, or null / '' for the page's language.
     *
     * @param mixed $value
     * @throws FieldsException Anything else.
     */
    public static function lang( $value ): string {
        if ( $value === null || is_string( $value ) ) {
            return (string) $value;
        }
        throw new FieldsException( 'lang: give a language code, not ' . esc_html( get_debug_type( $value ) ) . '.' );
    }

    public static function check_language( string $lang ): string {
        return self::language( $lang );
    }

    public static function in( string $lang, callable $fn ) {
        $lang   = self::language( $lang );
        $bridge = Translation::bridge();
        if ( $lang === '' || $lang === $bridge->current_language() ) {
            return $fn();
        }
        return $bridge->in_language( $lang, $fn );
    }

    /**
     * @param string|array|null $status
     * @return list<string>|null null = as WordPress shows records here
     */
    public static function statuses( $status ): ?array {
        if ( $status === null || $status === '' ) {
            return null;
        }
        if ( $status === 'all' ) {
            return self::STATUSES;
        }
        $list = is_array( $status ) ? array_values( $status ) : [ $status ];
        if ( ! $list ) {
            throw new FieldsException( 'status: give at least one of ' . esc_html( implode( ', ', self::STATUSES ) ) . ', or \'all\'.' );
        }
        foreach ( $list as $one ) {
            if ( ! is_string( $one ) || ! in_array( $one, self::STATUSES, true ) ) {
                $hint = $one === 'all' ? " ('all' stands alone, not in a list)" : '';
                throw new FieldsException( 'status: "' . ( is_scalar( $one ) ? esc_html( (string) $one ) : esc_html( gettype( $one ) ) ) . '" — use ' . esc_html( implode( ', ', self::STATUSES ) ) . ', or \'all\'' . esc_html( $hint ) . '.' );
            }
        }
        return array_values( array_unique( $list ) );
    }

    public static function init(): void {
        add_action( 'loop_start', [ self::class, 'enter_loop' ] );
    }

    public static function enter_loop( $query ): void {
        if ( $query instanceof \WP_Query ) {
            // Loops that have ended leave here too: a page of many lists keeps none of them.
            self::$loops = array_values( array_filter( self::running(), static fn( \WeakReference $loop ): bool => $loop->get() !== $query ) );
            self::$loops[] = \WeakReference::create( $query );
        }
    }

    /**
     * The post of the innermost loop being drawn, 0 outside one. A loop counts while it runs and
     * its post is the current one — as the template tags see it. A running loop whose post is not
     * the current one (the global post set to another record for a moment, or a nested loop left
     * with break) is passed over, not forgotten: it answers again once its post is current again.
     */
    public static function looped(): int {
        self::$loops = self::running();
        $current     = get_post();
        if ( ! $current instanceof \WP_Post ) {
            return 0;
        }
        for ( $i = count( self::$loops ) - 1; $i >= 0; $i-- ) {
            $q = self::$loops[ $i ]->get();
            if ( $q instanceof \WP_Query && $q->post->ID === $current->ID ) {
                return (int) $current->ID;
            }
        }
        return 0;
    }

    /**
     * The registered loops still running, outermost first. One that has ended (WordPress rewound
     * it) or whose query is gone is left out.
     *
     * @return list<\WeakReference>
     */
    private static function running(): array {
        return array_values( array_filter( self::$loops, static function ( \WeakReference $loop ): bool {
            $q = $loop->get();
            return $q instanceof \WP_Query && $q->in_the_loop && $q->current_post >= 0 && $q->post instanceof \WP_Post;
        } ) );
    }

    /**
     * The types a record is read from, as ACF lists them for its record fields: every type but
     * WordPress's own internal ones (built in and not public), attachments and Fabrement's own.
     *
     * @return list<string>
     */
    public static function post_types(): array {
        $out = [];
        foreach ( get_post_types( [], 'objects' ) as $name => $type ) {
            if ( ( $type->_builtin && ! $type->public ) || in_array( $name, [ 'attachment', SiteTemplatePostType::POST_TYPE ], true ) ) {
                continue;
            }
            $out[] = (string) $name;
        }
        return $out;
    }

    /**
     * The taxonomies a term is read from: every one but WordPress's own internal ones (built in and not public).
     *
     * @return list<string>
     */
    public static function taxonomies(): array {
        $out = [];
        foreach ( get_taxonomies( [], 'objects' ) as $name => $taxonomy ) {
            if ( ! ( $taxonomy->_builtin && ! $taxonomy->public ) ) {
                $out[] = (string) $name;
            }
        }
        return $out;
    }

    private static function language( string $lang ): string {
        $bridge = Translation::bridge();
        $codes = array_column( $bridge->languages(), 'code' );
        if ( ! $codes ) {
            return '';
        }
        if ( $lang === '' ) {
            $current = $bridge->current_language();
            if ( $current === 'all' ) {
                return '';
            }
            return in_array( $current, $codes, true ) ? $current : $bridge->default_language();
        }
        if ( ! in_array( $lang, $codes, true ) ) {
            throw new FieldsException( 'lang: the site has no language "' . esc_html( $lang ) . '" (it has ' . esc_html( implode( ', ', $codes ) ) . ').' );
        }
        return $lang;
    }
}
