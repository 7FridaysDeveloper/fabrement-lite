<?php

namespace Fabrement\Modules\Storage;

/**
 * THE one place a stored text is changed by fragment instead of re-sent whole — used by the
 * `text` edit of block content (a block's markup or one string attribute). An agent changes
 * three lines by sending three lines.
 *
 * Fragments, not line numbers: after the first edit every later line number is off, and a
 * number that silently points at the wrong code is worse than a fragment that fails loudly.
 * Matching is exact first, then whitespace-insensitive; each `old` must match exactly once
 * unless `replaceAll`; all edits apply to one copy in order, and one failure applies none.
 *
 * A content hash guards the other direction: the caller pins a save to what it read, so content
 * changed in between is refused instead of silently overwritten.
 */
final class TextEdits {

    /**
     * `edits` as an array whether it arrived as an object or as a JSON string of one.
     *
     * @param mixed $raw
     * @return mixed The decoded array, or $raw unchanged when it is not a JSON object string.
     */
    public static function decode_object( $raw ) {
        if ( ! is_string( $raw ) ) {
            return $raw;
        }
        // The transport may hand the string over slashed (WordPress adds slashes to request data),
        // so try it as sent first, then unslashed.
        // …and a client may encode it twice (a JSON string whose content is the JSON object).
        foreach ( [ $raw, wp_unslash( $raw ) ] as $candidate ) {
            for ( $depth = 0; $depth < 3 && is_string( $candidate ); $depth++ ) {
                $candidate = json_decode( $candidate, true );
            }
            if ( is_array( $candidate ) ) {
                return $candidate;
            }
        }
        return $raw;
    }

    public const MODES = [ 'replace', 'remove', 'insertAfter', 'insertBefore', 'append', 'prepend' ];

    /** Modes that need no anchor — the only ones allowed on text that is still empty. */
    private const UNANCHORED = [ 'append', 'prepend' ];

    /**
     * @param array<int, array{mode?: mixed, old?: mixed, new?: mixed, replaceAll?: mixed}> $edits
     * @return string|\WP_Error The edited text.
     */
    public static function apply( string $text, array $edits ) {
        if ( $edits === [] ) {
            return new \WP_Error( 'edits_empty', '`edits` is empty — nothing to change.' );
        }

        foreach ( array_values( $edits ) as $i => $edit ) {
            $n    = $i + 1;
            $edit = is_array( $edit ) ? $edit : [];
            $mode = (string) ( $edit['mode'] ?? 'replace' );
            $all  = ! empty( $edit['replaceAll'] );

            $invalid = self::invalid( $edit, $mode, $n );
            if ( $invalid !== null ) {
                return $invalid;
            }

            $old = (string) ( $edit['old'] ?? '' );
            $new = (string) ( $edit['new'] ?? '' );

            if ( $mode === 'append' ) {
                $text .= $new;
                continue;
            }
            if ( $mode === 'prepend' ) {
                $text = $new . $text;
                continue;
            }

            $matches = self::matches( $text, $old );
            if ( is_string( $matches ) ) {
                return new \WP_Error( 'edit_not_found', sprintf( 'Edit #%d (%s): `old` was not found. Nothing was saved — read the content again and copy the fragment.', $n, $mode ) );
            }
            if ( count( $matches ) > 1 && ! $all ) {
                return new \WP_Error( 'edit_ambiguous', sprintf( 'Edit #%d (%s): `old` matches %d places — %s. Nothing was saved — include more surrounding text so it matches one of them%s.', $n, $mode, count( $matches ), self::describe_matches( $text, $matches ), in_array( $mode, [ 'replace', 'remove' ], true ) ? ', or set replaceAll' : '' ) );
            }

            // Back to front, so earlier offsets stay valid.
            foreach ( array_reverse( $matches ) as [ $match, $offset ] ) {
                switch ( $mode ) {
                    case 'remove':
                        $with = '';
                        break;
                    case 'insertAfter':
                        $with = $match . $new;
                        break;
                    case 'insertBefore':
                        $with = $new . $match;
                        break;
                    default:
                        $with = $new;
                }
                $text = substr_replace( $text, $with, $offset, strlen( $match ) );
            }
        }

        return $text;
    }

    /** How many lines of an ambiguous match to name — enough to pick a wider fragment, short enough to read. */
    private const MAX_LISTED = 5;

    /**
     * "line 3: `.a { color: red; }`; line 14: `…`" — where each match starts, with that line's text,
     * so the caller can widen `old` with what differs instead of re-reading the whole text.
     *
     * @param array<int, array{0: string, 1: int}> $matches
     */
    private static function describe_matches( string $text, array $matches ): string {
        // Grouped by line: two matches on one line (common in a block's one-line markup) are one
        // entry "line 1 (2×)", not the same line printed twice with nothing to tell them apart.
        $lines = [];
        foreach ( $matches as [ , $offset ] ) {
            $line = substr_count( $text, "\n", 0, $offset ) + 1;
            if ( ! isset( $lines[ $line ] ) ) {
                $lines[ $line ] = [ 'offset' => $offset, 'count' => 0 ];
            }
            $lines[ $line ]['count']++;
        }

        $parts = [];
        foreach ( array_slice( $lines, 0, self::MAX_LISTED, true ) as $line => $info ) {
            $offset = $info['offset'];
            $start  = strrpos( substr( $text, 0, $offset ), "\n" );
            $start  = $start === false ? 0 : $start + 1;
            $end    = strpos( $text, "\n", $offset );
            $body   = trim( substr( $text, $start, ( $end === false ? strlen( $text ) : $end ) - $start ) );
            if ( strlen( $body ) > 80 ) {
                // mb-safe cut: never split a multibyte character in the middle.
                $body = rtrim( mb_strcut( $body, 0, 80, 'UTF-8' ) ) . '…';
            }
            $parts[] = sprintf( 'line %d%s: `%s`', $line, $info['count'] > 1 ? sprintf( ' (%d×)', $info['count'] ) : '', $body );
        }
        $rest = count( $lines ) - count( $parts );
        return implode( '; ', $parts ) . ( $rest > 0 ? sprintf( '; and %d more lines', $rest ) : '' );
    }

    /** A clear refusal for a mode given the wrong fields, or null when the edit is well-formed. */
    private static function invalid( array $edit, string $mode, int $n ): ?\WP_Error {
        if ( ! in_array( $mode, self::MODES, true ) ) {
            return new \WP_Error( 'edit_mode', sprintf( 'Edit #%d: unknown mode "%s". Use %s.', $n, $mode, implode( ', ', self::MODES ) ) );
        }

        $has_old = isset( $edit['old'] ) && (string) $edit['old'] !== '';
        $has_new = array_key_exists( 'new', $edit );

        if ( in_array( $mode, self::UNANCHORED, true ) ) {
            if ( $has_old ) {
                return new \WP_Error( 'edit_fields', sprintf( 'Edit #%d: %s takes no `old` — it adds `new` at the %s of the text. To add next to a fragment use insertAfter/insertBefore.', $n, $mode, $mode === 'append' ? 'end' : 'start' ) );
            }
        } elseif ( ! $has_old ) {
            return new \WP_Error( 'edit_old_empty', sprintf( 'Edit #%d: %s needs `old` — the exact fragment to %s.', $n, $mode, $mode === 'remove' ? 'remove' : ( $mode === 'replace' ? 'replace' : 'anchor on' ) ) );
        }

        if ( $mode === 'remove' ) {
            if ( $has_new ) {
                return new \WP_Error( 'edit_fields', sprintf( 'Edit #%d: remove takes no `new`. To put something in its place use replace.', $n ) );
            }
        } elseif ( $mode !== 'replace' && ( ! $has_new || (string) $edit['new'] === '' ) ) {
            return new \WP_Error( 'edit_fields', sprintf( 'Edit #%d: %s needs a non-empty `new`.', $n, $mode ) );
        } elseif ( $mode === 'replace' && ! $has_new ) {
            return new \WP_Error( 'edit_fields', sprintf( 'Edit #%d: replace needs `new` ("" deletes — or use remove).', $n ) );
        }

        if ( ! empty( $edit['replaceAll'] ) && ! in_array( $mode, [ 'replace', 'remove' ], true ) ) {
            return new \WP_Error( 'edit_fields', sprintf( 'Edit #%d: replaceAll is for replace/remove — an insert anchor must be unique.', $n ) );
        }

        return null;
    }

    /**
     * Every place `old` matches as [matched text, byte offset]: exact occurrences when there are
     * any, otherwise the whitespace-insensitive ones. A string return means no match at all.
     *
     * @return array<int, array{0: string, 1: int}>|string
     */
    private static function matches( string $text, string $old ) {
        $found = [];
        $pos   = 0;
        while ( ( $at = strpos( $text, $old, $pos ) ) !== false ) {
            $found[] = [ $old, $at ];
            $pos     = $at + strlen( $old );
        }
        if ( $found !== [] ) {
            return $found;
        }

        // An agent that re-typed the fragment rarely reproduces indentation, CRLF or trailing
        // spaces. One `\s+` per whitespace run — never adjacent quantifiers, so no catastrophic
        // backtracking.
        $loose = self::loose_matches( $text, $old );
        return $loose !== [] ? $loose : 'none';
    }

    /**
     * Content hash the caller echoes back as expectedHash. A list of blocks hashes as one:
     * a change to any of them is a change to the thing that was read.
     *
     * @param string|array<string,string> $content
     */
    public static function hash( $content ): string {
        $text = is_array( $content ) ? (string) wp_json_encode( $content ) : (string) $content;
        return substr( sha1( $text ), 0, 12 );
    }

    /** @return array<int, array{0: string, 1: int}> Whitespace-insensitive matches with byte offsets. */
    private static function loose_matches( string $text, string $old ): array {
        $parts = preg_split( '/\s+/', trim( $old ) );
        if ( ! is_array( $parts ) || implode( '', $parts ) === '' ) {
            return [];
        }
        $pattern = '/' . implode( '\s+', array_map( static fn( $p ) => preg_quote( $p, '/' ), $parts ) ) . '/';
        if ( ! preg_match_all( $pattern, $text, $found, PREG_OFFSET_CAPTURE ) ) {
            return [];
        }
        return $found[0];
    }
}
