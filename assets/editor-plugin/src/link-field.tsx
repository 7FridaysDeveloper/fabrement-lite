import { usePostRefs, LangChips, OnPageNote } from './post-picker-shared';
import './link-field.css';



const { useState, useEffect, useRef, Fragment } = wp.element;
const { Modal, Button, Spinner, ToggleControl, TextControl, SelectControl } = wp.components;
const apiFetch = wp.apiFetch;

type SearchResult = {
    id:      number;
    url:     string;
    title:   string | { rendered?: string };
    subtype: string;
};

/** The stored link value: the keys LinkType (PHP) accepts, and no others. */
export type LinkFieldValue = {
    url?:    string;
    label?:  string;
    /**
     * The link is named after the record it points at: the name is read when the link is
     * printed, in the language `lang` opens. `label` stays beside it as the fallback for a
     * record that is gone or has no version in that language.
     */
    useRecordTitle?: boolean;
    newTab?: boolean;
    /**
     * The picked record, when the link points inside this site: its address is resolved live,
     * so the link survives a slug change. 0 means no record — an external address, `mailto:`,
     * `tel:` or an anchor, kept in `url`.
     */
    postId?: number;
    /**
     * WHICH language version of `postId` the link opens. Editorial, chosen per link, and only
     * ever offered when the site has more than one language.
     *
     * '' (the default) follows the reader: the link opens this page in the language being
     * read, falling back to the original where no translation exists. That is what a
     * duplicated page wants — every link keeps pointing "here", whatever "here" currently is.
     *
     * A language code pins the link to that version for every reader, which is what a link to
     * a document that exists once needs (an English-only price list, a single legal page).
     */
    lang?: string;
};

export type LinkFieldProps = {
    label?:    string;
    value?:    LinkFieldValue;
    onChange?: (value: LinkFieldValue) => void;
};

function plainTitle(t: SearchResult['title']): string {
    if (!t) return '';
    if (typeof t === 'object' && t.rendered) return t.rendered.replace(/<[^>]+>/g, '');
    return String(t).replace(/<[^>]+>/g, '');
}

/**
 * ACF-style link picker — trigger button opens a Modal with a search field
 * (apiFetch into `/wp/v2/search`) on top, custom URL + label inputs, "open in
 * new tab" toggle, Cancel / Apply / Remove footer.
 */
export function LinkField(props: LinkFieldProps) {
    const { onChange } = props;
    // Explicit empty string hides the field label (the fields form prints its own).
    const label = props.label === undefined ? __('Link', 'fabrement-lite') : props.label;
    const value = props.value || {};

    const currentUrl    = value.url    || '';
    const currentLabel  = value.label  || '';
    const currentNewTab = value.newTab || false;
    const currentPostId = value.postId || 0;
    const currentLang   = value.lang   || '';

    // Empty on a single-language site (see Languages::all) — the language control is then not
    // rendered at all, so an ordinary site sees exactly the modal it saw before.
    const languages = window.fabrementEditor?.languages || [];

    const [isOpen,    setIsOpen]    = useState(false);
    const [search,    setSearch]    = useState('');
    const [results,   setResults]   = useState<SearchResult[]>([]);
    const [loading,   setLoading]   = useState(false);
    const [customUrl, setCustomUrl] = useState('');
    const [selLabel,  setSelLabel]  = useState('');
    // The title of the record currently picked, so the caption can tell "this is the page's name"
    // from "somebody wrote this".
    const [pickedTitle, setPickedTitle] = useState('');
    // Whether the caption is the record's name or written words — the editor's choice, stored
    // with the link, not guessed from whether the two currently happen to match.
    const [useRecordTitle, setUseRecordTitle] = useState(false);
    const [newTab,    setNewTab]    = useState(false);
    const [postId,    setPostId]    = useState(0);
    const [lang,      setLang]      = useState('');
    const searchTimer = useRef<number | null>(null);

    // The page this link is attached to, by name. A link that points at a record shows the
    // button's words and nothing else, so an editor could not tell it apart from a typed-in
    // address — nor notice that it had been pointed at the wrong page. Resolved through the
    // same route the record pickers use, which answers in the editor's language.
    // While the modal is open the record being looked up is the one being EDITED, not the one
    // last saved. Reading the saved id meant the attached-record card only caught up after Apply
    // and a reopen: detaching left the card on screen with no way back to the address field,
    // picking a different page kept printing the previous page's title, and the "use the page
    // name" toggle (which reads the live id) disagreed with the card beside it.
    const shownPostId = isOpen ? postId : currentPostId;
    const { refs: pageRefs } = usePostRefs(shownPostId > 0 ? [shownPostId] : []);
    const pickedPage = shownPostId > 0 ? pageRefs[shownPostId] : undefined;

    // What the link OPENS, said the way a person would: the page's name when one is attached,
    // the address otherwise.
    const destination = pickedPage ? pickedPage.title : currentUrl;
    // The words this link will actually show: with the switch on, the record's title — the
    // stored caption is then only the fallback.
    const shownLabel = value.useRecordTitle && pickedPage ? pickedPage.title : currentLabel;

    // The record's kind, from its post-type slug — "case-study" reads as "Case study". Worth
    // showing because a site's records are not all pages: which KIND a link points at is part of
    // knowing whether it points at the right thing.
    const pickedKind = pickedPage
        ? pickedPage.postType.replace(/[-_]/g, ' ').replace(/^./, (c) => c.toUpperCase())
        : '';

    // The picker's styles too: the language chips and the "this page shows…" note are its
    // components, and a field that borrows them has to bring their CSS or they arrive bare.

    const openModal = () => {
        setCustomUrl(currentUrl);
        setSelLabel(currentLabel);
        setUseRecordTitle(!!value.useRecordTitle);
        setNewTab(currentNewTab);
        setPostId(currentPostId);
        setLang(currentLang);
        // Filled by the effect below once the record's name is known — a link opened from storage
        // carries an id, not a title.
        setPickedTitle('');
        setSearch('');
        setResults([]);
        setIsOpen(true);
    };

    // The stored record's name, as soon as the lookup answers. Only while the modal shows the
    // record it was fetched for: after a pick, selectResult already set the title itself.
    useEffect(() => {
        if (pickedPage && postId === pickedPage.id && !pickedTitle) {
            setPickedTitle(pickedPage.title);
        }
    }, [pickedPage, postId, pickedTitle]);

    const closeModal = () => {
        if (searchTimer.current) window.clearTimeout(searchTimer.current);
        setIsOpen(false);
    };

    const handleSearch = (val: string) => {
        setSearch(val);
        if (searchTimer.current) window.clearTimeout(searchTimer.current);
        if (!val.trim()) { setResults([]); return; }
        setLoading(true);
        searchTimer.current = window.setTimeout(() => {
            apiFetch?.({
                path: '/wp/v2/search?search=' + encodeURIComponent(val)
                    + '&per_page=8&type=post&subtype=any&_fields=id,title,url,subtype',
            })
                .then((items: SearchResult[]) => { setResults(items || []); setLoading(false); })
                .catch(() => { setResults([]); setLoading(false); });
        }, 300);
    };

    /**
     * An address on THIS site, stored without the host it happens to be served from today.
     *
     * The search hands back an absolute url — `http://example.test/about` — and storing that
     * pins the link to one hostname. `postId` normally carries the link past a move, but `url` is
     * what the helper falls back to when the record cannot answer (deleted, unpublished, missing
     * in the language being read), and a fallback pointing at the old host is a dead link on the
     * new one. A leading-slash path survives the move; anything not on this site is left exactly
     * as it came, because for an external address the host IS the address.
     */
    const siteRelative = (raw: string) => {
        // Only an ABSOLUTE address is a candidate. Anything already relative is left untouched,
        // and `#contact` especially so: resolved against the site it would become `/#contact`,
        // which is an anchor on the HOME page rather than on this one.
        if (typeof window === 'undefined' || !/^(https?:)?\/\//i.test(raw)) {
            return raw;
        }
        try {
            // Matched on HOST, not on the whole origin. The admin can be served over https while
            // home_url() still answers http (a proxy, FORCE_SSL_ADMIN, a setting nobody revisited
            // since the certificate) — comparing the scheme too would then call the site's own
            // page external and store the absolute address after all, in exactly the installs
            // where that hurts most. Parsing also takes the query and the fragment along, and
            // leaves mailto:/tel: alone, since those have no host to match.
            const picked = new URL(raw, window.location.origin);

            return picked.host === window.location.host
                ? (picked.pathname + picked.search + picked.hash) || '/'
                : raw;
        } catch {
            return raw;
        }
    };

    const selectResult = (item: SearchResult) => {
        const t = plainTitle(item.title);
        // The caption follows the record while nobody has written one of their own. Empty is the
        // obvious case; so is a caption that IS the previous record's title, because that was
        // this field's own doing and not a decision anyone made. Anything else was typed on
        // purpose and survives the pick — picking a different page must not quietly rewrite the
        // words on a button.
        const followsRecord = !selLabel || selLabel === pickedTitle;
        setCustomUrl(siteRelative(item.url || ''));
        setPostId(item.id || 0);
        setPickedTitle(t);
        // Not while the switch is on: the caption is then whatever the record is called at the
        // moment it renders, and the written words underneath are only the fallback. Copying
        // the new title over them would spend that fallback on a page that is no longer picked.
        if (!useRecordTitle && followsRecord) setSelLabel(t);
        setSearch('');
        setResults([]);
    };

    /**
     * Typing an address by hand drops the picked record. Without this the id outlives the
     * pick it came from: the editor would show the typed address while the link still
     * resolved to the OLD record's permalink.
     */
    const editUrlByHand = (val: string) => {
        setCustomUrl(val);
        setPostId(0);
    };

    /** The link with nothing in it: every part cleared, the picked record included. */
    const EMPTY: LinkFieldValue = { url: '', label: '', useRecordTitle: false, newTab: false, postId: 0, lang: '' };

    const apply = () => {
        onChange?.({
            // A PICKED record carries no address of its own. The one the search handed back is a
            // snapshot of where that page sat today, and storing it beside the id is how a link
            // ends up with two answers that drift apart: the id follows the page, the snapshot
            // does not, and the snapshot is what gets used the day the page stops answering —
            // pointing at an address that is, by then, exactly as gone as the page.
            //
            // An address typed by hand is a different thing and is kept: it is the answer, not a
            // copy of one. The modal still holds the picked page's address in `customUrl` so
            // detaching the record puts it back in the box — it simply never reaches the value
            // while a record is attached.
            url: postId > 0 ? '' : customUrl,
            label: selLabel,
            // Only a picked record has a name to follow; an address typed by hand has none.
            useRecordTitle: useRecordTitle && postId > 0,
            newTab,
            postId,
            lang,
        });
        closeModal();
    };

    const remove = () => {
        onChange?.({ ...EMPTY });
        closeModal();
    };

    const trigger = (
        <div className="fabrement-link-field">
            {label && <span className="fabrement-link-field__label">{label}</span>}
            <button type="button" className="fabrement-link-field__trigger" onClick={openModal}>
                <span className="fabrement-link-field__trigger-icon">
                    <svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                    </svg>
                </span>
                <span className={'fabrement-link-field__trigger-text' + (currentUrl || pickedPage ? '' : ' fabrement-link-field__trigger-text--empty')}>
                    {currentUrl || pickedPage ? (shownLabel || destination) : __('Choose a page or enter URL…', 'fabrement-lite')}
                    {/* Where it goes, beside what it says — the two are different things, and only
                        showing the words hides a button pointed at the wrong page. Dropped when the
                        words ARE the destination, so nothing is printed twice. */}
                    {shownLabel && destination && shownLabel !== destination && (
                        <span className="fabrement-link-field__trigger-dest">
                            {pickedPage ? ' → ' : ' · '}{destination}
                            {pickedKind && <span className="fabrement-link-field__trigger-kind"> ({pickedKind})</span>}
                        </span>
                    )}
                </span>
                {currentUrl && (
                    <span
                        className="fabrement-link-field__trigger-clear"
                        title={__('Remove link', 'fabrement-lite')}
                        // Clears every part, like the modal's Remove: a value emptied here that
                        // kept its picked record would still resolve to that page's permalink.
                        onClick={(e: any) => { e.stopPropagation(); onChange?.({ ...EMPTY }); }}
                    >×</span>
                )}
            </button>
        </div>
    );

    if (!isOpen) return trigger;

    // By id when the link is attached to one, because the address it was picked at may no longer
    // be that record's address — matching on the url would then tick nothing, or tick whichever
    // record has since taken the slug.
    const isSelected = (item: SearchResult) => (postId > 0 ? item.id === postId : item.url === customUrl);

    let resultsList: any = null;
    if (loading) {
        resultsList = (
            <div className="fabrement-link-modal__results">
                <div className="fabrement-link-modal__spinner"><Spinner /></div>
            </div>
        );
    } else if (results.length > 0) {
        resultsList = (
            <div className="fabrement-link-modal__results">
                {results.map((item) => {
                    const t = plainTitle(item.title);
                    const selected = isSelected(item);
                    return (
                        <div
                            key={item.id}
                            className={'fabrement-link-modal__result' + (selected ? ' fabrement-link-modal__result--selected' : '')}
                            onClick={() => selectResult(item)}
                        >
                            <div className="fabrement-link-modal__result-left">
                                <span className="fabrement-link-modal__result-icon">📄</span>
                                <div className="fabrement-link-modal__result-info">
                                    <div className="fabrement-link-modal__result-title">{t}</div>
                                    <div className="fabrement-link-modal__result-url">{item.url}</div>
                                </div>
                            </div>
                            {selected && <span className="fabrement-link-modal__result-check">✓</span>}
                        </div>
                    );
                })}
            </div>
        );
    } else if (search.trim()) {
        resultsList = (
            <div className="fabrement-link-modal__results">
                <div className="fabrement-link-modal__empty">{__('No results found', 'fabrement-lite')}</div>
            </div>
        );
    }

    const modal = (
        <Modal
            title={label || __('Link', 'fabrement-lite')}
            onRequestClose={closeModal}
            className="fabrement-link-modal"
            overlayClassName="fabrement-link-modal-overlay"
        >
            <div className="fabrement-link-modal__body">
                <div className="fabrement-link-modal__search">
                    <span className="fabrement-link-modal__search-icon">
                        <svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
                            <circle cx={11} cy={11} r={8} />
                            <path d="m21 21-4.35-4.35" />
                        </svg>
                    </span>
                    <TextControl
                        placeholder={__('Search pages, posts…', 'fabrement-lite')}
                        value={search}
                        onChange={handleSearch}
                        autoFocus
                    />
                </div>

                {resultsList}

                {/* A link is EITHER attached to a record or typed as an address — so the modal
                    shows one or the other, never both. An address field beside a chosen record
                    invites editing an address that is not what the link follows: the record's
                    permalink is, and it moves when the record does. The × lets go of the record,
                    and the address field comes back holding where it pointed. */}
                {pickedPage ? (
                    <div className="fabrement-link-modal__attached">
                        <div className="fabrement-link-modal__result-info">
                            <div className="fabrement-link-modal__result-title">
                                {pickedPage.title}
                                <LangChips languages={pickedPage.languages} />
                            </div>
                            <div className="fabrement-link-modal__result-url">
                                <strong className="fabrement-link-modal__attached-kind">{pickedKind}</strong>
                                {' · '}{pickedPage.url}
                            </div>
                            <OnPageNote record={pickedPage} />
                        </div>
                        <button
                            type="button"
                            className="fabrement-link-modal__attached-detach"
                            title={__('Use a plain address instead of following this record', 'fabrement-lite')}
                            onClick={() => setPostId(0)}
                        >×</button>
                    </div>
                ) : (
                    <>
                        <div className="fabrement-link-modal__divider">
                            <div className="fabrement-link-modal__divider-line" />
                            <span className="fabrement-link-modal__divider-text">{__('or enter a URL', 'fabrement-lite')}</span>
                            <div className="fabrement-link-modal__divider-line" />
                        </div>

                        <TextControl
                            label={__('URL', 'fabrement-lite')}
                            placeholder="https://"
                            value={customUrl}
                            onChange={editUrlByHand}
                            type="url"
                        />
                    </>
                )}

                {/* Two ways to caption a link, and the switch says which — offered only where
                    there is a record to be named after. With it on the box below shows what the
                    page is called and stops being the source of truth; the words stay in it as
                    the fallback for a record that has been deleted or is missing this language. */}
                <div className="fabrement-link-modal__label-field">
                    {postId > 0 && (
                        <ToggleControl
                            label={__('Use the page name', 'fabrement-lite')}
                            help={
                                useRecordTitle
                                    ? __('The link is captioned with whatever this page is called when it renders, so renaming the page renames the link.', 'fabrement-lite')
                                    : __('The link shows the wording below, whatever the page is later renamed to.', 'fabrement-lite')
                            }
                            checked={useRecordTitle}
                            onChange={setUseRecordTitle}
                        />
                    )}
                    <TextControl
                        label={useRecordTitle ? __('Button Label (fallback)', 'fabrement-lite') : __('Button Label', 'fabrement-lite')}
                        placeholder={pickedTitle || __('e.g. View All Work', 'fabrement-lite')}
                        value={useRecordTitle ? pickedTitle : selLabel}
                        disabled={useRecordTitle}
                        onChange={setSelLabel}
                    />
                    {useRecordTitle && (
                        <p className="fabrement-link-modal__label-note">
                            {selLabel
                                /* translators: %s: the wording the editor wrote for the link */
                                ? wp.i18n.sprintf(__('Shown while the page exists. Turn the switch off to write your own wording (“%s” is kept).', 'fabrement-lite'), selLabel)
                                : __('Shown while the page exists. Turn the switch off to write your own wording.', 'fabrement-lite')}
                        </p>
                    )}
                </div>

                <ToggleControl
                    label={__('Open in new tab', 'fabrement-lite')}
                    checked={newTab}
                    onChange={setNewTab}
                />

                {languages.length > 0 && (
                    <SelectControl
                        label={__('Language', 'fabrement-lite')}
                        help={__('Which version of the linked page opens. Follow the page language unless this link must always reach one specific version.', 'fabrement-lite')}
                        value={lang}
                        options={[
                            { value: '', label: __('Follow page language', 'fabrement-lite') },
                            ...languages.map((l) => ({ value: l.code, label: l.label })),
                        ]}
                        onChange={setLang}
                    />
                )}

                <div className="fabrement-link-modal__footer">
                    <Button variant="tertiary" onClick={remove}>{__('Remove link', 'fabrement-lite')}</Button>
                    <div className="fabrement-link-modal__footer-actions">
                        <Button variant="secondary" onClick={closeModal}>{__('Cancel', 'fabrement-lite')}</Button>
                        <Button variant="primary"   onClick={apply} disabled={!customUrl}>{__('Apply', 'fabrement-lite')}</Button>
                    </div>
                </div>
            </div>
        </Modal>
    );

    return (
        <Fragment>
            {trigger}
            {modal}
        </Fragment>
    );
}
