import type { MouseEvent } from 'react';

/**
 * Whether the current press started on a backdrop. Module scope, NOT a ref, and
 * that is deliberate: these props are spread onto backdrops that render
 * conditionally (`{isOpen && <div {...backdropCloseProps(close)}>}`), so anything
 * hook-based here would be a conditional hook call and crash the tree with
 * "Rendered more hooks than during the previous render".
 *
 * A module-level flag is sound because it models something genuinely global and
 * momentary: there is one pointer, and the value only has to survive from a
 * mousedown to the mouseup that ends the same press.
 */
let pressedOnBackdrop = false;

/**
 * Props for a modal backdrop that closes only on a click that BEGAN and ENDED on
 * the backdrop itself.
 *
 * Why not `onClick`: a `click` event fires on the nearest common ancestor of the
 * mousedown and mouseup targets. Press inside the dialog, drag out, release over
 * the backdrop — and the common ancestor IS the backdrop, so its onClick runs and
 * the dialog closes. That is not a stray edge case: it happens every time someone
 * selects text and overshoots the panel, losing both the selection and the dialog.
 * `stopPropagation` on the panel cannot help, because the event never travels
 * through the panel — the backdrop is the target.
 *
 * Checking `e.target === e.currentTarget` inside onClick is not enough either, for
 * exactly the same reason: on that drag the target already IS the backdrop.
 *
 * So the press position has to be remembered: `onMouseDown` records whether the
 * press landed on the backdrop, `onMouseUp` closes only if it did AND the release
 * is on the backdrop too.
 *
 * Usage: `<div className="fixed inset-0 …" {...backdropCloseProps(onClose)}>`.
 *
 * @param enabled Pass false to suppress closing (e.g. while a save is in flight).
 */
export function backdropCloseProps(onClose: () => void, enabled = true): {
    onMouseDown: (e: MouseEvent<HTMLElement>) => void;
    onMouseUp: (e: MouseEvent<HTMLElement>) => void;
} {
    return {
        onMouseDown: (e) => {
            pressedOnBackdrop = e.target === e.currentTarget;
            // Disarm on the release WHEREVER it lands. Clearing it in `onMouseUp`
            // below is not enough, because that handler only runs for a release ON
            // the backdrop: a press here that ends elsewhere (off-window, over the
            // admin bar) would leave the flag armed. The next press could then miss
            // this handler entirely — the code editor's panel stops `mousedown` from
            // reaching the backdrop on purpose (code-editor-drawer.tsx) — so a later
            // release on the backdrop would read a stale `true` and close a dialog
            // holding unsaved edits.
            //
            // window, not the element: it is the one target every release reaches.
            // React's own onMouseUp still runs first — its listeners live on the root
            // container, which the event passes before window — so the flag is read
            // before this clears it.
            window.addEventListener('mouseup', () => { pressedOnBackdrop = false; }, {once: true});
        },
        onMouseUp: (e) => {
            const fromBackdrop = pressedOnBackdrop;
            pressedOnBackdrop = false;
            if (enabled && fromBackdrop && e.target === e.currentTarget) onClose();
        },
    };
}
