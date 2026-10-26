import { z } from 'zod';

/**
 * What a template's Content Source block renders. Stored server-side in the template's
 * manifest file, addressed by a key in post meta and shared by every language version of
 * the template. The server validates each rule on save and answers a 400 with the reason
 * for one that could never render.
 */
export const contentSourceConfigSchema = z.object({
  /** Per-view overrides. One rule per view — the first match wins at render time. */
  rules: z.array(z.object({ context: z.string(), file: z.string() })),
});

/** One selectable body file: a PHP file from the theme's templates folder. */
export const contentSourceFileSchema = z.object({
  path: z.string(),
  label: z.string(),
  /**
   * Which view shape it fills: "singular" | "archive", or null when undeclared.
   * FILTERS the picker — a body written for the other shape renders wrong, not
   * merely differently. A theme file declares its kind with a `Fabrement:` file
   * header, so null means "cannot tell" and the file stays offered everywhere.
   * Kept loose (string, not enum) so a future kind cannot make an older client
   * throw on parse.
   */
  kind: z.string().nullable().optional(),
  /**
   * What the file DECLARES, in the same dialect as a rule's context: a bare kind
   * ("archive") or an exact view ("archive:product" | "singular:page" | "blog-home").
   * The exact form is a narrower promise than `kind` — "written for product archives" —
   * so the picker offers such a file only for that view.
   */
  declares: z.string().nullable().optional(),
});

export const contentSourceFilesResponseSchema = z.object({
  files: z.array(contentSourceFileSchema),
});

export const saveContentSourceResponseSchema = z.object({
  contentSource: contentSourceConfigSchema,
});

export const siteTemplateSummarySchema = z.object({
  id: z.number(),
  title: z.string(),
  /** `_wp_page_template` value (`fabrement-template-<id>`), shared by both theme appliers. */
  slug: z.string(),
  /** What is wrong with the template, in a sentence; '' when nothing is. It dresses pages all the same. */
  problem: z.string().default(''),
  /** 'full': sections reach the edges and stack flush; 'constrained': the theme's content column. */
  contentWidth: z.enum(['full', 'constrained']).default('full'),
  /** Post types this template is the DEFAULT for (wraps every singular of that type without its own pick). */
  defaultFor: z.array(z.string()),
  /** How many pages EXPLICITLY picked this template (site-default coverage not included). */
  pageCount: z.number(),
  /** The template's body settings, shipped with the list so the screen paints them at once. */
  contentSource: contentSourceConfigSchema.optional(),
  /**
   * language code → the post id of that language's version, the original included.
   * EMPTY on a single-language site, which is how the screen decides to show no language
   * control at all.
   */
  translations: z.record(z.string(), z.number()).default({}),
  /**
   * language code → that language's version, for the languages that have one (the original is
   * not among them). A version is a template record of its own, started as a copy of the
   * original, so the card offers it Open in editor / Delete. Pages are
   * never assigned to it: they point at the original, and a reader in this language is handed
   * the version.
   */
  versions: z
    .record(
      z.string(),
      z.object({
        id: z.number(),
        problem: z.string().default(''),
      }),
    )
    .default({}),
  // The translation plugin's screen when ITS editor owns this template's versions; '' when they
  // are edited here. Decides what the Languages row offers.
  translationScreenUrl: z.string().default(''),
});

/** One language the site publishes in. */
export const siteLanguageSchema = z.object({
  code: z.string(),
  label: z.string(),
  isCurrent: z.boolean(),
  /** The translation plugin's flag image URL; '' / absent when it has none. */
  flag: z.string().optional(),
});

/** Every post type a template can dress: slug → human label. Which of them HAVE a default is
 *  each template's `defaultFor`. */
export const siteTemplatePostTypesSchema = z.object({
  available: z.record(z.string(), z.string()),
});

export const siteTemplateListResponseSchema = z.object({
  templates: z.array(siteTemplateSummarySchema),
  postTypes: siteTemplatePostTypesSchema,
  /** Empty unless a translation plugin reports more than one language. */
  languages: z.array(siteLanguageSchema).default([]),
});

/** POST /site-templates/translate — start (or reopen) one language's version. */
export const siteTemplateTranslateResponseSchema = z.object({
  /** The post holding that language's words — the one to open in the editor. */
  templateId: z.number(),
});

/** POST /site-templates — the saved template; the screen re-reads the list for the rest. */
export const siteTemplateSaveResponseSchema = z.object({
  template: z.object({ id: z.number() }),
});

export const siteTemplateDeleteResponseSchema = z.object({
  /** Pages whose explicit pick was reset to the default template. */
  clearedPages: z.number(),
});

/** One page row in the per-template page picker. */
export const siteTemplatePageSchema = z.object({
  id: z.number(),
  title: z.string(),
  status: z.string(),
  /** Current raw `_wp_page_template` slug; '' = default template. */
  template: z.string(),
  isFront: z.boolean(),
  /** The record's post type and its singular label — the picker lists every public type now. */
  postType: z.string().optional(),
  typeLabel: z.string().optional(),
  /**
   * Present on a multilingual site only: the row is the page's ORIGINAL, and `translations`
   * (code → id, itself included) is the group a tick on it is written to. Absent = the plain
   * one-post row of a single-language site.
   */
  language: z.string().optional(),
  translations: z.record(z.string(), z.number()).optional(),
});

export const siteTemplatePagesResponseSchema = z.object({
  pages: z.array(siteTemplatePageSchema),
});

export const siteTemplateAssignResponseSchema = z.object({
  assigned: z.number(),
  cleared: z.number(),
});

/** POST /site-templates/default — nothing in the reply is read; the screen re-reads the list. */
export const siteTemplateDefaultResponseSchema = z.object({});

/**
 * One ARCHIVE view a template can dress. `templateId` 0 means the theme still
 * renders it — archives are opt-in, unlike singulars which inherit their post
 * type's default.
 */
export const siteTemplateArchiveSchema = z.object({
  /** "blog-home" or "archive:<postType>". */
  context: z.string(),
  label: z.string(),
  templateId: z.number(),
});

export const siteTemplateArchivesResponseSchema = z.object({
  archives: z.array(siteTemplateArchiveSchema),
});

export interface SaveSiteTemplatePayload {
  /** Absent → create; present → update. */
  id?: number;
  /** Needed on create; on update only when the name changes. */
  title?: string;
  /** Absent keeps the stored width; a new template is full width. */
  contentWidth?: ContentWidth;
}

export type ContentWidth = 'full' | 'constrained';
export type SiteLanguage = z.infer<typeof siteLanguageSchema>;
export type SiteTemplateTranslateResponse = z.infer<typeof siteTemplateTranslateResponseSchema>;
export type SiteTemplateSummary = z.infer<typeof siteTemplateSummarySchema>;
export type SiteTemplateVersion = SiteTemplateSummary['versions'][string];
export type SiteTemplateListResponse = z.infer<typeof siteTemplateListResponseSchema>;
export type SiteTemplatePostTypes = z.infer<typeof siteTemplatePostTypesSchema>;
export type SiteTemplateSaveResponse = z.infer<typeof siteTemplateSaveResponseSchema>;
export type SiteTemplateDeleteResponse = z.infer<typeof siteTemplateDeleteResponseSchema>;
export type SiteTemplateDefaultResponse = z.infer<typeof siteTemplateDefaultResponseSchema>;
export type SiteTemplateArchive = z.infer<typeof siteTemplateArchiveSchema>;
export type SiteTemplateArchivesResponse = z.infer<typeof siteTemplateArchivesResponseSchema>;
export type ContentSourceConfig = z.infer<typeof contentSourceConfigSchema>;
export type ContentSourceFile = z.infer<typeof contentSourceFileSchema>;
export type SaveContentSourceResponse = z.infer<typeof saveContentSourceResponseSchema>;
export type ContentSourceFilesResponse = z.infer<typeof contentSourceFilesResponseSchema>;
export type SiteTemplatePage = z.infer<typeof siteTemplatePageSchema>;
export type SiteTemplatePagesResponse = z.infer<typeof siteTemplatePagesResponseSchema>;
export type SiteTemplateAssignResponse = z.infer<typeof siteTemplateAssignResponseSchema>;
