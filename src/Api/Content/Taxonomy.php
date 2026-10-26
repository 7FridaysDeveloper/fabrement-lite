<?php

namespace Fabrement\Api\Content;

use Fabrement\Api\Fabrement;
use Fabrement\Api\FieldsException;
use Fabrement\Api\Support\ReadResult;
use Fabrement\Api\Support\OrElse;
use Fabrement\Api\Support\Quietly;
use Fabrement\Modules\Translation\Version;

defined( 'ABSPATH' ) || exit;

/**
 * A taxonomy as WordPress registers it, from fabrement()->taxonomies(): its name, its label in the
 * page's language and its terms. A taxonomy that is not there is an empty Taxonomy.
 */
final class Taxonomy {

    use OrElse;

    private string $name;

    private function __construct( string $name ) {
        $this->name = $name;
    }

    public static function none(): self {
        return new self( '' );
    }

    /** @internal A registered taxonomy's name, already checked. */
    public static function of( string $name ): self {
        return taxonomy_exists( $name ) ? new self( $name ) : self::none();
    }

    public function not_empty( ...$unknown ): bool {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->name !== '';
    }

    public function name( ...$unknown ): string {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->name;
    }

    /** Its plural label (labels->name), as WordPress and WPML give it in the page's language. */
    public function label( ...$options ): ?string {
        return ReadResult::read( 'Taxonomy::label()', $options, [], function (): string {
            $object = $this->name !== '' ? get_taxonomy( $this->name ) : false;
            return $object instanceof \WP_Taxonomy ? (string) $object->labels->name : '';
        }, '' );
    }

    /**
     * Its terms, as fabrement()->terms( taxonomy: … ) gives them.
     *
     * @param mixed $hide_empty As in terms().
     * @param mixed $lang       As in terms().
     * @return list<Term>
     */
    public function terms( $hide_empty = null, $lang = '', ...$unknown ): array {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return Quietly::run( 'Taxonomy::terms()', function () use ( $hide_empty, $lang ): array {
            // A wrong option is a mistake in the code, an empty Taxonomy or not.
            if ( $hide_empty !== null && $hide_empty !== '' && ! is_bool( $hide_empty ) ) {
                throw new FieldsException( 'hide_empty: true or false, not ' . esc_html( get_debug_type( $hide_empty ) ) . '.' );
            }
            $lang = Version::lang( $lang );
            Version::check_language( $lang );
            return $this->name === '' ? [] : Fabrement::instance()->terms( taxonomy: $this->name, hide_empty: $hide_empty, lang: $lang );
        }, [] );
    }

    /** @return array{name:string,label:string}|array{} */
    public function to_array( ...$unknown ): array {
        Quietly::unknown( static::class . '::' . __FUNCTION__, $unknown );
        return $this->name === '' ? [] : [ 'name' => $this->name, 'label' => (string) $this->label() ];
    }
}
