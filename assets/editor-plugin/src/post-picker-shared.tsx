/**
 * Internals shared by PostObjectField (single) and PostObjectsField (multiple): the search
 * modal, the result list and the looked-up record shape.
 *
 * The search is core's `/wp/v2/search`, the endpoint LinkField uses too. It covers custom post
 * types registered with `show_in_rest`; its `subtype` parameter restricts the picker to the
 * post types the field declares.
 */
import { refs, PostRow } from './refs-repository';
import './post-picker-shared.css';



const { useState, useEffect, useRef } = wp.element;
const { Modal, Spinner, TextControl } = wp.components;
const apiFetch = wp.apiFetch;

/** One `/wp/v2/search` row (`type=post`), before it is narrowed to a stored value. */
type SearchResult = {
    id:      number;
    url:     string;
    title:   string | { rendered?: string };
    subtype: string;
};

/**
 * One resolved record as the field displays it. This is NOT what is stored: the value is the
 * bare id (like ACF), and the title shown beside it is looked up live.
 */
export type PostRef = {
    id:        number;
    title:     string;
    url:       string;
    /** Post type slug of the picked record ("post", "page", "project"…). */
    postType:  string;
    /** Languages the record is PUBLISHED in (empty on a single-language site). */
    languages?: string[];
    /** What a page in the editor's language shows for it — absent on a single-language site. */
    onPage?:   { state: 'same' | 'translated' | 'original' | 'hidden'; id: number; title: string };
    /** post_status of the picked record (publish, draft, trash…) when the server said it. */
    status?:   string;
};

/** One /fabrement/v1/post-refs row. */
export type RefRow = PostRow;

function fromRow(row: RefRow): PostRef {
    return {
        id: row.id, title: row.title || __('(no title)', 'fabrement-lite'), url: row.url || '', postType: row.subtype || 'post',
        languages: row.languages || [], onPage: row.onPage, status: row.status,
    };
}

/** The language of the page being edited ('' on a single-language site). */
export function pageLanguage(): string {
    return window.fabrementEditor?.currentLanguage || '';
}

function languageLabel(code: string): string {
    return (window.fabrementEditor?.languages || []).find((l) => l.code === code)?.label || code.toUpperCase();
}

/** The languages a record is published in, as small chips. Nothing on a single-language site. */
export function LangChips({ languages }: { languages?: string[] }) {
    if (!languages || languages.length === 0) return null;
    return (
        <span className="fabrement-post-picker__langs" title={__('Languages this record is published in', 'fabrement-lite')}>
            {languages.map((l) => <span key={l} className="fabrement-post-picker__lang">{l.toUpperCase()}</span>)}
        </span>
    );
}

/** What THIS page (its language) shows for a picked record, when that is not simply the record. */
export function OnPageNote({ record }: { record?: PostRef }) {
    return <>
        <StatusNote record={record} />
        <LanguageNote record={record} />
    </>;
}

/** A picked record that is not published: what it is, and whether visitors see it. */
function StatusNote({ record }: { record?: PostRef }) {
    const status = record?.status;
    if (!status || status === 'publish') return null;
    const text: Record<string, [string, string]> = {
        trash:   ['hidden', __('In the trash — not shown on the site.', 'fabrement-lite')],
        draft:   ['warn', __('Draft — not shown until it is published.', 'fabrement-lite')],
        pending: ['warn', __('Pending review — not shown until it is published.', 'fabrement-lite')],
        future:  ['warn', __('Scheduled — shown once it is published.', 'fabrement-lite')],
        private: ['warn', __('Private — visitors do not see it.', 'fabrement-lite')],
    };
    /* translators: %s: post status slug */
    const other = wp.i18n.sprintf(__('Status "%s" — not shown on the site.', 'fabrement-lite'), status);
    const [tone, message] = text[status] || ['warn', other];
    return <span className={'fabrement-post-picker__onpage fabrement-post-picker__onpage--' + tone}>{message}</span>;
}

function LanguageNote({ record }: { record?: PostRef }) {
    const on = record?.onPage;
    if (!on || on.state === 'same') return null;
    const lang = languageLabel(pageLanguage());
    if (on.state === 'translated') {
        /* translators: 1: language name, 2: record title */
        const note = wp.i18n.sprintf(__('This page shows its %1$s version: %2$s', 'fabrement-lite'), lang, on.title);
        return <span className="fabrement-post-picker__onpage">{note}</span>;
    }
    if (on.state === 'original') {
        /* translators: 1: language name, 2: record title */
        const note = wp.i18n.sprintf(__('No %1$s version yet — this page shows the default-language version: %2$s', 'fabrement-lite'), lang, on.title);
        return <span className="fabrement-post-picker__onpage fabrement-post-picker__onpage--warn">{note}</span>;
    }
    /* translators: %s: language name */
    const note = wp.i18n.sprintf(__('No %s version yet — hidden on this page.', 'fabrement-lite'), lang);
    return <span className="fabrement-post-picker__onpage fabrement-post-picker__onpage--hidden">{note}</span>;
}

export function plainTitle(t: SearchResult['title']): string {
    if (!t) return '';
    if (typeof t === 'object' && t.rendered) return t.rendered.replace(/<[^>]+>/g, '');
    return String(t).replace(/<[^>]+>/g, '');
}

function toRef(item: SearchResult): PostRef {
    return {
        id:       item.id,
        title:    plainTitle(item.title) || __('(no title)', 'fabrement-lite'),
        url:      item.url || '',
        postType: item.subtype || 'post',
    };
}

/** The field's `postTypes` → the endpoint's `subtype` (empty list = every public type). */
function subtypeParam(postTypes?: string[]): string {
    const list = (postTypes || []).filter((t) => typeof t === 'string' && t.trim());
    return list.length ? list.join(',') : 'any';
}

/**
 * Debounced `/wp/v2/search` against the declared post types. An empty query is a
 * valid state, not a dead one: it lists the most recent records, so the editor
 * can pick from a small site without knowing what to type.
 */
export function usePostSearch(postTypes?: string[]) {
    const [search,  setSearch]  = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed,  setFailed]  = useState(false);
    const timer = useRef<number | null>(null);
    const subtype = subtypeParam(postTypes);

    const run = (val: string, delay: number) => {
        if (timer.current) window.clearTimeout(timer.current);
        setLoading(true);
        timer.current = window.setTimeout(() => {
            apiFetch?.({
                path: '/wp/v2/search?search=' + encodeURIComponent(val)
                    + '&per_page=10&type=post&subtype=' + encodeURIComponent(subtype)
                    + '&_fields=id,title,url,subtype',
            })
                .then((items: SearchResult[]) => { setResults(items || []); setFailed(false); setLoading(false); })
                // A rejected request here is almost always `rest_invalid_param` on
                // `subtype`: WP only lets you search post types that are BOTH public and
                // show_in_rest. Surfacing it beats an "empty results" list the admin
                // would read as "the site has no such records".
                .catch(() => { setResults([]); setFailed(true); setLoading(false); });
        }, delay);
    };

    const onSearch = (val: string) => { setSearch(val); run(val, 300); };
    /** Called when the modal opens — seeds the list with recent records, no delay. */
    const prime = () => { setSearch(''); run('', 0); };
    const stop  = () => { if (timer.current) window.clearTimeout(timer.current); };

    useEffect(() => stop, []);

    return { search, results, loading, failed, onSearch, prime, stop };
}

/**
 * Ids → what the field shows for them, from the editor's one repository ({@see refs}): rows the
 * page or the fields box already carried are served at once, the rest is asked for in one batch.
 *
 * Returns a map, not a list: the field's own id order is the authoritative one. The PostRef
 * objects are stable per row, so effects that depend on one do not re-run on every render.
 */
export function usePostRefs(ids: number[]) {
    const lang = pageLanguage();
    // Primitive dep — a fresh array identity on every render would loop forever.
    const key = ids.join(',');
    // The ids this hook finished asking about (whatever the answer). Until then an id the
    // repository does not know is LOADING, not unavailable — derived on every render, so a newly
    // added id never flashes "unavailable" for a frame.
    const [settled, setSettled] = useState('');

    useEffect(() => {
        if (ids.every((id) => refs.posts.known(id, lang))) return;
        let live = true;
        refs.posts.require(ids, lang).then(() => { if (live) setSettled(lang + '|' + key); });
        return () => { live = false; };
    }, [key, lang]);

    const out: Record<number, PostRef> = {};
    let unknown = false;
    for (const id of ids) {
        const row = refs.posts.get(id, lang);
        if (row) out[id] = asRef(row);
        else if (!refs.posts.isAbsent(id, lang)) unknown = true;
    }
    return { refs: out, loading: unknown && settled !== lang + '|' + key };
}

/** One PostRef per stored row, so the same row always gives the same object. */
const refOf = new WeakMap<RefRow, PostRef>();
function asRef(row: RefRow): PostRef {
    let ref = refOf.get(row);
    if (!ref) {
        ref = fromRow(row);
        refOf.set(row, ref);
    }
    return ref;
}

/**
 * Rows the server sent, into the repository. `ids` = everything that was looked up: the ones
 * missing from `rows` are known to be unavailable. The form itself goes through refs.prime();
 * tests fill the cache with this.
 */
export function primePostRefs(rows: RefRow[], lang: string, ids: number[] = []): void {
    refs.posts.prime(rows, lang, ids);
}

export type PostPickerModalProps = {
    title:      string;
    postTypes?: string[];
    /** Ids already stored — rendered with a ✓ (and blocked when `full`). */
    selectedIds: number[];
    /** True once `max` is reached: unselected rows stop responding. */
    full?:      boolean;
    hint?:      string;
    onPick:     (ref: PostRef) => void;
    onClose:    () => void;
    /** Extra footer content (Done / Remove buttons the two fields differ on). */
    footer?:    any;
};

/**
 * The search-and-pick modal itself. Selection semantics live in the CALLER:
 * PostObjectField closes on the first pick, PostObjectsField keeps it open and toggles.
 */
export function PostPickerModal(props: PostPickerModalProps) {
    const { title, postTypes, selectedIds, full, hint, onPick, onClose, footer } = props;
    const { search, results, loading, failed, onSearch, prime, stop } = usePostSearch(postTypes);
    // The languages of what the search found — the search itself does not say.
    const { refs: found } = usePostRefs(results.map((r) => r.id));
    const typeList = (postTypes || []).join(', ');

    useEffect(() => { prime(); }, []);

    const close = () => { stop(); onClose(); };

    let list: any = null;
    if (loading) {
        list = (
            <div className="fabrement-post-picker__results">
                <div className="fabrement-post-picker__spinner"><Spinner /></div>
            </div>
        );
    } else if (results.length > 0) {
        list = (
            <div className="fabrement-post-picker__results">
                {results.map((item) => {
                    const selected = selectedIds.indexOf(item.id) !== -1;
                    const blocked  = !!full && !selected;
                    const cls = 'fabrement-post-picker__result'
                        + (selected ? ' fabrement-post-picker__result--selected' : '')
                        + (blocked  ? ' fabrement-post-picker__result--disabled' : '');
                    return (
                        <div
                            key={item.id}
                            className={cls}
                            onClick={() => { if (!blocked) onPick(toRef(item)); }}
                        >
                            <div className="fabrement-post-picker__result-left">
                                <span className="fabrement-post-picker__result-icon">📄</span>
                                <div className="fabrement-post-picker__result-info">
                                    <div className="fabrement-post-picker__result-title">
                                        {plainTitle(item.title)}
                                        <LangChips languages={found[item.id]?.languages} />
                                    </div>
                                    <div className="fabrement-post-picker__result-meta">
                                        <span className="fabrement-post-picker__result-type">{item.subtype}</span>
                                        {item.url}
                                    </div>
                                </div>
                            </div>
                            {selected && <span className="fabrement-post-picker__result-check">✓</span>}
                        </div>
                    );
                })}
            </div>
        );
    } else {
        let empty = search.trim() ? __('No results found', 'fabrement-lite') : __('Nothing to pick yet', 'fabrement-lite');
        if (failed) {
            empty = typeList
                /* translators: %s: post type slugs */
                ? wp.i18n.sprintf(__('Cannot search “%s” — the post type must be public and visible in REST.', 'fabrement-lite'), typeList)
                : __('Search failed.', 'fabrement-lite');
        }
        list = (
            <div className="fabrement-post-picker__results">
                <div className="fabrement-post-picker__empty">{empty}</div>
            </div>
        );
    }

    return (
        <Modal
            title={title}
            onRequestClose={close}
            className="fabrement-post-picker-modal"
            overlayClassName="fabrement-post-picker-overlay"
        >
            <div className="fabrement-post-picker__body">
                <div className="fabrement-post-picker__search">
                    <span className="fabrement-post-picker__search-icon">
                        <svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
                            <circle cx={11} cy={11} r={8} />
                            <path d="m21 21-4.35-4.35" />
                        </svg>
                    </span>
                    <TextControl
                        placeholder={__('Search…', 'fabrement-lite')}
                        value={search}
                        onChange={onSearch}
                        autoFocus
                    />
                </div>

                {list}

                {hint && <span className="fabrement-post-picker__hint">{hint}</span>}

                {footer}
            </div>
        </Modal>
    );
}
