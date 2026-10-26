<?php

namespace Fabrement\Modules\Fields\Translation;

use Fabrement\Framework\Keys;
use Fabrement\Modules\Fields\Schema\Owner;
use Fabrement\Modules\Fields\Schema\SchemaRepository;
use Fabrement\Modules\Fields\Values\FieldObject;
use Fabrement\Modules\Fields\Values\ValueCodec;
use Fabrement\Modules\Fields\Values\ValueRepository;
use Fabrement\Modules\Fields\Values\Store\OptionStore;
use Fabrement\Modules\Fields\Values\Store\OptionRows;
use Fabrement\Modules\Translation\Translation;

/** Options lifecycle adapter; translation rules and seeding belong to FieldSync. */
final class OptionVersions {
    public static function init(): void {
        add_action( 'fabrement_fields_saved', [ self::class, 'saved' ], 10, 2 );
    }

    /** An empty row is a version too. Field presence cannot answer this question. */
    public static function exists( FieldObject $object ): bool {
        return ( new OptionStore() )->exists( $object->id );
    }

    /** Explicit creation only: a validated write, never a read or an admin page view. */
    public static function ensure( FieldObject $object, array $changes = [], array $selection = [] ): bool {
        if ( self::exists( $object ) ) {
            return false;
        }
        $map = FieldSync::initial( $object, ValueCodec::active( $object->all_fields() ) );
        // Creation and the caller's validated edits publish together, not as two visible writes.
        $patch = ValueRepository::patch( $object->all_fields(), $map, $changes, [], $selection );
        $map = array_replace( array_diff_key( $map, array_flip( $patch['remove'] ) ), $patch['insert'] );
        return ( new OptionStore() )->create( $object->id, $map );
    }

    /** Only already-created translations follow a saved original. */
    public static function saved( string $type, $id ): void {
        if ( $type !== 'options' ) {
            return;
        }
        $slug = OptionRows::slug_of( (string) $id );
        if ( $slug === '' || $id !== Keys::options_page( $slug ) ) {
            return;
        }
        $source = FieldObject::options( $slug, Translation::bridge()->default_language() );
        if ( ! $source ) {
            return;
        }
        foreach ( Translation::bridge()->languages() as $language ) {
            $target = FieldObject::options( $slug, $language['code'] );
            if ( $target && $target->id !== $source->id && self::exists( $target ) ) {
                FieldSync::sync( $source, $target );
            }
        }
    }

    public static function default_changed( string $new, string $previous ): void {
        if ( $new === '' || $previous === '' || $new === $previous ) {
            return;
        }
        foreach ( SchemaRepository::all() as $document ) {
            if ( ( $document['type'] ?? '' ) === 'options' && is_string( $document['slug'] ?? null ) ) {
                self::swap( (string) $document['slug'], $new, $previous );
            }
        }
    }

    private static function swap( string $slug, string $new, string $previous ): void {
        $store = new OptionStore();
        $base = Keys::options_page( $slug );
        $old = Keys::options_page( $slug, $previous );
        $next = Keys::options_page( $slug, $new );
        if ( ! $store->exists( $base ) ) {
            return;
        }
        $seed = null;
        if ( ! $store->exists( $next ) ) {
            $owner = Owner::of( 'options', $slug );
            $fields = ValueCodec::active( $owner ? ( SchemaRepository::get( $owner )['fields'] ?? [] ) : [] );
            $fields = array_column( $fields, null, 'key' );
            $plan = FieldSync::project( $fields, ValueRepository::decode( $fields, $store->load( $base ) ), ValueRepository::decode( $fields, [] ) );
            $seed = [];
            foreach ( $plan['changes'] as $key => $value ) {
                ValueCodec::flatten( $fields[ $key ], $value, $key, $seed );
            }
        }
        self::rename( $old, null );
        self::rename( $base, $old );
        if ( $seed === null ) {
            self::rename( $next, $base );
        } else {
            $store->create( $base, $seed );
        }
    }

    private static function rename( string $from, ?string $to ): void {
        global $wpdb;
        if ( $to === null ) {
            delete_option( $from );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an option keeps its value and autoload under a new name; caches are cleared below.
            $wpdb->update( $wpdb->options, [ 'option_name' => $to ], [ 'option_name' => $from ] );
        }
        foreach ( array_filter( [ $from, $to ] ) as $name ) {
            ( new OptionStore() )->clean( $name, true );
        }
    }
}
