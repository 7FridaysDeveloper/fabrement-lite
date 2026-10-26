<?php

namespace Fabrement\Api\Support;

use Fabrement\Api\FieldsException;
use Fabrement\Modules\Translation\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * fabrement()->format( lang: '' ): numbers, dates and file sizes as a language writes them, its
 * locale switched through the translation bridge while formatting. A language that cannot be used
 * gives an empty Format: every answer is ''.
 */
final class Format {

    private const MAX_DECIMALS = 20;

    private string $lang;

    private bool $usable;

    /** @var array<string, array{0: string, 1: string}> */
    private static array $separators = [];

    private function __construct( string $lang, bool $usable ) {
        $this->lang = $lang;
        $this->usable = $usable;
    }

    public static function none(): self {
        return new self( '', false );
    }

    /** @internal fabrement()->format() makes it, the language already checked. */
    public static function in( string $lang ): self {
        return new self( $lang, true );
    }

    /** @param mixed $n A number, also written as a string ('12.5'); null or '' is none. */
    public function number( $n = null, ...$options ): ?string {
        return ReadResult::read( 'Format::number()', $options, [ 'decimals' => null, 'separators' => null ], function ( array $o ) use ( $n ): string {
            $decimals = $o['decimals'];
            $separators = $o['separators'];
            self::check_decimals( $decimals );
            if ( $separators !== null && ( count( $separators ) !== 2 || array_values( $separators ) !== $separators || ! is_string( $separators[0] ) || ! is_string( $separators[1] ) ) ) {
                throw new FieldsException( 'separators: give [ decimal point, thousands separator ], two strings, or leave it out for the language\'s own.' );
            }
            if ( ! $this->usable || $n === null || $n === '' ) {
                return '';
            }
            if ( is_string( $n ) && is_numeric( $n ) ) {
                $n = $n + 0;
            }
            if ( ! is_int( $n ) && ! is_float( $n ) ) {
                throw new FieldsException( 'give a number, not ' . ( is_string( $n ) ? '"' . esc_html( $n ) . '"' : esc_html( get_debug_type( $n ) ) ) . '.' );
            }
            if ( is_float( $n ) && ! is_finite( $n ) ) {
                throw new FieldsException( 'the number is not finite.' );
            }
            [ $point, $thousands ] = $separators ?? $this->separators();
            return number_format( $n, $decimals ?? self::decimals_of( $n ), $point, $thousands );
        }, '' );
    }

    /** @param mixed $value A date as text; null or '' is none. */
    public function date( $value = null, ...$options ): ?string {
        return ReadResult::read( 'Format::date()', $options, [ 'format' => null ], function ( array $o ) use ( $value ): string {
            $format = $o['format'];
            if ( $format === '' ) {
                throw new FieldsException( 'format: give a PHP date format, or leave it out for the site\'s date format.' );
            }
            if ( ! $this->usable || $value === null || $value === '' ) {
                return '';
            }
            if ( ! is_string( $value ) ) {
                throw new FieldsException( 'give a date as text, not ' . esc_html( get_debug_type( $value ) ) . '.' );
            }
            $parsed = self::parse( $value );
            if ( $parsed === null ) {
                throw new FieldsException( '"' . esc_html( $value ) . '" is not a date — give YYYY-MM-DD, YYYY-MM-DDTHH:mm, YYYY-MM-DD HH:mm:ss or ISO 8601 with an offset.' );
            }
            [ $date, $with_time ] = $parsed;
            if ( $format === null ) {
                $format = (string) get_option( 'date_format', 'F j, Y' ) . ( $with_time ? ' ' . (string) get_option( 'time_format', 'g:i a' ) : '' );
            }
            $shown = Translation::bridge()->in_locale( $this->lang, static fn() => wp_date( $format, $date->getTimestamp(), $date->getTimezone() ) );
            return (string) $shown;
        }, '' );
    }

    /** @param mixed $bytes A whole number of bytes, also written as a string; null or '' is none. */
    public function filesize( $bytes = null, ...$options ): ?string {
        return ReadResult::read( 'Format::filesize()', $options, [ 'decimals' => null ], function ( array $o ) use ( $bytes ): string {
            $decimals = $o['decimals'];
            self::check_decimals( $decimals );
            if ( $bytes === '' ) {
                $bytes = null;
            } elseif ( is_string( $bytes ) && preg_match( '/^-?\d+$/', $bytes ) === 1 ) {
                $bytes = (int) $bytes;
            } elseif ( is_float( $bytes ) && is_finite( $bytes ) && floor( $bytes ) === $bytes && $bytes <= PHP_INT_MAX ) {
                $bytes = (int) $bytes;
            }
            if ( $bytes !== null && ! is_int( $bytes ) ) {
                throw new FieldsException( 'give a number of bytes, not ' . ( is_string( $bytes ) ? '"' . esc_html( $bytes ) . '"' : esc_html( get_debug_type( $bytes ) ) ) . '.' );
            }
            if ( $bytes !== null && $bytes < 0 ) {
                throw new FieldsException( 'a file size is a number of bytes from 0 (' . esc_html( $bytes ) . ' given).' );
            }
            if ( ! $this->usable || $bytes === null ) {
                return '';
            }
            $shown = Translation::bridge()->in_locale( $this->lang, static fn() => size_format( $bytes, $decimals ?? ( $bytes >= 1048576 ? 1 : 0 ) ) );
            return is_string( $shown ) ? $shown : '';
        }, '' );
    }

    /** @return array{0: string, 1: string} */
    private function separators(): array {
        return self::$separators[ $this->lang ] ??= Translation::bridge()->in_locale( $this->lang, static function (): array {
            global $wp_locale;
            $format = is_object( $wp_locale ) && is_array( $wp_locale->number_format ?? null ) ? $wp_locale->number_format : [];
            return [
                html_entity_decode( is_string( $format['decimal_point'] ?? null ) ? $format['decimal_point'] : '.', ENT_QUOTES, 'UTF-8' ),
                html_entity_decode( is_string( $format['thousands_sep'] ?? null ) ? $format['thousands_sep'] : ',', ENT_QUOTES, 'UTF-8' ),
            ];
        } );
    }

    private static function check_decimals( ?int $decimals ): void {
        if ( $decimals !== null && ( $decimals < 0 || $decimals > self::MAX_DECIMALS ) ) {
            throw new FieldsException( 'decimals: a whole number from 0 to ' . esc_html( (string) self::MAX_DECIMALS ) . ' (' . esc_html( $decimals ) . ' given), or leave it out for the number as stored.' );
        }
    }

    private static function decimals_of( int|float $n ): int {
        if ( is_int( $n ) ) {
            return 0;
        }
        $text = rtrim( rtrim( sprintf( '%.10F', $n ), '0' ), '.' );
        $dot = strpos( $text, '.' );
        return $dot === false ? 0 : strlen( $text ) - $dot - 1;
    }

    /** @return array{0: \DateTimeImmutable, 1: bool}|null */
    private static function parse( string $value ): ?array {
        $local = [
            'Y-m-d'       => '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',
            'Y-m-d\TH:i'  => '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D',
            'Y-m-d H:i:s' => '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
        ];
        if ( (int) substr( $value, 0, 4 ) < 1 ) {
            return null;
        }
        foreach ( $local as $format => $shape ) {
            if ( preg_match( $shape, $value ) !== 1 ) {
                continue;
            }
            $with_time = $format !== 'Y-m-d';
            $date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, $with_time ? wp_timezone() : new \DateTimeZone( 'UTC' ) );
            return $date && $date->format( $format ) === $value ? [ $date, $with_time ] : null;
        }
        if ( preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2})(?::([0-9]{2})(?:\.[0-9]+)?)?(?:Z|[+-][0-9]{2}:?[0-9]{2})$/D', $value, $m ) !== 1 ) {
            return null;
        }
        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) ( $m[6] ?? 0 ) > 59 ) {
            return null;
        }
        try {
            return [ ( new \DateTimeImmutable( $value ) )->setTimezone( wp_timezone() ), true ];
        } catch ( \Exception $e ) {
            return null;
        }
    }
}
