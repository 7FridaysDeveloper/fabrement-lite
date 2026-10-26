import { AbstractApiRepository, type RestRequestInit } from '@shared/repository';
import { parseJsonResponse } from '@shared/zod';

import {
  type SaveSiteTemplatePayload,
  type SiteTemplateAssignResponse,
  type SiteTemplateDefaultResponse,
  type SiteTemplateTranslateResponse,
  type SiteTemplateDeleteResponse,
  type SiteTemplateListResponse,
  type SiteTemplatePagesResponse,
  type ContentSourceConfig,
  type ContentSourceFilesResponse,
  type SaveContentSourceResponse,
  type SiteTemplateArchivesResponse,
  type SiteTemplateSaveResponse,
  siteTemplateAssignResponseSchema,
  contentSourceFilesResponseSchema,
  saveContentSourceResponseSchema,
  siteTemplateArchivesResponseSchema,
  siteTemplateDefaultResponseSchema,
  siteTemplateTranslateResponseSchema,
  siteTemplateDeleteResponseSchema,
  siteTemplateListResponseSchema,
  siteTemplatePagesResponseSchema,
  siteTemplateSaveResponseSchema,
} from './schemas';

/** Single repository for all `fabrement/v1/site-templates` endpoints. */
export class SiteTemplateRepository extends AbstractApiRepository {
  constructor() {
    super();
  }

  /** `GET /site-templates` — every template, the post types and the site's languages. */
  async list(init: RestRequestInit = {}): Promise<SiteTemplateListResponse> {
    const response = await this.wp.get('site-templates', init, 'site-templates list');
    return parseJsonResponse(response, siteTemplateListResponseSchema, 'site-templates list');
  }


  /** `POST /site-templates` — create (no id) or update (with id). */
  async save(payload: SaveSiteTemplatePayload, init: RestRequestInit = {}): Promise<SiteTemplateSaveResponse> {
    const response = await this.wp.post('site-templates', payload, init, 'site-templates save');
    return parseJsonResponse(response, siteTemplateSaveResponseSchema, 'site-templates save');
  }

  /** `DELETE /site-templates/{id}` — pages that picked it are reset to the default template. */
  async remove(id: number, init: RestRequestInit = {}): Promise<SiteTemplateDeleteResponse> {
    const response = await this.wp.delete(`site-templates/${id}`, init, 'site-templates delete');
    return parseJsonResponse(response, siteTemplateDeleteResponseSchema, 'site-templates delete');
  }

  /**
   * `GET /site-templates/pages` — pages for the picker. With `limit`/`search`/
   * `templateSlug` the server returns a bounded, searched window (plus every page
   * already picking this template, so checkboxes stay correct) instead of all pages.
   */
  async listPages(
    params: { search?: string; limit?: number; templateSlug?: string } = {},
    init: RestRequestInit = {},
  ): Promise<SiteTemplatePagesResponse> {
    const qs = new URLSearchParams();
    if (params.limit != null) qs.set('limit', String(params.limit));
    if (params.templateSlug) qs.set('templateSlug', params.templateSlug);
    if (params.search) qs.set('search', params.search);
    const path = qs.toString() ? `site-templates/pages?${qs.toString()}` : 'site-templates/pages';
    const response = await this.wp.get(path, init, 'site-templates pages');
    return parseJsonResponse(response, siteTemplatePagesResponseSchema, 'site-templates pages');
  }

  /** `POST /site-templates/{id}/pages` — make exactly these pages pick the template. */
  async assignPages(id: number, pageIds: number[], init: RestRequestInit = {}): Promise<SiteTemplateAssignResponse> {
    const response = await this.wp.post(`site-templates/${id}/pages`, { pageIds }, init, 'site-templates assign');
    return parseJsonResponse(response, siteTemplateAssignResponseSchema, 'site-templates assign');
  }

  /**
   * `POST /site-templates/default` — set (or clear with templateId 0) the default
   * template for ONE post type. Each type has at most one default; every singular
   * of that type without its own explicit pick renders with it (resolved at render
   * time, nothing written into posts). The screen re-reads the list afterwards.
   */
  async setDefault(postType: string, templateId: number, init: RestRequestInit = {}): Promise<SiteTemplateDefaultResponse> {
    const response = await this.wp.post('site-templates/default', { postType, templateId }, init, 'site-templates default');
    return parseJsonResponse(response, siteTemplateDefaultResponseSchema, 'site-templates default');
  }

  /**
   * `POST /site-templates/translate` — open one language's version of a template, creating it
   * from a copy of the original's content when it does not exist yet.
   *
   * The copy is a template record of its own, edited in the editor by its id. Only the ROLE
   * stays on the original: which
   * pages the template dresses, and which type or archive it is the default for.
   */
  async translate(templateId: number, language: string, init: RestRequestInit = {}): Promise<SiteTemplateTranslateResponse> {
    const response = await this.wp.post('site-templates/translate', { templateId, language }, init, 'site-templates translate');
    return parseJsonResponse(response, siteTemplateTranslateResponseSchema, 'site-templates translate');
  }

  /**
   * `GET /site-templates/archives` — every archive view a template can dress
   * (the posts index + each post type with an archive), each with the template
   * currently bound to it (0 = the theme still renders it).
   */
  async listArchives(init: RestRequestInit = {}): Promise<SiteTemplateArchivesResponse> {
    const response = await this.wp.get('site-templates/archives', init, 'site-templates archives');
    return parseJsonResponse(response, siteTemplateArchivesResponseSchema, 'site-templates archives');
  }

  /**
   * `POST /site-templates/archive` — bind one archive view to a template, or
   * unbind it with templateId 0. Returns the FULL archive list so one call
   * re-syncs the screen. Note this only chooses the FRAME: the archive's body
   * comes from the template's own content-slot rule for the same context.
   */
  async setArchive(context: string, templateId: number, init: RestRequestInit = {}): Promise<SiteTemplateArchivesResponse> {
    const response = await this.wp.post('site-templates/archive', { context, templateId }, init, 'site-templates archive');
    return parseJsonResponse(response, siteTemplateArchivesResponseSchema, 'site-templates archive');
  }

  /**
   * `GET /content-source/files` — every theme PHP file a content slot may point at. Fetched ONCE per screen: the list is the same for
   * every template, so it is not part of the per-template config.
   */
  async listContentSourceFiles(init: RestRequestInit = {}): Promise<ContentSourceFilesResponse> {
    const response = await this.wp.get('content-source/files', init, 'content source files');
    return parseJsonResponse(response, contentSourceFilesResponseSchema, 'content source files');
  }

  /** `POST /site-templates/{id}/content-source` — replace the template's body config. */
  async saveContentSource(id: number, config: ContentSourceConfig, init: RestRequestInit = {}): Promise<SaveContentSourceResponse> {
    const response = await this.wp.post(`site-templates/${id}/content-source`, config, init, 'content source save');
    return parseJsonResponse(response, saveContentSourceResponseSchema, 'content source save');
  }
}
