/**
 * THE editor's repository of what records and media look like — titles, addresses, languages,
 * statuses, thumbnails. One instance for the whole editor (`refs` below): the record pickers, the
 * Fabrement fields box, the search modal and the gallery all read and fill the same cache.
 *
 *   refs.prime({lang, posts, media, postIds, mediaIds})   what the page already carries
 *   refs.posts.require(ids, lang) / refs.media.require(ids) what is missing is asked for
 *   refs.posts.get(id, lang) / refs.media.get(id)           read from the cache
 *
 * The server side is EditorRefs (src/Route/Editor/EditorRefs.php): /post-refs and /media-refs
 * answer by id, exactly, in batches. Rules, the same for every kind:
 *   - a row is kept for the editor session; rows the server sends again replace it;
 *   - ids asked for in the same moment go out as ONE request (chunks of 100);
 *   - an id already on its way is not asked again: the later asker waits for that request;
 *   - an id the server looked up but did not return (gone, unreadable) is remembered as absent;
 *   - a failed request marks nothing: the next asker tries again;
 *   - a PARTIAL row (built from a control's value, without a thumbnail) is shown but still
 *     fetched in full, and never replaces a complete one.
 */

type Row = { id: number; partial?: boolean };
type Fetch = (ids: number[], lang: string) => Promise<Row[]>;

export class RefStore<T extends Row> {
    private rows = new Map<string, T>();
    private absent = new Set<string>();
    private inflight = new Map<string, Promise<void>>();
    private queue = new Map<string, { ids: Set<number>; done: Promise<void>; resolve: () => void }>();

    constructor(private readonly fetch: Fetch, private readonly chunk = 100) {}

    private key(id: number, lang: string): string {
        return lang + ':' + id;
    }

    get(id: number, lang = ''): T | undefined {
        return this.rows.get(this.key(id, lang));
    }

    isAbsent(id: number, lang = ''): boolean {
        return this.absent.has(this.key(id, lang));
    }

    /** Known = a complete row, or looked up and not there. Anything else still has to be asked for. */
    known(id: number, lang = ''): boolean {
        const row = this.rows.get(this.key(id, lang));
        return (!!row && !row.partial) || this.absent.has(this.key(id, lang));
    }

    /**
     * Rows the server sent: they replace what the cache held. `lookedUp` = every id the server was
     * asked about; the ones missing from `rows` become absent (and lose any old row).
     */
    prime(rows: T[] | null | undefined, lang = '', lookedUp: number[] = []): void {
        const returned = new Set<number>();
        for (const row of rows || []) {
            if (!row || !row.id) continue;
            this.rows.set(this.key(row.id, lang), row);
            this.absent.delete(this.key(row.id, lang));
            returned.add(row.id);
        }
        for (const id of lookedUp) {
            if (returned.has(id)) continue;
            this.rows.delete(this.key(id, lang));
            this.absent.add(this.key(id, lang));
        }
    }

    /** One row from somewhere else (a pick in the media library). A partial row never replaces a complete one. */
    remember(row: T | null | undefined, lang = ''): void {
        if (!row || !row.id) return;
        const known = this.rows.get(this.key(row.id, lang));
        if (row.partial && known && !known.partial) return;
        this.rows.set(this.key(row.id, lang), row);
        this.absent.delete(this.key(row.id, lang));
    }

    /** Make sure these ids are known; resolves when every one of them is (or its request failed). */
    require(ids: number[], lang = ''): Promise<void> {
        const waiting: Promise<void>[] = [];
        const ask: number[] = [];
        for (const id of ids) {
            if (!id || this.known(id, lang)) continue;
            const running = this.inflight.get(this.key(id, lang));
            if (running) waiting.push(running); else ask.push(id);
        }
        if (!ask.length) return Promise.all(waiting).then(() => undefined);
        let batch = this.queue.get(lang);
        if (!batch) {
            let resolve: () => void = () => {};
            const done = new Promise<void>((r) => { resolve = r; });
            batch = { ids: new Set<number>(), done, resolve };
            this.queue.set(lang, batch);
            // Collect everyone who asks in this tick, then ask the server once.
            setTimeout(() => { void this.flush(lang); }, 0);
        }
        for (const id of ask) {
            batch.ids.add(id);
            this.inflight.set(this.key(id, lang), batch.done);
        }
        return Promise.all([batch.done, ...waiting]).then(() => undefined);
    }

    private async flush(lang: string): Promise<void> {
        const batch = this.queue.get(lang);
        this.queue.delete(lang);
        if (!batch) return;
        const all = Array.from(batch.ids).filter((id) => !this.known(id, lang));
        for (let i = 0; i < all.length; i += this.chunk) {
            const chunk = all.slice(i, i + this.chunk);
            try {
                this.prime((await this.fetch(chunk, lang)) as T[], lang, chunk);
            } catch {
                // Nothing is marked: the next asker tries again.
            }
        }
        for (const id of batch.ids) this.inflight.delete(this.key(id, lang));
        batch.resolve();
    }
}

/** One /fabrement/v1/post-refs row, as the pickers keep it. */
export type PostRow = {
    id: number; title: string; url: string; subtype: string; status?: string;
    languages?: string[];
    onPage?: { state: 'same' | 'translated' | 'original' | 'hidden'; id: number; title: string };
};

/** One /fabrement/v1/media-refs row (also what the fields box receives with its values). */
export type MediaRef = {
    id: number; url: string; thumb: string; alt: string; title: string;
    filename: string; mimeType: string; fileSize: number;
    /** Built from what a control handed back, not from the server: fetched in full when shown. */
    partial?: boolean;
};

/** What the server may send with a page or a form, to fill the repository before anyone asks. */
export type RefsPayload = { lang?: string; posts?: PostRow[]; media?: MediaRef[]; postIds?: number[]; mediaIds?: number[] };

type ApiFetch = (options: { path: string }) => Promise<any>;

export class RefsRepository {
    readonly posts: RefStore<PostRow>;
    readonly media: RefStore<MediaRef>;

    constructor(apiFetch: () => ApiFetch | undefined = () => (wp.apiFetch as unknown as ApiFetch | undefined)) {
        const ask = (path: string) => {
            const fetch = apiFetch();
            return fetch ? fetch({ path }) : Promise.reject(new Error('wp.apiFetch is not available'));
        };
        // Our own routes, never core listings: WPML scopes those to the admin's language and
        // answers with other ids (a translated copy) or with nothing.
        this.posts = new RefStore<PostRow>((ids, lang) =>
            ask('/fabrement/v1/post-refs?ids=' + encodeURIComponent(ids.join(',')) + '&lang=' + encodeURIComponent(lang)));
        this.media = new RefStore<MediaRef>((ids) =>
            ask('/fabrement/v1/media-refs?ids=' + encodeURIComponent(ids.join(','))));
    }

    /** Fill the repository with what the page or a response already carries. */
    prime(payload: RefsPayload | null | undefined): void {
        if (!payload) return;
        this.posts.prime(payload.posts, payload.lang || '', payload.postIds || []);
        this.media.prime(payload.media, '', payload.mediaIds || []);
    }
}

/** The one instance every component uses. */
export const refs = new RefsRepository();
