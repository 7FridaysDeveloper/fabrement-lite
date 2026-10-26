<?php

namespace Fabrement\Modules\Storage;

/**
 * Where everything Fabrement writes to disk lives: `uploads/fabrement-lite/`, beside the media, where
 * WordPress keeps files a plugin writes. One root, always.
 *
 * NOT A THEME and not the plugin's own folder, deliberately: both are replaced or deleted from the
 * admin in two clicks, and everything inside them goes too.
 *
 * NO FALLBACK, also deliberately. There used to be one — "if the root is not writable, use another
 * folder" — and it was decided per request by is_writable() on the root. A deploy that merely
 * changed the folder's permissions therefore flipped every READ to an empty folder: no fields,
 * no templates, no log line, while the real tree sat intact one directory over. And because the same
 * request could still WRITE to the other folder, recreating what seemed lost forked the data, and
 * the next migration run deleted the good copy in favour of the fork. Read permission and write
 * permission are different questions: a read-only root is still where the data is. So reads always
 * go here, and a write that cannot happen is refused out loud by {@see StorageFiles::ensure_dir()}
 * — never rerouted.
 */
final class FabrementStorage {

    /** Folder name under uploads. */
    public const NAME = 'fabrement-lite';

    /** Absolute path of the storage root, no trailing slash. */
    public static function dir(): string {
        // false: only the path; WordPress would otherwise create this month's uploads folder.
        $uploads = wp_upload_dir( null, false );
        return rtrim( wp_normalize_path( (string) $uploads['basedir'] ), '/' ) . '/' . self::NAME;
    }

    /** Absolute path of one folder inside the root, no trailing slash. */
    public static function path( string $folder ): string {
        return self::dir() . '/' . trim( $folder, '/' );
    }
}
