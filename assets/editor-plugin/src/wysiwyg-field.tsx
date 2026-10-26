import Squire from 'squire-rte';
import './wysiwyg-field.css';



const { useEffect, useRef, useState } = wp.element;
const MediaUpload      = wp.blockEditor?.MediaUpload;
const MediaUploadCheck = wp.blockEditor?.MediaUploadCheck;

/**
 * THE TAGS A VALUE MAY HOLD, and what each becomes — all of them pass wp_kses_post.
 *
 * Squire formats with <b>/<i> and asks hasFormat('B') about them, so inside the editor those are
 * the names; the stored value uses <strong>/<em>. {@see sanitize} converts in both directions. A
 * heading level the format menu does not offer is kept as the nearest one it does, so a pasted
 * <h1> stays a heading instead of melting into a paragraph.
 */
const KEEP: Record<string, string> = {
    P: 'P', BR: 'BR',
    B: 'B', STRONG: 'B', I: 'I', EM: 'I',
    UL: 'UL', OL: 'OL', LI: 'LI',
    H1: 'H2', H2: 'H2', H3: 'H3', H4: 'H4', H5: 'H4', H6: 'H4',
    BLOCKQUOTE: 'BLOCKQUOTE',
    A: 'A',
    IMG: 'IMG',
};

/** Dropped WITH their content — their text is code or form chrome, never prose. */
const DROP = new Set([
    'SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH',
    'TEXTAREA', 'SELECT', 'OPTION', 'BUTTON', 'INPUT', 'HEAD', 'TITLE', 'META', 'LINK',
]);

/** Blocks a value is made of, top level. */
const BLOCKS = new Set(['P', 'H2', 'H3', 'H4', 'UL', 'OL', 'BLOCKQUOTE']);
/** Blocks whose trailing <br> is Squire's caret filler, not content. */
const TEXT_BLOCKS = new Set(['P', 'H2', 'H3', 'H4', 'LI']);

/** The block formats the menu offers. */
const FORMATS = [
    { tag: 'P',  label: __('Paragraph', 'fabrement-lite') },
    { tag: 'H2', label: __('Heading 2', 'fabrement-lite') },
    { tag: 'H3', label: __('Heading 3', 'fabrement-lite') },
    { tag: 'H4', label: __('Heading 4', 'fabrement-lite') },
];

/** WordPress's own image sizes, in the order the size menu lists them. */
const IMAGE_SIZES = [
    { slug: 'thumbnail', label: __('Thumbnail', 'fabrement-lite') },
    { slug: 'medium',    label: __('Medium', 'fabrement-lite') },
    { slug: 'large',     label: __('Large', 'fabrement-lite') },
    { slug: 'full',      label: __('Full size', 'fabrement-lite') },
];

/** WordPress's alignment classes — the ones themes and the block editor already style. */
const ALIGNMENTS = [
    { cls: '',            label: __('None', 'fabrement-lite') },
    { cls: 'alignleft',   label: __('Left', 'fabrement-lite') },
    { cls: 'aligncenter', label: __('Center', 'fabrement-lite') },
    { cls: 'alignright',  label: __('Right', 'fabrement-lite') },
];

/** The image classes a stored value may keep — each is a WordPress convention with a job. */
const IMAGE_CLASS = /^(wp-image-[0-9]+|size-(thumbnail|medium|medium_large|large|full)|align(left|center|right|none))$/;

/**
 * Drop every character a browser ignores when it reads a URL scheme — control characters, spaces,
 * the C1 range — so a scheme split by a tab is checked as the "javascript:" the browser would run.
 */
function schemeProbe(value: string): string {
    let out = '';
    for (let i = 0; i < value.length; i++) {
        const code = value.charCodeAt(i);
        if (code > 32 && (code < 127 || code > 159)) {
            out += value[i];
        }
    }
    return out;
}

/** An address that cannot run code: http(s), mailto, tel, or a relative / fragment address. */
function safeHref(raw: string): string {
    const href = (raw || '').trim();
    const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(schemeProbe(href));
    if (scheme && !['http', 'https', 'mailto', 'tel'].includes(scheme[1].toLowerCase())) {
        return '';
    }
    return href;
}

/** An image source: http(s) or relative only — never data:, which can carry anything. */
function safeSrc(raw: string): string {
    const src = (raw || '').trim();
    const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(schemeProbe(src));
    if (scheme && !['http', 'https'].includes(scheme[1].toLowerCase())) {
        return '';
    }
    return src;
}

/**
 * HTML → a fragment holding only the allowlist, built in THIS document.
 *
 * WHY NOT A GENERAL SANITIZER. Squire's default hands pasted and loaded HTML to a global
 * DOMPurify, which WordPress does not ship and which would add 10 KB to the bundle. It is also the
 * wrong shape: we accept a short list of tags, and an allowlist that builds new nodes from scratch
 * cannot be talked into keeping an attribute it never copies. That matters beyond tidy markup —
 * setHTML() puts a value another user saved into the admin's DOM, and `<img onerror>` there would
 * run in the admin's session.
 *
 * DOMParser builds an INERT document: nothing in it loads, runs or fires, so reading the untrusted
 * markup is safe; only the nodes rebuilt below ever reach the live page.
 */
function sanitize(html: string, forEditor: boolean): DocumentFragment {
    const source = new DOMParser().parseFromString('<!doctype html><body>' + (html || ''), 'text/html').body;
    const out = document.createDocumentFragment();
    copyChildren(source, out, forEditor);
    return out;
}

function copyChildren(from: Node, to: Node, forEditor: boolean): void {
    const nodes = Array.from(from.childNodes);
    for (let i = 0; i < nodes.length; i++) {
        const node = nodes[i];
        if (node.nodeType === Node.TEXT_NODE) {
            to.appendChild(document.createTextNode(node.nodeValue || ''));
            continue;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            continue;
        }
        const el  = node as Element;
        const tag = el.tagName.toUpperCase();
        if (DROP.has(tag)) {
            continue;
        }

        let name = KEEP[tag];
        if (!name) {
            // Unknown wrapper (div, span, font, figure, table cell…): keep what is inside it.
            copyChildren(el, to, forEditor);
            continue;
        }
        if (!forEditor) {
            name = name === 'B' ? 'STRONG' : name === 'I' ? 'EM' : name;
        }

        if (name === 'IMG') {
            const img = imageCopy(el);
            if (img) to.appendChild(img);
            continue;
        }

        const copy = document.createElement(name);
        if (name === 'A') {
            const href = safeHref(el.getAttribute('href') || '');
            if (!href) {
                copyChildren(el, to, forEditor);   // a link that could run code keeps its words only
                continue;
            }
            copy.setAttribute('href', href);
            if (el.getAttribute('target') === '_blank') {
                copy.setAttribute('target', '_blank');
                copy.setAttribute('rel', 'noopener noreferrer');
            }
        }
        to.appendChild(copy);
        copyChildren(el, copy, forEditor);
    }
}

/**
 * An <img> with only what a content image needs. The classes kept are WordPress's own:
 * `wp-image-<id>` (what wp_filter_content_tags() needs to add srcset, sizes and lazy loading),
 * `size-<slug>` and `align<side>`. Nothing else in a class belongs in stored prose.
 */
function imageCopy(el: Element): HTMLImageElement | null {
    const src = safeSrc(el.getAttribute('src') || '');
    if (!src) return null;
    const img = document.createElement('img');
    img.setAttribute('src', src);
    img.setAttribute('alt', el.getAttribute('alt') || '');
    ['width', 'height'].forEach((attr) => {
        const v = el.getAttribute(attr) || '';
        if (/^[0-9]{1,5}$/.test(v)) img.setAttribute(attr, v);
    });
    const classes = (el.getAttribute('class') || '').split(/\s+/).filter((c) => IMAGE_CLASS.test(c));
    if (classes.length) img.setAttribute('class', classes.join(' '));
    return img;
}

/** The editor's HTML → the value that is stored: allowlisted, <strong>/<em>, no editor filler. */
function toStored(editorHtml: string): string {
    const holder = document.createElement('div');
    holder.appendChild(sanitize(editorHtml, false));

    // Inline content left between blocks — the text of an unwrapped table cell or <div> from a
    // paste, Squire's own bottom-line filler (a bare <br> in a wrapper the allowlist drops) — goes
    // into a paragraph of its own, so every value is a sequence of blocks. This runs FIRST: only
    // once the filler sits in a <p> can the two passes below recognise it as an empty line.
    let run: HTMLElement | null = null;
    Array.from(holder.childNodes).forEach((node) => {
        if (node.nodeType === Node.ELEMENT_NODE && BLOCKS.has(node.nodeName)) {
            run = null;
            return;
        }
        if (node.nodeType === Node.TEXT_NODE && !(node.nodeValue || '').trim() && !run) {
            node.remove();
            return;
        }
        if (!run) {
            run = document.createElement('p');
            holder.insertBefore(run, node);
        }
        run.appendChild(node);
    });

    // Squire keeps a <br> at the end of every block so an empty line can hold the caret. It is
    // editing machinery, not content: left in, every paragraph would render with a blank line.
    holder.querySelectorAll('br').forEach((br) => {
        const parent = br.parentElement;
        if (parent && parent !== holder && TEXT_BLOCKS.has(parent.tagName) && br === parent.lastChild) {
            br.remove();
        }
    });

    holder.querySelectorAll('p, h2, h3, h4').forEach((block) => {
        if (!block.textContent?.trim() && !block.querySelector('br, img')) {
            block.remove();
        }
    });

    return holder.innerHTML;
}


/** The attachment id an <img> carries in its `wp-image-<id>` class, or 0. */
function imageId(img: HTMLImageElement | null): number {
    const hit = /(?:^|\s)wp-image-([0-9]+)(?:\s|$)/.exec(img?.getAttribute('class') || '');
    return hit ? Number(hit[1]) : 0;
}

type SizeInfo = { url: string; width: number; height: number };

export type WysiwygFieldProps = {
    label?:       string;
    value?:       string;
    onChange?:    (value: string) => void;
};

/** Minimum height of the editing area in px; it grows to three times that, then scrolls. */
const HEIGHT = 160;

/**
 * Rich-text field: Squire as the engine, our chrome on top.
 *
 * Value is an HTML string holding only p, br, strong, em, ul, ol, li, h2, h3, h4, blockquote,
 * a[href|target|rel] and img[src|alt|width|height|class]. Render it with wp_kses_post() inside
 * wp_filter_content_tags() — NOT fabrement_kses_textarea(), which is for plain multi-line text and
 * strips every block tag. Sanitise on the PHP side regardless: a browser-side editor is not a trust
 * boundary.
 *
 * WHY SQUIRE. It edits a plain contenteditable <div> in OUR DOM — no <iframe>, which matters in the
 * repeater: makeGhost() clones a dragged row with cloneNode(true), and an iframe's document does
 * not clone (the drag clone would show an empty editor) and reloads when moved (wiping what was
 * typed). It keeps no global editor registry, rewrites no URLs, and is ~18 KB gzipped. The previous
 * engine, WordPress's bundled TinyMCE 4.9, converted `/about` to `about` as soon as the field was
 * clicked, and could not be made to edit inside the block editor.
 *
 * WHAT THE FIRST EDIT DOES TO FOREIGN MARKUP. Opening the field changes nothing. The first edit
 * writes the value back through the allowlist, so markup from elsewhere — a <span class>, a
 * <table>, an <h5> — is reduced to what the list keeps (the <h5> becomes an <h4>).
 */
export function WysiwygField(props: WysiwygFieldProps) {
    const {
        label,
        value = '',
        onChange,
    } = props;


    const areaRef   = useRef<any>(null);
    const editorRef = useRef<any>(null);
    // onChange is rebuilt by the parent on every render. Reading it from a ref keeps it out of the
    // mount effect's deps — with it in there, the editor would be destroyed on every keystroke.
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;
    // What we last handed upward. Guards the value -> editor sync below from re-writing content
    // the editor itself just produced (setHTML() moves the caret and resets undo).
    const lastEmitted = useRef<string>(value);
    // The editor's selection as it last stood. The format menu, the link row and the media modal
    // all take focus away from the editor, and whatever they do must land where the caret was.
    const savedRange = useRef<Range | null>(null);
    // Hand the current content upward. Set by the mount effect; changes made through
    // modifyDocument() raise no `input` event and must call it themselves.
    const emitRef = useRef<() => void>(() => {});
    // The image the image bar is editing, and its renditions once fetched (per attachment id).
    const imageRef = useRef<HTMLImageElement | null>(null);
    const sizesCache = useRef<Record<number, Record<string, SizeInfo>>>({});

    const [ready,    setReady]    = useState(false);
    const [locked,   setLocked]   = useState(false);   // inside a disabled <fieldset> (a locked field)
    const [focused,  setFocused]  = useState(false);
    const [active,   setActive]   = useState<Record<string, boolean>>({});
    const [format,   setFormat]   = useState('P');
    const [undo,     setUndo]     = useState({ canUndo: false, canRedo: false });
    const [linkOpen, setLinkOpen] = useState(false);
    const [linkUrl,  setLinkUrl]  = useState('');
    const [linkTab,  setLinkTab]  = useState(false);
    const [source,   setSource]   = useState<string | null>(null);   // non-null = HTML view is open
    const [image,    setImage]    = useState<{ alt: string; align: string; size: string; sizes: Record<string, SizeInfo> | null } | null>(null);

    useEffect(() => {
        const root = areaRef.current;
        if (!root) return;

        const editor = new Squire(root, {
            blockTag: 'P',
            // Every way HTML enters the editor — setHTML and paste alike — goes through this.
            sanitizeToDOMFragment: (html: string) => sanitize(html, true),
            didError: () => { /* Squire reports recoverable internal states here; nothing to show. */ },
        });

        // SQUIRE'S OWN IMAGE RESIZER IS SWITCHED OFF. It draws drag handles positioned against the
        // image, which drift inside a scrolling container, and a drag stores whatever pixel size the
        // pointer stopped at — `width="613"` — which then renders at that size on a phone too. The
        // image bar below offers WordPress's own renditions and alignment instead, both of which
        // stay responsive. `_imageResizer` is private API, pinned by the exact squire-rte version in
        // package.json; if it ever goes away the resizer simply stays on.
        try { editor._imageResizer?.destroy?.(); } catch { /* nothing to switch off */ }

        // Shortcuts that make tags the allowlist does not keep. Replaced by a handler that swallows
        // the key, NOT removed: with no Squire handler the browser applies its own contenteditable
        // underline instead, which would then silently vanish on save.
        const mod = /Mac|iPhone|iPad/.test(navigator.platform) ? 'Meta-' : 'Ctrl-';
        const swallow = (_editor: any, event: Event) => event.preventDefault();
        ['u', 'Shift-7', 'Shift-5', 'Shift-6', 'd'].forEach((key) => editor.setKeyHandler(mod + key, swallow));

        editor.setHTML(value || '');

        const emit = () => {
            const html = toStored(editor.getHTML());
            if (html === lastEmitted.current) return;
            lastEmitted.current = html;
            onChangeRef.current?.(html);
        };
        emitRef.current = emit;
        const refresh = () => {
            savedRange.current = editor.getSelection();
            setActive(readActive(editor));
            setFormat(readFormat(editor));
        };

        // Clicking an image selects it for the image bar; clicking anywhere else in the text lets
        // it go. A deleted image (Backspace, undo) is let go by the input handler below.
        const onClick = (event: MouseEvent) => {
            const target = event.target as HTMLElement;
            selectImage(target && target.nodeName === 'IMG' && root.contains(target) ? (target as HTMLImageElement) : null);
        };

        editor.addEventListener('input', () => {
            if (imageRef.current && !root.contains(imageRef.current)) selectImage(null);
            emit();
        });
        editor.addEventListener('pathChange', refresh);
        editor.addEventListener('select', refresh);
        editor.addEventListener('undoStateChange', (e: any) => setUndo({ canUndo: !!e.detail?.canUndo, canRedo: !!e.detail?.canRedo }));
        editor.addEventListener('focus', () => setFocused(true));
        editor.addEventListener('blur',  () => setFocused(false));
        root.addEventListener('click', onClick);

        editorRef.current = editor;
        setReady(true);

        return () => {
            root.removeEventListener('click', onClick);
            editorRef.current = null;
            imageRef.current = null;
            setReady(false);
            try { editor.destroy(); } catch { /* already torn down */ }
        };
        // Mount-only. `value` is read once for the initial content and thereafter pushed in by the
        // sync effect below.
    }, []);

    // A disabled <fieldset> (a field a translation cannot change) disables its inputs, not a
    // contenteditable: the text must be switched off here, or it takes typing the save then refuses.
    useEffect(() => {
        const root = areaRef.current;
        if (!root || !ready) return;
        const off = !!root.closest('fieldset:disabled');
        root.contentEditable = off ? 'false' : 'true';
        if (off !== locked) setLocked(off);
    });

    // Outside-in updates: an undo in Gutenberg, a reset, or the repeater handing this slot a
    // different item's value. Skipped while the caret is inside — mid-typing the parent is always
    // one render behind us.
    useEffect(() => {
        const editor = editorRef.current;
        const root   = areaRef.current;
        if (!editor || !ready || !root) return;
        const next = value || '';
        if (next === lastEmitted.current) return;
        if (root.contains(root.ownerDocument.activeElement)) return;
        lastEmitted.current = next;
        selectImage(null);
        editor.setHTML(next);
    }, [value, ready]);

    /** Put the caret back where it was before focus left the editor, then focus it. */
    const restore = (editor: any) => {
        editor.focus();
        if (savedRange.current) {
            try { editor.setSelection(savedRange.current); } catch { /* the range's nodes are gone */ }
        }
    };

    /**
     * Change the document directly, as ONE undo step, and hand the result upward. modifyDocument()
     * keeps Squire's change observer out of the way, so neither the undo state nor the `input`
     * event happens on its own — both are done here.
     */
    const change = (mutate: () => void) => {
        const editor = editorRef.current;
        if (!editor) return;
        editor.saveUndoState();
        editor.modifyDocument(mutate);
        emitRef.current();
    };

    function selectImage(img: HTMLImageElement | null) {
        const previous = imageRef.current;
        if (previous === img) return;
        // The outline marks the selection in the editor only; data-* never survives the allowlist.
        const editor = editorRef.current;
        const mark = () => {
            previous?.removeAttribute('data-fab-selected');
            img?.setAttribute('data-fab-selected', '');
        };
        if (editor) editor.modifyDocument(mark); else mark();
        imageRef.current = img;
        if (!img) {
            setImage(null);
            return;
        }
        const classes = (img.getAttribute('class') || '').split(/\s+/);
        setImage({
            alt:   img.getAttribute('alt') || '',
            align: classes.find((c) => /^align(left|center|right)$/.test(c)) || '',
            size:  (classes.find((c) => /^size-/.test(c)) || '').replace('size-', ''),
            sizes: sizesCache.current[imageId(img)] || null,
        });
        loadSizes(img);
    }

    /** The renditions of the selected image's attachment, from the REST API, once per id. */
    function loadSizes(img: HTMLImageElement) {
        const id = imageId(img);
        if (!id || sizesCache.current[id] || !wp.apiFetch) return;
        wp.apiFetch({ path: '/wp/v2/media/' + id + '?context=edit&_fields=source_url,media_details' })
            .then((media: any) => {
                const out: Record<string, SizeInfo> = {};
                const sizes = media?.media_details?.sizes || {};
                IMAGE_SIZES.forEach(({ slug }) => {
                    const s = sizes[slug];
                    if (s?.source_url) out[slug] = { url: s.source_url, width: Number(s.width) || 0, height: Number(s.height) || 0 };
                });
                if (!out.full && media?.source_url) {
                    out.full = { url: media.source_url, width: Number(media.media_details?.width) || 0, height: Number(media.media_details?.height) || 0 };
                }
                sizesCache.current[id] = out;
                if (imageRef.current === img) {
                    setImage((current) => (current ? { ...current, sizes: out } : current));
                }
            })
            .catch(() => { /* no REST access: the size menu stays hidden */ });
    }

    const setImageSize = (slug: string) => {
        const img = imageRef.current;
        const rendition = image?.sizes?.[slug];
        if (!img || !rendition) return;
        change(() => {
            img.setAttribute('src', rendition.url);
            if (rendition.width)  img.setAttribute('width', String(rendition.width));  else img.removeAttribute('width');
            if (rendition.height) img.setAttribute('height', String(rendition.height)); else img.removeAttribute('height');
            const kept = (img.getAttribute('class') || '').split(/\s+/).filter((c) => c && !/^size-/.test(c));
            img.setAttribute('class', kept.concat('size-' + slug).join(' '));
        });
        setImage((current) => (current ? { ...current, size: slug } : current));
    };

    const setImageAlign = (cls: string) => {
        const img = imageRef.current;
        if (!img) return;
        change(() => {
            const kept = (img.getAttribute('class') || '').split(/\s+/).filter((c) => c && !/^align/.test(c));
            const next = cls ? kept.concat(cls) : kept;
            if (next.length) img.setAttribute('class', next.join(' ')); else img.removeAttribute('class');
        });
        setImage((current) => (current ? { ...current, align: cls } : current));
    };

    const setImageAlt = (alt: string) => {
        const img = imageRef.current;
        if (!img) return;
        change(() => img.setAttribute('alt', alt));
        setImage((current) => (current ? { ...current, alt } : current));
    };

    const removeImage = () => {
        const img = imageRef.current;
        if (!img) return;
        selectImage(null);
        change(() => {
            const parent = img.parentElement;
            img.remove();
            // An image inserted on its own line leaves an empty paragraph behind; take it too.
            if (parent && parent !== areaRef.current && !parent.textContent?.trim() && !parent.querySelector('img')) {
                parent.remove();
            }
        });
    };

    const run = (id: string) => {
        const editor = editorRef.current;
        if (!editor) return;
        editor.focus();

        switch (id) {
            case 'B':      active.B  ? editor.removeBold()   : editor.bold();   break;
            case 'I':      active.I  ? editor.removeItalic() : editor.italic(); break;
            case 'UL':     active.UL ? editor.removeList() : editor.makeUnorderedList(); break;
            case 'OL':     active.OL ? editor.removeList() : editor.makeOrderedList();   break;
            case 'QUOTE':  active.QUOTE ? editor.removeQuote() : editor.increaseQuoteLevel(); break;
            case 'unlink': editor.removeLink(); break;
            case 'undo':   editor.undo(); break;
            case 'redo':   editor.redo(); break;
            case 'clear':  editor.removeAllFormatting(); break;
            case 'A': {
                const link = closestLink(editor);
                savedRange.current = editor.getSelection();
                setLinkUrl(link ? link.getAttribute('href') || '' : '');
                setLinkTab(link ? link.getAttribute('target') === '_blank' : false);
                setLinkOpen(true);
                return;
            }
        }
        setActive(readActive(editor));
        setFormat(readFormat(editor));
    };

    const applyFormat = (tag: string) => {
        const editor = editorRef.current;
        if (!editor) return;
        restore(editor);
        setBlockFormat(editor, tag);
        setFormat(readFormat(editor));
        setActive(readActive(editor));
    };

    const applyLink = () => {
        const editor = editorRef.current;
        setLinkOpen(false);
        if (!editor) return;
        restore(editor);
        const href = safeHref(linkUrl);
        if (!href) {
            editor.removeLink();
        } else {
            editor.makeLink(href, linkTab ? { target: '_blank', rel: 'noopener noreferrer' } : {});
        }
        editor.focus();
    };

    const cancelLink = () => {
        setLinkOpen(false);
        const editor = editorRef.current;
        if (editor) restore(editor);
    };

    const insertImage = (media: any) => {
        const editor = editorRef.current;
        const root   = areaRef.current;
        if (!editor || !root || !media?.url) return;
        restore(editor);

        // The large rendition when there is one: the full original of a camera photo is far bigger
        // than a text column. The class carries the attachment id, which is what lets WordPress add
        // srcset on the front end, and the size, which is what the image bar reads back.
        const slug = media.sizes?.large ? 'large' : 'full';
        const size = media.sizes?.[slug] || media;
        const img = document.createElement('img');
        img.setAttribute('src', size.url || media.url);
        img.setAttribute('alt', media.alt || '');
        img.setAttribute('class', (media.id ? 'wp-image-' + media.id + ' ' : '') + 'size-' + slug);
        if (size.width)  img.setAttribute('width', String(size.width));
        if (size.height) img.setAttribute('height', String(size.height));

        // AN IMAGE IS A BLOCK OF ITS OWN, after the block the caret is in — never an inline
        // character at the caret, which dropped it into the middle of a word ("Lin[img]e two"),
        // and never appended inside that block, which is what insertHTML() at the block's end does.
        let block: Node | null = editor.getSelection().startContainer;
        while (block && block.parentNode !== root) block = block.parentNode;
        const paragraph = document.createElement('p');
        paragraph.appendChild(img);
        change(() => {
            if (block && block !== root) root.insertBefore(paragraph, block.nextSibling);
            else root.appendChild(paragraph);
        });

        const after = document.createRange();
        after.setStartAfter(img);
        after.collapse(true);
        editor.setSelection(after);
        selectImage(img);
    };

    const toggleSource = () => {
        const editor = editorRef.current;
        if (!editor) return;
        if (source === null) {
            setLinkOpen(false);
            selectImage(null);
            setSource(toStored(editor.getHTML()));
            return;
        }
        // Back to the visual view: whatever was typed goes through the same allowlist as a paste.
        editor.setHTML(source);
        setSource(null);
        emitRef.current();
    };

    const disabled = !ready || locked || source !== null;
    const button = (id: string, text: any, title: string, extra = '', on = false, off = false) => (
        <button
            key={id}
            type="button"
            title={title}
            aria-label={title}
            aria-pressed={on}
            disabled={disabled || off}
            className={'fabrement-wysiwyg-field__btn' + (extra ? ' ' + extra : '') + (on ? ' fabrement-wysiwyg-field__btn--active' : '')}
            // A plain click moves focus to the button BEFORE onClick runs, and the selection dies
            // with it — the command would apply to nothing. Refusing the default mousedown keeps
            // the caret where it was.
            onMouseDown={(e: any) => e.preventDefault()}
            onClick={() => run(id)}
        >{text}</button>
    );
    const sep = (key: string) => <span key={key} className="fabrement-wysiwyg-field__sep" />;

    const imageButton = MediaUpload && MediaUploadCheck ? (
        <MediaUploadCheck key="img">
            <MediaUpload
                allowedTypes={['image']}
                onSelect={insertImage}
                render={({ open }: any) => (
                    <button
                        type="button"
                        title={__('Insert image', 'fabrement-lite')}
                        aria-label={__('Insert image', 'fabrement-lite')}
                        disabled={disabled}
                        className="fabrement-wysiwyg-field__btn"
                        onMouseDown={(e: any) => {
                            e.preventDefault();
                            savedRange.current = editorRef.current?.getSelection() || savedRange.current;
                        }}
                        onClick={open}
                    >🖼</button>
                )}
            />
        </MediaUploadCheck>
    ) : null;

    const availableSizes = image?.sizes ? IMAGE_SIZES.filter(({ slug }) => image.sizes?.[slug]) : [];

    return (
        <div className={'fabrement-wysiwyg-field' + (focused ? ' fabrement-wysiwyg-field--focused' : '')}>
            {label && <span className="fabrement-wysiwyg-field__label">{label}</span>}
            <div className="fabrement-wysiwyg-field__frame">
                <div className="fabrement-wysiwyg-field__toolbar">
                    <select
                        className="fabrement-wysiwyg-field__select"
                        title={__('Text format', 'fabrement-lite')}
                        aria-label={__('Text format', 'fabrement-lite')}
                        value={format}
                        disabled={disabled}
                        onMouseDown={() => { savedRange.current = editorRef.current?.getSelection() || savedRange.current; }}
                        onChange={(e: any) => applyFormat(e.target.value)}
                    >
                        {FORMATS.map((f) => <option key={f.tag} value={f.tag}>{f.label}</option>)}
                    </select>
                    {sep('s1')}
                    {button('B', 'B', __('Bold', 'fabrement-lite'), 'fabrement-wysiwyg-field__btn--bold', !!active.B)}
                    {button('I', 'I', __('Italic', 'fabrement-lite'), 'fabrement-wysiwyg-field__btn--italic', !!active.I)}
                    {sep('s2')}
                    {button('UL', '•≡', __('Bulleted list', 'fabrement-lite'), '', !!active.UL)}
                    {button('OL', '1≡', __('Numbered list', 'fabrement-lite'), '', !!active.OL)}
                    {button('QUOTE', '❝', __('Quote', 'fabrement-lite'), '', !!active.QUOTE)}
                    {sep('s3')}
                    {button('A', '🔗', __('Insert link', 'fabrement-lite'), '', !!active.A)}
                    {button('unlink', '⛓', __('Remove link', 'fabrement-lite'), '', false, !active.A)}
                    {imageButton}
                    {sep('s4')}
                    {button('undo', '↶', __('Undo', 'fabrement-lite'), '', false, !undo.canUndo)}
                    {button('redo', '↷', __('Redo', 'fabrement-lite'), '', false, !undo.canRedo)}
                    {button('clear', '⌫', __('Clear formatting', 'fabrement-lite'))}
                    <span className="fabrement-wysiwyg-field__spacer" />
                    <button
                        type="button"
                        title={source === null ? __('Edit HTML', 'fabrement-lite') : __('Back to visual', 'fabrement-lite')}
                        aria-label={source === null ? __('Edit HTML', 'fabrement-lite') : __('Back to visual', 'fabrement-lite')}
                        aria-pressed={source !== null}
                        disabled={!ready || locked}
                        className={'fabrement-wysiwyg-field__btn' + (source !== null ? ' fabrement-wysiwyg-field__btn--active' : '')}
                        onClick={toggleSource}
                    >{'</>'}</button>
                </div>
                {linkOpen && (
                    <div
                        className="fabrement-wysiwyg-field__bar"
                        // On the row, not the input: after ticking "New tab" the focus is on the
                        // checkbox, and Enter there must still apply the link.
                        onKeyDown={(e: any) => {
                            if (e.key === 'Enter')  { e.preventDefault(); applyLink(); }
                            // Escape should only close the row, not anything around the field.
                            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancelLink(); }
                        }}
                    >
                        <input
                            type="text"
                            className="fabrement-wysiwyg-field__input"
                            value={linkUrl}
                            placeholder={__('https://… or /page', 'fabrement-lite')}
                            autoFocus
                            onChange={(e: any) => setLinkUrl(e.target.value)}
                        />
                        <label>
                            <input type="checkbox" checked={linkTab} onChange={(e: any) => setLinkTab(e.target.checked)} />
                            {__('New tab', 'fabrement-lite')}
                        </label>
                        <button type="button" className="fabrement-wysiwyg-field__btn" onClick={applyLink}>{__('Apply', 'fabrement-lite')}</button>
                        <button type="button" className="fabrement-wysiwyg-field__btn" onClick={cancelLink}>{__('Cancel', 'fabrement-lite')}</button>
                    </div>
                )}
                {image && source === null && (
                    <div
                        className="fabrement-wysiwyg-field__bar fabrement-wysiwyg-field__image-bar"
                        onKeyDown={(e: any) => {
                            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); selectImage(null); }
                        }}
                    >
                        <span className="fabrement-wysiwyg-field__bar-title">{__('Image', 'fabrement-lite')}</span>
                        {availableSizes.length > 0 && (
                            <select
                                className="fabrement-wysiwyg-field__select"
                                title={__('Image size', 'fabrement-lite')}
                                aria-label={__('Image size', 'fabrement-lite')}
                                value={image.size}
                                onChange={(e: any) => setImageSize(e.target.value)}
                            >
                                {!image.size && <option value="">{__('Custom', 'fabrement-lite')}</option>}
                                {availableSizes.map(({ slug, label: text }) => (
                                    <option key={slug} value={slug}>
                                        {text}{image.sizes?.[slug]?.width ? ' — ' + image.sizes[slug].width + 'px' : ''}
                                    </option>
                                ))}
                            </select>
                        )}
                        {ALIGNMENTS.map(({ cls, label: text }) => (
                            <button
                                key={cls || 'none'}
                                type="button"
                                /* translators: %s: alignment name (None, Left, Center, Right) */
                                title={wp.i18n.sprintf(__('Align: %s', 'fabrement-lite'), text)}
                                aria-pressed={image.align === cls}
                                className={'fabrement-wysiwyg-field__btn' + (image.align === cls ? ' fabrement-wysiwyg-field__btn--active' : '')}
                                onClick={() => setImageAlign(cls)}
                            >{text}</button>
                        ))}
                        <input
                            type="text"
                            className="fabrement-wysiwyg-field__input"
                            title={__('Alternative text', 'fabrement-lite')}
                            aria-label={__('Alternative text', 'fabrement-lite')}
                            placeholder={__('Alt text — describe the image', 'fabrement-lite')}
                            value={image.alt}
                            onChange={(e: any) => setImageAlt(e.target.value)}
                        />
                        <button type="button" className="fabrement-wysiwyg-field__btn fabrement-wysiwyg-field__btn--danger" title={__('Remove image', 'fabrement-lite')} onClick={removeImage}>{__('Remove', 'fabrement-lite')}</button>
                    </div>
                )}
                <div className="fabrement-wysiwyg-field__area-wrap" hidden={source !== null}>
                    <div
                        ref={areaRef}
                        className="fabrement-wysiwyg-field__area"
                        style={{ minHeight: HEIGHT, maxHeight: HEIGHT * 3 }}
                    />
                </div>
                {source !== null && (
                    <textarea
                        className="fabrement-wysiwyg-field__source"
                        style={{ minHeight: HEIGHT }}
                        value={source}
                        spellCheck={false}
                        onChange={(e: any) => setSource(e.target.value)}
                    />
                )}
            </div>
        </div>
    );
}

/** Which toolbar buttons should read as "on" for the current selection. */
function readActive(editor: any): Record<string, boolean> {
    const path = String(editor.getPath() || '');
    const inPath = (tag: string) => new RegExp('(?:^|>)' + tag + '(?:$|[>.#[])').test(path);
    return {
        B:     editor.hasFormat('B'),
        I:     editor.hasFormat('I'),
        A:     editor.hasFormat('A'),
        UL:    inPath('UL'),
        OL:    inPath('OL'),
        QUOTE: inPath('BLOCKQUOTE'),
    };
}

/** The block format the caret sits in, as the menu names it. */
function readFormat(editor: any): string {
    const path = String(editor.getPath() || '');
    const hit = /(?:^|>)(H2|H3|H4)(?:$|[>.#[])/.exec(path);
    return hit ? hit[1] : 'P';
}

/**
 * Give the selected lines block format `tag` (P, H2, H3, H4).
 *
 * A HEADING IS A LINE, NOT A PARAGRAPH'S WORTH OF LINES. A block format is a property of the whole
 * block, and text that arrived as one paragraph — a default value, a paste, lines broken with
 * Shift+Enter — is one block whatever it looks like. Applied directly, "Heading 2" on the line
 * under the caret turned every line of that paragraph into the heading. So a block broken by <br>
 * is first split into one block per line, and only then is the format applied, to the lines the
 * selection actually touches. Squire keeps the selection as hidden <input> bookmarks among the
 * block's children; moving the children moves the bookmarks, so the caret survives both steps.
 */
function setBlockFormat(editor: any, tag: string): void {
    editor.modifyBlocks((frag: DocumentFragment) => {
        Array.from(frag.childNodes).forEach((node) => {
            if (!/^(P|H2|H3|H4)$/.test(node.nodeName)) return;
            const lines: Node[][] = [[]];
            Array.from(node.childNodes).forEach((child) => {
                if (child.nodeName === 'BR') lines.push([]);
                else lines[lines.length - 1].push(child);
            });
            if (lines.length < 2) return;
            lines.forEach((line) => {
                const block = document.createElement(node.nodeName);
                line.forEach((child) => block.appendChild(child));
                frag.insertBefore(block, node);
            });
            frag.removeChild(node);
        });
        return frag;
    });

    editor.modifyBlocks((frag: DocumentFragment) => {
        Array.from(frag.childNodes).forEach((node) => {
            // Only top-level text blocks. A list item or a quoted line keeps its structure — an
            // <h2> inside a <ul> is not markup wp_kses_post, or the allowlist, lets through cleanly.
            if (!/^(P|H2|H3|H4)$/.test(node.nodeName) || node.nodeName === tag) return;
            const next = document.createElement(tag);
            while (node.firstChild) next.appendChild(node.firstChild);
            frag.replaceChild(next, node);
        });
        return frag;
    });
}

/** The <a> the caret or selection start sits in, if any. */
function closestLink(editor: any): HTMLAnchorElement | null {
    const root = editor.getRoot();
    let node: Node | null = editor.getSelection().startContainer;
    while (node && node !== root) {
        if (node.nodeName === 'A') return node as HTMLAnchorElement;
        node = node.parentNode;
    }
    return null;
}
