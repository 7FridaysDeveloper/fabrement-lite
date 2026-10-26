/**
 * Media previews for the Fabrement fields box — a thin face over the editor's one repository
 * (refs.media, see refs-repository.ts): the rows the form arrives with are there at once, and
 * whatever is missing is asked for in ONE /fabrement/v1/media-refs request per moment, by id,
 * exactly (a core list query is rewritten by WPML to other ids).
 */
import { refs, MediaRef } from './refs-repository';

export type { MediaRef };

/**
 * THE one place where media data from outside becomes a MediaRef. Every entry point says where
 * its data comes from — nothing guesses a shape from its keys:
 *   library  an attachment from the media library window: {mime, filesizeInBytes, sizes, title}
 *   control  what the image / file controls hand back: image {id, url, alt},
 *            file {id, url, filename, fileSize, mimeType}. They carry no thumbnail or title,
 *            so the row is PARTIAL and fetched in full when shown.
 */
export function toMediaRef(from: 'library' | 'control', item: any): MediaRef | null {
    const id = Number(item?.id);
    if (!id) return null;
    if (from === 'library') {
        const url = item.url || '';
        return {
            id, url,
            thumb: item.sizes?.medium?.url || item.sizes?.thumbnail?.url || url,
            alt: item.alt || '',
            title: item.title || '',
            filename: item.filename || url.split('/').pop() || '',
            mimeType: item.mime || '',
            fileSize: item.filesizeInBytes || 0,
        };
    }
    const url = item.url || '';
    return {
        id, url, thumb: url,
        alt: item.alt || '',
        title: '',
        filename: item.filename || url.split('/').pop() || '',
        mimeType: item.mimeType || '',
        fileSize: item.fileSize || 0,
        partial: true,
    };
}

/** Rows the server sent, straight into the repository. The form itself goes through refs.prime(); tests fill the cache with this. */
export function primeMedia(rows: MediaRef[] | null | undefined, lookedUp: number[] = []): void {
    refs.media.prime(rows, '', lookedUp);
}

/** The preview to show: a row, a {missing} stand-in for an id the server does not have, or nothing yet. */
export function cachedMedia(id: number): (MediaRef & { missing?: boolean }) | undefined {
    const row = refs.media.get(id);
    if (row) return row;
    return refs.media.isAbsent(id) ? { ...missingRow(id) } : undefined;
}

/** Keep a row. A partial one never replaces a complete row the repository already holds. */
export function rememberMedia(ref: MediaRef | null): void {
    refs.media.remember(ref);
}

/** Whether a field must still fetch this id to show it properly. */
export function needsMedia(id: number): boolean {
    return !refs.media.known(id);
}

/** Resolve one id; ids asked for in the same moment share ONE request. */
export function loadMedia(id: number): Promise<MediaRef & { missing?: boolean }> {
    return refs.media.require([id]).then(() => cachedMedia(id) || missingRow(id));
}

/**
 * For a field showing these media ids: asks for the ones the repository lacks — together, in one
 * request — and renders the field again when they arrive. Read the rows with cachedMedia().
 */
export function useMediaRefs(ids: number[]): void {
    const { useState, useEffect } = wp.element;
    const [, setLoaded] = useState(0);
    useEffect(() => {
        const missing = ids.filter((id) => id > 0 && needsMedia(id));
        if (!missing.length) return;
        let cancelled = false;
        Promise.all(missing.map(loadMedia)).then(() => { if (!cancelled) setLoaded((n: number) => n + 1); });
        return () => { cancelled = true; };
    }, [ids.join(',')]);
}

function missingRow(id: number): MediaRef & { missing: boolean } {
    return { id, url: '', thumb: '', alt: '', title: '', filename: '', mimeType: '', fileSize: 0, missing: true };
}
