import { useEffect, useState } from 'react';

import { CustomSelect, type SelectOption } from '@shared/components/custom-select';
import { backdropCloseProps } from '@shared/components/backdrop-close';

import type { SiteTemplateRepository } from '@repository/site-templates';
import type {
  ContentSourceConfig,
  ContentSourceFile,
  SiteTemplateArchive,
  SiteTemplatePostTypes,
  SiteTemplateSummary,
} from '@repository/site-templates';

/**
 * The template's BODY — what its content slot shows — as a
 * full-width section of the template card, under the Archives toggles.
 *
 * ALWAYS VISIBLE, never behind a button: it answers the other half of the question
 * the toggles above it ask. Archives says WHICH views this template dresses, this
 * says WHAT fills them, and for an archive the two are inseparable — binding one
 * without giving it a body renders an empty middle. Hiding either behind a click
 * is how that pairing gets missed.
 *
 * The config arrives with the templates list, so the section paints with no request
 * of its own; `files` is fetched once per screen because it is identical for every
 * template.
 *
 * Where it is stored: the template's manifest file on the server, one per template and
 * shared by ALL of its language versions — so saving here rewrites the rules every
 * language renders, not just this record's. The server also mirrors them onto the
 * content-source block's attributes, which is what the renderer is handed.
 */
export function ContentSourceSection({
  template,
  repo,
  postTypes,
  archives,
  files,
  onSaved,
  open,
  onClose,
}: {
  template: SiteTemplateSummary;
  repo: SiteTemplateRepository;
  postTypes: SiteTemplatePostTypes;
  archives: SiteTemplateArchive[];
  files: ContentSourceFile[];
  onSaved: (message: string) => void;
  /**
   * The modal is shown. Opened by the card's button in the archives group; this component stays
   * mounted while closed, so unsaved rows are still there on reopen.
   */
  open: boolean;
  onClose: () => void;
}) {
  const [draft, setDraft] = useState<ContentSourceConfig | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  /**
   * What the SERVER confirmed on the last save, held until the list prop catches up.
   *
   * Without it, clearing `draft` on save drops straight back to `template.contentSource`
   * — a prop that is still pre-save until the parent's reload lands. The user watched
   * their new values flip back to the old ones for the length of that round-trip and
   * then flip forward again. Worse, the parent swallows a failed reload
   * (`void reload().catch(() => undefined)`), so a reload that never lands left the old
   * values on screen under a green "saved" notice, with nothing left to re-submit.
   *
   * This is also where SANITISATION becomes visible: the server drops rules with a
   * wrong-kind file or a duplicate context, and the
   * response is the only place that says so.
   */
  const [confirmed, setConfirmed] = useState<ContentSourceConfig | null>(null);

  // The prop is authoritative again as soon as it actually changes — the reload landed.
  useEffect(() => setConfirmed(null), [template.contentSource]);

  // Saved state comes from the list (or the last save's response); `draft` exists only
  // while there are unsaved edits, so a reload of the list is picked up instead of
  // being shadowed by stale local state.
  const saved: ContentSourceConfig = confirmed ??
    template.contentSource ?? { rules: [] };
  const config = draft ?? saved;
  const dirty = draft !== null;

  // Escape closes the modal — but not mid-save, and not while one of its select lists is open:
  // the list closes itself on that same Escape, and the modal should outlive it.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape' || busy) return;
      if (document.querySelector('.fabrement-bb-cs__trigger.is-open')) return;
      onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, busy, onClose]);

  // Every view a rule can target. A singular view exists for every public type — a record of any
  // of them can wear this template, so any of them can have a body of its own. Archives come from
  // listArchives(), which is fetched async, so they arrive after the singular ones.
  const views = [
    ...Object.keys(postTypes.available).map((slug) => ({
      value: `singular:${slug}`,
      /* translators: %s: post type label */
      label: wp.i18n.sprintf( __( '%s (single)', 'fabrement-lite' ), postTypes.available[slug] ?? slug ),
    })),
    ...archives.map((a) => ({ value: a.context, label: a.label })),
  ];

  /** The view shape a context needs. Everything that is not a single view is an archive. */
  const kindFor = (context: string) => (context.startsWith('singular:') ? 'singular' : 'archive');

  /**
   * The views one rule may target. One rule per view — a second row for the same view
   * would be dead, since the renderer takes the first match — so views already claimed
   * by another rule are hidden.
   *
   * A stored context that is NOT in `views` is surfaced rather than dropped. A rule
   * outlives the view it names when the CPT lost has_archive or the type stopped being
   * public — and the archives request can fail on its own. Without this the control falls
   * back to its placeholder and an existing rule reads as "nothing selected", which is
   * exactly how a user deletes a rule they never knew was there.
   */
  const viewOptions = (context: string): SelectOption[] => {
    const opts = views.filter(
      (v) => v.value === context || !config.rules.some((r) => r.context === v.value),
    );
    if (context !== '' && !opts.some((o) => o.value === context)) {
      /* translators: %s: the view a rule names, e.g. archive:product */
      opts.push({ value: context, label: wp.i18n.sprintf( __( '%s · unavailable', 'fabrement-lite' ), context ) });
    }
    return opts;
  };

  // Only a rule whose view still exists claims a slot. Counting an orphaned one would
  // block "+ Give a view its own layout" for a view that is actually free.
  const claimedViews = config.rules.filter((r) => views.some((v) => v.value === r.context)).length;

  /**
   * The theme files a rule may show, each labelled with the view it declares — a bare
   * filename gives no clue which view it was written for.
   *
   * FILTERED by kind: a body written for the other shape does not render "a bit
   * wrong", it renders an arbitrary post (singular body on an archive) or a blank
   * page with HTTP 200 (archive body on a singular). An UNDECLARED file — a theme
   * file with no `Fabrement:` header — stays offered everywhere, because we cannot
   * tell and the admin, who can read the file, can.
   */
  const fileOptions = (value: string, context: string): SelectOption[] => {
    /**
     * Mirrors RenderKindHeader::fits() on the server. A file may declare a bare KIND
     * ("archive"), which fits every view of that shape, or an exact VIEW
     * ("archive:product"), which fits only itself — naming the post type is a narrower
     * promise, so honour it. Undeclared fits everything: we cannot tell.
     * A rule whose view is not picked yet has nothing to match against either.
     */
    const fits = (f: ContentSourceFile) => {
      const d = f.declares ?? f.kind;
      if (!d || !context) return true;
      if (d === 'singular' || d === 'archive') return d === kindFor(context);
      return d === context;
    };
    const opts: SelectOption[] = files.filter(fits).map((f) => ({
      value: f.path,
      /* translators: 1: file label, 2: the view the file declares, e.g. archive */
      label: wp.i18n.sprintf( __( '%1$s · theme · %2$s', 'fabrement-lite' ), f.label, f.declares ?? f.kind ?? __( 'undeclared', 'fabrement-lite' ) ),
    }));
    // A rule may point at a file that no longer exists (a switched theme) — or at one this view filtered out. Surfacing it keeps the control
    // honest instead of silently falling back to the placeholder and reading as
    // "nothing selected". Checked against opts, not files, so a filtered-out file is
    // surfaced too.
    if (value !== '' && !opts.some((o) => o.value === value)) {
      const known = files.find((f) => f.path === value);
      opts.push({
        value,
        label: known
          ? /* translators: 1: file path, 2: the view the file declares */
            wp.i18n.sprintf( __( '%1$s · wrong view (%2$s)', 'fabrement-lite' ), value, known.declares ?? known.kind )
          : /* translators: %s: file path */
            wp.i18n.sprintf( __( '%s · missing', 'fabrement-lite' ), value ),
      });
    }
    return opts;
  };

  const save = async () => {
    if (draft === null) return;
    setBusy(true);
    setError(null);
    try {
      const res = await repo.saveContentSource(template.id, draft);
      // Adopt what the server STORED, not what we sent: it is the same config minus
      // anything sanitisation dropped, and it is current the instant the request
      // returns — no waiting on the list to reload.
      setConfirmed(res.contentSource);
      setDraft(null);
      /* translators: %s: template title */
      onSaved(wp.i18n.sprintf( __( 'Content settings saved for “%s”.', 'fabrement-lite' ), template.title ));
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : __( 'Failed to save.', 'fabrement-lite' ));
    } finally {
      setBusy(false);
    }
  };

  // CustomSelect brings its own trigger styling, so the wrapper only owns layout —
  // and dims the whole field while a save is in flight (the control has no
  // disabled prop of its own).
  const fieldClass = `min-w-[180px] flex-1 ${busy ? 'pointer-events-none opacity-50' : ''}`;

  const ghostButtonClass =
    'inline-flex h-9 items-center gap-1 rounded-[8px] border border-[#d9e0e6] bg-white px-3 text-[13px] font-semibold ' +
    'text-[#2e3442]! transition-colors hover:border-[#b9c4cd] hover:bg-[#f2f6f9] disabled:cursor-not-allowed disabled:opacity-50';

  // In a MODAL: the card keeps its height, and the rules get room of their own instead of a
  // strip at the bottom of a busy card.
  if (!open) return null;

  return (
    <>
      <div
        role="dialog"
        aria-modal="true"
        aria-label={
          /* translators: %s: template title */
          wp.i18n.sprintf( __( 'Layouts for "%s"', 'fabrement-lite' ), template.title )
        }
        className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/45 p-6"
        {...backdropCloseProps(onClose, !busy)}
      >
      {/* No overflow clipping: the select lists inside are absolute, and a clipped panel
          would cut them off. */}
      <div
        className="w-full max-w-[640px] rounded-[15px] bg-white p-6 shadow-[0_24px_48px_rgba(46,52,66,0.25)]"
        onClick={(e) => e.stopPropagation()}
      >
      {/* NOT "Content Part": `template part` is a WordPress term for a file in a block theme's
          `parts/` folder; this is a slot a theme PHP file fills. */}
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="m-0 text-[17px] font-semibold leading-tight text-[#2e3442]!">
            {/* translators: %s: template title */}
            {wp.i18n.sprintf( __( 'Content area · “%s”', 'fabrement-lite' ), template.title )}
          </h2>
          <p className="m-0 mt-1 text-[13px] text-[#667185]!">{__( 'What the Content Source block shows', 'fabrement-lite' )}</p>
        </div>
        <button
          type="button"
          disabled={busy}
          onClick={onClose}
          aria-label={__( 'Close', 'fabrement-lite' )}
          className="h-8 w-8 shrink-0 cursor-pointer rounded-[6px] border border-[#d9e0e6] bg-white text-[15px] font-semibold text-[#667185]! transition-colors hover:bg-[#f2f6f9] disabled:opacity-50"
        >
          ×
        </button>
      </div>

      {error !== null && (
        <p className="m-0 mt-2 rounded-[8px] border border-[#f3c1c1] bg-[#fdf1f1] px-3 py-2 text-[12px] text-[#b23b3b]!">
          {error}
        </p>
      )}

      {/* Two facts, and only the two a reader cannot get from the controls: WHEN this section is
          needed at all, and that a layout built just now is missing until the page is fetched
          again. The rest — what the dropdown holds, what a layout looks like — the dropdown
          itself shows the moment it is opened. */}
      <p className="m-0 mt-3 text-[12px] leading-relaxed text-[#667185]!">
        {__( 'You can choose a theme PHP file for list pages, like the blog and other custom post types, and for their single pages. Without one, a list page shows nothing in the content slot, and a single page shows its own content.', 'fabrement-lite' )}
      </p>
      <p className="m-0 mt-1 text-[12px] leading-relaxed text-[#a7b3bd]!">
        {__( 'Files come from the templates folder of the active theme; a file added just now appears after you reload this page.', 'fabrement-lite' )}
      </p>

      {/* "For" heads the rules instead of sitting in a column beside the first of them. As a
          column it was printed on row one and blank on every row after, so a 92px strip of
          nothing ran down the left of the list and pushed the selects — and the add button
          under them — out of the section's own width. */}
      {config.rules.length > 0 && (
        <p className="m-0 mt-3 text-[12px] font-semibold text-[#667185]!">{__( 'For', 'fabrement-lite' )}</p>
      )}

      {config.rules.map((rule, i) => (
        <div key={`rule-${i}`} className="mt-2 flex flex-wrap items-center gap-2">
          <div className={fieldClass}>
            <CustomSelect
              value={rule.context}
              ariaLabel={__( 'Which view this layout is for', 'fabrement-lite' )}
              placeholder={__( 'Which view?', 'fabrement-lite' )}
              options={viewOptions(rule.context)}
              onChange={(v) =>
                setDraft({
                  ...config,
                  rules: config.rules.map((r, n) => (n === i ? { ...r, context: v } : r)),
                })
              }
            />
          </div>
          <div className={fieldClass}>
            <CustomSelect
              value={rule.file}
              ariaLabel={__( 'The layout to show', 'fabrement-lite' )}
              placeholder={__( 'Show this layout', 'fabrement-lite' )}
              options={fileOptions(rule.file, rule.context)}
              onChange={(v) =>
                setDraft({ ...config, rules: config.rules.map((r, n) => (n === i ? { ...r, file: v } : r)) })
              }
            />
          </div>
          <button
            type="button"
            disabled={busy}
            aria-label={__( 'Remove rule', 'fabrement-lite' )}
            onClick={() => setDraft({ ...config, rules: config.rules.filter((_, n) => n !== i) })}
            className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-[8px] border border-[#d9e0e6] bg-white text-[16px] leading-none text-[#667185]! transition-colors hover:border-[#f3c1c1] hover:bg-[#fdf1f1] hover:text-[#b23b3b]! disabled:opacity-50"
          >
            ×
          </button>
        </div>
      ))}

      {/* ADD A ROW, drawn as the next row rather than as a sentence beside Save — so the empty
          row reads as where a new one will appear. Full width, not the selects' width: it is
          the whole section's action, not a third column of the rule above it. */}
      <button
        type="button"
        disabled={busy || claimedViews >= views.length}
        onClick={() => setDraft({ ...config, rules: [...config.rules, { context: '', file: '' }] })}
        aria-label={__( 'Add a layout for another view', 'fabrement-lite' )}
        title={
          claimedViews >= views.length
            ? __( 'Every view already has a layout — one rule per view.', 'fabrement-lite' )
            : __( 'Add a layout for another view.', 'fabrement-lite' )
        }
        className="mt-2 flex h-9 w-full cursor-pointer items-center justify-center rounded-[8px] border border-[#e3e9ee] bg-[#f6f9fc] text-[16px] leading-none font-semibold text-[#667185]! transition-colors hover:border-[#d9e0e6] hover:bg-[#eef3f8] disabled:cursor-not-allowed disabled:opacity-50"
      >
        +
      </button>

      {/* Only rendered when there is something to save — an always-present row would leave a
          button-high gap under the add row for the whole time nothing has changed. Its right
          edge is the section's, the same one the add button now reaches. */}
      {dirty && (
        <div className="mt-3 flex flex-wrap items-center justify-end gap-2">
          <button
            type="button"
            disabled={busy}
            onClick={() => void save()}
            className="inline-flex h-9 items-center rounded-[8px] border border-[#2cc486] bg-[#2cc486] px-4 text-[13px] font-semibold text-white transition-colors hover:bg-[#25a973] disabled:opacity-50"
          >
            {busy ? __( 'Saving…', 'fabrement-lite' ) : __( 'Save changes', 'fabrement-lite' )}
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => {
              setDraft(null);
              setError(null);
            }}
            className={ghostButtonClass}
          >
            {__( 'Cancel', 'fabrement-lite' )}
          </button>
        </div>
      )}
      </div>
      </div>
    </>
  );
}
