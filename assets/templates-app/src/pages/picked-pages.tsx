import { useEffect, useRef, useState } from 'react';

import type { SiteTemplatePage, SiteTemplateRepository } from '@repository/site-templates';

/** Titles shown before the rest collapse into a "+N more". */
const SHOWN = 12;

/**
 * The pages that picked ONE template, by name — under "Individual pages using this template".
 *
 * WHY IT FETCHES. The templates list carries a COUNT and nothing else, and "2 pages" answers
 * the wrong question: the reason to look at this group is to find out WHICH pages escaped the
 * defaults above, and the only way to learn that was to open the picker modal and read its
 * checkboxes.
 *
 * The call is the picker's own — `listPages` returns every page currently picking the slug,
 * whatever the search window — so the titles here and the ticks in the modal can never
 * disagree. `limit: 1` keeps the window that rides along as small as the server allows;
 * the picks themselves are not subject to it.
 *
 * ONLY WHEN THERE IS SOMETHING TO FETCH. A template with no picks — most of them, most of the
 * time — renders its empty line and makes no request at all, so opening this screen does not
 * cost one round-trip per card.
 */
export function PickedPages({
  repo,
  slug,
  pageCount,
  notFound,
  pickVersion,
}: {
  repo: SiteTemplateRepository;
  slug: string;
  /** From the templates list; also the signal that the set may have changed. */
  pageCount: number;
  /**
   * The not-found view picked this template. It is NOT a page — it has no record, so it is
   * absent from `listPages` and from `pageCount` — but it is ticked in the same modal as the
   * pages, so leaving it out of this list made a save look like it had not happened.
   */
  notFound: boolean;
  /** Bumped by the screen after every picker save, so a SWAP — one page out, another in,
   *  count unchanged — still refreshes this list. */
  pickVersion: number;
}) {
  const [pages, setPages] = useState<SiteTemplatePage[] | null>(null);
  const [failed, setFailed] = useState(false);

  // Only the latest fetch may write. Two reloads in quick succession (a picker save while an
  // earlier request is still open) would otherwise settle in whatever order they finish in.
  const seq = useRef(0);

  useEffect(() => {
    if (pageCount === 0) {
      setPages([]);
      setFailed(false);
      return;
    }
    const mine = ++seq.current;
    setFailed(false);
    repo
      .listPages({ limit: 1, templateSlug: slug })
      .then((res) => {
        if (mine !== seq.current) return;
        setPages(res.pages.filter((p) => p.template === slug));
      })
      .catch(() => {
        if (mine !== seq.current) return;
        // Fall back to the count rather than to nothing: a failed request must not read as
        // "no pages use this template", which is the opposite of what it means.
        setFailed(true);
      });
  }, [repo, slug, pageCount, pickVersion]);

  // The not-found chip is rendered from a prop and needs no request, so it shows even while
  // the pages are still loading — and on a template whose ONLY pick is the 404 view, nothing
  // is fetched at all.
  const notFoundChip = notFound ? (
    <span
      key="not-found"
      title={__( 'Every address that does not exist renders in this template.', 'fabrement-lite' )}
      className="inline-flex max-w-full items-center gap-1.5 rounded-[6px] bg-[#f6f9fc] px-2 py-1 text-[12px] text-[#2e3442]"
    >
      <span className="truncate">{__( 'Not found (404)', 'fabrement-lite' )}</span>
      <span className="shrink-0 text-[11px] text-[#8a94a6]">{__( 'special view', 'fabrement-lite' )}</span>
    </span>
  ) : null;

  if (pageCount === 0 && !notFound) {
    return <span className="text-[13px] text-[#a7b3bd]!">{__( 'Nothing picked by hand yet.', 'fabrement-lite' )}</span>;
  }

  const trailing = failed ? (
    <span className="text-[13px] text-[#667185]!">
      {/* translators: %d: number of pages */}
      {wp.i18n.sprintf( _n( '%d page picked by hand — its name could not be loaded.', '%d pages picked by hand — their names could not be loaded.', pageCount, 'fabrement-lite' ), pageCount )}
    </span>
  ) : pages === null ? (
    <span className="text-[13px] text-[#a7b3bd]!">{__( 'Loading pages…', 'fabrement-lite' )}</span>
  ) : null;

  const shown = pages === null || failed ? [] : pages.slice(0, SHOWN);
  const rest = pages === null || failed ? 0 : pages.length - shown.length;

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {notFoundChip}
      {shown.map((page) => (
        <span
          key={page.id}
          title={page.typeLabel !== undefined ? `${page.title} · ${page.typeLabel}` : page.title}
          className="inline-flex max-w-full items-center gap-1.5 rounded-[6px] bg-[#f6f9fc] px-2 py-1 text-[12px] text-[#2e3442]"
        >
          <span className="truncate">{page.title}</span>
          {/* The list mixes types — a page, a post and a service may all be called "About" —
              so the type rides along exactly as it does on the picker's rows. */}
          {page.typeLabel !== undefined && <span className="shrink-0 text-[11px] text-[#8a94a6]">{page.typeLabel}</span>}
          {page.isFront && <span className="shrink-0 text-[11px] text-[#8a94a6]">· {__( 'front', 'fabrement-lite' )}</span>}
        </span>
      ))}
      {rest > 0 && <span className="text-[12px] text-[#8a94a6]!">
          {/* translators: %d: number of pages not shown */}
          {wp.i18n.sprintf( __( '+%d more', 'fabrement-lite' ), rest )}
        </span>}
      {trailing}
    </div>
  );
}
