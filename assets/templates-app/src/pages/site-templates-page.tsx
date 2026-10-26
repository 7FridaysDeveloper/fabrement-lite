import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { backdropCloseProps } from '@shared/components/backdrop-close';
import { ContentSourceSection } from './content-source-section';
import { PickedPages } from './picked-pages';
import { postEditorUrl } from './admin-urls';
import { EditorModal } from './editor-modal';
import { listed, screenMessage } from './messages';
import {
  SiteTemplateRepository,
  type SiteTemplatePage,
  type SiteTemplatePostTypes,
  type SiteTemplateArchive,
  type SiteTemplateSummary,
  type SiteTemplateVersion,
  type ContentWidth,
  type SiteLanguage,
  type ContentSourceFile,
} from '@repository/site-templates';

/**
 * The not-found view's key in the defaults map — not a post type, but chosen the same way
 * ("Default for"). Mirrors SiteTemplatePostType::NOT_FOUND on the PHP side.
 */
const NOT_FOUND_DEFAULT = '404';

/**
 * The Templates screen: named block frames around the page content. Each ready template shows
 * up in the native WordPress template pickers, and can be the default for a post type or archive.
 * A template is only its blocks: whatever sits above and below the content is placed in the editor.
 */
export const SiteTemplatesPage = () => {
  const templatesRepo = useMemo(() => new SiteTemplateRepository(), []);
  const [templates, setTemplates] = useState<SiteTemplateSummary[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  /** null = list view; a string = the name being typed for a new template. */
  const [draft, setDraft] = useState<string | null>(null);
  /** The width picked in the new-template form. */
  const [draftWidth, setDraftWidth] = useState<ContentWidth>('full');
  /** Per-template page picker modal; null = closed. */
  const [picker, setPicker] = useState<PickerState | null>(null);
  /** Template id whose fields are being edited in the iframe modal; null = closed. */
  const [editorId, setEditorId] = useState<number | null>(null);
  /** Files any template's content slot may point at — identical for all of them, so fetched once. */
  const [contentFiles, setContentFiles] = useState<ContentSourceFile[]>([]);
  /** Which post types templates apply to + the pool to pick from. */
  const [postTypes, setPostTypes] = useState<SiteTemplatePostTypes>({ available: {} });
  // Empty unless a translation plugin reports more than one language — the screen then shows no
  // language control anywhere, and an ordinary site looks exactly as it did.
  const [languages, setLanguages] = useState<SiteLanguage[]>([]);
  // A picker save can swap one page for another and leave pageCount identical, so the count
  // alone is not enough to tell a card its list went stale.
  const [pickVersion, setPickVersion] = useState(0);

  /** The archive views a template can dress, each with its current binding. */
  const [archives, setArchives] = useState<SiteTemplateArchive[]>([]);

  const reload = useCallback(async () => {
    // The templates ARE the screen, so their failure propagates. The two site-wide lists are
    // supporting detail: they set their own state and swallow their own failure.
    const [list] = await Promise.all([
      templatesRepo.list(),
      templatesRepo
        .listArchives()
        .then((res) => setArchives(res.archives))
        .catch(() => setArchives([])),
      templatesRepo
        .listContentSourceFiles()
        .then((res) => setContentFiles(res.files))
        .catch(() => setContentFiles([])),
    ]);
    setTemplates(list.templates);
    setPostTypes(list.postTypes);
    setLanguages(list.languages ?? []);
  }, [templatesRepo]);

  useEffect(() => {
    let cancelled = false;
    reload()
      .catch((e: unknown) => {
        if (!cancelled) setLoadError(e instanceof Error ? e.message : __( 'Failed to load templates.', 'fabrement-lite' ));
      })
      .finally(() => {
        if (!cancelled) setLoaded(true);
      });
    return () => {
      cancelled = true;
    };
  }, [reload]);

  const run = useCallback(
    async (action: () => Promise<unknown>) => {
      setBusy(true);
      setActionError(null);
      setNotice(null);
      try {
        await action();
        await reload();
        return true;
      } catch (e: unknown) {
        setActionError(screenMessage(e, __( 'The request failed.', 'fabrement-lite' )));
        return false;
      } finally {
        setBusy(false);
      }
    },
    [reload],
  );

  const createTemplate = async (title: string) => {
    if (!title.trim()) return;
    let savedId = 0;
    const ok = await run(async () => {
      savedId = (await templatesRepo.save({ title: title.trim(), contentWidth: draftWidth })).template.id;
    });
    if (!ok) return;
    setDraft(null);
    // A new template is a lone content slot: open it in the editor to place blocks around it.
    if (savedId > 0) setEditorId(savedId);
  };

  const deleteTemplate = async (t: SiteTemplateSummary) => {
    /* translators: %s: template title */
    const question = wp.i18n.sprintf( __( 'Delete template "%s"?', 'fabrement-lite' ), t.title );
    const usage =
      t.pageCount > 0
        ? /* translators: %d: number of pages */
          wp.i18n.sprintf( _n( '%d page picked it explicitly — it will go back to the default template.', '%d pages picked it explicitly — they will go back to the default template.', t.pageCount, 'fabrement-lite' ), t.pageCount )
        : __( 'No page picked it explicitly.', 'fabrement-lite' );
    // Its language versions go with it.
    const versions = Object.keys(t.versions ?? {}).map((code) => languages.find((l) => l.code === code)?.label ?? code);
    const others =
      versions.length > 0
        ? /* translators: %s: language name, or a list of them */
          wp.i18n.sprintf( _n( 'Its %s version is deleted too.', 'Its %s versions are deleted too.', versions.length, 'fabrement-lite' ), listed(versions) )
        : '';
    // eslint-disable-next-line no-alert -- destructive action needs a confirm; matches wp-admin conventions.
    if (!window.confirm([question, others, usage].filter((part) => part !== '').join(' '))) return;
    let cleared = 0;
    const ok = await run(async () => {
      cleared = (await templatesRepo.remove(t.id)).clearedPages;
    });
    if (ok && cleared > 0) {
      setNotice(
        /* translators: %d: number of pages */
        wp.i18n.sprintf( _n( 'Template deleted; %d page reset to the default template.', 'Template deleted; %d pages reset to the default template.', cleared, 'fabrement-lite' ), cleared ),
      );
    }
  };

  // One language's version goes alone — the original and the other languages stay, the way
  // deleting a translated page leaves the rest of the group be.
  const deleteVersion = async (t: SiteTemplateSummary, versionId: number, label: string) => {
    /* translators: 1: language name, 2: template title */
    const question = wp.i18n.sprintf( __( 'Delete the %1$s version of "%2$s"? Readers in %1$s will see the original instead.', 'fabrement-lite' ), label, t.title );
    // eslint-disable-next-line no-alert -- destructive action needs a confirm; matches wp-admin conventions.
    if (!window.confirm(question)) return;
    const ok = await run(() => templatesRepo.remove(versionId));
    /* translators: 1: language name, 2: template title */
    if (ok) setNotice(wp.i18n.sprintf( __( '%1$s version of "%2$s" deleted.', 'fabrement-lite' ), label, t.title ));
  };

  // Monotonic id for the picker's page fetches (open + every search). Only the
  // latest request may write results, so a slow earlier response can't clobber
  // the list with stale rows (out-of-order resolution race).
  const pickerFetchSeq = useRef(0);

  const openPicker = (t: SiteTemplateSummary) => {
    const seq = ++pickerFetchSeq.current;
    const notFound = t.defaultFor.includes(NOT_FOUND_DEFAULT);
    setPicker({ template: t, phase: 'loading', pages: [], selected: new Set(), notFoundWas: notFound, notFoundNow: notFound });
    // Bounded server-side fetch (not all pages): the first PAGE_LIMIT rows plus
    // every page already picking this template (templateSlug) so the checkboxes
    // start correct. Typing narrows the list through searchPickerPages().
    templatesRepo
      .listPages({ limit: PAGE_LIMIT, templateSlug: t.slug })
      .then((res) => {
        if (seq !== pickerFetchSeq.current) return; // superseded by a later search
        setPicker((p) =>
          p && p.template.id === t.id
            ? {
                ...p,
                phase: 'ready',
                pages: res.pages,
                selected: new Set(res.pages.filter((pg) => pg.template === t.slug).map((pg) => pg.id)),
              }
            : p,
        );
      })
      .catch((e: unknown) => {
        if (seq !== pickerFetchSeq.current) return;
        setPicker((p) => (p && p.template.id === t.id ? { ...p, phase: 'error', error: e instanceof Error ? e.message : __( 'Failed to load pages.', 'fabrement-lite' ) } : p));
      });
  };

  // Latest picked template, read by the stable searchPickerPages callback so the
  // modal's debounced search doesn't churn a new closure every render.
  const pickerTemplateRef = useRef<SiteTemplateSummary | null>(null);
  pickerTemplateRef.current = picker?.template ?? null;

  const searchPickerPages = useCallback(
    (search: string) => {
      const t = pickerTemplateRef.current;
      if (!t) return;
      const seq = ++pickerFetchSeq.current;
      setPicker((p) => (p && p.template.id === t.id ? { ...p, searching: true } : p));
      templatesRepo
        .listPages({ limit: PAGE_LIMIT, templateSlug: t.slug, search })
        .then((res) => {
          if (seq !== pickerFetchSeq.current) return; // a newer keystroke won
          setPicker((p) => (p && p.template.id === t.id ? { ...p, phase: 'ready', searching: false, pages: res.pages } : p));
        })
        .catch(() => {
          if (seq !== pickerFetchSeq.current) return;
          // Keep the current rows; a failed keystroke shouldn't blank the picker.
          setPicker((p) => (p && p.template.id === t.id ? { ...p, searching: false } : p));
        });
    },
    [templatesRepo],
  );

  const savePicker = async (p: PickerState) => {
    let assigned = 0;
    let cleared = 0;
    const notFoundChanged = p.notFoundNow !== p.notFoundWas;
    const ok = await run(async () => {
      const res = await templatesRepo.assignPages(p.template.id, [...p.selected]);
      assigned = res.assigned;
      cleared = res.cleared;
      // A SECOND store, written only when its checkbox moved. Sent after the pages so a
      // failure here cannot leave the picks unsaved, and skipped entirely when untouched —
      // re-sending the current value would clear the view off whichever template holds it.
      if (notFoundChanged) {
        await templatesRepo.setDefault(NOT_FOUND_DEFAULT, p.notFoundNow ? p.template.id : 0);
      }
    });
    if (ok) {
      setPicker(null);
      setPickVersion((v) => v + 1);
      const parts = [
        /* translators: 1: template title, 2: number of pages */
        wp.i18n.sprintf( _n( '"%1$s": %2$d page assigned.', '"%1$s": %2$d pages assigned.', assigned, 'fabrement-lite' ), p.template.title, assigned ),
      ];
      if (cleared > 0) {
        /* translators: %d: number of pages */
        parts.push(wp.i18n.sprintf( _n( '%d page reset to the default template.', '%d pages reset to the default template.', cleared, 'fabrement-lite' ), cleared ));
      }
      if (notFoundChanged) {
        parts.push(
          p.notFoundNow
            ? __( 'The not-found (404) view now uses it.', 'fabrement-lite' )
            : __( 'The not-found (404) view no longer uses it.', 'fabrement-lite' ),
        );
      }
      setNotice(parts.join(' '));
    }
  };

  const toggleDefaultFor = async (t: SiteTemplateSummary, postType: string) => {
    // Radio semantics per post type across templates: turning it on for a type
    // re-points that type's default here (clearing it from any other template);
    // turning it off leaves the type with no default.
    const turningOff = t.defaultFor.includes(postType);
    const label = postTypes.available[postType] ?? postType;
    const ok = await run(() => templatesRepo.setDefault(postType, turningOff ? 0 : t.id));
    if (ok && !turningOff && postType === NOT_FOUND_DEFAULT) {
      setNotice(
        /* translators: %s: template title */
        wp.i18n.sprintf( __( '"%s" is now the frame of every not-found (404) address. Its content comes from a "404" content rule, or else from the page chosen under Settings → Reading → Page not found (404).', 'fabrement-lite' ), t.title ),
      );
    } else if (ok && !turningOff) {
      setNotice(
        /* translators: 1: template title, 2: post type label */
        wp.i18n.sprintf( __( '"%1$s" is now the default template for %2$s: every item of that type without its own template renders with it. Items with an explicit template (editor, Elementor, theme) are not affected.', 'fabrement-lite' ), t.title, label ),
      );
    }
  };

  const toggleArchiveFor = async (t: SiteTemplateSummary, context: string) => {
    // Radio semantics per VIEW (not per template): an archive has exactly one
    // template, so binding overwrites whoever held it; clicking the view that is
    // already bound here unbinds it and the theme takes the archive back.
    const current = archives.find((a) => a.context === context);
    const turningOff = current?.templateId === t.id;
    const ok = await run(async () => {
      const res = await templatesRepo.setArchive(context, turningOff ? 0 : t.id);
      setArchives(res.archives);
    });
    if (ok && !turningOff) {
      setNotice(
        /* translators: 1: template title, 2: archive label */
        wp.i18n.sprintf( __( '"%1$s" now wraps %2$s. Its body still comes from the template’s Content Source rule for this view — add one there if the archive looks empty.', 'fabrement-lite' ), t.title, current?.label ?? context ),
      );
    }
  };

  return (
    // No heading of its own: the wp-admin page around it carries the screen's title.
    <div className="relative">
      <div className="flex w-full flex-col gap-4">
        {loadError !== null && (
          <p className="m-0 rounded-[8px] border border-[#f3c1c1] bg-[#fdf1f1] px-4 py-3 text-sm text-[#b23b3b]!">{loadError}</p>
        )}
        {actionError !== null && (
          <p className="m-0 rounded-[8px] border border-[#f3c1c1] bg-[#fdf1f1] px-4 py-3 text-sm text-[#b23b3b]!">{actionError}</p>
        )}
        {notice !== null && (
          <p className="m-0 rounded-[8px] border border-[#bfe8d6] bg-[#e7f8f0] px-4 py-3 text-sm text-[#21865d]!">{notice}</p>
        )}

        {draft !== null ? (
          <form
            className="flex max-w-[560px] flex-col gap-3 rounded-[15px] border border-[#d9e0e6] bg-white p-5"
            onSubmit={(e) => {
              e.preventDefault();
              void createTemplate(draft);
            }}
          >
            <label className="flex flex-col gap-1.5">
              <span className="text-[13px] font-semibold text-[#2e3442]">{__( 'Template name', 'fabrement-lite' )}</span>
              <input
                type="text"
                // eslint-disable-next-line jsx-a11y/no-autofocus -- the form only exists because the user just asked for it.
                autoFocus
                value={draft}
                disabled={busy}
                onChange={(e) => setDraft(e.target.value)}
                className="h-10 rounded-[6px] border border-[#d9e0e6]! bg-white! px-3 text-sm text-[#2e3442]! shadow-none! outline-none! focus:border-[#8a99a6]!"
              />
            </label>
            <WidthChoice value={draftWidth} disabled={busy} onChange={setDraftWidth} />
            <p className="m-0 text-[13px] text-[#667185]!">
              {__( 'The template opens in the editor with a Content Source block. Add the blocks or synced patterns that go above and below the page content there, at the top level of the template.', 'fabrement-lite' )}
            </p>
            <div className="flex items-center gap-2">
              <button
                type="submit"
                disabled={busy || draft.trim() === ''}
                className="flex h-10 cursor-pointer items-center rounded-[6px] bg-[#2cc486] px-4 text-sm font-semibold text-white! transition-colors hover:bg-[#21865d] disabled:opacity-60"
              >
                {__( 'Create template', 'fabrement-lite' )}
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => setDraft(null)}
                className="flex h-10 cursor-pointer items-center rounded-[6px] border border-[#d9e0e6] bg-white px-4 text-sm font-semibold text-[#2e3442]! transition-colors hover:bg-[#f2f6f9]"
              >
                {__( 'Cancel', 'fabrement-lite' )}
              </button>
            </div>
          </form>
        ) : (
          <>
            <div className="flex items-center justify-between">
              <span className="text-[13px] text-[#667185]">
                {templates.length > 0
                  ? /* translators: %d: number of templates */
                    wp.i18n.sprintf( _n( '%d template', '%d templates', templates.length, 'fabrement-lite' ), templates.length )
                  : loaded
                    ? __( 'No templates yet', 'fabrement-lite' )
                    : __( 'Loading…', 'fabrement-lite' )}
              </span>
              <button
                type="button"
                disabled={busy}
                onClick={() => {
                  setDraftWidth('full');
                  setDraft('');
                }}
                className="flex h-10 cursor-pointer items-center gap-2 rounded-[6px] bg-[#2cc486] px-4 text-sm font-semibold text-white! transition-colors hover:bg-[#21865d] disabled:opacity-60"
              >
                {__( '+ New template', 'fabrement-lite' )}
              </button>
            </div>

            {/* TWO CARDS PER ROW on a wide screen. A card is a self-contained panel, and a
                column of them down a full-width screen left most of that width unused while
                the second template sat below the fold. */}
            {/* CONTAINER queries, not viewport ones. A media query measures the WINDOW, and the
                grid never gets the window: wp-admin's menu takes ~160px off it, or ~36px when
                the user folds it — so a 1600px laptop leaves the cards about 1440px and a
                `min-[1480px]` rule that looks right on paper never fires. Folding the menu
                would move the answer again, mid-session. Measuring the grid itself is immune
                to both: the numbers below are card widths, which is what actually decides
                whether three of them fit. */}
            <div className="@container">
              <div className="grid grid-cols-1 gap-8 @min-[1120px]:grid-cols-2 @min-[1320px]:grid-cols-3">
                {templates.map((t) => (
                  <TemplateCard
                    key={t.id}
                    template={t}
                    postTypes={postTypes}
                    busy={busy}
                    languages={languages}
                    onDeleteVersion={(v, label) => void deleteVersion(t, v.id, label)}
                    onEditFields={(language) => {
                      // No language: the template's own record. A language: that language's version
                      // — a record of its own, created as a full copy of this one the first time it
                      // is asked for, and edited like any template after that.
                      if (!language) {
                        setEditorId(t.id);
                        return;
                      }
                      // The translation plugin's editor owns the words: anything typed into our
                      // editor would be rebuilt from the original on its next run, so go where the
                      // translation is really made.
                      if (t.translationScreenUrl) {
                        window.open(t.translationScreenUrl, '_blank', 'noopener');
                        return;
                      }
                      void run(async () => {
                        const res = await templatesRepo.translate(t.id, language);
                        setEditorId(res.templateId);
                        // No reload here: run() already reloads after the action, and a second one
                        // would repaint the whole screen — four REST calls — for nothing.
                      });
                    }}
                    onDelete={() => void deleteTemplate(t)}
                    // The name alone: a save without a width keeps the stored one.
                    onRename={(title) => void run(() => templatesRepo.save({ id: t.id, title }))}
                    onToggleDefaultFor={(pt) => void toggleDefaultFor(t, pt)}
                    archives={archives}
                    onToggleArchiveFor={(ctx) => void toggleArchiveFor(t, ctx)}
                    templatesRepo={templatesRepo}
                    contentFiles={contentFiles}
                    onContentSaved={(m) => {
                      setNotice(m);
                      // The saved config lives on the template summary, so re-read the list.
                      void reload().catch(() => undefined);
                    }}
                    onPickPages={() => openPicker(t)}
                    pickVersion={pickVersion}
                  />
                ))}
              </div>
            </div>
          </>
        )}
      </div>

      {picker !== null && (
        <PagePickerModal
          picker={picker}
          templates={templates}
          busy={busy}
          onChange={setPicker}
          onSearch={searchPickerPages}
          onSave={() => void savePicker(picker)}
          onClose={() => setPicker(null)}
        />
      )}

      {editorId !== null && (
        <EditorModal
          // The id can be a TRANSLATION's, and `templates` holds originals only — so looking it
          // up there always misses and the modal is headed with the literal word "Template".
          // Worse, a translation deliberately carries the original's title, so two languages of
          // the same template are indistinguishable on screen: nothing tells the admin which one
          // they are typing into. Fall back to the template that owns this language version, and
          // say which language it is.
          title={
            templates.find((t) => t.id === editorId)?.title ??
            (() => {
              for (const t of templates) {
                const code = Object.entries(t.translations ?? {}).find(([, id]) => id === editorId)?.[0];
                if (code) {
                  /* translators: 1: template title, 2: language name */
                  return wp.i18n.sprintf( __( '%1$s — %2$s', 'fabrement-lite' ), t.title, languages.find((l) => l.code === code)?.label ?? code );
                }
              }
              return __( 'Template', 'fabrement-lite' );
            })()
          }
          hint={__( 'Place blocks or synced patterns around the Content Source block, then Update. Close when done.', 'fabrement-lite' )}
          editorUrl={postEditorUrl(editorId)}
          onClose={() => setEditorId(null)}
        />
      )}
    </div>
  );
};

/** The two widths a template can have, as the new-template form names them. Built on render, when translations are loaded. */
const widths = (): { value: ContentWidth; label: string; hint: string }[] => [
  {
    value: 'full',
    label: __( 'Full width', 'fabrement-lite' ),
    hint: __( 'For section blocks that hold their own inner column: they reach the edges and stack with no gap.', 'fabrement-lite' ),
  },
  {
    value: 'constrained',
    label: __( 'Content width', 'fabrement-lite' ),
    hint: __( 'For text and simple blocks: the theme\'s content column and spacing.', 'fabrement-lite' ),
  },
];

function WidthChoice({ value, disabled, onChange }: { value: ContentWidth; disabled: boolean; onChange: (width: ContentWidth) => void }) {
  const options = widths();
  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-[13px] font-semibold text-[#2e3442]">{__( 'Width', 'fabrement-lite' )}</span>
      <div className="flex flex-wrap items-center gap-2" role="radiogroup" aria-label={__( 'Template width', 'fabrement-lite' )}>
        {options.map((w) => {
          const on = value === w.value;
          return (
            <button
              key={w.value}
              type="button"
              role="radio"
              aria-checked={on}
              disabled={disabled}
              title={w.hint}
              onClick={() => !on && onChange(w.value)}
              className={
                'h-8 cursor-pointer rounded-full border px-3 text-[13px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ' +
                (on ? 'border-[#2cc486] bg-[#e7f8f0] text-[#21865d]!' : 'border-[#d9e0e6] bg-white text-[#667185]! hover:bg-[#f2f6f9]')
              }
            >
              {w.label}
            </button>
          );
        })}
      </div>
      <span className="text-[12px] text-[#a7b3bd]!">{options.find((w) => w.value === value)?.hint}</span>
    </div>
  );
}

/** The small action buttons on a language row of a template card. */
const langActionClass =
  'h-7 cursor-pointer rounded-[6px] border border-[#d9e0e6] bg-white px-2.5 text-[12px] font-semibold text-[#2e3442]! transition-colors hover:bg-[#f2f6f9] disabled:opacity-60';

/** Server-side page window: how many rows one bounded fetch returns. */
const PAGE_LIMIT = 30;

interface PickerState {
  template: SiteTemplateSummary;
  phase: 'loading' | 'ready' | 'error';
  pages: SiteTemplatePage[];
  selected: Set<number>;
  /**
   * The not-found view, which is NOT a page: it has no record to hold a pick, so it lives in
   * the per-type defaults map instead. It rides in this modal because the question it answers
   * is the same one every row here answers — does this thing use this template — and the two
   * stores behind that answer are ours to reconcile, not the reader's. Kept as a pair so the
   * save can tell a real change from an untouched checkbox and leave the option alone.
   */
  notFoundWas: boolean;
  notFoundNow: boolean;
  /** A debounced search fetch is in flight (rows stay visible meanwhile). */
  searching?: boolean;
  error?: string;
}

/** One saved template: name, languages, what it is assigned to and the actions. */
function TemplateCard({
  template,
  postTypes,
  busy,
  onEditFields,
  languages,
  onDeleteVersion,
  onDelete,
  onRename,
  onToggleDefaultFor,
  archives,
  onToggleArchiveFor,
  templatesRepo,
  contentFiles,
  onContentSaved,
  onPickPages,
  pickVersion,
}: {
  template: SiteTemplateSummary;
  postTypes: SiteTemplatePostTypes;
  busy: boolean;
  /** '' = the template's own record; a language code = that language's version (started as a copy if absent). */
  onEditFields: (language: string) => void;
  languages: SiteLanguage[];
  onDeleteVersion: (version: SiteTemplateVersion, label: string) => void;
  onDelete: () => void;
  /** Save a new name for this template, leaving its blocks alone. */
  onRename: (title: string) => void;
  onToggleDefaultFor: (postType: string) => void;
  archives: SiteTemplateArchive[];
  onToggleArchiveFor: (context: string) => void;
  templatesRepo: SiteTemplateRepository;
  contentFiles: ContentSourceFile[];
  onContentSaved: (message: string) => void;
  onPickPages: () => void;
  /** Bumped after every picker save so the per-card page list refetches. */
  pickVersion: number;
}) {
  /** The name is being typed in the heading strip rather than read from it. */
  const [renaming, setRenaming] = useState(false);
  const [draftTitle, setDraftTitle] = useState(template.title);
  /** Set by Escape so the blur it causes abandons the edit instead of saving it. */
  const renameCancelled = useRef(false);
  /** The layouts modal (Content area), opened from the archives group. */
  const [layoutsOpen, setLayoutsOpen] = useState(false);

  // Two post types may carry the SAME label — "Projects" for both `hbproject` and `project` is
  // the usual way it happens. Every public type is offered here now, so the collision is on
  // screen rather than hidden behind a shorter list, and two identical chips are a coin flip.
  // Show the slug alongside, but only for the labels that actually collide.
  const ambiguousLabels = new Set(
    Object.values(postTypes.available).filter(
      (label, i, all) => all.indexOf(label) !== i,
    ),
  );
  const typeLabel = (slug: string) => {
    if (slug === NOT_FOUND_DEFAULT) return __( 'Not found (404)', 'fabrement-lite' );
    const label = postTypes.available[slug];
    if (label === undefined) return slug;
    return ambiguousLabels.has(label) ? `${label} (${slug})` : label;
  };

  return (
    // No padding on the card itself: the heading is a tinted strip edge to edge, so the
    // padding belongs to the strip and to the body separately.
    <div className="flex flex-col rounded-[15px] border border-[#d9e0e6] bg-white shadow-[0_10px_24px_rgba(46,52,66,0.10)]">
      {/* The strip's OWN top corners are rounded rather than clipping the card with
          overflow-hidden — clipping would cut off anything that has to escape this row. */}
      <div className="flex flex-wrap items-center gap-2 rounded-t-[15px] bg-[#d6dee4] px-5 py-[18px]">
        {renaming ? (
          // Renaming in place rather than in the form below: a name is one field, and sending
          // someone to a two-field form to change it made the form the only way to do the
          // commonest edit on the card. The save carries the CURRENT blocks unchanged, so
          // this writes the title and nothing else.
          <input
            type="text"
            // eslint-disable-next-line jsx-a11y/no-autofocus -- the field only exists because the user just asked to type in it.
            autoFocus
            value={draftTitle}
            disabled={busy}
            aria-label={__( 'Template name', 'fabrement-lite' )}
            onChange={(e) => setDraftTitle(e.target.value)}
            onKeyDown={(e) => {
              // Both keys leave through BLUR, which is the single commit path — committing on
              // Enter as well would run the save twice, once for the key and once for the
              // blur that follows it.
              if (e.key === 'Enter') {
                e.preventDefault();
                e.currentTarget.blur();
              }
              if (e.key === 'Escape') {
                renameCancelled.current = true;
                e.currentTarget.blur();
              }
            }}
            onBlur={() => {
              const cancelled = renameCancelled.current;
              renameCancelled.current = false;
              setRenaming(false);
              if (cancelled) return;
              const next = draftTitle.trim();
              // An empty name would be refused by the server anyway, and an unchanged one is a
              // request round-trip for nothing.
              if (next !== '' && next !== template.title) onRename(next);
            }}
            // Every colour here carries `!`, because wp-admin styles bare inputs and wins
            // against plain utilities: it paints the field white and, on focus, draws the blue
            // ring as a BOX-SHADOW (`box-shadow: 0 0 0 1px #2271b1`) rather than an outline —
            // so `outline-none` alone never removed it. `shadow-none!` is what does.
            // The size and weight match the h2 it replaces, so the name does not jump when the
            // field opens — and both carry `!` for the same reason the colours do.
            className="h-8 min-w-0 flex-1 rounded-[6px] border border-[#b9c4cd]! bg-transparent! px-2 text-[16px]! font-semibold! text-[#2e3442]! shadow-none! outline-none! focus:border-[#8a99a6]!"
          />
        ) : (
          <>
            {/* `m-0!`, not `m-0`: wp-admin ships its own margins for a bare h2 and they
                out-weigh a plain utility, which is why the plain one did nothing here. */}
            <h2 className="m-0! text-[16px] font-semibold leading-tight text-[#2e3442]!">{template.title}</h2>
            <button
              type="button"
              disabled={busy}
              aria-label={
                /* translators: %s: template title */
                wp.i18n.sprintf( __( 'Rename "%s"', 'fabrement-lite' ), template.title )
              }
              title={__( 'Rename this template', 'fabrement-lite' )}
              onClick={() => {
                setDraftTitle(template.title);
                setRenaming(true);
              }}
              className="inline-flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-full border-0 bg-transparent text-[#667185]! transition-colors hover:bg-[#c6d1da] hover:text-[#2e3442]! disabled:opacity-50"
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                <path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
              </svg>
            </button>
          </>
        )}
        {/* Icon only, and shaped like the rename pencil beside the title — they share this
            strip, so two different button shapes on it read as two unrelated things. The
            label survives as `aria-label` + `title`: a bin with no words is recognisable, but
            it is still the one irreversible control on the card. */}
        <button
          type="button"
          disabled={busy}
          onClick={onDelete}
          aria-label={
            /* translators: %s: template title */
            wp.i18n.sprintf( __( 'Delete "%s"', 'fabrement-lite' ), template.title )
          }
          title={__( 'Delete this template', 'fabrement-lite' )}
          // A shade DARKER than the strip, not lighter: a lighter disc reads as a hole punched
          // in the bar, a darker one as a control sitting on it. Same value the rename pencil
          // lights up with, so the strip carries one tone rather than two near-identical ones.
          className="ml-auto inline-flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-full border-0 bg-[#c6d1da] text-[#b23b3b]! transition-colors hover:bg-[#f0bfbf] disabled:opacity-50"
        >
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
            <polyline points="3 6 5 6 21 6" />
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
          </svg>
        </button>
      </div>

      <div className="flex flex-col gap-3 px-5 py-[18px]">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => onEditFields('')}
              title={__( 'Open this template in the WordPress editor: the blocks around the content slot.', 'fabrement-lite' )}
              className="flex h-8 cursor-pointer items-center rounded-[6px] border border-[#d9e0e6] bg-white px-3 text-[13px] font-semibold text-[#2e3442]! transition-colors hover:bg-[#f2f6f9]"
            >
              {__( 'Open in editor', 'fabrement-lite' )}
            </button>
          </div>
        </div>

        {template.problem !== '' && (
          <p className="m-0 rounded-[8px] border border-[#f3c1c1] bg-[#fdf1f1] px-3 py-2 text-[13px] text-[#b23b3b]!">
            {template.problem}
          </p>
        )}

        {/* The template's other languages — as the translation plugin lists a post's
            translations beside it. Each version is a record of its own: started as a copy of
            this template, then edited like one (its blocks, its fields), deleted alone. Pages
            are never assigned to a version — they point at this template, and a reader in that
            language is handed the version. Absent on a single-language site — `languages` is
            empty and there is nothing to list. Sits right under the original's own row, so
            each language reads as that row's counterpart: label, then the same three actions. */}
        {languages.length > 0 && (
          <div className="flex flex-col items-start gap-1.5">
            <span
              className="text-[13px] font-semibold text-[#2e3442]"
              title={__( 'This template in the site\'s other languages. A version starts as a copy of this template and is then edited on its own. Readers in that language see the version; pages stay assigned to this template.', 'fabrement-lite' )}
            >
              {__( 'Languages', 'fabrement-lite' )}
            </span>
            <div className="flex flex-col gap-1.5">
              {languages
                .filter((l) => template.translations?.[l.code] !== template.id)
                .map((l) => {
                  const v = template.versions?.[l.code];
                  const owned = template.translationScreenUrl !== '';
                  return (
                    <div key={l.code} className="flex flex-wrap items-center gap-2 text-[13px]">
                      <span className="inline-flex min-w-[7rem] items-center gap-1.5 font-semibold text-[#2e3442]">
                        {l.flag ? <img src={l.flag} alt="" width={18} height={12} className="h-3 w-[18px] rounded-[2px] object-cover" /> : null}
                        {l.label}
                      </span>
                      {v && v.problem !== '' && (
                        <span title={v.problem} className="rounded-full bg-[#fdf1f1] px-2 py-0.5 text-[12px] font-semibold text-[#b23b3b]!">{__( 'Needs attention', 'fabrement-lite' )}</span>
                      )}
                      {owned ? (
                        // The translation plugin's editor owns this template's languages: it
                        // rebuilds every version from the original, so nothing edited here would
                        // survive. One way in — its screen.
                        <button type="button" disabled={busy} onClick={() => onEditFields(l.code)} className={langActionClass}>
                          {v ? __( 'Edit in WPML', 'fabrement-lite' ) : __( '+ Translate in WPML', 'fabrement-lite' )}
                        </button>
                      ) : v ? (
                        <>
                          <button type="button" disabled={busy} onClick={() => onEditFields(l.code)} className={langActionClass} title={__( 'Open this language\'s version in the WordPress editor.', 'fabrement-lite' )}>
                            {__( 'Open in editor', 'fabrement-lite' )}
                          </button>
                          <button type="button" disabled={busy} onClick={() => onDeleteVersion(v, l.label)} className={langActionClass + ' border-[#f3c1c1] text-[#b23b3b]! hover:bg-[#fdf1f1]'}>
                            {__( 'Delete', 'fabrement-lite' )}
                          </button>
                        </>
                      ) : (
                        <button type="button" disabled={busy} onClick={() => onEditFields(l.code)} className={langActionClass} title={__( 'Start this language\'s version as a copy of this template and open it in the editor.', 'fabrement-lite' )}>
                          {__( '+ Add version', 'fabrement-lite' )}
                        </button>
                      )}
                    </div>
                  );
                })}
            </div>
          </div>
        )}

        {/* Everything below answers ONE question — which content wears this template — and
            said so nowhere: three controls arrived unannounced, and the reader had to work
            out from the labels alone that they belonged together. */}
        <div className="mt-1 border-t border-[#eef2f6] pt-4">
          {/* `text-[13px]!`, not `text-[13px]`: wp-admin sizes a bare h3 itself and out-weighs
              a plain utility, so this heading came out bigger than the `Default for` /
              `Archives` labels below it — those are spans, which wp-admin leaves alone. */}
          <h3 className="m-0! text-center text-[13px]! font-semibold uppercase tracking-wide text-[#2e3442]!">{__( 'Assign to', 'fabrement-lite' )}</h3>
          <p className="m-0! text-center text-[12px] leading-relaxed text-[#a7b3bd]!">
            {__( 'Choose what renders with this template — a whole content type, a list page, or individual items you pick.', 'fabrement-lite' )}
          </p>
        </div>

        {/* One column now: all three groups answer the same question, so they stack in
            reading order instead of two of them sharing a row with a stray button. */}
        <div className="flex flex-col">
          {/* The three groups of the section, each a question of its own — so they sit
              further apart than the chips inside any one of them (gap-2), or the eye reads
              the whole thing as one long row of buttons. */}
          <div className="flex flex-col gap-6">
          <div className="flex flex-col gap-2">
            <span
              className="text-[13px] font-semibold text-[#2e3442]"
              title={__( 'The fallback for a whole content type: every item of that type that has not picked a template of its own renders with this one. Each type has a single default; nothing is written into the items themselves.', 'fabrement-lite' )}
            >
              {__( 'Default for post types', 'fabrement-lite' )}
            </span>
            <div className="flex flex-wrap items-center gap-2">
              {Object.keys(postTypes.available).map((slug) => {
                const on = template.defaultFor.includes(slug);
                return (
                  <button
                    key={slug}
                    type="button"
                    disabled={busy}
                    aria-pressed={on}
                    onClick={() => onToggleDefaultFor(slug)}
                    className={
                      'h-8 cursor-pointer rounded-full border px-3 text-[13px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ' +
                      (on
                        ? 'border-[#2cc486] bg-[#e7f8f0] text-[#21865d]!'
                        : 'border-[#d9e0e6] bg-white text-[#667185]! hover:bg-[#f2f6f9]')
                    }
                  >
                    {on ? '✓ ' : ''}
                    {typeLabel(slug)}
                  </button>
                );
              })}
              {template.defaultFor
                .filter((slug) => slug !== NOT_FOUND_DEFAULT && !(slug in postTypes.available))
                .map((slug) => (
                  <button
                    key={slug}
                    type="button"
                    disabled={busy}
                    aria-pressed
                    onClick={() => onToggleDefaultFor(slug)}
                    title={
                      /* translators: %s: post type slug */
                      wp.i18n.sprintf( __( '"%s" is no longer a content type this template can wrap — it was removed or made private after it took this default. Click to clear it.', 'fabrement-lite' ), slug )
                    }
                    className="h-8 cursor-pointer rounded-full border border-[#f0c47a] bg-[#fff4e5] px-3 text-[13px] font-semibold text-[#9a5b00]! transition-colors hover:bg-[#ffe9c9] disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {/* translators: %s: post type slug */}
                    ✓ {wp.i18n.sprintf( __( '%s · gone', 'fabrement-lite' ), slug )}
                  </button>
                ))}
              {Object.keys(postTypes.available).length === 0 && template.defaultFor.length === 0 && (
                <span className="text-[13px] text-[#a7b3bd]!">{__( 'This site has no public content types.', 'fabrement-lite' )}</span>
              )}
            </div>
          </div>

          {/* The second way in, as a labelled group rather than a button floating at the
              card's edge: it answers the same question as the groups around it, so it reads
              as their equal. And it is the STRONGEST of the three — a page that picked this
              template keeps it whatever the defaults say — which is worth a line. Archives
              come LAST, right above the layout settings they depend on. */}
          <div className="flex flex-col gap-2">
            {/* The button sits opposite the LABEL, not opposite the list: the list grows with
                every page picked, and a button anchored to it drifted further down the card
                the more there was to read. */}
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="flex min-w-0 flex-col">
                <span
                  className="text-[13px] font-semibold text-[#2e3442]"
                  title={__( 'The same per-item choice as the template picker in the page editor, gathered in one place.', 'fabrement-lite' )}
                >
                  {__( 'Individual pages using this template', 'fabrement-lite' )}
                </span>
                <span className="text-[12px] text-[#a7b3bd]!">
                  {__( 'These keep this template whatever the defaults say.', 'fabrement-lite' )}
                </span>
              </div>
              <button
                type="button"
                disabled={busy}
                onClick={onPickPages}
                title={__( 'Pick the exact pages, posts or items that should use this template.', 'fabrement-lite' )}
                className="h-9 shrink-0 cursor-pointer rounded-[6px] border border-[#d9e0e6] bg-white px-3.5 text-[13px] font-semibold text-[#2e3442]! transition-colors hover:bg-[#f2f6f9] disabled:cursor-not-allowed disabled:opacity-50"
              >
                {__( 'Choose pages…', 'fabrement-lite' )}
              </button>
            </div>
            <PickedPages
              repo={templatesRepo}
              slug={template.slug}
              pageCount={template.pageCount}
              notFound={template.defaultFor.includes(NOT_FOUND_DEFAULT)}
              pickVersion={pickVersion}
            />
          </div>

          {/* Rendered even with no archives: the layout button in its head also covers single
              pages, which exist on every site. */}
          <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <span
                className="text-[13px] font-semibold text-[#2e3442]"
                // "Default for" reads as a fallback, which an archive does not have — so the
                // tooltip says outright that nothing applies until it is switched on here. The
                // label stays parallel with the group above because the pair of them is what
                // makes the single-item / list-page split visible at a glance.
                title={__( 'The pages that LIST items — a post type\'s archive, or the posts index. Unlike a post type, an archive has no fallback: until one is switched on here the theme keeps rendering it, and a list page wears one template at a time.', 'fabrement-lite' )}
              >
                {__( 'Default for archives', 'fabrement-lite' )}
              </span>
            </div>
            {archives.length > 0 && (
              <div className="flex flex-wrap items-center gap-2">
                {archives.map((a) => {
                  const on = a.templateId === template.id;
                  // Bound to a DIFFERENT template: still clickable — clicking takes
                  // the view over, which is what "one template per archive" means.
                  const takenByOther = !on && a.templateId > 0;
                  return (
                    <button
                      key={a.context}
                      type="button"
                      disabled={busy}
                      aria-pressed={on}
                      onClick={() => onToggleArchiveFor(a.context)}
                      title={
                        on
                          ? /* translators: %s: archive label */
                            wp.i18n.sprintf( __( 'This template wraps %s. Click to hand it back to the theme.', 'fabrement-lite' ), a.label )
                          : takenByOther
                            ? /* translators: %s: archive label */
                              wp.i18n.sprintf( __( '%s is wrapped by another template — click to take it over.', 'fabrement-lite' ), a.label )
                            : /* translators: %s: archive label */
                              wp.i18n.sprintf( __( '%s is rendered by the theme. Click to wrap it in this template.', 'fabrement-lite' ), a.label )
                      }
                      className={
                        'h-8 cursor-pointer rounded-full border px-3 text-[13px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ' +
                        (on
                          ? 'border-[#2cc486] bg-[#e7f8f0] text-[#21865d]!'
                          : takenByOther
                            ? 'border-[#e2d3b0] bg-[#fdf8ec] text-[#8a6d3b]! hover:bg-[#f8f0dd]'
                            : 'border-[#d9e0e6] bg-white text-[#667185]! hover:bg-[#f2f6f9]')
                      }
                    >
                      {on ? '✓ ' : ''}
                      {a.label}
                    </button>
                  );
                })}
              </div>
            )}
            {/* Under the chips it serves, dressed like the Content area's "+" row: a full-width
                quiet strip that reads as "more for this group", not as a third primary action. */}
            <button
              type="button"
              disabled={busy}
              onClick={() => setLayoutsOpen(true)}
              aria-haspopup="dialog"
              title={__( 'What the content slot shows on archives and single pages.', 'fabrement-lite' )}
              className="flex h-9 w-full cursor-pointer items-center justify-center rounded-[8px] border border-[#e3e9ee] bg-[#f6f9fc] text-[13px] font-semibold text-[#667185]! transition-colors hover:border-[#d9e0e6] hover:bg-[#eef3f8] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {__( 'Advanced layout settings for archives and single pages', 'fabrement-lite' )}
            </button>
          </div>

          </div>
      </div>

      {/* The modal the "Advanced layout settings…" button above opens; renders nothing while closed. */}
      <ContentSourceSection
        template={template}
        repo={templatesRepo}
        postTypes={postTypes}
        archives={archives}
        files={contentFiles}
        onSaved={onContentSaved}
        open={layoutsOpen}
        onClose={() => setLayoutsOpen(false)}
      />
      </div>
    </div>
  );
}

/**
 * Page picker modal: choose exactly which pages pick this template (writes
 * the same `_wp_page_template` value the native per-page picker writes;
 * unchecking a previously-picked page resets it to the default template).
 */
function PagePickerModal({
  picker,
  templates,
  busy,
  onChange,
  onSearch,
  onSave,
  onClose,
}: {
  picker: PickerState;
  templates: SiteTemplateSummary[];
  busy: boolean;
  onChange: (p: PickerState) => void;
  onSearch: (search: string) => void;
  onSave: () => void;
  onClose: () => void;
}) {
  const [query, setQuery] = useState('');

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !busy) onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [busy, onClose]);

  // Typing hits the server (bounded fetch) instead of loading every page up
  // front. Debounced; the first run is skipped because openPicker already
  // fetched the initial window with an empty search.
  const firstSearchRun = useRef(true);
  useEffect(() => {
    if (firstSearchRun.current) {
      firstSearchRun.current = false;
      return;
    }
    const id = window.setTimeout(() => onSearch(query.trim()), 300);
    return () => window.clearTimeout(id);
  }, [query, onSearch]);

  // Rows come pre-bounded from the server; render them as-is.
  const atLimit = picker.pages.length >= PAGE_LIMIT;

  // TWO GROUPS, and the split is on what is SAVED rather than on what is ticked.
  // Grouping by the checkbox would make a row jump between sections under the cursor as it is
  // clicked; grouping by the stored pick keeps the list still and lets the pending change be
  // stated instead — "will be removed on save" next to a pick that has just been unticked.
  // The server always returns this template's current picks, whatever the search, so the top
  // group is complete even when the search matches nothing.
  const picked = picker.pages.filter((pg) => pg.template === picker.template.slug);
  const rest = picker.pages.filter((pg) => pg.template !== picker.template.slug);
  // The not-found row counts with the rest: the footer summarises what Save will do, and a
  // pending change it leaves out makes the summary contradict the row that shows it.
  const notFoundAdding = picker.notFoundNow && !picker.notFoundWas ? 1 : 0;
  const notFoundRemoving = !picker.notFoundNow && picker.notFoundWas ? 1 : 0;
  const adding = rest.filter((pg) => picker.selected.has(pg.id)).length + notFoundAdding;
  const removing = picked.filter((pg) => !picker.selected.has(pg.id)).length + notFoundRemoving;

  /** Human label for a page's CURRENT pick shown to the right of its row. */
  const currentLabel = (page: SiteTemplatePage): string => {
    if (page.template === '') return __( 'Default', 'fabrement-lite' );
    if (page.template === picker.template.slug) return '';
    const ours = templates.find((t) => t.slug === page.template);
    /* translators: %s: template title */
    return ours ? wp.i18n.sprintf( __( 'Fabrement: %s', 'fabrement-lite' ), ours.title ) : page.template;
  };

  const toggle = (id: number) => {
    const selected = new Set(picker.selected);
    if (selected.has(id)) selected.delete(id);
    else selected.add(id);
    onChange({ ...picker, selected });
  };

  const renderRow = (page: SiteTemplatePage) => {
    const isPick = page.template === picker.template.slug;
    const ticked = picker.selected.has(page.id);
    // What THIS save will do to the row, said on the row. The modal replaces the whole set, so a
    // pick left unticked is a removal — and that is worth reading before pressing Save, not after.
    const pending = isPick && !ticked ? __( 'will be removed', 'fabrement-lite' ) : !isPick && ticked ? __( 'will be added', 'fabrement-lite' ) : '';

    return (
      <label
        key={page.id}
        className="flex cursor-pointer items-center gap-2.5 rounded-[6px] px-2 py-1.5 text-sm text-[#2e3442] hover:bg-[#f6f9fc]"
      >
        <input
          type="checkbox"
          checked={ticked}
          disabled={busy}
          onChange={() => toggle(page.id)}
          className="h-4 w-4 shrink-0 accent-[#2cc486]"
        />
        <span className="min-w-0 flex-1 truncate">
          {page.title}
          {/* The type, because this list mixes them: a page, a post and a project may all be
              called "About", and the title alone would make the reader guess. */}
          {page.typeLabel && (
            <span className="ml-2 rounded-[4px] bg-[#f2f6f9] px-1.5 py-0.5 text-[11px] text-[#667185]">
              {page.typeLabel}
            </span>
          )}
          {page.isFront && <span className="ml-1.5 text-[12px] text-[#8a94a6]">· {__( 'Front page', 'fabrement-lite' )}</span>}
          {page.status !== 'publish' && <span className="ml-1.5 text-[12px] text-[#8a94a6]">· {page.status}</span>}
          {/* One row is the page in every language it exists in — a tick covers them all.
              The codes say which those are, so a page not yet translated reads as such. */}
          {page.translations && (
            <span className="ml-1.5 text-[12px] uppercase text-[#8a94a6]" title={__( 'Languages this page exists in — the template applies to all of them.', 'fabrement-lite' )}>
              · {Object.keys(page.translations).join(' ')}
            </span>
          )}
        </span>
        <span className={`shrink-0 text-[12px] ${pending ? 'text-[#b26a00]' : 'text-[#8a94a6]'}`}>
          {pending || currentLabel(page)}
        </span>
      </label>
    );
  };

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={
        /* translators: %s: template title */
        wp.i18n.sprintf( __( 'Choose pages for "%s"', 'fabrement-lite' ), picker.template.title )
      }
      className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/45 p-6"
      {...backdropCloseProps(onClose, !busy)}
    >
      <div
        className="flex max-h-[80vh] w-full max-w-[560px] flex-col gap-4 rounded-[15px] bg-white p-6 shadow-[0_24px_48px_rgba(46,52,66,0.25)]"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-4">
          <div>
            <h2 className="m-0 text-[17px] font-semibold leading-tight text-[#2e3442]!">
              {/* translators: %s: template title */}
              {wp.i18n.sprintf( __( 'Pages using “%s”', 'fabrement-lite' ), picker.template.title )}
            </h2>
            <p className="m-0 mt-1 text-[13px] text-[#667185]!">
              {__( 'The same choice as the page editor\'s template picker. Unchecking a page resets it to the default template.', 'fabrement-lite' )}
            </p>
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

        {picker.phase === 'loading' && <p className="m-0 py-6 text-center text-sm text-[#667185]!">{__( 'Loading pages…', 'fabrement-lite' )}</p>}
        {picker.phase === 'error' && (
          <p className="m-0 rounded-[8px] border border-[#f3c1c1] bg-[#fdf1f1] px-4 py-3 text-sm text-[#b23b3b]!">
            {picker.error}
          </p>
        )}

        {picker.phase === 'ready' && (
          <div className="relative shrink-0">
            <input
              type="search"
              value={query}
              disabled={busy}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={__( 'Search pages by title…', 'fabrement-lite' )}
              className="h-10 w-full rounded-[6px] border border-[#d9e0e6] px-3 text-sm text-[#2e3442] outline-none placeholder:text-[#a7b3bd] focus:border-[#2cc486]"
            />
            {picker.searching && (
              <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-[#8a94a6]">{__( 'searching…', 'fabrement-lite' )}</span>
            )}
          </div>
        )}

        {picker.phase === 'ready' && (
          <div className="flex flex-col gap-1 overflow-y-auto rounded-[8px] border border-[#eef2f6] p-2">
            {/* THE NOT-FOUND VIEW, first and always — it is not a page, so no search can
                reach it and no page row can stand for it. It used to sit among the post-type
                chips on the card, where it was the one entry that was not a post type. */}
            <p className="m-0 px-2 pb-1 pt-1 text-[12px] font-semibold uppercase tracking-wide text-[#8a94a6]!">
              {__( 'Special view', 'fabrement-lite' )}
            </p>
            <label className="flex cursor-pointer items-center gap-2.5 rounded-[6px] px-2 py-1.5 text-sm text-[#2e3442] hover:bg-[#f6f9fc]">
              <input
                type="checkbox"
                checked={picker.notFoundNow}
                disabled={busy}
                onChange={() => onChange({ ...picker, notFoundNow: !picker.notFoundNow })}
                className="h-4 w-4 shrink-0 accent-[#2cc486]"
              />
              <span className="min-w-0 flex-1 truncate">
                {__( 'Not found (404)', 'fabrement-lite' )}
                <span className="ml-2 text-[12px] text-[#8a94a6]">· {__( 'every address that does not exist', 'fabrement-lite' )}</span>
              </span>
              <span
                className={`shrink-0 text-[12px] ${picker.notFoundNow !== picker.notFoundWas ? 'text-[#b26a00]' : 'text-[#8a94a6]'}`}
              >
                {picker.notFoundNow !== picker.notFoundWas
                  ? picker.notFoundNow
                    ? __( 'will be added', 'fabrement-lite' )
                    : __( 'will be removed', 'fabrement-lite' )
                  : ''}
              </span>
            </label>
            <div className="my-1 border-t border-[#eef2f6]" />
            {picker.pages.length === 0 && query.trim() === '' && (
              <p className="m-0 px-2 py-4 text-sm text-[#667185]!">{__( 'No pages on this site yet.', 'fabrement-lite' )}</p>
            )}
            {picker.pages.length === 0 && query.trim() !== '' && (
              <p className="m-0 px-2 py-4 text-sm text-[#667185]!">
                {/* translators: %s: the search text */}
                {wp.i18n.sprintf( __( 'Nothing matches “%s”.', 'fabrement-lite' ), query )}
              </p>
            )}
            {picked.length > 0 && (
              <>
                <p className="m-0 px-2 pb-1 pt-1 text-[12px] font-semibold uppercase tracking-wide text-[#8a94a6]!">
                  {/* translators: %d: number of pages */}
                  {wp.i18n.sprintf( __( 'On this template (%d)', 'fabrement-lite' ), picked.length )}
                </p>
                {picked.map((page) => renderRow(page))}
                {rest.length > 0 && <div className="my-1 border-t border-[#eef2f6]" />}
              </>
            )}
            {picked.length > 0 && rest.length > 0 && (
              <p className="m-0 px-2 pb-1 text-[12px] font-semibold uppercase tracking-wide text-[#8a94a6]!">
                {__( 'Other pages', 'fabrement-lite' )}
              </p>
            )}
            {rest.map((page) => renderRow(page))}
            {atLimit && query.trim() === '' && (
              <p className="m-0 border-t border-[#eef2f6] px-2 pb-1 pt-2 text-[12px] text-[#8a94a6]!">
                {/* translators: %d: number of pages */}
                {wp.i18n.sprintf( __( 'Showing the first %d pages — type to search for more.', 'fabrement-lite' ), PAGE_LIMIT )}
              </p>
            )}
          </div>
        )}

        <div className="flex items-center justify-between gap-2">
          <span className="text-[13px] text-[#667185]">
            {picker.phase === 'ready' &&
              /* translators: %d: number of selected items */
              wp.i18n.sprintf( __( '%d selected', 'fabrement-lite' ), picker.selected.size + (picker.notFoundNow ? 1 : 0) )}
            {picker.phase === 'ready' && (adding > 0 || removing > 0) && ' · '}
            {picker.phase === 'ready' &&
              (adding > 0 || removing > 0) &&
              /* translators: 1: number of items to add, 2: number of items to remove */
              wp.i18n.sprintf( __( '%1$d to add, %2$d to remove', 'fabrement-lite' ), adding, removing )}
          </span>
          <div className="flex items-center gap-2">
            <button
              type="button"
              disabled={busy}
              onClick={onClose}
              className="h-10 cursor-pointer rounded-[6px] border border-[#d9e0e6] bg-white px-4 text-sm font-semibold text-[#2e3442]! transition-colors hover:bg-[#f2f6f9] disabled:opacity-60"
            >
              {__( 'Cancel', 'fabrement-lite' )}
            </button>
            <button
              type="button"
              disabled={busy || picker.phase !== 'ready'}
              onClick={onSave}
              className="h-10 cursor-pointer rounded-[6px] bg-[#2cc486] px-5 text-sm font-semibold text-white! transition-colors hover:bg-[#21865d] disabled:opacity-60"
            >
              {busy ? __( 'Saving…', 'fabrement-lite' ) : __( 'Save pages', 'fabrement-lite' )}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
