import { useEffect, useState } from 'react';
import { backdropCloseProps } from '@shared/components/backdrop-close';

/**
 * Full-screen modal that embeds a wp-admin Gutenberg editor in an iframe, so editing
 * never leaves the Templates screen.
 *
 * The caller supplies the heading and the line under it.
 */
export function EditorModal({
  title,
  hint,
  editorUrl,
  onClose,
}: {
  /** Heading of the modal, already naming what is being edited. */
  title: string;
  /** One line under the heading: what to do in there, and how to get out. */
  hint: string;
  editorUrl: string;
  onClose: () => void;
}) {
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  // `fullscreen=false` keeps WP's editor out of its own full-screen mode so it
  // sits cleanly inside the modal (the editor is otherwise shown as-is).
  const src = `${editorUrl}${editorUrl.includes('?') ? '&' : '?'}fullscreen=false`;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={title}
      className="fixed inset-0 z-[100000] flex flex-col bg-black/45 p-3 sm:p-6"
      {...backdropCloseProps(onClose)}
    >
      <div
        className="mx-auto flex h-full w-full max-w-[1680px] flex-col overflow-hidden rounded-[12px] bg-white shadow-[0_24px_48px_rgba(46,52,66,0.25)]"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex shrink-0 items-center justify-between gap-4 border-b border-[#eef2f6] px-4 py-2.5">
          <div className="min-w-0">
            <h2 className="m-0 truncate text-[15px] font-semibold text-[#2e3442]!">{title}</h2>
            <p className="m-0 text-[12px] text-[#8a94a6]!">{hint}</p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="h-8 shrink-0 cursor-pointer rounded-[6px] border border-[#d9e0e6] bg-white px-3 text-[13px] font-semibold text-[#2e3442]! transition-colors hover:bg-[#f2f6f9]"
          >
            {__( 'Close', 'fabrement-lite' )}
          </button>
        </div>
        <div className="relative flex-1">
          {!loaded && (
            <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-white">
              <span
                className="h-8 w-8 animate-spin rounded-full border-[3px] border-[#d9e0e6] border-t-[#2cc486]"
                aria-hidden
              />
              <span className="text-[13px] text-[#8a94a6]!">{__( 'Loading editor…', 'fabrement-lite' )}</span>
            </div>
          )}
          <iframe
            title={title}
            src={src}
            onLoad={() => setLoaded(true)}
            className="h-full w-full border-0"
          />
        </div>
      </div>
    </div>
  );
}
