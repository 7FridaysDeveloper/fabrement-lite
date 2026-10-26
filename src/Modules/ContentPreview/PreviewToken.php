<?php

namespace Fabrement\Modules\ContentPreview;

/** Shared lifetime and stored-status encoding for frontend content preview tokens. */
final class PreviewToken {

    /** How long a content preview link stays valid ({@see ContentPreview::TTL}). */
    public const TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * @param list<string>|null $statuses
     * @internal The suffix a stored token value carries its statuses in.
     */
    public static function with_statuses( ?array $statuses ): string {
        return $statuses === null ? '' : '|' . implode( ',', $statuses );
    }

    /**
     * @return list<string>|null
     * @internal The statuses a stored token value carries.
     */
    public static function statuses_in( string $stored ): ?array {
        $parts = explode( '|', $stored, 2 );
        return isset( $parts[1] ) && $parts[1] !== '' ? explode( ',', $parts[1] ) : null;
    }

}
