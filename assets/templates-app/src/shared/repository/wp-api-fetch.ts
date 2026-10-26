import { throwIfNotOk } from '@shared/http';

import {
  applyJsonContentTypeIfStringBody,
  mergeHeaders,
  type RestJsonRequestInit,
  type RestRequestInit,
} from './http-client-helpers';
import { joinBasePath } from './join-base-path';

/**
 * `wp.apiFetch({ parse: false })` rejects with a failed `Response` instead of resolving with it.
 * Native `fetch` resolves with non-OK responses. Align behaviour so {@link throwIfNotOk} can read the body.
 */
function settleApiFetchResponse(promise: Promise<Response>): Promise<Response> {
  return promise.catch((err: unknown) => {
    if (err instanceof Response) {
      return err;
    }
    return Promise.reject(err);
  });
}

/** WordPress REST from wp-admin: prefers `wp.apiFetch`, falls back to native `fetch` + nonce. */
export class WpApiFetch {
  private readonly wpRestBaseUrl: string;

  private readonly restRoutePrefix: string;

  /**
   * @param wpJsonBaseUrl REST root without trailing slash (e.g. `…/wp-json`).
   * @param restRoutePrefix e.g. `fabrement/v1` — relative paths are under this namespace.
   */
  constructor(wpJsonBaseUrl: string, restRoutePrefix: string = '') {
    const prefix = restRoutePrefix.trim().replace(/^\/+|\/+$/g, '');
    this.restRoutePrefix = prefix;
    this.wpRestBaseUrl = prefix !== '' ? joinBasePath(wpJsonBaseUrl, prefix) : wpJsonBaseUrl;
  }

  get(path: string, init: RestRequestInit = {}, context?: string): Promise<Response> {
    return this.request(path, { ...init, method: 'GET' }, context);
  }

  delete(path: string, init: RestRequestInit = {}, context?: string): Promise<Response> {
    return this.request(path, { ...init, method: 'DELETE' }, context);
  }

  /** JSON body (`JSON.stringify`). */
  post(path: string, data: unknown, init: RestJsonRequestInit = {}, context?: string): Promise<Response> {
    return this.request(path, { ...init, method: 'POST', body: JSON.stringify(data) }, context);
  }

  private async request(path: string, init: RequestInit, context?: string): Promise<Response> {
    return throwIfNotOk(await this.fetch(path, init), context);
  }

  private fetch(path: string, init: RequestInit): Promise<Response> {
    const apiFetch = window.wp?.apiFetch;
    if (apiFetch) {
      const apiResponse = tryWpApiFetch(apiFetch, this.restPathForApiFetch(path), init);
      if (apiResponse != null) {
        return settleApiFetchResponse(apiResponse);
      }
    }
    return this.fetchNative(path, init);
  }

  /** Path under `/wp-json/` for `wp.apiFetch` (includes namespace when configured). */
  private restPathForApiFetch(segment: string): string {
    const p = segment.trim().replace(/^\/+/, '');
    if (this.restRoutePrefix === '') {
      return p;
    }
    return p === '' ? this.restRoutePrefix : `${this.restRoutePrefix}/${p}`;
  }

  /** Same-origin `fetch` with the REST nonce the page printed. */
  private fetchNative(path: string, init: RequestInit): Promise<Response> {
    const nonce = window.fabrementTemplatesApp?.restNonce;
    const headers = mergeHeaders(init, nonce ? { 'X-WP-Nonce': nonce } : {});
    applyJsonContentTypeIfStringBody(init, headers);

    return fetch(joinBasePath(this.wpRestBaseUrl, path), {
      ...init,
      credentials: init.credentials ?? 'same-origin',
      headers,
    });
  }
}

/** Null when `wp.apiFetch` cannot carry the request (a body that is not JSON text). */
function tryWpApiFetch(
  apiFetch: WordPressApiFetch,
  path: string,
  init: RequestInit,
): Promise<Response> | null {
  const normalizedPath = `/${path.trim().replace(/^\/+/, '')}`;
  const method = (init.method ?? 'GET').toUpperCase();

  const options: Parameters<WordPressApiFetch>[0] = {
    path: normalizedPath,
    method,
    parse: false,
  };

  if (init.signal != null) {
    options.signal = init.signal;
  }
  if (init.cache != null) {
    options.cache = init.cache;
  }
  if (init.credentials != null) {
    options.credentials = init.credentials;
  }
  if (init.headers != null && [...new Headers(init.headers).keys()].length > 0) {
    options.headers = init.headers;
  }

  if (init.body != null) {
    if (typeof init.body !== 'string') {
      return null;
    }
    if (method === 'GET' || method === 'HEAD') {
      return null;
    }
    try {
      options.data = JSON.parse(init.body) as unknown;
    } catch {
      return null;
    }
  }

  return apiFetch(options) as Promise<Response>;
}
