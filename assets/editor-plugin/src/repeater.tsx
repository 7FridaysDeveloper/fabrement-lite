/**
 * Repeater primitives — the frame around ONE row of a repeater field, and the
 * button that appends another. Their styles are injected by the components.
 *
 * REORDERING: the component owns the drag mechanics, the caller owns the array
 * (`onMove`). Reordering is live: the list rearranges under the cursor while
 * the item is still held, the wheel and the edges both scroll it, and Escape
 * puts everything back.
 */
import './repeater.css';


/**
 * Reordering runs on POINTER events, not the HTML5 drag-and-drop API.
 *
 * That API looks like the obvious fit and is the wrong tool here: while a
 * native drag is in flight Chrome stops delivering `wheel` to the page, so the
 * list cannot be scrolled with the wheel while an item is held — and a long
 * repeater is exactly where you need to. Native DnD also gives no
 * auto-scroll of its own, no touch support, and no way back out of a drag.
 * A plain pointer drag keeps the browser out of the way: wheel scrolls
 * normally, touch works, and Escape can put the item back.
 *
 * The item under the cursor is marked in the DOM so sibling items can be told
 * apart from a NEIGHBOURING repeater's items — two repeaters in one form
 * must never reorder each other.
 */
const GROUP_ATTR = 'data-fabrement-repeater';

/** Travel (px) before a press on the handle counts as a drag rather than a click. */
const DRAG_THRESHOLD = 4;

/** Put on <body> for the life of a drag: kills text selection everywhere and
 *  keeps the grabbing cursor even where the pointer wanders. */
const DRAGGING_BODY_CLASS = 'fabrement-repeater-dragging';

/**
 * Items of one repeater are siblings under one parent element; items of another
 * repeater are not. That makes the parent node a group identity we can derive
 * for free, so the caller never passes anything.
 */
const autoGroups = new WeakMap<Element, string>();
let autoGroupSeq = 0;

function groupKey(el: any): string {
    const parent = el && el.parentElement;
    if (!parent) return 'detached';
    let key = autoGroups.get(parent);
    if (!key) { key = 'g' + (++autoGroupSeq); autoGroups.set(parent, key); }
    return key;
}

/**
 * Which item is in flight, shared by every item of every repeater.
 *
 * This has to be module state rather than a prop: during a LIVE reorder the
 * dragged item changes position mid-drag, and the item that should look "picked
 * up" is no longer the one that started the drag. Items subscribe so they all
 * repaint when it moves.
 */
type DragState = { group: string; index: number } | null;
let dragState: DragState = null;

/**
 * The one drag in flight, anywhere. `session` used to be a per-item ref, which
 * only stopped an item re-entering ITS OWN drag: a second pointer landing on a
 * different item (two fingers, or a pen while the mouse is down) opened a
 * second full set of document listeners, and whichever finger lifted first ran
 * a cleanup that wiped the OTHER drag's state and left its rAF loop spinning.
 * Reordering is inherently single-item, so the lock belongs to the module.
 */
let activeSession: any = null;
const dragListeners = new Set<() => void>();

function setDragState(next: DragState) {
    dragState = next;
    dragListeners.forEach((fn) => fn());
}

/**
 * Subscribe to the drag, but re-render only when THIS row's answer to `pick`
 * changes. A drag step moves the held row one slot, which flips the flag on
 * exactly two rows — waking all of them (a repeater can hold dozens) costs a
 * full extra render pass per pointermove for rows whose output is identical.
 */
function useDragSelector<T>(pick: () => T): T {
    const [, bump] = useState(0);
    const last = useRef<any>(undefined);
    last.current = pick();
    useEffect(() => {
        const fn = () => {
            const next = pick();
            if (Object.is(next, last.current)) return;
            last.current = next;
            bump((n: number) => n + 1);
        };
        dragListeners.add(fn);
        // Run once after every commit: a drag may have started between this
        // render and the effect, and nothing would have queued a bump for it.
        fn();
        return () => { dragListeners.delete(fn); };
    });
    return last.current;
}

/**
 * How far ahead of the dragged card's centre the swap is judged, as a fraction
 * of its height. 0 would mean "swap when the centres cross" — correct but slow;
 * this looks ahead in the direction of travel so the row gives way sooner.
 *
 * It is also the hysteresis: after a swap the reverse trigger sits two biases
 * away, so a card cannot flicker between two slots.
 */
const SWAP_BIAS = 0.45;

/** How long a card takes to slide into a slot it was just pushed out of. */
const SLIDE_MS = 170;

/** The clone's flight onto its final slot when the drag ends. */
const DROP_MS = 160;

/** Announcement debounce — a drag crosses positions faster than speech. */
const LIVE_DELAY = 100;

/**
 * A floating clone of the held row that follows the pointer — what dnd-kit calls
 * a DragOverlay, and what it recommends for any sortable list that SCROLLS:
 * without it the thing you are dragging is a row inside the scroller, so it
 * slides away under wheel or edge auto-scroll instead of staying under the
 * cursor. The clone is `position: fixed` on <body>, outside the scroller, so it
 * cannot be scrolled, clipped or re-laid-out by the list it came from.
 *
 * The row left behind stays in the list as the landing slot and reorders live.
 */
const GHOST_CLASS = 'fabrement-fields__repeater-ghost';

/** How far right the clone rides while held. Enough to uncover the landing slot
 *  and the rows either side of it, without leaving the column it belongs to. */
const GHOST_NUDGE_X = 40;

/** cloneNode copies ATTRIBUTES; React writes field state as DOM PROPERTIES, so
 *  a raw clone shows empty inputs. Carry the live values across by hand. */
function copyFieldValues(from: any, to: any) {
    const src = from.querySelectorAll('input, textarea, select');
    const dst = to.querySelectorAll('input, textarea, select');
    for (let i = 0; i < src.length && i < dst.length; i++) {
        const t = src[i].type;
        if (t === 'checkbox' || t === 'radio') dst[i].checked = src[i].checked;
        else dst[i].value = src[i].value;
    }
}

/** A contenteditable area (WysiwygField edits a plain div, so its text is real
 *  DOM and cloneNode DOES carry it over — unlike an <iframe>, which clones
 *  empty). The clone only has to stop BEING an editor: still editable, it can
 *  take the caret away from the live row, and a duplicated id leaves two
 *  elements answering to one name. */
function neutralizeEditables(to: any) {
    const editables = to.querySelectorAll('[contenteditable]');
    for (let i = 0; i < editables.length; i++) {
        editables[i].setAttribute('contenteditable', 'false');
        editables[i].removeAttribute('id');
        editables[i].removeAttribute('data-id');
    }
}

/**
 * The visible band of the scroller: its rect minus a sticky tab bar drawn over
 * it. Auto-scroll measures its edge zones from THIS.
 */
function travelBand(scroller: any, doc: any) {
    if (!scroller) return { top: 0, bottom: doc.documentElement.clientHeight };
    const box = scroller.getBoundingClientRect();
    let top = box.top;
    const tabs = scroller.querySelector('.components-tab-panel__tabs');
    if (tabs) top = Math.max(top, tabs.getBoundingClientRect().bottom);
    return { top, bottom: box.bottom };
}

/**
 * Mounted INSIDE the scroller, not on <body>: in the scroller's own stacking
 * context plain z-index keeps the clone over the rows and under anything
 * sticky drawn over the list.
 *
 * Still `position: fixed`, so it tracks the viewport and the list scrolls
 * beneath it; overflow on the scroller does not clip fixed descendants.
 */
function ghostHost(el: any, doc: any): any {
    return scrollParentOf(el) || doc.body;
}

function makeGhost(el: any, doc: any): any {
    const rect = el.getBoundingClientRect();
    const ghost = el.cloneNode(true);
    ghost.removeAttribute('id');
    ghost.removeAttribute(GROUP_ATTR);
    ghost.classList.add(GHOST_CLASS);
    ghost.classList.remove('fabrement-fields__repeater-item--dragging');
    ghost.setAttribute('aria-hidden', 'true');
    ghost.style.width = rect.width + 'px';
    ghost.style.height = rect.height + 'px';
    ghost.style.left = rect.left + 'px';
    ghost.style.top = rect.top + 'px';
    copyFieldValues(el, ghost);
    neutralizeEditables(ghost);
    ghostHost(el, doc).appendChild(ghost);

    // `fixed` is viewport-relative ONLY while no ancestor establishes a
    // containing block for it — and a transform/filter/will-change anywhere up
    // the page does exactly that, after which
    // left/top are measured from that ancestor and the card sits visibly off to
    // the right. Rather than guess which ancestor is to blame, place it, read
    // back where it actually landed, and correct by the difference.
    const landed = ghost.getBoundingClientRect();
    if (landed.left !== rect.left || landed.top !== rect.top) {
        ghost.style.left = (rect.left + (rect.left - landed.left)) + 'px';
        ghost.style.top = (rect.top + (rect.top - landed.top)) + 'px';
    }
    return { node: ghost, baseTop: rect.top };
}

/**
 * Settle the clone onto the slot the row ended up in, then drop it. Without the
 * short flight the card vanishes from under the cursor and reappears elsewhere,
 * which reads as a glitch rather than as a placement.
 */
function retireGhost(ghost: any, landing: any) {
    if (!ghost) return;
    const node = ghost.node;
    const done = () => { if (node.parentNode) node.parentNode.removeChild(node); };
    if (!landing) { done(); return; }
    const to = landing.getBoundingClientRect().top - ghost.baseTop;
    node.style.transition = `transform ${DROP_MS}ms cubic-bezier(.2,.7,.3,1), box-shadow ${DROP_MS}ms`;
    node.style.transform = `translate3d(0, ${to}px, 0)`;
    node.style.boxShadow = 'none';
    setTimeout(done, DROP_MS);
}

/**
 * One polite-but-assertive live region for the whole page. Debounced, because a
 * drag crosses several positions per second and a screen reader announcing
 * every one of them lags behind and disorients — the advice from GitHub's
 * write-up on accessible sortable lists.
 */
let liveNode: any = null;
let liveTimer: any = 0;
function announce(doc: any, message: string) {
    if (!liveNode || !liveNode.isConnected) {
        liveNode = doc.createElement('div');
        liveNode.className = 'fabrement-repeater-live';
        liveNode.setAttribute('aria-live', 'assertive');
        liveNode.setAttribute('aria-atomic', 'true');
        doc.body.appendChild(liveNode);
    }
    clearTimeout(liveTimer);
    liveTimer = setTimeout(() => { liveNode.textContent = message; }, LIVE_DELAY);
}

/** Pixels from a scroll container's edge where auto-scroll kicks in, and the
 *  fastest it ever scrolls (px per frame) right at the edge. */
const SCROLL_EDGE = 64;
const SCROLL_MAX_SPEED = 14;

/** Nearest ancestor that actually scrolls vertically, found generically:
 *  wherever the repeater is rendered. */
function scrollParentOf(el: any): any {
    let node = el && el.parentElement;
    while (node) {
        const style = node.ownerDocument.defaultView.getComputedStyle(node);
        const overflowY = style.overflowY;
        if ((overflowY === 'auto' || overflowY === 'scroll') && node.scrollHeight > node.clientHeight) return node;
        node = node.parentElement;
    }
    return null;
}

/**
 * Holding an item against the top or bottom edge scrolls the list, so items
 * outside the current view are reachable without letting go. Runs a rAF loop
 * for the life of the drag; speed ramps with how deep into the edge band the
 * pointer is, so a nudge creeps and a hard press flies.
 *
 * `onScrolled` fires on the frames that actually moved: the pointer stands
 * still while the list slides under it, and the landing slot has to keep up.
 */
function startAutoScroll(container: any, getY: () => number | null, onScrolled: () => void, getBand: () => any) {
    if (!container) return () => {};
    const win = container.ownerDocument.defaultView;
    let frame = 0;
    const step = () => {
        // The BAND, not the container's own rect: an edge zone must not sit
        // behind sticky chrome drawn over the scroller.
        const box = getBand();
        const y = getY();
        let delta = 0;
        // `null` means no pointer position yet. It must NOT be a `y > 0` test:
        // clientY is 0 or negative at the very top of the screen, which is
        // exactly where upward scrolling should be at full speed.
        if (y !== null) {
            const fromTop = y - box.top;
            const fromBottom = box.bottom - y;
            // Nearest edge wins. An `else if` on the top branch would starve
            // downward scrolling in any container shorter than two edge bands.
            if (fromTop < SCROLL_EDGE && fromTop <= fromBottom) delta = -SCROLL_MAX_SPEED * (1 - Math.max(0, fromTop) / SCROLL_EDGE);
            else if (fromBottom < SCROLL_EDGE) delta = SCROLL_MAX_SPEED * (1 - Math.max(0, fromBottom) / SCROLL_EDGE);
        }
        if (delta) {
            const before = container.scrollTop;
            container.scrollTop += delta;
            if (container.scrollTop !== before) onScrolled();
        }
        frame = win.requestAnimationFrame(step);
    };
    frame = win.requestAnimationFrame(step);
    return () => win.cancelAnimationFrame(frame);
}


const { useEffect, useLayoutEffect, useState, useRef } = wp.element;

export type RepeaterItemProps = {
    label: string;
    onRemove?: () => void;
    children?: any;
    /** This item's position in the array. */
    index: number;
    /** Reorder callback owned by the caller: (from, to) => new array order. */
    onMove: (from: number, to: number) => void;
    /** Total item count — disables "move down" on the last item. */
    count: number;
};

export type AddButtonProps = {
    onClick: () => void;
    children?: any;
    /** Current item count — paired with `maxItems` to auto-disable at the ceiling. */
    count?: number;
    /** Maximum items allowed; when `count` >= this, the button is disabled. */
    maxItems?: number;
    /** Force-disable regardless of count (e.g. external validation). */
    disabled?: boolean;
};

function GripIcon() {
    return (
        <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden>
            <circle cx="6" cy="3" r="1.4" /><circle cx="10" cy="3" r="1.4" />
            <circle cx="6" cy="8" r="1.4" /><circle cx="10" cy="8" r="1.4" />
            <circle cx="6" cy="13" r="1.4" /><circle cx="10" cy="13" r="1.4" />
        </svg>
    );
}

function ChevronIcon({ up }: { up?: boolean }) {
    return (
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
            {up ? <polyline points="3 10 8 5 13 10" /> : <polyline points="3 6 8 11 13 6" />}
        </svg>
    );
}

/**
 * One item of a repeater, with a drag handle plus up/down buttons (the keyboard
 * path — a pointer-only reorder is unusable for anyone not using a mouse).
 *
 * The reorder happens LIVE, while the item is still held: passing a
 * neighbour's midpoint moves the item there and the list re-lays-out under the
 * cursor, so what you see mid-drag is what you get on release. Releasing
 * commits nothing — the array is already in its final order; Escape (or a
 * cancelled pointer) moves it back where it started.
 */
export function RepeaterItem({ label, onRemove, children, index, onMove, count }: RepeaterItemProps) {

    /* translators: 1: row label, 2: its position, 3: number of rows */
    const positionText = (at: number, of: number) => wp.i18n.sprintf(__('%1$s: position %2$d of %3$d', 'fabrement-lite'), label, at, of);
    const rootRef = useRef<any>(null);
    // Subscribes to the ONE boolean this row renders from. Reads the attribute
    // rather than calling `groupKey`, which assigns ids and must not run as a
    // side effect of rendering.
    const isDragged = useDragSelector(() => !!(
        dragState && dragState.index === index
        && rootRef.current && dragState.group === rootRef.current.getAttribute(GROUP_ATTR)
    ));
    /** Everything the in-flight drag needs; null whenever nothing is held. */
    const session = useRef<any>(null);
    /**
     * The drag's listeners are installed once, on pointerdown, and outlive many
     * renders — but `onMove` is rebuilt every render by `reorderItems(items,…)`
     * and closes over THAT render's array. Calling the one captured at
     * pointerdown would splice the pre-drag list on the second step and throw
     * the first step away. Always go through the ref.
     */
    const onMoveRef = useRef(onMove);
    onMoveRef.current = onMove;

    // The group has to live on the DOM node, not just in a closure: the sibling
    // scan below reads it off the neighbours to tell one repeater's items from
    // another's. Set from an effect because `rootRef` is null on first render.
    useEffect(() => {
        const el = rootRef.current;
        if (!el) return;
        const key = groupKey(el);
        // Compared before writing: this effect has no dep array (the parent
        // element is only knowable after mount), so it runs on every render.
        if (el.getAttribute(GROUP_ATTR) !== key) el.setAttribute(GROUP_ATTR, key);
    });

    /**
     * FLIP: the cards that give way slide into their new slot instead of
     * teleporting, which is what makes the reorder readable at speed.
     *
     * Measured with `offsetTop`, NOT a client rect — the list scrolls during a
     * drag (wheel, edge auto-scroll) and a viewport-relative measure would read
     * that scroll as movement and animate every card on every scrolled frame.
     * Only runs mid-drag, and never on the held card: that one should sit under
     * the cursor, not tween behind it.
     */
    /**
     * A revert (Escape, a cancelled pointer, a lost window) that arrived while a
     * live move was still awaiting its render. It cannot be applied then:
     * `onMoveRef` still points at the callback built over the PRE-move array, so
     * splicing the post-move index out of it lands on the moved order — the
     * opposite of what Escape promises. Held here and applied by the layout
     * effect below, on the commit that finally carries the move.
     */
    const pendingRevert = useRef<any>(null);
    const prevTop = useRef<number | null>(null);
    useLayoutEffect(() => {
        const el = rootRef.current;
        if (!el) return;
        // The list has re-laid-out, so `settle` may act again.
        if (pendingRevert.current) {
            const { from, to } = pendingRevert.current;
            pendingRevert.current = null;
            // onMoveRef was rewritten during THIS render, so it now closes over
            // the moved array and the revert lands on the original order.
            onMoveRef.current(from, to);
        }
        if (session.current && session.current.awaiting) {
            session.current.awaiting = false;
            // Retry what the latch turned away: the pointer may already be still,
            // and then no further event would ever arrive to finish the move.
            // Terminates because settle stops as soon as the target index equals
            // the current one.
            if (session.current.retry) session.current.retry();
        }
        // Nothing is being dragged: return BEFORE touching offsetTop. Reading it
        // forces a synchronous layout, and this effect runs for every row on
        // every render — one keystroke in any field would otherwise cost one
        // forced layout per row, for an animation that cannot be playing.
        if (!dragState) { prevTop.current = null; return; }

        const top = el.offsetTop;
        const from = prevTop.current;
        prevTop.current = top;
        if (from === null || from === top || isDragged) return;

        const win = el.ownerDocument.defaultView;
        if (win.matchMedia && win.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        el.style.transition = 'none';
        el.style.transform = `translateY(${from - top}px)`;
        win.requestAnimationFrame(() => {
            el.style.transition = `transform ${SLIDE_MS}ms cubic-bezier(.2,.7,.3,1)`;
            el.style.transform = '';
        });
    });

    // A drag can outlive its component — the form drops the row — and then
    // `pointerup` never lands. Without this the rAF scroll loop
    // and the document listeners would keep running forever.
    useLayoutEffect(() => () => { if (session.current) session.current.cleanup(); }, []);

    /**
     * Where the held row belongs: count how many of the OTHER rows have their
     * midpoint above the dragged CARD's leading edge. That count IS the final
     * index — no before/after casework and no shift correction for dragging
     * downwards.
     *
     * The reference is the CARD, not the cursor. The grip sits at the top of a
     * row, so a cursor-based test is lopsided: dragging up, the cursor is
     * already next to the row above and swaps almost at once, while dragging
     * down it must first cross the whole height of the card it is carrying.
     * Measured on the card, both directions cost the same (verified: 131px down
     * vs 54px up before, 66/65 now).
     */
    const indexForY = (siblings: any[]): number => {
        const s = session.current;
        // The held index must address a real node, or the `continue` below skips
        // nothing and the item's own rect is counted against itself. Happens
        // when the array shrinks mid-drag (an item removed by other UI).
        if (dragState!.index >= siblings.length) return -1;
        const box = s.ghost ? s.ghost.node.getBoundingClientRect() : null;
        // Centre of the dragged card, pushed forward in the direction of travel.
        const ref = box ? box.top + box.height / 2 + s.dir * box.height * SWAP_BIAS : s.y;
        let to = 0;
        for (let i = 0; i < siblings.length; i++) {
            if (i === dragState!.index) continue;
            const r = siblings[i].getBoundingClientRect();
            if (ref > r.top + r.height / 2) to++;
        }
        return to;
    };

    /** Re-run the live reorder for the last known pointer position. Called on
     *  pointer movement AND on scroll — wheeling the list under a stationary
     *  cursor changes what the cursor is over just as much as moving it does. */
    const settle = () => {
        const s = session.current;
        if (!s || !s.started || !dragState || dragState.group !== s.key) return;
        // At most ONE move per render. `settle` fires from pointermove, from the
        // scroll listener and from the auto-scroll loop, and two of those can
        // land in the same tick — before React has re-laid-out the list and
        // before `onMoveRef` points at a callback built over the moved array.
        // The second call would then measure stale geometry and commit the
        // pre-move array, silently throwing the first move away.
        // ORDER MATTERS. The awaiting latch is checked FIRST: bailing out below
        // would already have marked this position as handled, so the move it
        // skipped could never be retried — the row would stop one slot short of
        // the cursor and sit there.
        if (s.awaiting) return;
        // Geometry can only have changed if the pointer moved vertically or the
        // list scrolled. Pointermove also fires for purely horizontal jitter,
        // and answering that with a DOM walk plus a rect read per row is the
        // most expensive way to compute "nothing happened".
        if (s.y === s.settledY && !s.scrolled) return;
        s.settledY = s.y;
        s.scrolled = false;
        // Walked ONCE per settle and reused for the announcement — settle runs
        // on every pointermove and every scrolled frame, so a second walk here
        // is a second pass over the whole list for nothing.
        const rows = s.siblings();
        const from = dragState.index;
        const to = indexForY(rows);
        if (to < 0 || to === from) return;
        s.awaiting = true;
        setDragState({ group: dragState.group, index: to });
        onMoveRef.current(from, to);
        // s.doc, NOT rootRef.current.ownerDocument: React detaches refs during
        // the commit, while the unmount cleanup that removes these listeners is
        // a passive effect that runs after paint. In that window a pointermove
        // still reaches settle with rootRef.current already null.
        announce(s.doc, positionText(to + 1, rows.length));
    };

    const onHandleDown = (e: any) => {
        // The lock is module-wide: a drag already in flight ANYWHERE wins, even
        // if it belongs to a different item or a different repeater.
        if (activeSession) return;
        // Left button only; touch/pen report button 0 too.
        if (e.button !== 0) return;
        // Keeps the press from selecting the label text as the pointer travels.
        e.preventDefault();

        const el = rootRef.current;
        const doc = el.ownerDocument;
        const win = doc.defaultView;
        const parent = el.parentElement;
        const myKey = groupKey(el);
        const originIndex = index;
        const pointerId = e.pointerId;
        const handle = e.currentTarget;
        // Resolved ONCE: `scrollParentOf` depends on the container still
        // overflowing, which can change mid-drag. Binding the rAF loop and the
        // scroll listener to two different answers would leave the loop
        // scrolling a container nobody is listening to.
        const scroller = scrollParentOf(el);

        // Capture keeps `pointerup` coming even when the release happens
        // outside the window — without it that release is simply never
        // delivered, and the drag (with its rAF loop and the body-wide
        // grabbing cursor) is stranded until something else tears it down.
        try { handle.setPointerCapture(pointerId); } catch { /* older engines */ }

        const s: any = {
            started: false,
            startY: e.clientY,
            y: e.clientY,
            // Last direction the pointer actually travelled; 1 (down) until it
            // moves, and held through a wheel scroll that moves no pointer.
            dir: 1,
            key: myKey,
            doc,
            awaiting: false,
            settledY: null,
            scrolled: false,
            retry: () => settle(),
            // Re-queried per move: the form re-renders during the drag, and
            // with keyed items React may hand us different nodes than it did
            // at pointerdown.
            siblings: () => {
                const out: any[] = [];
                const kids = parent ? parent.children : [];
                for (let i = 0; i < kids.length; i++) {
                    if (kids[i].getAttribute(GROUP_ATTR) === myKey) out.push(kids[i]);
                }
                return out;
            },
            cleanup: () => {},
        };

        const onScrolled = () => { s.scrolled = true; settle(); };
        const move = (ev: any) => {
            // A stray second finger/pen must not steer, or end, this drag.
            if (ev.pointerId !== pointerId) return;
            if (ev.clientY !== s.y) s.dir = ev.clientY > s.y ? 1 : -1;
            s.y = ev.clientY;
            if (!s.started) {
                // A press that never travels is a click, not a drag.
                if (Math.abs(ev.clientY - s.startY) < DRAG_THRESHOLD) return;
                s.started = true;
                setDragState({ group: myKey, index: originIndex });
                doc.body.classList.add(DRAGGING_BODY_CLASS);
                s.ghost = makeGhost(el, doc);
                // The band governs AUTO-SCROLL only; the clone itself is
                // unclamped. Measured once, not per frame: sticky chrome holds
                // its viewport position while the list scrolls under it, so
                // only a resize can move it.
                let band = travelBand(scroller, doc);
                const remeasure = () => { band = travelBand(scroller, doc); };
                win.addEventListener('resize', remeasure);
                s.stopBand = () => win.removeEventListener('resize', remeasure);
                s.stopScroll = startAutoScroll(scroller, () => s.y, onScrolled, () => band);
            }
            // The clone tracks the POINTER DELTA, not the row: it is fixed to
            // the viewport, so it stays under the cursor while the list scrolls
            // underneath it. X is locked — the list is one column, and letting
            // the card drift sideways only makes the target harder to read.
            // Offset right while held: at full size the clone sits exactly over
            // the column, hiding the landing slot and the rows on either side of
            // it — the very things you are aiming between. It slides back to 0 on
            // release, so the card visibly returns to the column.
            if (s.ghost) s.ghost.node.style.transform = `translate3d(${GHOST_NUDGE_X}px, ${ev.clientY - s.startY}px, 0)`;
            settle();
        };
        const finish = (revert: boolean) => {
            const rows = s.siblings();
            const landedAt = revert ? originIndex : (dragState ? dragState.index : originIndex);
            retireGhost(s.ghost, rows[Math.min(landedAt, rows.length - 1)]);
            s.ghost = null;
            if (s.started) {
                announce(doc, revert
                    /* translators: 1: row label, 2: its position */
                    ? wp.i18n.sprintf(__('%1$s: reorder cancelled, back at position %2$d', 'fabrement-lite'), label, originIndex + 1)
                    /* translators: 1: row label, 2: its position, 3: number of rows */
                    : wp.i18n.sprintf(__('%1$s: dropped at position %2$d of %3$d', 'fabrement-lite'), label, landedAt + 1, rows.length));
            }
            if (revert && dragState && dragState.index !== originIndex) {
                // Clamped: the list can be shorter than when we picked the item
                // up, and an out-of-range move is dropped, leaving the array
                // stuck in its mid-drag order after an Escape that promised
                // otherwise.
                const room = s.siblings().length;
                // Every live step moved the same single item, so one move back
                // to where it started restores the original order exactly.
                const from = dragState.index;
                const to = Math.min(originIndex, Math.max(0, room - 1));
                if (s.awaiting) pendingRevert.current = { from, to };
                else onMoveRef.current(from, to);
            }
            s.cleanup();
        };
        const onKey = (ev: any) => {
            if (ev.key !== 'Escape') return;
            ev.preventDefault();
            ev.stopPropagation();   // ...or an enclosing dialog closes too.
            finish(true);
        };

        s.cleanup = () => {
            doc.removeEventListener('pointermove', move);
            doc.removeEventListener('pointerup', up);
            doc.removeEventListener('pointercancel', cancel);
            doc.removeEventListener('keydown', onKey, true);
            win.removeEventListener('blur', bail);
            if (scroller) scroller.removeEventListener('scroll', onScrolled);
            if (s.stopScroll) s.stopScroll();
            if (s.stopBand) s.stopBand();
            // finish() normally retires it; this covers an unmount mid-drag.
            if (s.ghost) { retireGhost(s.ghost, null); s.ghost = null; }
            try { handle.releasePointerCapture(pointerId); } catch { /* already gone */ }
            doc.body.classList.remove(DRAGGING_BODY_CLASS);
            session.current = null;
            activeSession = null;
            if (dragState) setDragState(null);
        };
        const up = (ev: any) => { if (ev.pointerId === pointerId) finish(false); };
        const cancel = (ev: any) => { if (ev.pointerId === pointerId) finish(true); };
        // Last resort: the window losing focus mid-drag (alt-tab, a native
        // dialog) means no further pointer events are coming.
        const bail = () => finish(true);

        session.current = s;
        activeSession = s;
        win.addEventListener('blur', bail);
        doc.addEventListener('pointermove', move);
        doc.addEventListener('pointerup', up);
        doc.addEventListener('pointercancel', cancel);
        doc.addEventListener('keydown', onKey, true);
        // Wheel needs no handler of its own — this is a plain pointer drag, so
        // the browser scrolls normally. We only have to notice that it did.
        if (scroller) scroller.addEventListener('scroll', onScrolled);
    };

    // The dimmed slot follows the item as it moves, so it is keyed on the LIVE
    // position rather than on "this instance started the drag" — after the
    // first live move those are two different cards.
    const className = 'fabrement-fields__repeater-item'
        + (isDragged ? ' fabrement-fields__repeater-item--dragging' : '');

    const isFirst = index === 0;
    const isLast = index === count - 1;

    return (
        <div ref={rootRef} className={className}>
            <div className="fabrement-fields__repeater-header">
                <span className="fabrement-fields__repeater-label">
                    <span
                        className="fabrement-fields__drag-handle"
                        title={__('Drag to reorder', 'fabrement-lite')}
                        aria-hidden
                        onPointerDown={onHandleDown}
                    >
                        <GripIcon />
                    </span>
                    <span>{label}</span>
                </span>
                <span className="fabrement-fields__repeater-actions">
                    <button
                        type="button"
                        className="fabrement-fields__move-btn"
                        /* translators: %s: row label */
                        aria-label={wp.i18n.sprintf(__('Move "%s" up', 'fabrement-lite'), label)}
                        title={__('Move up', 'fabrement-lite')}
                        // `aria-disabled`, not `disabled`: a disabled button loses
                        // focus to <body> — press "up" repeatedly and focus would
                        // vanish on reaching the top.
                        aria-disabled={isFirst}
                        onClick={() => {
                            if (isFirst) return;
                            onMove(index, index - 1);
                            announce(rootRef.current.ownerDocument, positionText(index, count));
                        }}
                    >
                        <ChevronIcon up />
                    </button>
                    <button
                        type="button"
                        className="fabrement-fields__move-btn"
                        /* translators: %s: row label */
                        aria-label={wp.i18n.sprintf(__('Move "%s" down', 'fabrement-lite'), label)}
                        title={__('Move down', 'fabrement-lite')}
                        aria-disabled={isLast}
                        onClick={() => {
                            if (isLast) return;
                            onMove(index, index + 1);
                            announce(rootRef.current.ownerDocument, positionText(index + 2, count));
                        }}
                    >
                        <ChevronIcon />
                    </button>
                    {onRemove && (
                        <button
                            type="button"
                            className="fabrement-fields__remove-btn"
                            /* translators: %s: row label */
                            aria-label={wp.i18n.sprintf(__('Remove "%s"', 'fabrement-lite'), label)}
                            onClick={onRemove}
                        >
                            {__('Remove', 'fabrement-lite')}
                        </button>
                    )}
                </span>
            </div>
            <div className="fabrement-fields__repeater-body">{children}</div>
        </div>
    );
}

export function AddButton({ onClick, children, count, maxItems, disabled }: AddButtonProps) {

    // At/over the ceiling the button is disabled. When count or maxItems is omitted
    // (and `disabled` is not set), the button always adds.
    const atMax = typeof count === 'number' && typeof maxItems === 'number' && count >= maxItems;
    const isDisabled = !!disabled || atMax;
    return (
        <button
            type="button"
            className={'fabrement-fields__add-btn' + (isDisabled ? ' fabrement-fields__add-btn--disabled' : '')}
            onClick={isDisabled ? undefined : onClick}
            disabled={isDisabled}
            aria-disabled={isDisabled}
        >
            <span className="fabrement-fields__add-btn-icon">+</span>
            {children}
        </button>
    );
}

/**
 * Builds the `onMove` callback RepeaterItem expects: the item is spliced out and
 * back in without mutating the stored array.
 *
 * `list` is the CURRENT array value; `commit` writes the new one. No-op and
 * out-of-range moves are dropped here, so the caller never re-checks bounds.
 */
export function reorderItems<T>(list: T[], commit: (next: T[]) => void) {
    return (from: number, to: number) => {
        if (from === to) return;
        if (from < 0 || to < 0 || from >= list.length || to >= list.length) return;
        const next = list.slice();
        next.splice(to, 0, next.splice(from, 1)[0]);
        commit(next);
    };
}
