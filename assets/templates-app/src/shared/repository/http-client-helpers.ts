export function mergeHeaders(init: RequestInit, defaults: Record<string, string>): Headers {
  const headers = new Headers(init.headers);
  for (const [key, value] of Object.entries(defaults)) {
    if (!headers.has(key)) {
      headers.set(key, value);
    }
  }
  return headers;
}

export function applyJsonContentTypeIfStringBody(init: RequestInit, headers: Headers): void {
  if (init.body == null || headers.has('Content-Type')) {
    return;
  }
  if (typeof init.body === 'string') {
    headers.set('Content-Type', 'application/json');
  }
}

/** Request options without HTTP method (for GET-style helpers). */
export type RestRequestInit = Omit<RequestInit, 'method'>;

/** Request options without method or body (for POST JSON helpers). */
export type RestJsonRequestInit = Omit<RequestInit, 'method' | 'body'>;
