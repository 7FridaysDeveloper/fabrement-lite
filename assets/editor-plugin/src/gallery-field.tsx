/**
 * GalleryField — an ordered set of images from the media library, for the Fabrement fields box.
 *
 *   <GalleryField value={[12, 34]} onChange={(ids) => …} max={12} min={3} />
 *
 * The value is the list of attachment ids, in the order shown. Pick several at once, drag to
 * reorder (arrow buttons do the same from the keyboard), remove one, edit the whole set in the
 * media library's own gallery screen, or clear it. Inside a disabled fieldset (a field locked in a
 * translation) nothing can be changed, dragging included.
 */
import { cachedMedia, rememberMedia, toMediaRef, useMediaRefs } from './media-refs';
import './gallery-field.css';

const { useState, useEffect, useRef } = wp.element;


/** A drag of a gallery tile carries this type only: text fields, which accept text/plain, ignore it. */
const DRAG_TYPE = 'application/x-fabrement-gallery';

/**
 * The stored value as a list of image ids, each once, as ACF keeps a gallery (a value written
 * elsewhere may hold strings or a repeat).
 */
export function galleryIds(value: unknown): number[] {
    const out: number[] = [];
    for (const raw of Array.isArray(value) ? value : []) {
        const id = Number(raw);
        if (Number.isInteger(id) && id > 0 && !out.includes(id)) out.push(id);
    }
    return out;
}

/**
 * Picked images added to the gallery: the ones already in it are skipped; an image picked twice
 * counts once, and nothing goes past the limit.
 */
export function addPicked(ids: number[], picked: number[], limit: number): { ids: number[]; already: number; repeated: number; cut: number } {
    const fresh: number[] = [];
    let already = 0, repeated = 0;
    for (const id of picked) {
        if (!(id > 0)) continue;
        if (ids.includes(id)) already++;
        else if (fresh.includes(id)) repeated++;
        else fresh.push(id);
    }
    const room = Math.max(0, limit - ids.length);
    return { ids: [...ids, ...fresh.slice(0, room)], already, repeated, cut: Math.max(0, fresh.length - room) };
}

/**
 * The gallery after the media library's gallery editor: its order and choice, plus the images it
 * could not open (network, permissions, not an image) — those are KEPT, each back at its old
 * position, never dropped silently.
 */
export function mergeEdited(before: number[], edited: number[], unopened: number[], limit: number): { ids: number[]; kept: number; cut: number } {
    const out = galleryIds(edited);
    let kept = 0;
    for (const id of before) {
        if (!unopened.includes(id) || out.includes(id)) continue;
        out.splice(Math.min(before.indexOf(id), out.length), 0, id);
        kept++;
    }
    return { ids: out.slice(0, limit), kept, cut: Math.max(0, out.length - limit) };
}

export function GalleryField({ value, onChange, max = 50, min }: {
    value: number[]; onChange: (ids: number[]) => void; max?: number; min?: number;
}) {
    const ids = galleryIds(value);
    // The media library's gallery editor shows an image once: a gallery holding one twice is edited here.
    const limit = Math.min(max || 50, 50);
    const [dragging, setDragging] = useState<number | null>(null);
    const [target, setTarget] = useState<number | null>(null);
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const root = useRef<HTMLDivElement | null>(null);
    const frameRef = useRef<any>(null);
    // After a keyboard move or removal, where focus goes next: [image id or 0 for the add tile, button role].
    const focusNext = useRef<[number, string] | null>(null);
    const MediaUpload = wp.blockEditor?.MediaUpload;
    const MediaUploadCheck = wp.blockEditor?.MediaUploadCheck;


    // Previews come from the editor's repository, filled from the server with the form; only ids
    // it lacks are asked for — together, in one request.
    useMediaRefs(ids);

    // Keyboard reorder and removal keep focus where the user is working.
    useEffect(() => {
        const next = focusNext.current;
        focusNext.current = null;
        if (!next || !root.current) return;
        const selector = next[0] ? '[data-gallery-id="' + next[0] + '"] [data-role="' + next[1] + '"]' : '.fabrement-gallery__add, .fabrement-gallery__empty';
        const button = root.current.querySelector<HTMLButtonElement>(selector);
        (button && !button.disabled ? button : root.current.querySelector<HTMLButtonElement>('[data-gallery-id="' + next[0] + '"] [data-role="remove"]'))?.focus();
    });

    // A frame left open when the field goes away is closed with it.
    useEffect(() => () => { frameRef.current?.close?.(); }, []);

    /** A field locked in a translation sits in a disabled fieldset: buttons are off, dragging must be too. */
    const locked = () => !!root.current?.closest('fieldset:disabled');

    /** Any change of the list the user makes clears the last notice. */
    const change = (next: number[], message = '') => { setNotice(message); onChange(next); };
    const remember = (items: any[]) => { for (const item of items) rememberMedia(toMediaRef('library', item)); };

    const add = (items: any) => {
        const list = (Array.isArray(items) ? items : [items]).filter(Boolean);
        remember(list);
        const result = addPicked(ids, list.map((item: any) => Number(item.id)), limit);
        const parts: string[] = [];
        /* translators: %d: number of images */
        if (result.already > 0) parts.push(wp.i18n.sprintf(__('%d already in the gallery, skipped (an image appears once).', 'fabrement-lite'), result.already));
        /* translators: 1: number of images left out, 2: the most images the gallery holds */
        if (result.cut > 0) parts.push(wp.i18n.sprintf(__('%1$d not added: the gallery holds at most %2$d.', 'fabrement-lite'), result.cut, limit));
        change(result.ids, parts.join(' '));
    };
    const move = (from: number, to: number) => {
        if (from === to || to < 0 || to >= ids.length) return;
        const next = ids.slice();
        next.splice(to, 0, next.splice(from, 1)[0]);
        change(next);
    };
    const remove = (index: number) => {
        const next = ids.filter((_, i) => i !== index);
        focusNext.current = next.length ? [next[Math.min(index, next.length - 1)], 'remove'] : [0, 'add'];
        change(next);
    };

    const picker = (render: (open: () => void) => any) => {
        if (!MediaUpload || !MediaUploadCheck) return null;
        return <MediaUploadCheck>
            <MediaUpload multiple="add" allowedTypes={['image']} value={[]} title={__('Add images', 'fabrement-lite')} onSelect={add}
                render={({ open }: { open: () => void }) => render(open)} />
        </MediaUploadCheck>;
    };

    /**
     * The media library's own gallery screen (reorder, add, remove, edit alt/caption), opened on
     * THESE images. They are loaded one by one and handed over as the selection: the library's
     * list query is filtered by WPML to the admin language and would come back empty. Images it
     * cannot open stay in the gallery; one frame at a time, removed when it closes.
     */
    const media = (window as any).wp?.media;
    const openEditor = async () => {
        if (!media || busy || frameRef.current || locked()) return;
        setBusy(true);
        const before = ids.slice();
        let models: any[] = [];
        try {
            models = before.map((id) => media.attachment(id));
            await Promise.all(models.map((model: any) => model.fetch().catch(() => null)));
        } finally {
            setBusy(false);
        }
        const opened = models.filter((model: any) => model.get('type') === 'image');
        const unopened = before.filter((id) => !opened.some((model: any) => model.id === id));
        const selection = new media.model.Selection(opened, { multiple: true });
        const frame = media({ frame: 'post', state: 'gallery-edit', title: __('Edit gallery', 'fabrement-lite'), editing: true, multiple: true, selection });
        frameRef.current = frame;
        const finish = (chosen: any, addOnly: boolean) => {
            const list = (chosen?.models || []).map((model: any) => model.toJSON());
            remember(list);
            if (addOnly) {
                add(list);
                return;
            }
            const result = mergeEdited(before, list.map((item: any) => Number(item.id)), unopened, limit);
            const parts: string[] = [];
            /* translators: %d: number of images */
            if (result.kept > 0) parts.push(wp.i18n.sprintf(__('%d could not be opened in the media library and were kept in the gallery.', 'fabrement-lite'), result.kept));
            /* translators: 1: number of images left out, 2: the most images the gallery holds */
            if (result.cut > 0) parts.push(wp.i18n.sprintf(__('%1$d not added: the gallery holds at most %2$d.', 'fabrement-lite'), result.cut, limit));
            change(result.ids, parts.join(' '));
        };
        frame.on('update', (chosen: any) => finish(chosen, false));
        // "Cancel gallery" leads to the library, whose button inserts: here that means "add these".
        frame.on('insert', (chosen: any) => finish(chosen || frame.state()?.get('selection'), true));
        frame.on('close', () => {
            frameRef.current = null;
            setTimeout(() => frame.remove?.(), 0);
        });
        frame.open();
    };

    const full = ids.length >= limit;
    const short = min !== undefined && ids.length < min;
    const count = ids.length;

    return <div className="fabrement-gallery" ref={root}>
        {count === 0
            ? picker((open) => <button type="button" className="fabrement-gallery__empty" onClick={open}>
                <span aria-hidden="true">＋</span>{__('Add images from the media library', 'fabrement-lite')}</button>)
            : <ul className="fabrement-gallery__grid">
                {ids.map((id, index) => {
                    const thumb = cachedMedia(id);
                    const isImage = !thumb || thumb.missing || !thumb.mimeType || thumb.mimeType.startsWith('image/');
                    const n = index + 1;
                    const classes = ['fabrement-gallery__item', dragging === index ? 'is-dragging' : '', target === index && dragging !== index ? 'is-target' : ''].join(' ');
                    return <li key={id + ':' + index} data-gallery-id={id} className={classes} draggable
                        /* translators: %d: attachment id */
                        title={thumb?.title || wp.i18n.sprintf(__('Image #%d', 'fabrement-lite'), id)}
                        onDragStart={(event: any) => {
                            if (locked()) { event.preventDefault(); return; }
                            setDragging(index);
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData(DRAG_TYPE, String(index));
                        }}
                        onDragOver={(event: any) => { if (dragging !== null) { event.preventDefault(); setTarget(index); } }}
                        onDragLeave={() => { if (target === index) setTarget(null); }}
                        onDrop={(event: any) => { event.preventDefault(); if (dragging !== null) move(dragging, index); setDragging(null); setTarget(null); }}
                        onDragEnd={() => { setDragging(null); setTarget(null); }}>
                        {thumb?.thumb && isImage ? <img src={thumb.thumb} alt={thumb.alt} />
                            : thumb && !thumb.missing && !isImage
                                ? <span className="fabrement-gallery__file"><strong>{(thumb.filename.split('.').pop() || '').toUpperCase()}</strong>{thumb.filename}<span>{__('Not an image', 'fabrement-lite')}</span></span>
                                /* translators: %d: attachment id */
                                : <span className="fabrement-gallery__missing">{thumb?.missing ? wp.i18n.sprintf(__('Image not found #%d', 'fabrement-lite'), id) : ''}</span>}
                        <span className="fabrement-gallery__index">{n}</span>
                        {thumb && !thumb.missing && thumb.thumb && isImage && !thumb.alt && <span className="fabrement-gallery__noalt" title={__('This image has no alternative text in the media library.', 'fabrement-lite')}>{__('No alt', 'fabrement-lite')}</span>}
                        <div className="fabrement-gallery__actions">
                            <button type="button" data-role="earlier" disabled={index === 0}
                                /* translators: %d: position of the image in the gallery */
                                aria-label={wp.i18n.sprintf(__('Move image %d earlier', 'fabrement-lite'), n)}
                                onClick={() => { focusNext.current = [id, index - 1 === 0 ? 'later' : 'earlier']; move(index, index - 1); }}>‹</button>
                            <button type="button" data-role="remove" className="fabrement-gallery__remove"
                                /* translators: %d: position of the image in the gallery */
                                aria-label={wp.i18n.sprintf(__('Remove image %d', 'fabrement-lite'), n)}
                                onClick={() => remove(index)}>×</button>
                            <button type="button" data-role="later" disabled={index === count - 1}
                                /* translators: %d: position of the image in the gallery */
                                aria-label={wp.i18n.sprintf(__('Move image %d later', 'fabrement-lite'), n)}
                                onClick={() => { focusNext.current = [id, index + 1 === count - 1 ? 'earlier' : 'later']; move(index, index + 1); }}>›</button>
                        </div>
                    </li>;
                })}
                <li className="fabrement-gallery__slot">
                    {picker((open) => <button type="button" className="fabrement-gallery__add" disabled={full} onClick={open}
                        title={full ? __('The gallery is full.', 'fabrement-lite') : __('Add images', 'fabrement-lite')}>
                        <span aria-hidden="true">＋</span><span className="screen-reader-text">{__('Add images', 'fabrement-lite')}</span></button>)}
                </li>
            </ul>}
        <div className="fabrement-gallery__bar">
            <span className={short ? 'is-short' : ''}>
                {count} / {limit}{short ? ' · ' + /* translators: %d: smallest number of images the field asks for */ wp.i18n.sprintf(__('at least %d', 'fabrement-lite'), min) : ''}
            </span>
            {count > 1 && <span>{__('Drag to reorder.', 'fabrement-lite')}</span>}
            {count > 0 && media && <button type="button" className={'button-link' + (busy ? ' is-busy' : '')} disabled={busy} aria-busy={busy}
                onClick={() => { void openEditor(); }}>{busy ? __('Opening…', 'fabrement-lite') : __('Edit in media library', 'fabrement-lite')}</button>}
            {count > 0 && <button type="button" className="button-link is-destructive" onClick={() => {
                if (window.confirm(__('Remove all images from this gallery? The images stay in the media library.', 'fabrement-lite'))) change([]);
            }}>{__('Clear all', 'fabrement-lite')}</button>}
            {!MediaUpload && <span>{__('The media library is not available here.', 'fabrement-lite')}</span>}
            {/* Always mounted, so screen readers announce what is written into it. */}
            <span role="status" aria-live="polite" className="fabrement-gallery__status is-short">{notice}</span>
        </div>
    </div>;
}
